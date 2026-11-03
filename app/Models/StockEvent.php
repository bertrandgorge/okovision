<?php

namespace App\Models;

use App\Enums\StockEventType;
use Illuminate\Database\Eloquent\Model;

/**
 * Événements de suivi : livraisons de granulés, vidages du cendrier, ramonage, entretien…
 */
class StockEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'type' => StockEventType::class,
            'price' => 'float',
        ];
    }
}
