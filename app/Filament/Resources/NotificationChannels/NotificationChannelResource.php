<?php

namespace App\Filament\Resources\NotificationChannels;

use App\Alerts\Notifier;
use App\Enums\ChannelType;
use App\Filament\Resources\NotificationChannels\Pages\ManageNotificationChannels;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationChannelResource extends Resource
{
    protected static ?string $model = NotificationChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|\UnitEnum|null $navigationGroup = 'Alertes';

    /** Libellés en français : pas de majuscule à chaque mot */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'canal de notification';

    protected static ?string $pluralModelLabel = 'Canaux de notification';

    protected static ?string $navigationLabel = 'Canaux de notification';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $is = fn (ChannelType $t) => fn (Get $get) => in_array($get('type'), [$t, $t->value], true);

        return $schema->components([
            TextInput::make('name')->label('Nom')->required(),
            Select::make('type')->label('Type')->options(ChannelType::class)->required()->live(),
            TextInput::make('config.to')->label('Destinataires (séparés par des virgules)')->visible($is(ChannelType::Email))->required($is(ChannelType::Email)),
            TextInput::make('config.webhook_url')->label('URL du webhook entrant Slack')->url()->visible($is(ChannelType::Slack))->required($is(ChannelType::Slack))
                ->helperText('Slack › Apps › Incoming Webhooks › Add New Webhook'),
            TextInput::make('config.bot_token')->label('Jeton du bot')->password()->revealable()->visible($is(ChannelType::Telegram))->required($is(ChannelType::Telegram))
                ->helperText('Créé avec @BotFather'),
            TextInput::make('config.chat_id')->label('Chat ID')->visible($is(ChannelType::Telegram))->required($is(ChannelType::Telegram))
                ->helperText('Envoyez un message au bot puis consultez https://api.telegram.org/bot<jeton>/getUpdates'),
            TextInput::make('config.url')->label('URL')->url()->visible($is(ChannelType::Webhook))->required($is(ChannelType::Webhook)),
            Select::make('config.method')->label('Méthode')->options(['POST' => 'POST', 'PUT' => 'PUT'])->default('POST')->visible($is(ChannelType::Webhook)),
            TextInput::make('config.secret')->label('Secret (signature HMAC-SHA256, en-tête X-Okovision-Signature)')->password()->revealable()->visible($is(ChannelType::Webhook)),
            Toggle::make('enabled')->label('Actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun canal de notification')
            ->emptyStateDescription('Ajoutez un canal (e-mail, Slack, Telegram ou webhook), puis associez-le aux règles d\'alerte.')
            ->columns([
                TextColumn::make('name')->label('Nom'),
                TextColumn::make('type')->label('Type')->badge(),
                IconColumn::make('enabled')->label('Actif')->boolean(),
                TextColumn::make('last_used_at')->label('Dernier envoi')->since()->placeholder('jamais'),
                TextColumn::make('last_error')->label('Dernière erreur')->limit(50)->color('danger')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('test')->label('Tester')->icon(Heroicon::OutlinedPaperAirplane)
                    ->action(function (NotificationChannel $record) {
                        $r = app(Notifier::class)->deliver($record, 'Test Okovision', 'Ceci est un message de test : le canal fonctionne.', 'info');
                        $r['ok']
                            ? Notification::make()->title('Message envoyé')->success()->send()
                            : Notification::make()->title('Échec de l\'envoi')->body($r['error'])->danger()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNotificationChannels::route('/')];
    }
}
