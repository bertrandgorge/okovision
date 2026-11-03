<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boiler_profiles', function (Blueprint $table) {
            // manual = profil enregistré à la main, auto = relevé automatique, legacy = import ancienne application
            $table->string('source', 10)->default('manual')->after('id')->index();
            $table->json('changes')->nullable()->after('values')->comment('différences avec le relevé précédent');
        });

        DB::table('boiler_profiles')->where('description', 'like', 'Importé%')->update(['source' => 'legacy']);
    }

    public function down(): void
    {
        Schema::table('boiler_profiles', function (Blueprint $table) {
            $table->dropColumn(['source', 'changes']);
        });
    }
};
