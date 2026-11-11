<?php

namespace Tests\Feature;

use App\Analysis\Advisor;
use Tests\TestCase;

class HeatingCircuitAdviceTest extends TestCase
{
    private function ids(array $params, array $windows): array
    {
        return array_column(app(Advisor::class)->heatingCircuitAdvice($params, $windows), 'id');
    }

    public function test_flags_zero_room_hysteresis_fragmented_program_and_high_reduced_limit(): void
    {
        $params = ['room_comfort' => 20, 'room_reduced' => 18, 'room_hyst' => 0, 'room_influence' => 3, 'limit_reduced' => 14];
        $ids = $this->ids($params, [1 => [[360, 960], [990, 1380]]]); // lundi : coupure 16:00–16:30

        $this->assertSame(['room_hysteresis', 'fragmented_program', 'reduced_limit_high'], $ids);
    }

    public function test_room_hysteresis_proposal_keeps_the_band_centred(): void
    {
        $rec = app(Advisor::class)->heatingCircuitAdvice(['room_comfort' => 20, 'room_hyst' => 0, 'room_influence' => 3], [])[0];

        $this->assertSame([Advisor::P['room_hyst'] => 1, Advisor::P['room_comfort'] => 19.5], $rec['changes']);
    }

    public function test_sensible_settings_raise_nothing(): void
    {
        $params = ['room_comfort' => 19.5, 'room_reduced' => 17, 'room_hyst' => 1, 'room_influence' => 3, 'limit_reduced' => 10];

        $this->assertSame([], $this->ids($params, array_fill(0, 7, [[360, 1380]])));
    }
}
