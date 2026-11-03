<?php

namespace Database\Seeders;

use App\Alerts\AlertType;
use App\Models\AlertRule;
use Illuminate\Database\Seeder;

/** Règles d'alerte par défaut (sans canal : à associer dans l'interface). */
class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        $cooldowns = [
            AlertType::BoilerFault->value => 60,
            AlertType::BoilerUnreachable->value => 180,
            AlertType::NoData->value => 180,
            AlertType::RoomTempLow->value => 240,
            AlertType::DhwTempLow->value => 720,
        ];

        foreach (AlertType::cases() as $type) {
            AlertRule::query()->firstOrCreate(['type' => $type], [
                'name' => $type->getLabel(),
                'params' => $type->defaults(),
                'severity' => $type->defaultSeverity(),
                'enabled' => ! in_array($type, [AlertType::DhwTempLow, AlertType::RoomTempLow], true),
                'cooldown_minutes' => $cooldowns[$type->value] ?? 1440,
            ]);
        }
    }
}
