<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal de toutes les écritures de réglages sur la chaudière
        Schema::create('boiler_parameter_changes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->index();
            $table->string('old_value', 60)->nullable();
            $table->string('new_value', 60);
            $table->string('source', 20)->default('manual'); // manual, profile, curve, optimizer, advisor
            $table->string('status', 20)->default('applied'); // applied, failed, dry_run
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Profils de réglages sauvegardés (ex. « hiver », « été », « avant optimisation »)
        Schema::create('boiler_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('values');
            $table->timestamps();
        });

        // Relevés quotidiens des compteurs internes de la chaudière
        Schema::create('boiler_counters', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('burner_starts')->nullable();
            $table->unsignedInteger('ignitions')->nullable();
            $table->unsignedInteger('burner_hours')->nullable();
            $table->unsignedSmallInteger('mean_run_minutes')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boiler_counters');
        Schema::dropIfExists('boiler_profiles');
        Schema::dropIfExists('boiler_parameter_changes');
    }
};
