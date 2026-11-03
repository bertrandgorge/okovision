<?php

namespace App\Analysis;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Signature énergétique du bâtiment : consommation journalière de granulés en fonction de la
 * température extérieure moyenne, modèle « en coude » :
 *     kg/jour = socle (ECS + pertes) + pente × max(0, T0 − T ext)
 */
class EnergySignature
{
    public function model(int $days = 730): ?array
    {
        return Cache::remember("okovision.signature.$days", now()->addHours(6), fn () => $this->fit($days));
    }

    public function predictDailyKg(?float $outsideAvg): ?float
    {
        $m = $this->model();
        if (! $m) {
            return null;
        }
        if ($outsideAvg === null) {
            return $m['base_kg'];
        }

        // Interpolation dans la table empirique ; extrapolation linéaire par temps plus froid
        $table = $m['table'];
        $temps = array_map('floatval', array_keys($table));
        $min = min($temps);
        $max = max($temps);
        if ($outsideAvg <= $min) {
            return max(0, $table[(string) $min] + $m['slope_kg_per_k'] * ($min - $outsideAvg));
        }
        if ($outsideAvg >= $max) {
            return $table[(string) $max];
        }
        $lo = floor($outsideAvg);
        $hi = $lo + 1;
        $a = $table[(string) $lo] ?? null;
        $b = $table[(string) $hi] ?? null;
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a + ($b - $a) * ($outsideAvg - $lo);
    }

    /**
     * Consommation attendue un jour donné pour les projections : signature × part des jours où la chaudière
     * fonctionne habituellement ce mois-là (arrêt estival, mise en route tardive…).
     */
    public function expectedDailyKg(CarbonInterface $date, ?float $outsideAvg): ?float
    {
        $kg = $this->predictDailyKg($outsideAvg);

        return $kg === null ? null : $kg * ($this->runningShareByMonth()[$date->month] ?? 1.0);
    }

    /** Part des jours avec au moins un cycle, par mois (3 dernières années). */
    public function runningShareByMonth(): array
    {
        return Cache::remember('okovision.running_share', now()->addDay(), function () {
            $rows = DB::table('daily_stats')->where('day', '>=', now()->subYears(3)->format('Y-m-d'))->where('samples', '>=', 1380)
                ->selectRaw('MONTH(day) m, AVG(cycles > 0) share, COUNT(*) n')->groupBy('m')->get();
            $out = array_fill(1, 12, 1.0);
            foreach ($rows as $r) {
                if ($r->n >= 10) {
                    $out[(int) $r->m] = round((float) $r->share, 3);
                }
            }

            return $out;
        });
    }

    /** Puissance utile moyenne nécessaire (kW) pour une T ext donnée. */
    public function loadKw(float $outsideAvg): ?float
    {
        $kg = $this->predictDailyKg($outsideAvg);
        if ($kg === null) {
            return null;
        }

        return round($kg * (float) settings('pellets.lhv_kwh_per_kg', 4.8) * (float) settings('pellets.boiler_efficiency', 0.88) / 24, 2);
    }

    /** Températures extérieures « normales » par jour de l'année (moyenne historique lissée sur ±7 jours). */
    public function normalOutsideByDayOfYear(): array
    {
        return Cache::remember('okovision.normals', now()->addDay(), function () {
            $raw = DB::table('daily_stats')
                ->whereNotNull('outside_avg')
                ->selectRaw('DAYOFYEAR(day) as doy, AVG(outside_avg) as t')
                ->groupBy('doy')->pluck('t', 'doy')->map(fn ($v) => (float) $v)->all();

            if ($raw === []) {
                return [];
            }

            $out = [];
            for ($d = 1; $d <= 366; $d++) {
                $values = [];
                for ($k = -7; $k <= 7; $k++) {
                    $j = (($d + $k - 1 + 366) % 366) + 1;
                    if (isset($raw[$j])) {
                        $values[] = $raw[$j];
                    }
                }
                $out[$d] = $values ? round(array_sum($values) / count($values), 1) : null;
            }

            return $out;
        });
    }

    /**
     * Points (T ext moyenne, kg/jour) pour le nuage de la signature.
     *
     * @return list<array{0: float, 1: float, 2: string}>
     */
    public function points(string $from, string $to): array
    {
        return DB::table('daily_stats')
            ->whereBetween('day', [$from, $to])
            ->where('samples', '>=', 1380)
            // Jours sans aucun cycle (ni chauffage ni eau chaude) : chaudière à l'arrêt, pas représentatifs du besoin
            ->where('cycles', '>', 0)
            ->whereNotNull('outside_avg')
            ->orderBy('day')
            ->get(['day', 'outside_avg', 'pellets_kg'])
            ->map(fn ($r) => [(float) $r->outside_avg, (float) $r->pellets_kg, (string) $r->day])
            ->all();
    }

