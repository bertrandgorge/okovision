<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_archives', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique()->comment('YYYY-MM');
            $table->string('path');
            $table->unsignedInteger('rows');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->boolean('purged')->default(false)->comment('lignes supprimées de la table measurements');
            $table->timestamps();
        });

        Schema::create('optimizer_actions', function (Blueprint $table) {
            $table->id();
            $table->string('strategy', 40);
            $table->string('action', 40);
            $table->text('reason');
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimizer_actions');
        Schema::dropIfExists('measurement_archives');
    }
};
