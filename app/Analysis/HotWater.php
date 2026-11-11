<?php

namespace App\Analysis;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eau chaude sanitaire : températures du ballon jour par jour, désinfections (anti-légionelles) et charges.
 *
 * Repères légionelles (OMS, guide CSTB) : prolifération entre 25 et 45 °C (optimum ~35 °C), survie sous 50 °C,
 * destruction de 90 % en ~30 min à 60 °C, ~2 min à 65 °C, quasi instantanée à 70 °C.
 */
class HotWater
{
    /** Seuil de désinfection du ballon */
    public const DISINFECT_TEMP = 60.0;

    /** Température visée par le cycle anti-légionelles (fixe sur la chaudière, relevée sur les mesures) */
    public const LEGIONELLA_TEMP = 65.0;

    /** Minutes cumulées au-dessus du seuil pour compter une journée comme désinfectée */
    public const DISINFECT_MIN = 10;

    /** Zone de prolifération */
    public const GROWTH_LOW = 25.0;

    public const GROWTH_HIGH = 45.0;

    /**
     * Statistiques par jour sur les mesures minute (T ballon = sonde haute dhw1_temp_on).
     *
     * @return list<object{day: string, max: ?float, min: ?float, avg: ?float, set_max: ?float, min_above: int, min_growth: int, samples: int, charges: int, kg: float}>
     */
    public function daily(string $from, string $to): array
    {
        $rows = DB::table('measurements')
            ->whereBetween('ts', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereNotNull('dhw1_temp_on')
            ->selectRaw('DATE(ts) day, MAX(dhw1_temp_on) max, MIN(dhw1_temp_on) min, AVG(dhw1_temp_on) avg, MAX(dhw1_set) set_max, COUNT(*) samples')
            ->selectRaw('SUM(dhw1_temp_on >= ?) min_above', [self::DISINFECT_TEMP])
            ->selectRaw('SUM(dhw1_temp_on >= ? AND dhw1_temp_on < ?) min_growth', [self::GROWTH_LOW, self::GROWTH_HIGH])
            ->groupByRaw('DATE(ts)')->orderBy('day')->get()->keyBy('day');

        $charges = DB::table('burner_cycles')->whereBetween('day', [$from, $to])->where('dhw', true)
            ->selectRaw('day, COUNT(*) n')->groupBy('day')->pluck('n', 'day');
        $kg = DB::table('daily_stats')->whereBetween('day', [$from, $to])->pluck('pellets_kg_dhw', 'day');

        return $rows->map(fn ($r) => (object) [
            'day' => (string) $r->day,
            'max' => $r->max === null ? null : (float) $r->max,
            'min' => $r->min === null ? null : (float) $r->min,
            'avg' => $r->avg === null ? null : round((float) $r->avg, 1),
            'set_max' => $r->set_max === null ? null : (float) $r->set_max,
            'min_above' => (int) $r->min_above,
            'min_growth' => (int) $r->min_growth,
            'samples' => (int) $r->samples,
            'charges' => (int) ($charges[$r->day] ?? 0),
            'kg' => (float) ($kg[$r->day] ?? 0),
        ])->values()->all();
    }

    /**
     * Délai entre le début d'une plage ECS et un ballon chaud : pour chaque début de plage des derniers jours où le
     * ballon était sous la cible et la charge lancée dans la première demi-heure, minutes jusqu'à la cible de la charge
     * (consigne relevée par la chaudière pendant la charge). La charge ne démarre qu'au début de la plage : pas
     * d'anticipation pour l'eau chaude.
     *
     * @param  array<int, list<array{0: int, 1: int}>>|callable  $windows  plages par jour de semaine, ou fonction
     *                                                                     (jour) => plages de ce jour (programme en vigueur)
     * @return array{median: ?int, min: ?int, max: ?int, count: int, samples: list<int>}
     */
    public function chargeDelay(array|callable $windows, int $days = 30): array
    {
        $slotsOf = is_callable($windows) ? $windows : fn (CarbonImmutable $d) => $windows[$d->dayOfWeek] ?? [];
        $from = CarbonImmutable::today()->subDays($days);
        $rows = DB::table('measurements')->where('ts', '>=', $from->format('Y-m-d'))->orderBy('ts')
            ->get(['ts', 'dhw1_temp_on', 'dhw1_set', 'dhw1_pump']);
        $byTs = [];
        foreach ($rows as $r) {
            $byTs[substr($r->ts, 0, 16)] = $r;
        }

        $samples = [];
        for ($d = $from; $d->lte(CarbonImmutable::today()); $d = $d->addDay()) {
            foreach ($slotsOf($d) as [$a]) {
                $span = [];
                for ($m = 0; $m <= 120; $m++) {
                    if ($r = $byTs[$d->addMinutes($a + $m)->format('Y-m-d H:i')] ?? null) {
                        $span[$m] = $r;
                    }
                }
                // charge nécessaire dès le début (ballon sous la cible) et lancée dans la première demi-heure
                $first = collect($span)->first(fn ($r, $m) => $m <= 5);
                $target = $span ? max(array_map(fn ($r) => (float) $r->dhw1_set, $span)) : 0;
                $startsEarly = collect($span)->contains(fn ($r, $m) => $m <= 30 && ($r->dhw1_pump ?? 0) > 0);
                if (count($span) < 60 || ! $first || (float) $first->dhw1_temp_on >= $target - 2 || ! $startsEarly) {
                    continue;
                }
                foreach ($span as $m => $r) {
                    if ($r->dhw1_temp_on !== null && (float) $r->dhw1_temp_on >= $target - 0.5) {
                        $samples[] = $m;
                        break;
                    }
                }
            }
        }
        sort($samples);

        return [
            'median' => $samples ? $samples[intdiv(count($samples) - 1, 2)] : null,
            'min' => $samples ? $samples[0] : null,
            'max' => $samples ? end($samples) : null,
            'count' => count($samples),
            'samples' => $samples,
        ];
    }

    /** Journées où le ballon a passé au moins DISINFECT_MIN minutes à 60 °C ou plus. */
    public static function isDisinfected(object $day): bool
    {
        return $day->min_above >= self::DISINFECT_MIN;
    }

    /**
     * Dernière désinfection, cherchée jusqu'à un an en arrière.
     *
     * @return array{day: string, max: float, minutes: int}|null
     */
    public function lastDisinfection(): ?array
    {
        $r = DB::table('measurements')
            ->where('ts', '>=', CarbonImmutable::today()->subYear()->format('Y-m-d'))
            ->where('dhw1_temp_on', '>=', self::DISINFECT_TEMP)
            ->selectRaw('DATE(ts) day, COUNT(*) minutes, MAX(dhw1_temp_on) max')
            ->groupByRaw('DATE(ts)')->havingRaw('COUNT(*) >= ?', [self::DISINFECT_MIN])
            ->orderByDesc('day')->first();

        return $r ? ['day' => (string) $r->day, 'max' => (float) $r->max, 'minutes' => (int) $r->minutes] : null;
    }

    /** Prochain jour du cycle anti-légionelles (0 = dimanche … 6 = samedi ; 7 = arrêt => null). */
    public static function nextCycle(?int $weekday): ?CarbonImmutable
    {
        if ($weekday === null || $weekday < 0 || $weekday > 6) {
            return null;
        }
        $today = CarbonImmutable::today();

        return $today->dayOfWeek === $weekday ? $today : $today->next($weekday);
    }

    /**
     * Points d'attention sur les réglages et les relevés.
     *
     * @param  array<string, mixed>  $settings  clés de la page : set, min, legionella…
     * @return list<array{severity: string, text: string}>
     */
    public function diagnose(array $settings, ?array $last, array $daily): array
    {
        $out = [];
        $legio = $settings['legionella'] ?? null;
        $set = $settings['set'] ?? null;
        $min = $settings['min'] ?? null;

        if ($legio !== null && (int) $legio === 7) {
            $out[] = ['severity' => 'danger', 'text' => 'Le cycle anti-légionelles est à l\'arrêt : choisissez un jour pour que le ballon monte chaque semaine au-dessus de 60 °C.'];
        }
        if ($last === null) {
            $out[] = ['severity' => 'danger', 'text' => 'Aucune désinfection (au moins '.self::DISINFECT_MIN.' min à 60 °C) relevée depuis un an.'];
        } elseif (($days = CarbonImmutable::parse($last['day'])->diffInDays(CarbonImmutable::today())) > 8) {
            $out[] = ['severity' => 'warning', 'text' => 'Dernière désinfection il y a '.(int) $days.' jours : le cycle hebdomadaire ne s\'est pas fait (chaudière arrêtée, ECS en arrêt ?).'];
        }
        if ($set !== null && $set < 50) {
            $out[] = ['severity' => 'warning', 'text' => "Consigne à {$set} °C : sous 50 °C, les légionelles survivent dans le ballon entre deux cycles. 52 à 55 °C est un bon compromis."];
        }
        if ($min !== null && $min < self::GROWTH_HIGH && $min >= self::GROWTH_LOW) {
            $out[] = ['severity' => 'info', 'text' => "Hors plages horaires, le ballon peut descendre à {$min} °C, dans la zone de prolifération (25–45 °C). Sans gravité si le cycle hebdomadaire et la consigne ≥ 50 °C sont assurés."];
        }
        $recent = array_slice($daily, -14);
        $growth = array_sum(array_map(fn ($d) => $d->min_growth, $recent));
        $samples = array_sum(array_map(fn ($d) => $d->samples, $recent));
        if ($samples > 0 && $growth / $samples > 0.25) {
            $out[] = ['severity' => 'info', 'text' => 'Sur 14 jours, le haut du ballon a passé '.round($growth / $samples * 100).' % du temps entre 25 et 45 °C.'];
        }

        return $out;
    }
}
