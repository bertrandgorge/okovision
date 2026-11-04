<?php

namespace App\Notifications;

use App\Auth\MagicLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Envoyé immédiatement (pas de file d'attente) : le lien expire vite et l'hébergement n'a pas forcément de worker. */
class MagicLinkNotification extends Notification
{
    public function __construct(private string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre lien de connexion à '.config('app.name'))
            ->greeting('Bonjour,')
            ->line('Cliquez sur le bouton ci-dessous pour vous connecter à '.config('app.name').'.')
            ->action('Se connecter', $this->url)
            ->line('Ce lien est valable '.MagicLink::TTL_MINUTES.' minutes et ne fonctionne qu\'une fois. Vous resterez connecté sur cet appareil.')
            ->line('Si vous n\'avez pas demandé ce lien, ignorez cet e-mail.')
            ->salutation(config('app.name'));
    }
}
