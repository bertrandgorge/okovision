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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;

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
                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                    // Le début du jeton (avant « : ») est l'identifiant du bot, pas celui de la conversation
                    if ((string) $value === strstr((string) $get('config.bot_token'), ':', true)) {
                        $fail('Ce numéro est l\'identifiant du bot lui-même. Il faut l\'identifiant de votre conversation avec le bot.');
                    }
                })
                ->suffixAction(
                    Action::make('detectChatId')->label('Détecter')->icon(Heroicon::OutlinedMagnifyingGlass)
                        ->tooltip('Récupérer le Chat ID de la dernière conversation avec le bot')
                        ->action(fn (Get $get, Set $set) => self::detectTelegramChatId((string) $get('config.bot_token'), $set))
                )
                ->helperText('Dans Telegram, ouvrez votre bot et envoyez-lui /start (ou ajoutez-le à un groupe), puis cliquez sur la loupe.'),
            TextInput::make('config.url')->label('URL')->url()->visible($is(ChannelType::Webhook))->required($is(ChannelType::Webhook)),
            Select::make('config.method')->label('Méthode')->options(['POST' => 'POST', 'PUT' => 'PUT'])->default('POST')->visible($is(ChannelType::Webhook)),
            TextInput::make('config.secret')->label('Secret (signature HMAC-SHA256, en-tête X-Okovision-Signature)')->password()->revealable()->visible($is(ChannelType::Webhook)),
            Toggle::make('enabled')->label('Actif')->default(true),
        ]);
    }

    /** Remplit le Chat ID à partir du dernier message reçu par le bot (API getUpdates). */
    private static function detectTelegramChatId(string $token, Set $set): void
    {
        $fail = fn (string $msg) => Notification::make()->title('Chat ID introuvable')->body($msg)->danger()->send();

        if (trim($token) === '') {
            $fail('Saisissez d\'abord le jeton du bot.');

            return;
        }

        try {
            $api = 'https://api.telegram.org/bot'.trim($token);
            $me = Http::timeout(10)->get("{$api}/getMe");
            if (! $me->json('ok')) {
                $fail('Jeton refusé par Telegram : '.($me->json('description') ?? 'HTTP '.$me->status()));

                return;
            }
            $bot = '@'.$me->json('result.username');
            $updates = Http::timeout(10)->get("{$api}/getUpdates")->json('result') ?? [];
        } catch (\Throwable) {
            $fail('Impossible de joindre Telegram, réessayez dans un instant.');

            return;
        }

        // Le plus récent d'abord : message privé, message de groupe, ajout à un groupe ou publication de canal
        foreach (array_reverse($updates) as $u) {
            $chat = ($u['message'] ?? $u['my_chat_member'] ?? $u['channel_post'] ?? [])['chat'] ?? null;
            if ($chat) {
                $set('config.chat_id', (string) $chat['id']);
                $name = $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''));
                Notification::make()->title('Chat ID trouvé')->body("Conversation « {$name} ». Enregistrez puis testez le canal.")->success()->send();

                return;
            }
        }

        $fail("Aucun message reçu par {$bot}. Ouvrez https://t.me/".ltrim($bot, '@').' , appuyez sur « Démarrer » (ou envoyez /start), puis réessayez.');
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
