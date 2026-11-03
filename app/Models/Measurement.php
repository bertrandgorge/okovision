<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mesure minute par minute (colonnes décrites dans resources/okofen/catalog.yaml).
 */
class Measurement extends Model
{
    protected $primaryKey = 'ts';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ts' => 'datetime'];
    }
}
