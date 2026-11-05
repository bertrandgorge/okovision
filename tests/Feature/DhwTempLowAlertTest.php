<?php

namespace Tests\Feature;

use App\Alerts\AlertEvaluator;
use App\Alerts\AlertType;
use App\Models\AlertRule;
use App\Okofen\TimeProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DhwTempLowAlertTest extends TestCase
{
    use RefreshDatabase;

    private function evaluateAt(string $ts, float $temp): bool
    {
        DB::table('measurements')->delete();
        DB::table('measurements')->insert(['ts' => $ts, 'dhw1_temp_on' => $temp]);
        $rule = new AlertRule(['type' => AlertType::DhwTempLow, 'params' => ['temp' => 40]]);

        return app(AlertEvaluator::class)->evaluate($rule)->triggered;
    }

    public function test_alert_only_inside_dhw_window_after_grace_period(): void
    {
        // Plage ECS 08:00–21:00 tous les jours (programme lu sur la chaudière, ici en cache)
        Cache::put(TimeProgram::DHW_WINDOWS_CACHE, array_fill(0, 7, [[480, 1260]]), 3600);

        $this->assertFalse($this->evaluateAt('2026-11-05 07:20:00', 37), 'hors plage');
        $this->assertFalse($this->evaluateAt('2026-11-05 08:15:00', 26), 'début de plage, chauffe en cours');
        $this->assertTrue($this->evaluateAt('2026-11-05 10:05:00', 35), 'plage commencée depuis plus de 2 h');
        $this->assertFalse($this->evaluateAt('2026-11-05 10:05:00', 45), 'au-dessus du seuil');
        $this->assertFalse($this->evaluateAt('2026-11-05 21:30:00', 30), 'après la plage');
    }

    public function test_without_program_the_threshold_applies(): void
    {
        $this->mock(TimeProgram::class)->shouldReceive('read')->andThrow(new \RuntimeException('injoignable'));

        $this->assertTrue($this->evaluateAt('2026-11-05 07:20:00', 37));
    }
}
