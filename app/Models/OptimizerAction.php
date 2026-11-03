<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptimizerAction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'context' => 'array', 'dry_run' => 'boolean'];
    }
}
