<?php

namespace App\Filament\Resources\AlertEvents;

use App\Filament\Resources\AlertEvents\Pages\ManageAlertEvents;
use App\Models\AlertEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AlertEventResource extends Resource
{
    protected static ?string $model = AlertEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Alertes';

    /** Libellés en français : pas de majuscule à chaque mot */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'alerte';

    protected static ?string $pluralModelLabel = 'Historique des alertes';

    protected static ?string $navigationLabel = 'Historique des alertes';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = AlertEvent::query()->whereNull('resolved_at')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucune alerte')
            ->emptyStateDescription('Les alertes déclenchées apparaîtront ici.')
            ->defaultSort('triggered_at', 'desc')
            ->columns([
                TextColumn::make('triggered_at')->label('Déclenchée')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('severity')->label('Gravité')->badge()
                    ->color(fn ($state) => ['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'][$state] ?? 'gray'),
                TextColumn::make('title')->label('Alerte')->description(fn (AlertEvent $r) => $r->message)->wrap(),
                TextColumn::make('resolved_at')->label('Résolue')->dateTime('d/m/Y H:i')->placeholder('en cours'),
                TextColumn::make('deliveries')->label('Envois')
                    ->state(fn (AlertEvent $r) => collect($r->deliveries ?? [])->map(fn ($d) => ($d['ok'] ? '✓ ' : '✗ ').$d['channel'])->implode(', '))->placeholder('—'),
            ])
            ->filters([TernaryFilter::make('open')->label('En cours')->queries(
                true: fn ($q) => $q->whereNull('resolved_at'),
                false: fn ($q) => $q->whereNotNull('resolved_at'),
            )])
            ->recordActions([
                Action::make('resolve')->label('Clore')->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (AlertEvent $r) => $r->resolved_at === null)
                    ->action(fn (AlertEvent $r) => $r->update(['resolved_at' => now(), 'acknowledged_at' => now()])),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAlertEvents::route('/')];
    }
}
