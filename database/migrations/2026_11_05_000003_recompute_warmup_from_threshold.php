<?php

use App\Analysis\DayAggregator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * La montée « à vide » n'est plus mesurée qu'à partir de DayAggregator::WARMUP_FROM °C (effet de la température
 * de départ) : recalcul de l'historique encore présent en base.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('burner_cycles')->update(['warmup_min' => null, 'warmup_rise' => null, 'warmup_auger_min' => null]);
        DB::table('burner_cycles')->where('off_before_min', '>=', 120)->orderBy('id')
            ->select(['id', 'started_at', 'ended_at'])
            ->chunkById(500, function ($cycles) {
                foreach ($cycles as $c) {
                    $rows = DB::table('measurements')->whereBetween('ts', [$c->started_at, $c->ended_at])->orderBy('ts')
                        ->get(['ts', 'boiler_temp', 'hc1_flow_temp', 'dhw1_pump', 'pe1_status', 'pe1_auger_run', 'pe1_auger_pause'])->all();
                    if ($warmup = DayAggregator::warmup($rows)) {
                        DB::table('burner_cycles')->where('id', $c->id)->update($warmup);
                    }
                }
            });
    }

    public function down(): void {}
};
