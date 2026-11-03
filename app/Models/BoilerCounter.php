<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoilerCounter extends Model
{
    protected $primaryKey = 'day';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'date:Y-m-d', 'extra' => 'array'];
    }
}
