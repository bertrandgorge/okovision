<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('burner_cycles', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at')->unique();
            $table->dateTime('ended_at')->nullable();
            $table->date('day')->index();
            $table->boolean('complete')->default(true)->comment('false si le cycle est encore en cours ou coupé par la fin des données');
            $table->boolean('cold_start')->default(false)->comment('allumeur électrique utilisé');
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedSmallInteger('ignition_min')->default(0);
            $table->unsignedSmallInteger('combustion_min')->default(0);
            $table->unsignedSmallInteger('runon_min')->default(0);
            $table->unsignedSmallInteger('off_before_min')->nullable()->comment('durée d\'arrêt précédant le cycle');
            $table->decimal('auger_min', 7, 2)->default(0)->comment('minutes équivalentes de vis brûleur');
            $table->decimal('pellets_kg', 7, 2)->default(0);
            $table->boolean('dhw')->default(false);
            $table->boolean('heating')->default(false);
            $table->decimal('outside_temp_avg', 4, 1)->nullable();
            $table->decimal('boiler_temp_start', 4, 1)->nullable();
            $table->decimal('boiler_temp_max', 4, 1)->nullable();
            $table->decimal('fan_avg', 4, 1)->nullable()->comment('vitesse moyenne ventilateur en combustion');
            $table->decimal('flame_temp_avg', 5, 1)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('burner_cycles');
    }
};
