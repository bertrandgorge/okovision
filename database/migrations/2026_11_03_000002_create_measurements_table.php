<?php

use App\Okofen\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Mesures minute par minute issues des fichiers CSV de la chaudière.
 * Les colonnes sont celles du catalogue (resources/okofen/catalog.yaml) au moment de la migration.
 * Types compacts : ~60 octets par ligne, soit ~1,3 Mo par mois et par an ~16 Mo (hors index).
 */
return new class extends Migration
{
    public function up(): void
    {
        $measurements = app(Catalog::class)->measurements();

        Schema::create('measurements', function (Blueprint $table) use ($measurements) {
            $table->dateTime('ts')->primary()->comment('Horodatage local de la chaudière');

            foreach ($measurements as $column => $m) {
                match ($m['type']) {
                    'temperature' => $table->decimal($column, 4, 1)->nullable(),
                    'pressure' => $table->decimal($column, 5, 1)->nullable(),
                    'duration_cs' => $table->decimal($column, 6, 2)->unsigned()->nullable(),
                    'percent', 'enum' => $table->unsignedTinyInteger($column)->nullable(),
                    'bool' => $table->boolean($column)->nullable(),
                    default => $table->decimal($column, 8, 2)->nullable(),
                };
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurements');
    }
};
