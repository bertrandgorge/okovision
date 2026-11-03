<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementArchive extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['purged' => 'boolean'];
    }
}
