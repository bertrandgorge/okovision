<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Les programmes horaires sont journalisés sous forme de résumé (« Lu-Ve 06:00-22:00 · Sa-Di … »), plus long que 60 caractères
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boiler_parameter_changes', function (Blueprint $table) {
            $table->string('old_value', 255)->nullable()->change();
            $table->string('new_value', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('boiler_parameter_changes', function (Blueprint $table) {
            $table->string('old_value', 60)->nullable()->change();
            $table->string('new_value', 60)->change();
        });
    }
};
