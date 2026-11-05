<?php

namespace App\Analysis;

use App\Enums\StockEventType;
use App\Models\StockEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calibration du débit de la vis brûleur (grammes de granulés par minute de vis) à partir
 * des livraisons et des contrôles de niveau saisis : entre deux points où le niveau du silo est connu,
 * la quantité consommée divisée par les minutes de vis donne le débit réel.
 */
class PelletCalibration
{
    private const CACHE_KEY = 'okovision.calibration';

    private const MIN_G = 150;

    private const MAX_G = 900;

    /** Démarrages mesurés nécessaires pour juger l'énergie d'un lot. */
    private const MIN_WARMUPS = 30;

    /** Démarrages suffisants pour une première valeur (provisoire) sur le lot en cours. */
    private const MIN_WARMUPS_CURRENT = 5;

    private ?array $intervals = null;

    /** Débit à utiliser pour un jour donné. */
    public function gramsPerMinute(?CarbonImmutable $day = null): float
    {
        $default = (float) settings('pellets.grams_per_auger_minute', 380);
        if (! settings('pellets.auto_calibrate', true)) {
            return $default;
        }

        $intervals = $this->intervals()->where('valid', true);
        if ($intervals->isEmpty()) {
            return $default;
        }

        if ($day) {
            $match = $intervals->first(fn ($i) => $day->format('Y-m-d') >= $i['from'] && $day->format('Y-m-d') < $i['to']);
            if ($match) {
                return $match['grams_per_minute'];
            }
        }

        return $this->current() ?? $default;
    }

    /** Valeur récente : moyenne pondérée (par la quantité consommée) des 3 derniers intervalles valides. */
    public function current(): ?float
    {
        $last = $this->intervals()->where('valid', true)->sortBy('to')->take(-3);
        $kg = $last->sum('consumed_kg');

        return $kg > 0 ? round($last->sum(fn ($i) => $i['grams_per_minute'] * $i['consumed_kg']) / $kg, 1) : null;
    }

    /**
     * Intervalles entre deux points de niveau connus.
     *
     * @return Collection<int, array{from: string, to: string, consumed_kg: float, auger_min: float, coverage: float, grams_per_minute: float|null, valid: bool}>
     */
    public function intervals(): Collection
    {
        if ($this->intervals !== null) {
            return collect($this->intervals);
        }

        $this->intervals = Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => $this->compute());

