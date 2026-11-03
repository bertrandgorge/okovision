<?php

namespace App\Filament\Resources\AlertEvents\Pages;

use App\Filament\Resources\AlertEvents\AlertEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAlertEvents extends ManageRecords
{
    protected static string $resource = AlertEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
