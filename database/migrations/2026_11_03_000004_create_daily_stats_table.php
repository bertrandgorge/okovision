<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedSmallInteger('samples')->default(0)->comment('nombre de minutes mesurées');
            $table->decimal('outside_min', 4, 1)->nullable();
            $table->decimal('outside_max', 4, 1)->nullable();
            $table->decimal('outside_avg', 4, 1)->nullable();
            $table->decimal('room_avg', 4, 1)->nullable();
            $table->decimal('room_set_avg', 4, 1)->nullable();
            $table->decimal('flow_avg', 4, 1)->nullable()->comment('T départ moyenne quand la pompe tourne');
            $table->decimal('dhw_avg', 4, 1)->nullable();
            $table->decimal('dju', 5, 2)->nullable();
            $table->decimal('auger_min', 7, 2)->default(0);
            $table->decimal('auger_min_dhw', 7, 2)->default(0);
            $table->decimal('pellets_kg', 7, 2)->default(0);
            $table->decimal('pellets_kg_dhw', 7, 2)->default(0);
            $table->unsignedSmallInteger('cycles')->default(0);
            $table->unsignedSmallInteger('cold_starts')->default(0);
            $table->unsignedSmallInteger('burner_min')->default(0);
            $table->unsignedSmallInteger('combustion_min')->default(0);
            $table->unsignedSmallInteger('dhw_min')->default(0);
            $table->unsignedSmallInteger('heating_pump_min')->default(0);
            $table->decimal('fan_avg', 4, 1)->nullable();
            $table->decimal('boiler_temp_max', 4, 1)->nullable();
            $table->unsignedSmallInteger('faults')->default(0);
            $table->decimal('grams_per_auger_minute', 6, 1)->nullable()->comment('calibration utilisée pour pellets_kg');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
