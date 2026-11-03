<?php

namespace App\Models;

use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NotificationChannel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function rules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class, 'alert_rule_channel');
    }
}
