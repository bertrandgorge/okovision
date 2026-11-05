<?php

namespace App\Filament\Resources\AlertRules\Pages;

use App\Filament\Resources\AlertRules\AlertRuleResource;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAlertRules extends ManageRecords
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** Branche ou débranche un canal de notification pour une règle, depuis la liste. */
    public function toggleChannel(int $ruleId, int $channelId): void
    {
        $rule = AlertRule::findOrFail($ruleId);
        NotificationChannel::findOrFail($channelId);
        $rule->channels()->toggle([$channelId]);
    }
}
