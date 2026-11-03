<?php

namespace App\Analysis;

use App\Enums\StockEventType;
use App\Models\StockEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Estimation du stock de granulés dans le silo et du remplissage du cendrier.
 */
class StockEstimator
{
    public function __construct(
        private readonly PelletCalibration $calibration,
        private readonly EnergySignature $signature,
    ) {}

    /**
     * @return array{level_kg: float|null, capacity_kg: float, pct: float|null, anchor: array|null, consumed_since_kg: float, added_since_kg: float, days_left: int|null, empty_on: string|null, daily_avg_kg: float|null}
     */
    public function silo(): array
    {
        $capacity = (float) settings('equipment.silo_capacity_kg', 3500);
        $events = StockEvent::query()
            ->whereIn('type', [StockEventType::Refill, StockEventType::LevelCheck])
            ->orderBy('occurred_on')->orderBy('id')->get();

        // Dernier point de niveau connu : contrôle de niveau, ou livraison (reste + quantité ; reste inconnu = 0)
        $anchor = $events->last(fn ($e) => $e->type === StockEventType::LevelCheck || (int) $e->quantity_kg >= 300);
        if (! $anchor) {
            return ['level_kg' => null, 'capacity_kg' => $capacity, 'pct' => null, 'anchor' => null, 'consumed_since_kg' => 0,
                'added_since_kg' => 0, 'days_left' => null, 'empty_on' => null, 'daily_avg_kg' => null];
        }

        $from = $anchor->occurred_on->format('Y-m-d');
        $level = (float) ($anchor->remaining_kg ?? 0) + ($anchor->type === StockEventType::Refill ? (float) $anchor->quantity_kg : 0);
        $added = (float) $events->filter(fn ($e) => $e->type === StockEventType::Refill && $e->id !== $anchor->id
            && $e->occurred_on->format('Y-m-d') >= $from)->sum('quantity_kg');

        $consumed = $this->consumedSince($anchor->occurred_on->toImmutable());
        $current = max(0, $level + $added - $consumed);
        $forecast = $this->forecast($current);

        return [
            'level_kg' => round($current),
            'capacity_kg' => $capacity,
            'pct' => $capacity > 0 ? round(100 * $current / $capacity, 1) : null,
            'anchor' => ['date' => $from, 'type' => $anchor->type->getLabel(), 'level_kg' => $level],
            'consumed_since_kg' => round($consumed),
            'added_since_kg' => $added,
            'days_left' => $forecast['days'],
            'empty_on' => $forecast['date'],
            'daily_avg_kg' => $this->recentDailyAverage(),
        ];
    }

    /**
     * @return array{burnt_kg: float, capacity_kg: float, pct: float|null, since: string|null, days_left: int|null, full_on: string|null}
     */
    public function ashBox(): array
    {
        $capacity = (float) settings('equipment.ash_box_capacity_kg', 1500);
        $last = StockEvent::query()->where('type', StockEventType::AshEmptied)->latest('occurred_on')->first();
        if (! $last) {
            return ['burnt_kg' => 0, 'capacity_kg' => $capacity, 'pct' => null, 'since' => null, 'days_left' => null, 'full_on' => null];
        }

        $burnt = $this->consumedSince($last->occurred_on->toImmutable());
        // Date à laquelle le cendrier sera plein : même projection que pour le silo, sur la capacité restante
        $forecast = $this->forecast(max(0, $capacity - $burnt));

        return [
            'burnt_kg' => round($burnt),
            'capacity_kg' => $capacity,
            'pct' => $capacity > 0 ? round(100 * $burnt / $capacity, 1) : null,
            'since' => $last->occurred_on->format('Y-m-d'),
            'days_left' => $forecast['days'],
            'full_on' => $forecast['date'],
        ];
    }

    /** Granulés consommés depuis une date (incluse), en kg. */
    public function consumedSince(CarbonImmutable $date): float
    {
        $g = $this->calibration->gramsPerMinute();
        $auger = (float) DB::table('daily_stats')->where('day', '>=', $date->format('Y-m-d'))->sum('auger_min');

        return $auger * $g / 1000;
    }

    public function recentDailyAverage(int $days = 14): ?float
    {
        $avg = DB::table('daily_stats')->where('day', '>=', now()->subDays($days)->format('Y-m-d'))
            ->where('day', '<', now()->format('Y-m-d'))->avg('pellets_kg');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Projette la consommation jour par jour avec la signature énergétique et des DJU « normaux »
     * (moyenne historique du même jour de l'année).
     *
     * @return array{days: int|null, date: string|null}
     */
    public function forecast(float $stockKg, int $horizon = 730): array
    {
        if ($stockKg <= 0) {
            return ['days' => 0, 'date' => now()->format('Y-m-d')];
        }

        $normals = $this->signature->normalOutsideByDayOfYear();
        $day = CarbonImmutable::today();
        $remaining = $stockKg;

        for ($i = 1; $i <= $horizon; $i++) {
            $d = $day->addDays($i);
            $t = $normals[$d->dayOfYear] ?? null;
            $kg = $this->signature->expectedDailyKg($d, $t);
            if ($kg === null) {
                return ['days' => null, 'date' => null];
            }
            $remaining -= $kg;
            if ($remaining <= 0) {
                return ['days' => $i, 'date' => $d->format('Y-m-d')];
            }
        }

        return ['days' => null, 'date' => null];
    }
}
