<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum StockEventType: string implements HasColor, HasIcon, HasLabel
{
    case Refill = 'refill';
    case LevelCheck = 'level_check';
    case AshEmptied = 'ash_emptied';
    case Sweep = 'sweep';
    case Maintenance = 'maintenance';
    case Cleaning = 'cleaning';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Refill => 'Livraison de granulés',
            self::LevelCheck => 'Contrôle du niveau du silo',
            self::AshEmptied => 'Vidage du cendrier',
            self::Sweep => 'Ramonage du conduit',
            self::Maintenance => 'Entretien annuel',
            self::Cleaning => 'Nettoyage chaudière',
            self::Other => 'Autre',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Refill => 'success',
            self::LevelCheck => 'info',
            self::AshEmptied => 'gray',
            self::Sweep, self::Maintenance, self::Cleaning => 'warning',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Refill => 'heroicon-o-truck',
            self::LevelCheck => 'heroicon-o-scale',
            self::AshEmptied => 'heroicon-o-trash',
            self::Sweep => 'heroicon-o-sparkles',
            self::Maintenance, self::Cleaning => 'heroicon-o-wrench-screwdriver',
            self::Other => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}
