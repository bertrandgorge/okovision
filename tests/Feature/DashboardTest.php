<?php

namespace Tests\Feature;

use App\Models\User;
use App\Okofen\BoilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_value_is_included_in_the_day_min_max(): void
    {
        $today = now()->format('Y-m-d');
        DB::table('measurements')->insert([
            ['ts' => $today.' 06:00:00', 'outside_temp' => 8.6, 'hc1_room_temp' => 18.4],
            ['ts' => $today.' 08:00:00', 'outside_temp' => 10.2, 'hc1_room_temp' => 19.6],
        ]);

        // Lecture directe plus récente que les CSV : il fait plus chaud que le max des mesures
        $this->mock(BoilerService::class, fn ($m) => $m->shouldReceive('live')->andReturn([
            'at' => now()->toIso8601String(), 'error' => null, 'labels' => [],
            'values' => ['outside_temp' => 12.4, 'hc1_room_temp' => 18.1],
        ]));

        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('min 8,6 · max 12,4 °C')
            ->assertSee('min 18,1 · max 19,6 °C');
    }
}
