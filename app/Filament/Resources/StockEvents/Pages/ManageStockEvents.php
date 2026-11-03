<?php

namespace App\Filament\Resources\StockEvents\Pages;

use App\Filament\Resources\StockEvents\StockEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStockEvents extends ManageRecords
{
    protected static string $resource = StockEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
