<?php

namespace App\Analysis;

use App\Models\BurnerCycle;
use App\Models\DailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Construit, pour une journée, les cycles brûleur (burner_cycles) et la synthèse (daily_stats)
 * à partir des mesures minute.
 */
class DayAggregator
{
    /** États du brûleur considérés comme « en fonctionnement » (cf. catalogue pe1_status). */
    public const ACTIVE = [0, 1, 2, 3, 4, 5];

    public const COMBUSTION = 4;

    public const IGNITION = 2;

    public const RUNON = 5;

    public const FAULTS = [8, 9, 11];

    private const COLUMNS = ['ts', 'outside_temp', 'boiler_temp', 'hc1_flow_temp', 'hc1_flow_set', 'hc1_room_temp',
        'hc1_room_set', 'hc1_pump', 'dhw1_temp_on', 'dhw1_pump', 'pe1_flame_temp', 'pe1_auger_run', 'pe1_auger_pause',
        'pe1_fan', 'pe1_status', 'pe1_motor_ignition'];

    public function __construct(private readonly PelletCalibration $calibration) {}

    public function aggregate(CarbonImmutable $day, ?float $gramsPerMinute = null): ?DailyStat
    {
        $day = $day->startOfDay();
        $g = $gramsPerMinute ?? $this->calibration->gramsPerMinute($day);

        // Fenêtre élargie pour reconstituer les cycles à cheval sur minuit
        $rows = $this->rows($day->subHours(8), $day->addDay()->addHours(8));
        $dayRows = array_values(array_filter($rows, fn ($r) => str_starts_with($r->ts, $day->format('Y-m-d'))));

        if ($dayRows === []) {
            return null;
        }

        $cycles = $this->detectCycles($rows, $g);
        $this->storeCycles($day, $cycles);

        return $this->storeStats($day, $dayRows, array_filter($cycles, fn ($c) => str_starts_with($c['started_at'], $day->format('Y-m-d'))), $g);
    }

    /**
     * Découpe une série de mesures en cycles brûleur.
     *
     * Un cycle commence au premier état « actif » (démarrage/allumage/combustion) et se termine au retour
     * à l'arrêt, ou lorsqu'un nouveau démarrage suit directement une fin de combustion.
     *
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    public function detectCycles(array $rows, float $gramsPerMinute): array
    {
        $cycles = [];
        $current = null;
        $prevTs = null;
        $prevStatus = null;
        $lastEnd = null;

        foreach ($rows as $r) {
            $status = $r->pe1_status === null ? null : (int) $r->pe1_status;
            $ts = CarbonImmutable::parse($r->ts);
            $gap = $prevTs ? $prevTs->diffInMinutes($ts) : 1;
            $active = $status !== null && in_array($status, self::ACTIVE, true);
            $restart = $current && $prevStatus === self::RUNON && in_array($status, [1, 2, 3], true);

            if ($current && ($gap > 5 || ! $active || $restart)) {
                $current['complete'] = $gap <= 5;
                $cycles[] = $this->closeCycle($current, $gramsPerMinute);
                $lastEnd = $current['ended_at'];
                $current = null;
            }

            if ($active && ! $current) {
                $current = [
                    'started_at' => $ts->format('Y-m-d H:i:s'),
                    'ended_at' => $ts->format('Y-m-d H:i:s'),
                    'off_before_min' => $lastEnd ? (int) CarbonImmutable::parse($lastEnd)->diffInMinutes($ts) : null,
                    'minutes' => 0, 'ignition' => 0, 'combustion' => 0, 'runon' => 0, 'auger' => 0.0,
                    'cold' => false, 'dhw' => false, 'heating' => false,
                    'outside' => [], 'boiler_start' => $r->boiler_temp, 'boiler_max' => null, 'fan' => [], 'flame' => [],
                ];
            }

            if ($current) {
                $current['ended_at'] = $ts->addMinute()->format('Y-m-d H:i:s');
                $current['minutes']++;
                $current['ignition'] += $status === self::IGNITION ? 1 : 0;
                $current['runon'] += $status === self::RUNON ? 1 : 0;
                $current['auger'] += self::augerRatio($r);
                $current['cold'] = $current['cold'] || $status === self::IGNITION || (int) $r->pe1_motor_ignition === 1;
                $current['dhw'] = $current['dhw'] || (int) $r->dhw1_pump === 1;
                $current['heating'] = $current['heating'] || ((int) $r->hc1_pump === 1 && (float) $r->hc1_flow_set > 8);
                if ($r->outside_temp !== null) {
                    $current['outside'][] = (float) $r->outside_temp;
                }
                if ($r->boiler_temp !== null) {
                    $current['boiler_max'] = max($current['boiler_max'] ?? -99, (float) $r->boiler_temp);
                }
                if ($status === self::COMBUSTION) {
                    $current['combustion']++;
                    if ($r->pe1_fan !== null) {
                        $current['fan'][] = (float) $r->pe1_fan;
                    }
                    if ($r->pe1_flame_temp !== null) {
                        $current['flame'][] = (float) $r->pe1_flame_temp;
                    }
                }
            } elseif ($active === false && $status !== null) {
                $lastEnd ??= $ts->format('Y-m-d H:i:s');
            }

            $prevTs = $ts;
            $prevStatus = $status;
        }

        if ($current) {
            $current['complete'] = false;
            $cycles[] = $this->closeCycle($current, $gramsPerMinute);
        }

        return $cycles;
    }

    /** Fraction de la minute pendant laquelle la vis brûleur tourne. */
    public static function augerRatio(object $r): float
    {
        $run = (float) $r->pe1_auger_run;
        $pause = (float) $r->pe1_auger_pause;

        return $run > 0 ? $run / ($run + $pause) : 0.0;
    }

