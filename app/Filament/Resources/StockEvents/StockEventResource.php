<?php

namespace App\Filament\Resources\StockEvents;

use App\Analysis\PelletCalibration;
use App\Enums\StockEventType;
use App\Filament\Resources\StockEvents\Pages\ManageStockEvents;
use App\Models\StockEvent;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockEventResource extends Resource
{
    protected static ?string $model = StockEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Silo & entretien';

    protected static ?string $modelLabel = 'événement';

    protected static ?string $pluralModelLabel = 'Livraisons & entretien';

    protected static ?string $navigationLabel = 'Livraisons & entretien';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $isRefill = fn (Get $get) => in_array($get('type'), [StockEventType::Refill, StockEventType::Refill->value], true);
        $isLevel = fn (Get $get) => in_array($get('type'), [StockEventType::Refill, StockEventType::Refill->value, StockEventType::LevelCheck, StockEventType::LevelCheck->value], true);

        return $schema->components([
            Select::make('type')->label('Type')->options(StockEventType::class)->required()->live(),
            DatePicker::make('occurred_on')->label('Date')->required()->default(now()),
            TextInput::make('quantity_kg')->label('Quantité livrée')->suffix('kg')->numeric()->visible($isRefill),
            TextInput::make('remaining_kg')->label(fn (Get $get) => $isRefill($get) ? 'Reste dans le silo avant livraison' : 'Quantité constatée dans le silo')
                ->suffix('kg')->numeric()->visible($isLevel),
            TextInput::make('price')->label('Prix TTC')->suffix('€')->numeric(),
            TextInput::make('supplier')->label('Fournisseur / intervenant'),
            Textarea::make('notes')->label('Notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label('Date')->date('d/m/Y')->sortable(),
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('quantity_kg')->label('Quantité')->suffix(' kg')->numeric(locale: 'fr')->placeholder('—'),
                TextColumn::make('remaining_kg')->label('Reste / niveau')->suffix(' kg')->numeric(locale: 'fr')->placeholder('—'),
                TextColumn::make('price')->label('Prix')->money('EUR', locale: 'fr')->placeholder('—'),
                TextColumn::make('price_per_ton')->label('€/t')
                    ->state(fn (StockEvent $r) => $r->price && $r->quantity_kg ? round($r->price * 1000 / $r->quantity_kg) : null)->placeholder('—'),
                TextColumn::make('supplier')->label('Fournisseur')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->label('Notes')->limit(40)->toggleable(),
            ])
            ->filters([SelectFilter::make('type')->options(StockEventType::class)])
            ->recordActions([
                EditAction::make()->after(fn () => app(PelletCalibration::class)->flush()),
                DeleteAction::make()->after(fn () => app(PelletCalibration::class)->flush()),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStockEvents::route('/')];
    }
}
