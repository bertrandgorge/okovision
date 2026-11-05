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

    /** Libellés en français : pas de majuscule à chaque mot */
    protected static bool $hasTitleCaseModelLabel = false;

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
            ->emptyStateHeading('Aucun événement')
            ->emptyStateDescription('Enregistrez une livraison, un vidage du cendrier ou un entretien.')
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label('Date')->date('d/m/Y')->sortable(),
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('quantity_kg')->label('Quantité')->suffix(' kg')->numeric(locale: 'fr')->placeholder('—'),
                TextColumn::make('remaining_kg')->label('Reste / niveau')->suffix(' kg')->numeric(locale: 'fr')->placeholder('—'),
                TextColumn::make('price')->label('Prix')->money('EUR', locale: 'fr')->placeholder('—'),
                TextColumn::make('price_per_ton')->label('€/t')
                    ->state(fn (StockEvent $r) => $r->price && $r->quantity_kg ? round($r->price * 1000 / $r->quantity_kg) : null)->placeholder('—'),
                TextColumn::make('supplier')->label('Fournisseur / intervenant')->placeholder('—'),
                TextColumn::make('energy_index')->label('Énergie relative')
                    ->state(fn (StockEvent $r) => ($b = self::batch($r)) && $b['energy_index'] !== null
                        ? ($b['energy_estimated'] ? '≈ ' : '').$b['energy_index'].' %'.($b['energy_provisional'] ? ' (provisoire)' : '') : null)
                    ->placeholder('—')
                    ->color(fn (StockEvent $r) => ($e = self::batch($r)['energy_index'] ?? null) === null ? null : ($e < 90 ? 'danger' : ($e > 110 ? 'success' : null)))
                    ->tooltip(fn (StockEvent $r) => ($b = self::batch($r)) && $b['warmups']
                        ? sprintf('Lot consommé du %s au %s : %d démarrages mesurés (100 %% = lot médian).%s Détail dans Silo & cendrier.',
                            \Carbon\Carbon::parse($b['from'])->format('d/m/Y'), \Carbon\Carbon::parse($b['to'])->format('d/m/Y'), $b['warmups'],
                            ($b['energy_estimated'] ? ($b['current'] ? ' Lot en cours : estimé avec le débit moyen de la vis.' : ' Estimé avec le débit moyen de la vis : mesures du lot incomplètes.') : '')
                            .($b['energy_provisional'] ? ' Provisoire : moins de 30 démarrages.' : ''))
                        : 'Non mesuré : lot sans niveau connu à la livraison et au contrôle suivant, ou trop peu de démarrages.')
                    ->toggleable(),
                TextColumn::make('notes')->label('Notes')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('type')->options(StockEventType::class)])
            ->recordActions([
                EditAction::make()->after(fn () => app(PelletCalibration::class)->flush()),
                DeleteAction::make()->after(fn () => app(PelletCalibration::class)->flush()),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /** Lot de granulés qui commence avec cette livraison (intervalle de calibration), s'il est connu. */
    private static function batch(StockEvent $r): ?array
    {
        if ($r->type !== StockEventType::Refill) {
            return null;
        }

        return app(PelletCalibration::class)->intervals()->firstWhere('from_event_id', $r->id);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStockEvents::route('/')];
    }
}
