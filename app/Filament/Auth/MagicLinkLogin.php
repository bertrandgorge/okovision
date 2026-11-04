<?php

namespace App\Filament\Auth;

use App\Auth\MagicLink;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Timebox;

/**
 * Connexion par lien magique : on saisit son e-mail et on reçoit un lien à usage unique.
 * La réponse est identique que le compte existe ou non (pas d'énumération des comptes).
 */
class MagicLinkLogin extends Login
{
    #[Exposed]
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $email = mb_strtolower(trim($this->form->getState()['email']));

        // Durée constante, que le compte existe ou non
        app(Timebox::class)->call(function () use ($email) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $user || ! $this->isUserAllowedToAccessPanel($user)) {
                return;
            }
            try {
                $user->notify(new MagicLinkNotification(app(MagicLink::class)->issue($user)));
            } catch (\Throwable $e) {
                Log::error('Envoi du lien de connexion impossible : '.$e->getMessage());
            }
        }, 1_000_000);

        Notification::make()
            ->title('Vérifiez votre boîte mail')
            ->body('Si un compte correspond à cette adresse, un lien de connexion vient d\'être envoyé. Il est valable '.MagicLink::TTL_MINUTES.' minutes.')
            ->success()
            ->persistent()
            ->send();

        return null;
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([$this->getEmailFormComponent()]);
    }

    protected function getAuthenticateFormAction(): \Filament\Actions\Action
    {
        return parent::getAuthenticateFormAction()->label('Recevoir un lien de connexion');
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        return 'Saisissez votre adresse e-mail : vous recevrez un lien pour vous connecter, sans mot de passe.';
    }
}
