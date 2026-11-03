<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStat extends Model
{
    protected $primaryKey = 'day';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        $floats = ['outside_min', 'outside_max', 'outside_avg', 'room_avg', 'room_set_avg', 'flow_avg', 'dhw_avg', 'dju',
            'auger_min', 'auger_min_dhw', 'pellets_kg', 'pellets_kg_dhw', 'fan_avg', 'boiler_temp_max', 'grams_per_auger_minute'];

        return ['day' => 'date:Y-m-d'] + array_fill_keys($floats, 'float');
    }
}
