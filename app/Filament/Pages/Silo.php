<?php

namespace App\Filament\Pages;

use App\Analysis\EnergySignature;
use App\Analysis\PelletCalibration;
use App\Analysis\StockEstimator;
use App\Charts\ChartFactory;
use App\Enums\StockEventType;
use App\Models\StockEvent;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class Silo extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Silo & cendrier';

    protected static ?string $title = 'Silo à granulés et cendrier';

    protected static string|\UnitEnum|null $navigationGroup = 'Silo & entretien';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'silo';

    protected string $view = 'filament.pages.silo';

    protected function getHeaderActions(): array
    {
        $after = function () {
            app(PelletCalibration::class)->flush();
            Cache::forget('okovision.dashboard.advice');
        };

        return [
            Action::make('refill')
                ->label('Livraison de granulés')
                ->icon(Heroicon::OutlinedTruck)
                ->schema([
                    DatePicker::make('occurred_on')->label('Date')->default(now())->required(),
                    TextInput::make('quantity_kg')->label('Quantité livrée')->suffix('kg')->numeric()->required(),
                    TextInput::make('remaining_kg')->label('Reste estimé dans le silo avant livraison')->suffix('kg')->numeric()
                        ->default(fn () => app(StockEstimator::class)->silo()['level_kg'])
                        ->helperText('Mesuré ou estimé : il sert à calibrer le débit de la vis (laisser l\'estimation si inconnu).'),
                    TextInput::make('price')->label('Prix payé TTC')->suffix('€')->numeric(),
                    TextInput::make('supplier')->label('Fournisseur'),
                ])
                ->action(function (array $data) use ($after) {
                    StockEvent::query()->create($data + ['type' => StockEventType::Refill]);
                    if (! empty($data['price']) && $data['quantity_kg'] > 0) {
                        settings()->set('pellets.price_per_ton', round($data['price'] * 1000 / $data['quantity_kg']));
                    }
                    $after();
                    Notification::make()->title('Livraison enregistrée')->success()->send();
                }),
            Action::make('level')
                ->label('Contrôle de niveau')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->schema([
                    DatePicker::make('occurred_on')->label('Date')->default(now())->required(),
                    TextInput::make('remaining_kg')->label('Quantité constatée dans le silo')->suffix('kg')->numeric()->required(),
                    Textarea::make('notes')->label('Méthode / remarques')->placeholder('ex. hauteur mesurée 60 cm'),
                ])
                ->action(function (array $data) use ($after) {
                    StockEvent::query()->create($data + ['type' => StockEventType::LevelCheck]);
                    $after();
                    Notification::make()->title('Niveau enregistré')->success()->send();
                }),
            Action::make('ash')
                ->label('Cendrier vidé')
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->schema([DatePicker::make('occurred_on')->label('Date')->default(now())->required(), Textarea::make('notes')->label('Remarques')])
                ->action(function (array $data) {
                    StockEvent::query()->create($data + ['type' => StockEventType::AshEmptied]);
                    Notification::make()->title('Vidage enregistré')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $stock = app(StockEstimator::class);
        $silo = $stock->silo();
        $ash = $stock->ashBox();
        $charts = app(ChartFactory::class);
        $calibration = app(PelletCalibration::class);

        return [
            'silo' => $silo,
            'ash' => $ash,
            'siloGauge' => $charts->gauge($silo['pct'], 'stock'),
            'ashGauge' => $charts->gauge($ash['pct'], 'remplissage', '%', [[0.7, '#16a34a'], [0.85, '#f59e0b'], [1, '#ef4444']]),
            'history' => $charts->stockHistory($silo, app(EnergySignature::class)->normalOutsideByDayOfYear(), ash: $ash),
            'intervals' => $calibration->intervals()->sortByDesc('from')->take(12),
            'grams' => $calibration->gramsPerMinute(),
            'events' => StockEvent::query()->latest('occurred_on')->limit(10)->get(),
        ];
    }
}
