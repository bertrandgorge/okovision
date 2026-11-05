<?php

namespace App\Filament\Resources\AlertRules;

use App\Alerts\AlertEvaluator;
use App\Alerts\AlertType;
use App\Filament\Resources\AlertRules\Pages\ManageAlertRules;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|\UnitEnum|null $navigationGroup = 'Alertes';

    /** Libellés en français : pas de majuscule à chaque mot */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'règle d\'alerte';

    protected static ?string $pluralModelLabel = 'Règles d\'alerte';

    protected static ?string $navigationLabel = 'Règles d\'alerte';

    protected static ?int $navigationSort = 1;

    /** Champs de seuil par type d'alerte */
    private const PARAM_FIELDS = [
        'minutes' => ['Délai sans données', 'min', [AlertType::NoData]],
        'kg' => ['Stock sous', 'kg', [AlertType::SiloLow]],
        'days' => ['ou épuisement dans moins de / plus de', 'jours', [AlertType::SiloLow, AlertType::MaintenanceDue, AlertType::SweepDue]],
        'pct' => ['Remplissage supérieur à', '%', [AlertType::AshFull]],
        'temp' => ['Température sous', '°C', [AlertType::RoomTempLow, AlertType::DhwTempLow]],
        'cycles' => ['Cycles par jour au-delà de', 'cycles', [AlertType::ShortCycling]],
    ];

    public static function form(Schema $schema): Schema
    {
        $fields = [];
        foreach (self::PARAM_FIELDS as $key => [$label, $unit, $types]) {
            $fields[] = TextInput::make("params.$key")->label($label)->suffix($unit)->numeric()
                ->visible(fn (Get $get) => in_array(self::type($get('type')), $types, true));
        }

        return $schema->components([
            Select::make('type')->label('Événement')->options(AlertType::class)->required()->live()
                ->afterStateUpdated(function (Set $set, $state) {
                    $type = self::type($state);
                    if ($type) {
                        $set('params', $type->defaults());
                        $set('severity', $type->defaultSeverity());
                        $set('name', $type->getLabel());
                    }
                })
                ->helperText(fn (Get $get) => self::type($get('type'))?->getDescription()),
            TextInput::make('name')->label('Nom')->required(),
            ...$fields,
            Select::make('severity')->label('Gravité')->options(['info' => 'Information', 'warning' => 'Avertissement', 'critical' => 'Critique'])->default('warning')->required(),
            Select::make('channels')->label('Canaux de notification')->relationship('channels', 'name')->multiple()->preload(),
            TextInput::make('cooldown_minutes')->label('Délai minimal entre deux rappels')->suffix('min')->numeric()->default(720),
            Toggle::make('notify_resolved')->label('Notifier le retour à la normale')->default(true),
            Toggle::make('enabled')->label('Active')->default(true),
        ]);
    }

    private static function type(mixed $state): ?AlertType
    {
        return $state instanceof AlertType ? $state : AlertType::tryFrom((string) $state);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('channels:id'))
            ->emptyStateHeading('Aucune règle d\'alerte')
            ->emptyStateDescription('Créez une règle ou lancez « php artisan db:seed » pour les règles par défaut.')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable(),
                TextColumn::make('type')->label('Événement')->badge(),
                TextColumn::make('params')->label('Seuils')->state(fn (AlertRule $r) => collect($r->params ?? [])->map(fn ($v, $k) => "$k : $v")->implode(', ')),
                TextColumn::make('severity')->label('Gravité')->badge()
                    ->color(fn ($state) => ['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'][$state] ?? 'gray'),
                // Une icône par canal existant : cliquer branche ou débranche le canal pour cette règle
                ViewColumn::make('channels')->label('Canaux')->view('filament.tables.alert-rule-channels')->disabledClick()
                    ->viewData(['allChannels' => NotificationChannel::orderBy('name')->get()]),
                IconColumn::make('active')->label('En cours')->boolean()->trueColor('danger')->falseColor('success')
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)->falseIcon(Heroicon::OutlinedCheckCircle),
                ToggleColumn::make('enabled')->label('Active'),
            ])
            ->recordActions([
                EditAction::make()->iconButton()
                    ->extraModalFooterActions(fn (EditAction $action) => [
                        Action::make('check')->label('Évaluer')->icon(Heroicon::OutlinedPlay)->color('gray')
                            ->tooltip('Évalue la règle telle qu\'enregistrée (enregistrez d\'abord vos modifications)')
                            ->action(function () use ($action) {
                                $r = app(AlertEvaluator::class)->evaluate($action->getRecord());
                                Notification::make()
                                    ->title($r->triggered ? 'Condition vraie : '.$r->title : 'Condition fausse')
                                    ->body($r->message ?: null)
                                    ->color($r->triggered ? 'warning' : 'success')->send();
                            }),
                    ]),
                DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAlertRules::route('/')];
    }
}
