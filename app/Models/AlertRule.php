<?php

namespace App\Models;

use App\Alerts\AlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'params' => 'array',
            'enabled' => 'boolean',
            'notify_resolved' => 'boolean',
            'active' => 'boolean',
            'last_triggered_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'alert_rule_channel');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->params, $key, $default);
    }
}
