<?php

namespace Tests\Feature;

use App\Charts\ChartFactory;
use App\Models\BoilerParameterChange;
use App\Models\BoilerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_parameter_changed_twice_in_a_day_keeps_first_and_last_value(): void
    {
        foreach ([['5', '12', '10:00:00'], ['12', '10', '11:00:00']] as [$old, $new, $time]) {
            BoilerParameterChange::query()->create(['name' => 'CAPPL:FA[0].pe_einschalthysterese_smart', 'old_value' => $old,
                'new_value' => $new, 'status' => 'applied', 'created_at' => '2026-11-04 '.$time]);
        }

        $notes = app(ChartFactory::class)->settingChanges('2026-11-04', '2026-11-04');

        $this->assertCount(1, $notes);
        $this->assertCount(1, $notes[0]['lines']);
        $this->assertSame(['5 K', '10 K'], [$notes[0]['lines'][0]['old'], $notes[0]['lines'][0]['new']]);
    }

    public function test_notes_combine_snapshots_app_changes_and_legacy_profiles(): void
    {
        // premier relevé (pas un changement), relevé avec changement, écriture sans relevé, profil importé
        BoilerProfile::query()->create(['source' => 'auto', 'name' => 'R1', 'values' => [], 'created_at' => '2026-11-01 03:10:00']);
        BoilerProfile::query()->create(['source' => 'auto', 'name' => 'R2', 'values' => [], 'changes' => ['CAPPL:LOCAL.ww[0].hysterese' => ['old' => 5, 'new' => 8]],
            'description' => '1 réglage(s) modifié(s) : Hystérésis de redémarrage : 5 → 12', 'created_at' => '2026-11-02 10:00:00']);
        BoilerParameterChange::query()->create(['name' => 'CAPPL:LOCAL.ww[0].temp_heizen', 'old_value' => '40', 'new_value' => '52',
            'status' => 'applied', 'created_at' => '2026-11-03 09:00:00']);
        BoilerParameterChange::query()->create(['name' => 'CAPPL:FA[0].pe_einschalthysterese_smart', 'old_value' => '5', 'new_value' => '12',
            'status' => 'applied', 'created_at' => '2026-11-02 09:59:00']); // même jour que R2 : fusionné
        BoilerProfile::query()->create(['source' => 'legacy', 'name' => '2017-10-03 13:59 – Puissance de la chaudière 25kW -> 21kW',
            'values' => [], 'created_at' => '2017-10-03 13:59:00']);

        $charts = app(ChartFactory::class);
        $notes = $charts->settingChanges('2017-01-01', '2026-11-30');

        $this->assertSame(['2017-10-03', '2026-11-02', '2026-11-03'], array_column($notes, 'day'));
        $this->assertSame(['Puissance de la chaudière 25kW -> 21kW'], $notes[0]['titles']);

        // relevé + écriture du même jour fusionnés : deux réglages distincts, deux sources
        $this->assertCount(2, $notes[1]['lines']);
        $this->assertEqualsCanonicalizing(['auto', 'app'], $notes[1]['sources']);
        $this->assertSame('2026-11-02 09:59:00', $notes[1]['from']);
        $this->assertSame('2026-11-02 10:00:00', $notes[1]['to']);

        $this->assertSame(['label' => 'Consigne eau chaude', 'old' => '40 °C', 'new' => '52 °C', 'reverted' => false], $notes[2]['lines'][0]);

        $option = $charts->withChangeMarkers($charts->dailyConsumption('2026-11-01', '2026-11-30'), $notes);
        $this->assertSame('⚙ 2', $option['series'][0]['markLine']['data'][1]['name']);
    }
}
