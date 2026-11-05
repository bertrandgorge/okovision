<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ChannelType: string implements HasIcon, HasLabel
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

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Email => Heroicon::OutlinedEnvelope,
            self::Slack => Heroicon::OutlinedHashtag,
            self::Telegram => Heroicon::OutlinedPaperAirplane,
            self::Webhook => Heroicon::OutlinedCodeBracketSquare,
        };
    }
}
