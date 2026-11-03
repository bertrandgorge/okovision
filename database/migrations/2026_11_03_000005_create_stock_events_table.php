<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_events', function (Blueprint $table) {
            $table->id();
            $table->date('occurred_on')->index();
            // refill, level_check, ash_emptied, sweep, maintenance, cleaning, other
            $table->string('type', 20)->index();
            $table->unsignedInteger('quantity_kg')->nullable()->comment('quantité livrée');
            $table->unsignedInteger('remaining_kg')->nullable()->comment('reste estimé/mesuré dans le silo avant livraison');
            $table->decimal('price', 9, 2)->nullable()->comment('prix TTC');
            $table->string('supplier')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_events');
    }
};
