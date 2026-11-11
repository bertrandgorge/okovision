<?php

namespace Tests\Feature;

use App\Analysis\HotWater;
use App\Models\BoilerParameterChange;
use App\Models\BoilerProfile;
use App\Okofen\SettingsHistory;
use App\Okofen\TimeProgram;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotWaterTest extends TestCase
{
    use RefreshDatabase;

    private function minutes(string $from, int $count, float $temp): void
    {
        $t = CarbonImmutable::parse($from);
        DB::table('measurements')->insert(array_map(fn ($i) => [
            'ts' => $t->addMinutes($i)->format('Y-m-d H:i:s'), 'dhw1_temp_on' => $temp, 'dhw1_set' => 52,
        ], range(0, $count - 1)));
    }

    public function test_a_day_counts_as_disinfected_after_ten_minutes_at_60(): void
    {
        $this->minutes('2026-11-08 14:00', 15, 64);   // cycle anti-légionelles
        $this->minutes('2026-11-08 15:00', 30, 40);   // zone de prolifération
        $this->minutes('2026-11-09 14:00', 5, 61);    // trop court

        $daily = collect(app(HotWater::class)->daily('2026-11-08', '2026-11-09'))->keyBy('day');

        $this->assertTrue(HotWater::isDisinfected($daily['2026-11-08']));
        $this->assertSame(30, $daily['2026-11-08']->min_growth);
        $this->assertFalse(HotWater::isDisinfected($daily['2026-11-09']));
    }

    public function test_next_cycle_is_today_or_the_next_matching_weekday(): void
    {
        CarbonImmutable::setTestNow('2026-11-10 12:00'); // mardi

        $this->assertTrue(HotWater::nextCycle(2)->isSameDay('2026-11-10'));
        $this->assertSame('2026-11-15', HotWater::nextCycle(0)->format('Y-m-d'));
        $this->assertNull(HotWater::nextCycle(7));
    }

    public function test_program_summary_round_trips(): void
    {
        $days = [1 => [[360, 1320]], 2 => [[360, 1320]], 3 => [[420, 1380], [1390, 1430]], 4 => [[360, 1320]], 5 => [], 6 => [[420, 1380]], 0 => [[420, 1380]]];

        $this->assertEquals($days, TimeProgram::parseSummary(TimeProgram::summary($days)));
        $this->assertNull(TimeProgram::parseSummary('texte inconnu'));
    }

    public function test_settings_history_gives_the_value_in_force_at_a_time(): void
    {
        $log = fn (string $at, string $old, string $new) => BoilerParameterChange::query()->forceCreate([
            'name' => 'X', 'old_value' => $old, 'new_value' => $new, 'source' => 'manual', 'status' => 'applied', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $log('2026-11-03 22:00:00', '40', '52');
        $log('2026-11-10 10:00:00', '52', '50');
        $history = new SettingsHistory(['X' => '50', 'Y' => '7']);

        $this->assertSame('40', $history->valueAt('X', '2026-11-01 12:00:00'));
        $this->assertSame('52', $history->valueAt('X', '2026-11-09 12:00:00'));
        $this->assertSame('50', $history->valueAt('X', '2026-11-10 10:00:00'));
        $this->assertSame('7', $history->valueAt('Y', '2026-11-09 12:00:00'));
    }

    public function test_settings_history_also_uses_automatic_snapshots(): void
    {
        // changement fait sur la chaudière : absent du journal, vu par le relevé de 18:11
        BoilerParameterChange::query()->forceCreate(['name' => 'X', 'old_value' => '20', 'new_value' => '20.5', 'source' => 'manual', 'status' => 'applied',
            'created_at' => '2026-11-10 10:04:00', 'updated_at' => '2026-11-10 10:04:00']);
        BoilerProfile::query()->forceCreate(['source' => 'auto', 'name' => 'relevé', 'values' => ['X' => 20], 'created_at' => '2026-11-10 18:11:00', 'updated_at' => '2026-11-10 18:11:00']);
        $history = new SettingsHistory;

        $this->assertSame('20', (string) $history->valueAt('X', '2026-11-10 09:00:00'));
        $this->assertSame('20.5', (string) $history->valueAt('X', '2026-11-10 12:00:00'));
        $this->assertSame('20', (string) $history->valueAt('X', '2026-11-10 19:00:00'));
        $this->assertSame('2026-11-10 18:11:00', $history->observedAt('X', '2026-11-10 19:00:00'));
    }
}
