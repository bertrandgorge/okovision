<?php

namespace App\Alerts;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum AlertType: string implements HasDescription, HasLabel
{
    case BoilerFault = 'boiler_fault';
    case BoilerUnreachable = 'boiler_unreachable';
    case NoData = 'no_data';
    case SiloLow = 'silo_low';
    case AshFull = 'ash_full';
    case RoomTempLow = 'room_temp_low';
    case DhwTempLow = 'dhw_temp_low';
    case ShortCycling = 'short_cycling';
    case MaintenanceDue = 'maintenance_due';
    case SweepDue = 'sweep_due';

    public function getLabel(): string
    {
        return match ($this) {
            self::BoilerFault => 'Défaut chaudière',
            self::BoilerUnreachable => 'Chaudière injoignable',
            self::NoData => 'Plus de données reçues',
            self::SiloLow => 'Silo bientôt vide',
            self::AshFull => 'Cendrier bientôt plein',
            self::RoomTempLow => 'Température ambiante basse',
            self::DhwTempLow => 'Eau chaude trop froide',
            self::ShortCycling => 'Trop de cycles brûleur',
            self::MaintenanceDue => 'Entretien annuel à prévoir',
            self::SweepDue => 'Ramonage à prévoir',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::BoilerFault => "L'état de la chaudière indique un défaut (cendres, granulés, défaut général).",
            self::BoilerUnreachable => "L'API de la chaudière ne répond plus.",
            self::NoData => 'Aucune mesure reçue depuis N minutes.',
            self::SiloLow => 'Stock estimé sous un seuil (kg) ou épuisement prévu dans moins de N jours.',
            self::AshFull => 'Granulés brûlés depuis le dernier vidage au-delà de N % de la capacité du cendrier.',
            self::RoomTempLow => 'Température ambiante sous le seuil.',
            self::DhwTempLow => 'Température du ballon ECS sous le seuil.',
            self::ShortCycling => 'Nombre de cycles de la veille supérieur au seuil.',
            self::MaintenanceDue => 'Dernier entretien annuel il y a plus de N jours.',
            self::SweepDue => 'Dernier ramonage il y a plus de N jours.',
        };
    }

    /** Paramètres par défaut (seuils). */
    public function defaults(): array
    {
        return match ($this) {
            self::BoilerFault, self::BoilerUnreachable => [],
            self::NoData => ['minutes' => (int) settings('alerts.no_data_minutes', 60)],
            self::SiloLow => ['kg' => (int) settings('alerts.silo_low_kg', 500), 'days' => (int) settings('alerts.silo_low_days', 21)],
            self::AshFull => ['pct' => (int) settings('alerts.ash_full_pct', 85)],
            self::RoomTempLow => ['temp' => (float) settings('alerts.room_temp_low', 17)],
            self::DhwTempLow => ['temp' => 40],
            self::ShortCycling => ['cycles' => (int) settings('alerts.cycles_per_day_high', 30)],
            self::MaintenanceDue => ['days' => 365],
            self::SweepDue => ['days' => 365],
        };
    }

    public function defaultSeverity(): string
    {
        return match ($this) {
            self::BoilerFault, self::BoilerUnreachable, self::NoData => 'critical',
            self::SiloLow, self::AshFull, self::RoomTempLow, self::DhwTempLow => 'warning',
            default => 'info',
        };
    }
}