        return collect($this->intervals);
    }

    public function flush(): void
    {
        $this->intervals = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function compute(): array
    {
        $events = StockEvent::query()
            ->whereIn('type', [StockEventType::Refill, StockEventType::LevelCheck])
            ->orderBy('occurred_on')->orderBy('id')
            ->get();

        // Points de niveau connus : avant une livraison significative (reste saisi) ou contrôle de niveau
        $anchors = [];
        foreach ($events as $e) {
            $isAnchor = $e->remaining_kg !== null
                && ($e->type === StockEventType::LevelCheck || (int) $e->quantity_kg >= 300);
            if ($isAnchor) {
                $anchors[] = $e;
            }
        }

        $result = [];
        for ($i = 0; $i < count($anchors) - 1; $i++) {
            $a = $anchors[$i];
            $b = $anchors[$i + 1];
            $from = $a->occurred_on->format('Y-m-d');
            $to = $b->occurred_on->format('Y-m-d');
            if ($from === $to) {
                continue;
            }

            $startLevel = $a->remaining_kg + ($a->type === StockEventType::Refill ? (int) $a->quantity_kg : 0);
            $added = $events
                ->filter(fn ($e) => $e->type === StockEventType::Refill && $e->id !== $a->id
                    && $e->occurred_on->format('Y-m-d') >= $from && $e->occurred_on->format('Y-m-d') < $to)
                ->sum('quantity_kg');
            $consumed = $startLevel + $added - $b->remaining_kg;

            $stats = DB::table('daily_stats')
                ->where('day', '>=', $from)->where('day', '<', $to)
                ->selectRaw('SUM(auger_min) as auger, SUM(auger_min_dhw) as auger_dhw, SUM(dju) as dju, COUNT(*) as days, SUM(samples) as samples')
                ->first();
            $expectedDays = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
            $coverage = $expectedDays > 0 ? min(1, ((int) $stats->samples) / (1440 * $expectedDays)) : 0;
            $auger = (float) $stats->auger;
            $g = $auger > 0 ? round($consumed * 1000 / $auger, 1) : null;
            // Efficacité : kg consommés pour le chauffage (part eau chaude retirée au prorata des minutes de vis) par degré-jour.
            // Non calculé sur une période trop douce (moins de 100 DJU) ou mal couverte par les mesures (DJU incomplets).
            $dju = (float) $stats->dju;
            $heatingKg = $auger > 0 ? $consumed * (1 - min(1, (float) $stats->auger_dhw / $auger)) : null;
            $kgPerDju = $heatingKg !== null && $dju >= 100 && $coverage >= 0.9 ? round($heatingKg / $dju, 2) : null;

            $result[] = [
                'from' => $from,
                'to' => $to,
                'current' => false,
                'consumed_kg' => (float) $consumed,
                'auger_min' => round($auger, 1),
                'coverage' => round($coverage, 3),
                'grams_per_minute' => $g,
                'dju' => round($dju),
                'kg_per_dju' => $kgPerDju,
                'valid' => $valid = $g !== null && $coverage >= 0.9 && $consumed >= 200 && $g >= self::MIN_G && $g <= self::MAX_G,
                'from_event_id' => $a->id,
                'supplier' => $a->type === StockEventType::Refill ? $a->supplier : null,
            ];
        }

        // Lot en cours : depuis le dernier niveau connu, sans niveau de fin (ni consommation mesurée, ni débit propre)
        $last = end($anchors);
        $today = CarbonImmutable::today()->format('Y-m-d');
        if ($last && $last->occurred_on->format('Y-m-d') < $today) {
            $from = $last->occurred_on->format('Y-m-d');
            $stats = DB::table('daily_stats')->where('day', '>=', $from)->where('day', '<', $today)
                ->selectRaw('SUM(auger_min) as auger, SUM(samples) as samples')->first();
            $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($today));
            $result[] = [
                'from' => $from, 'to' => $today, 'current' => true,
                'consumed_kg' => null, 'auger_min' => round((float) $stats->auger, 1),
                'coverage' => $days > 0 ? round(min(1, ((int) $stats->samples) / (1440 * $days)), 3) : 0,
                'grams_per_minute' => null, 'dju' => null, 'kg_per_dju' => null, 'valid' => false,
                'from_event_id' => $last->id,
                'supplier' => $last->type === StockEventType::Refill ? $last->supplier : null,
            ];
        }

        // Débit moyen des lots bien mesurés (pondéré par la quantité consommée) : sert à estimer les kg brûlés
        // des lots dont les mesures sont trop incomplètes pour calibrer leur propre débit
        $validKg = array_sum(array_column(array_filter($result, fn ($i) => $i['valid']), 'consumed_kg'));
        $meanG = $validKg > 0 ? array_sum(array_map(fn ($i) => $i['valid'] ? $i['grams_per_minute'] * $i['consumed_kg'] : 0, $result)) / $validKg : null;

        foreach ($result as &$i) {
            $i += $this->warmupEnergy($i['from'], $i['to'], $i['valid'] ? $i['grams_per_minute'] : $meanG);
            $i['energy_estimated'] = ! $i['valid'];
            $i['energy_grams_per_minute'] = $i['valid'] ? $i['grams_per_minute'] : ($meanG !== null ? round($meanG, 1) : null);
        }
        unset($i);

        // Énergie relative : médiane du lot ÷ médiane des lots bien mesurés (les lots estimés ne déplacent pas la référence)
        $reference = $this->median(array_column(array_filter($result, fn ($i) => ! $i['energy_estimated'] && $i['warmups'] >= self::MIN_WARMUPS), 'rise_per_kg'));
        foreach ($result as &$i) {
            // Lot en cours : valeur provisoire dès quelques démarrages, affinée au fil de la saison
            $min = ($i['current'] ?? false) ? self::MIN_WARMUPS_CURRENT : self::MIN_WARMUPS;
            $i['energy_index'] = $reference && $i['warmups'] >= $min ? (int) round(100 * $i['rise_per_kg'] / $reference) : null;
            $i['energy_provisional'] = $i['energy_index'] !== null && $i['warmups'] < self::MIN_WARMUPS;
        }
        unset($i);

        return $result;
    }

    /**
     * Montée en température de la chaudière par kg de granulés lors des démarrages après un long arrêt
     * (chaleur qui reste dans la chaudière, voir DayAggregator::warmup) : proportionnelle à l'énergie utile par kg.
     *
     * @return array{warmups: int, rise_per_kg: float|null}
     */
    private function warmupEnergy(string $from, string $to, ?float $g): array
    {
        if ($g === null) {
            return ['warmups' => 0, 'rise_per_kg' => null];
        }
        $ratios = DB::table('burner_cycles')
            ->where('started_at', '>=', $from)->where('started_at', '<', $to)
            ->where('off_before_min', '>=', 120)->where('warmup_min', '>=', 4)
            ->where('warmup_auger_min', '>', 0.4)->where('warmup_rise', '>', 2)
            ->get(['warmup_rise', 'warmup_auger_min'])
            ->map(fn ($c) => $c->warmup_rise / ($c->warmup_auger_min * $g / 1000))->all();

        return ['warmups' => count($ratios), 'rise_per_kg' => $ratios ? round($this->median($ratios), 2) : null];
    }

    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
