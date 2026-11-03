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
                ->selectRaw('SUM(auger_min) as auger, COUNT(*) as days, SUM(samples) as samples')
                ->first();
            $expectedDays = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
            $coverage = $expectedDays > 0 ? min(1, ((int) $stats->samples) / (1440 * $expectedDays)) : 0;
            $auger = (float) $stats->auger;
            $g = $auger > 0 ? round($consumed * 1000 / $auger, 1) : null;

            $result[] = [
                'from' => $from,
                'to' => $to,
                'consumed_kg' => (float) $consumed,
                'auger_min' => round($auger, 1),
                'coverage' => round($coverage, 3),
                'grams_per_minute' => $g,
                'valid' => $g !== null && $coverage >= 0.9 && $consumed >= 200 && $g >= self::MIN_G && $g <= self::MAX_G,
            ];
        }

        return $result;
    }
}
