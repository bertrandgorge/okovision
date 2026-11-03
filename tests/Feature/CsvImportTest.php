<?php

namespace Tests\Feature;

use App\Analysis\DayAggregator;
use App\Models\BurnerCycle;
use App\Okofen\CsvParser;
use App\Okofen\MeasurementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function sample(): string
    {
        return file_get_contents(base_path('tests/Fixtures/touch_sample.csv'));
    }

    public function test_parses_latin1_csv_with_comma_decimals(): void
    {
        $rows = iterator_to_array(app(CsvParser::class)->parse($this->sample()), false);

        $this->assertCount(120, $rows);
        $first = $rows[0];
        $this->assertSame('2026-11-02 09:00:00', $first['ts']);
        $this->assertIsFloat($first['outside_temp']);
        $this->assertArrayHasKey('pe1_status', $first);
        $this->assertArrayHasKey('pe1_auger_run', $first);
        $this->assertCount(36, $first); // ts + 35 colonnes
    }

    public function test_import_is_idempotent_and_builds_cycles(): void
    {
        $importer = app(MeasurementImporter::class);
        $importer->importContent($this->sample());
        $importer->importContent($this->sample());

        $this->assertSame(120, DB::table('measurements')->count());

        $stat = app(DayAggregator::class)->aggregate(CarbonImmutable::parse('2026-11-02'), 380);

        $this->assertSame(120, $stat->samples);
        $this->assertGreaterThan(0, $stat->pellets_kg);
        $this->assertGreaterThan(0, BurnerCycle::query()->count());
        $this->assertEqualsWithDelta($stat->auger_min * 0.38, $stat->pellets_kg, 0.05);
    }

    public function test_cycle_detection_splits_restart_after_runon(): void
    {
        $row = fn (string $t, int $status, float $run = 0) => (object) [
            'ts' => '2026-01-12 '.$t, 'outside_temp' => 10, 'boiler_temp' => 75, 'hc1_flow_set' => 40, 'hc1_pump' => 1,
            'dhw1_pump' => 0, 'pe1_flame_temp' => 600, 'pe1_auger_run' => $run, 'pe1_auger_pause' => $run ? 80 : 0,
            'pe1_fan' => 20, 'pe1_status' => $status, 'pe1_motor_ignition' => 0,
        ];
        $rows = [
            $row('10:00:00', 99), $row('10:01:00', 1), $row('10:02:00', 4, 20), $row('10:03:00', 4, 20), $row('10:04:00', 5),
            $row('10:05:00', 1), $row('10:06:00', 4, 20), $row('10:07:00', 5), $row('10:08:00', 99), $row('10:09:00', 99),
        ];

        $cycles = app(DayAggregator::class)->detectCycles($rows, 380);

        $this->assertCount(2, $cycles);
        $this->assertSame(2, $cycles[0]['combustion_min']);
        $this->assertSame(0, $cycles[1]['off_before_min']);
        $this->assertFalse($cycles[0]['cold_start']);
        $this->assertEqualsWithDelta(2 * 20 / 100, $cycles[0]['auger_min'], 0.001);
    }
}
