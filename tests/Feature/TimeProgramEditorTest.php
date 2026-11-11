<?php

namespace Tests\Feature;

use App\Livewire\TimeProgramEditor;
use App\Models\User;
use App\Okofen\TimeProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TimeProgramEditorTest extends TestCase
{
    use RefreshDatabase;

    private function editor(array $days): Testable
    {
        $this->actingAs(User::factory()->create());
        $this->mock(TimeProgram::class)->shouldReceive('read')->andReturn(['days' => $days, 'active' => 0]);

        return Livewire::test(TimeProgramEditor::class, ['circuit' => 'hk']);
    }

    public function test_same_slots_every_day_opens_with_a_single_row(): void
    {
        $page = $this->editor(array_fill(0, 7, [[360, 1380]]));

        $this->assertFalse($page->get('perDay'));
        $page->assertSee('Tous les jours');

        // une plage modifiée sur la ligne unique vaut pour toute la semaine
        $page->set('edit.1.0.end', '22:00');
        $this->assertEquals(array_fill_keys(array_keys(TimeProgram::DAYS), [[360, 1320]]), $page->instance()->parsed());
    }

    public function test_switching_off_with_different_days_applies_monday_everywhere_and_warns(): void
    {
        $days = array_fill(0, 7, [[360, 1380]]);
        $days[6] = [[480, 1380]]; // samedi différent
        $page = $this->editor($days);
        $this->assertTrue($page->get('perDay'));

        $page->set('perDay', false);
        $this->assertTrue($page->get('merged'));
        $this->assertSame([[360, 1380]], $page->instance()->parsed()[6]);

        // « Annuler » rétablit le programme lu et le réglage par jour
        $page->call('resetEdit');
        $this->assertTrue($page->get('perDay'));
        $this->assertSame([[480, 1380]], $page->instance()->parsed()[6]);
    }
}
