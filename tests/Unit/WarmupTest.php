<?php

namespace Tests\Unit;

use App\Analysis\DayAggregator;
use PHPUnit\Framework\TestCase;

class WarmupTest extends TestCase
{
    private function row(int $status, float $boiler, float $flow, int $dhwPump = 0, float $run = 20, float $pause = 80): object
    {
        return (object) ['pe1_status' => $status, 'boiler_temp' => $boiler, 'hc1_flow_temp' => $flow, 'dhw1_pump' => $dhwPump,
            'pe1_auger_run' => $run, 'pe1_auger_pause' => $pause];
    }

    public function test_measures_rise_above_the_threshold_until_the_heating_flow_starts(): void
    {
        $rows = [
            $this->row(2, 51.0, 20.2, run: 50, pause: 20),   // allumage : ignoré
            $this->row(4, 60.1, 20.2),                       // sous 66 °C : ignoré
            $this->row(4, 64.0, 20.2),
            $this->row(4, 66.5, 20.2),
            $this->row(4, 69.0, 20.6),
            $this->row(4, 72.3, 21.0),
            $this->row(4, 75.0, 23.5),                       // la vanne s'ouvre : fin de la fenêtre
            $this->row(4, 76.0, 30.0),
        ];

        $w = DayAggregator::warmup($rows);

        $this->assertSame(3, $w['warmup_min']);
        $this->assertSame(5.8, $w['warmup_rise']);
        $this->assertSame(0.6, $w['warmup_auger_min']);
    }

    public function test_stops_when_hot_water_is_drawn_and_ignores_cycles_without_a_usable_window(): void
    {
        $w = DayAggregator::warmup([$this->row(4, 70.0, 20.0), $this->row(4, 72.0, 20.0, dhwPump: 1)]);
        $this->assertSame(1, $w['warmup_min']);
        $this->assertSame(0.0, $w['warmup_rise']);

        $this->assertNull(DayAggregator::warmup([$this->row(2, 50.0, 20.0), $this->row(5, 52.0, 20.0)]));
        // la vanne s'ouvre avant 66 °C : rien à mesurer
        $this->assertNull(DayAggregator::warmup([$this->row(4, 55.0, 20.0), $this->row(4, 60.0, 25.0)]));
    }
}
