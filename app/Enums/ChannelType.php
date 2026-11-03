<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChannelType: string implements HasLabel
{
    case Email = 'email';
    case Slack = 'slack';
    case Telegram = 'telegram';
    case Webhook = 'webhook';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Slack => 'Slack (webhook entrant)',
            self::Telegram => 'Telegram (bot)',
            self::Webhook => 'Webhook HTTP (JSON)',
        };
    }
}
