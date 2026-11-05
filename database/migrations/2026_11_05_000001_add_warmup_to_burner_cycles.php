<?php

use App\Analysis\DayAggregator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Montée en température « à vide » au début de chaque cycle (voir DayAggregator::warmup), qui sert à comparer
 * l'énergie des lots de granulés. Calculée ici pour l'historique des démarrages après un long arrêt,
 * tant que les mesures minute sont encore en base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('burner_cycles', function (Blueprint $table) {
            $table->unsignedSmallInteger('warmup_min')->nullable()->after('flame_temp_avg');
            $table->float('warmup_rise')->nullable()->after('warmup_min');
            $table->float('warmup_auger_min')->nullable()->after('warmup_rise');
        });

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

    public function down(): void
    {
        Schema::table('burner_cycles', function (Blueprint $table) {
            $table->dropColumn(['warmup_min', 'warmup_rise', 'warmup_auger_min']);
        });
    }
};
