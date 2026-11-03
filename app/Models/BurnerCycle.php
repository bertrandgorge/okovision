<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BurnerCycle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'day' => 'date',
            'complete' => 'boolean',
            'cold_start' => 'boolean',
            'dhw' => 'boolean',
            'heating' => 'boolean',
            'auger_min' => 'float',
            'pellets_kg' => 'float',
            'outside_temp_avg' => 'float',
            'boiler_temp_start' => 'float',
            'boiler_temp_max' => 'float',
            'fan_avg' => 'float',
            'flame_temp_avg' => 'float',
        ];
    }
}
