<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Les notes « Import ancienne application #N » n'apportent rien : l'import est désormais identifié par la date et le type
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_events')->where('notes', 'like', 'Import ancienne application #%')->update(['notes' => null]);
    }

    public function down(): void {}
};