    private function closeCycle(array $c, float $g): array
    {
        $avg = fn (array $v) => $v ? round(array_sum($v) / count($v), 1) : null;

        return [
            'started_at' => $c['started_at'],
            'ended_at' => $c['ended_at'],
            'day' => substr($c['started_at'], 0, 10),
            'complete' => $c['complete'] ?? true,
            'cold_start' => $c['cold'],
            'duration_min' => $c['minutes'],
            'ignition_min' => $c['ignition'],
            'combustion_min' => $c['combustion'],
            'runon_min' => $c['runon'],
            'off_before_min' => $c['off_before_min'],
            'auger_min' => round($c['auger'], 2),
            'pellets_kg' => round($c['auger'] * $g / 1000, 2),
            'dhw' => $c['dhw'],
            'heating' => $c['heating'],
            'outside_temp_avg' => $avg($c['outside']),
            'boiler_temp_start' => $c['boiler_start'],
            'boiler_temp_max' => $c['boiler_max'],
            'fan_avg' => $avg($c['fan']),
            'flame_temp_avg' => $avg($c['flame']),
        ];
    }

    private function storeCycles(CarbonImmutable $day, array $cycles): void
    {
        $date = $day->format('Y-m-d');
        $mine = array_values(array_filter($cycles, fn ($c) => $c['day'] === $date));

        DB::transaction(function () use ($date, $mine) {
            BurnerCycle::query()->where('day', $date)->delete();
            if ($mine) {
                $now = now();
                BurnerCycle::query()->insert(array_map(fn ($c) => $c + ['created_at' => $now, 'updated_at' => $now], $mine));
            }
        });
    }

    private function storeStats(CarbonImmutable $day, array $rows, array $cycles, float $g): DailyStat
    {
        $col = fn (string $name, ?callable $filter = null) => array_values(array_filter(
            array_map(fn ($r) => $filter && ! $filter($r) ? null : $r->{$name}, $rows),
            fn ($v) => $v !== null
        ));
        $avg = fn (array $v) => $v ? round(array_sum($v) / count($v), 1) : null;

        $outside = array_map('floatval', $col('outside_temp'));
        $min = $outside ? min($outside) : null;
        $max = $outside ? max($outside) : null;
        $base = (float) settings('climate.dju_base', 18);

        $auger = 0.0;
        $augerDhw = 0.0;
        $active = 0;
        $combustion = 0;
        $faults = 0;
        $prevFault = false;
        foreach ($rows as $r) {
            $ratio = self::augerRatio($r);
            $auger += $ratio;
            if ((int) $r->dhw1_pump === 1) {
                $augerDhw += $ratio;
            }
            $s = $r->pe1_status === null ? null : (int) $r->pe1_status;
            $active += in_array($s, self::ACTIVE, true) ? 1 : 0;
            $combustion += $s === self::COMBUSTION ? 1 : 0;
            $isFault = in_array($s, self::FAULTS, true);
            $faults += $isFault && ! $prevFault ? 1 : 0;
            $prevFault = $isFault;
        }

        return DailyStat::query()->updateOrCreate(['day' => $day->format('Y-m-d')], [
            'samples' => count($rows),
            'outside_min' => $min,
            'outside_max' => $max,
            'outside_avg' => $avg($outside),
            'room_avg' => $avg(array_map('floatval', $col('hc1_room_temp'))),
            'room_set_avg' => $avg(array_map('floatval', $col('hc1_room_set', fn ($r) => (float) $r->hc1_room_set > 8))),
            'flow_avg' => $avg(array_map('floatval', $col('hc1_flow_temp', fn ($r) => (int) $r->hc1_pump === 1))),
            'dhw_avg' => $avg(array_map('floatval', $col('dhw1_temp_on'))),
            'dju' => $min !== null ? round(max(0, $base - ($min + $max) / 2), 2) : null,
            'auger_min' => round($auger, 2),
            'auger_min_dhw' => round($augerDhw, 2),
            'pellets_kg' => round($auger * $g / 1000, 2),
            'pellets_kg_dhw' => round($augerDhw * $g / 1000, 2),
            'cycles' => count($cycles),
            'cold_starts' => count(array_filter($cycles, fn ($c) => $c['cold_start'])),
            'burner_min' => $active,
            'combustion_min' => $combustion,
            'dhw_min' => count($col('dhw1_pump', fn ($r) => (int) $r->dhw1_pump === 1)),
            'heating_pump_min' => count($col('hc1_pump', fn ($r) => (int) $r->hc1_pump === 1)),
            'fan_avg' => $avg(array_map('floatval', $col('pe1_fan', fn ($r) => (int) $r->pe1_status === self::COMBUSTION))),
            'boiler_temp_max' => ($b = array_map('floatval', $col('boiler_temp'))) ? max($b) : null,
            'faults' => $faults,
            'grams_per_auger_minute' => $g,
        ]);
    }

    private function rows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('measurements')
            ->select(self::COLUMNS)
            ->where('ts', '>=', $from)
            ->where('ts', '<', $to)
            ->orderBy('ts')
            ->get()
            ->all();
    }
}
