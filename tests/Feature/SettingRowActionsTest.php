<?php

namespace Tests\Feature;

use App\Analysis\HeatingCurve;
use App\Filament\Pages\HeatingCurvePage;
use App\Models\User;
use App\Okofen\BoilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingRowActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_one_sends_only_that_setting_and_factory_fills_the_field(): void
    {
        $this->actingAs(User::factory()->create());
        $applied = [];
        $boiler = $this->mock(BoilerService::class);
        $boiler->shouldReceive('currentCurve')->andReturn(HeatingCurve::fromArray(['slope' => 1.6, 'foot' => 32.4, 'room_comfort' => 20, 'limit_reduced' => 14]));
        $boiler->shouldReceive('read')->andReturn([]);
        $boiler->shouldReceive('apply')->andReturnUsing(function (array $changes, string $source) use (&$applied) {
            $applied[] = [$changes, $source];

            return array_map(fn ($n) => ['name' => $n, 'status' => 'applied', 'message' => null], array_keys($changes));
        });

        $page = Livewire::test(HeatingCurvePage::class)
            ->set('edit.slope', 1.4)
            ->set('edit.limit_reduced', 10);
        $this->assertTrue($page->instance()->isSettingChanged('limit_reduced'));

        // « Appliquer » d'une ligne : ce réglage seul, l'autre modification reste en cours
        $page->call('applyOne', 'limit_reduced');
        $this->assertSame([[[HeatingCurve::PARAMS['limit_reduced'] => 10], 'curve']], $applied);
        $this->assertEquals(1.4, $page->get('edit.slope'));

        // « Usine » : valeur d'usine du catalogue, appliquée aussitôt
        $page->call('factoryOne', 'room_comfort');
        $this->assertSame([[HeatingCurve::PARAMS['room_comfort'] => 22], 'curve'], $applied[1]);
        $this->assertCount(2, $applied);

        // « Annuler » d'une ligne
        $page->call('resetOne', 'slope');
        $this->assertEquals(1.6, $page->get('edit.slope'));
    }
}