    /**
     * Modèle empirique : médiane des kg/jour par °C de T ext moyenne (lissée sur 3 °C, monotone décroissante),
     * complétée d'une régression linéaire sur les jours froids (pente → déperditions, extrapolation).
     */
    private function fit(int $days): ?array
    {
        $rows = DB::table('daily_stats')
            ->where('day', '>=', now()->subDays($days)->format('Y-m-d'))
            ->where('day', '<', now()->format('Y-m-d'))
            ->where('samples', '>=', 1380)
            // Jours sans aucun cycle (ni chauffage ni eau chaude) : chaudière à l'arrêt, pas représentatifs du besoin
            ->where('cycles', '>', 0)
            ->whereNotNull('outside_avg')
            ->get(['outside_avg', 'pellets_kg', 'pellets_kg_dhw']);

        if ($rows->count() < 60) {
            return null;
        }

        $median = function (array $v): float {
            sort($v);
            $n = count($v);

            return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
        };

        $byT = [];
        foreach ($rows as $r) {
            $byT[(int) round((float) $r->outside_avg)][] = (float) $r->pellets_kg;
        }
        ksort($byT);

        // Médiane lissée sur ±1 °C, uniquement là où il y a assez de jours
        $table = [];
        foreach ($byT as $t => $values) {
            $pool = array_merge($byT[$t - 1] ?? [], $values, $byT[$t + 1] ?? []);
            if (count($pool) >= 8) {
                $table[$t] = $median($pool);
            }
        }
        if (count($table) < 5) {
            return null;
        }
        // Monotonie : la consommation ne peut pas augmenter quand il fait plus doux
        $prev = INF;
        foreach ($table as $t => $kg) {
            $table[$t] = round(min($kg, $prev), 2);
            $prev = $table[$t];
        }

        // Régression sur les jours de chauffe franche (T ≤ 13 °C) pour la pente
        $cold = $rows->filter(fn ($r) => $r->outside_avg <= 13);
        $slope = 0.0;
        if ($cold->count() >= 20) {
            $n = $cold->count();
            $sx = $cold->sum(fn ($r) => (float) $r->outside_avg);
            $sy = $cold->sum(fn ($r) => (float) $r->pellets_kg);
            $sxx = $cold->sum(fn ($r) => (float) $r->outside_avg ** 2);
            $sxy = $cold->sum(fn ($r) => (float) $r->outside_avg * (float) $r->pellets_kg);
            $den = $n * $sxx - $sx * $sx;
            $slope = abs($den) > 1e-9 ? max(0, -($n * $sxy - $sx * $sy) / $den) : 0.0;
        }

        // Qualité : part de variance expliquée par la table
        $mean = $rows->avg('pellets_kg');
        $ssTot = $rows->sum(fn ($r) => ((float) $r->pellets_kg - $mean) ** 2);
        $ssRes = $rows->sum(function ($r) use ($table) {
            $t = (int) round((float) $r->outside_avg);
            $keys = array_keys($table);
            $t = max(min($keys), min(max($keys), $t));
            $pred = $table[$t] ?? $table[array_reduce($keys, fn ($c, $k) => $c === null || abs($k - $t) < abs($c - $t) ? $k : $c)];

            return ((float) $r->pellets_kg - $pred) ** 2;
        });

        // Température de non-chauffage : premier °C où la médiane passe sous 10 % de la conso max
        $maxKg = max($table);
        $t0 = null;
        foreach ($table as $t => $kg) {
            if ($kg <= 0.1 * $maxKg) {
                $t0 = $t;
                break;
            }
        }

        $kwh = (float) settings('pellets.lhv_kwh_per_kg', 4.8) * (float) settings('pellets.boiler_efficiency', 0.88);
        // Socle (eau chaude, pertes) : médiane des jours chauds où la chaudière fonctionne
        $summer = $rows->filter(fn ($r) => $r->outside_avg >= 20)->pluck('pellets_kg')->map(fn ($v) => (float) $v)->all();

        return [
            'table' => array_combine(array_map('strval', array_keys($table)), array_values($table)),
            'base_kg' => $summer ? round($median($summer), 2) : 0.0,
            'slope_kg_per_k' => round($slope, 3),
            't0' => $t0 ?? 18.0,
            'r2' => $ssTot > 0 ? round(1 - $ssRes / $ssTot, 3) : null,
            'days' => $rows->count(),
            'loss_w_per_k' => (int) round($slope * $kwh * 1000 / 24),
        ];
    }
}
