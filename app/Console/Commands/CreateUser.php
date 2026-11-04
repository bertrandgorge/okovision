<?php

namespace App\Console\Commands;

use App\Auth\MagicLink;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('okovision:user {email : Adresse e-mail du compte} {--name= : Nom affiché (à la création)}')]
#[Description('Crée le compte s\'il n\'existe pas et affiche un lien de connexion à usage unique (utile si l\'envoi d\'e-mails ne fonctionne pas)')]
class CreateUser extends Command
{
    public function handle(MagicLink $links): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Adresse e-mail invalide.');

            return self::FAILURE;
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            $user = User::create([
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(64), // inutilisé : connexion par lien magique uniquement
            ]);
            $this->info("Compte créé : {$email}");
        }

        $this->line('Lien de connexion (valable '.MagicLink::TTL_MINUTES.' min, usage unique) :');
        $this->line($links->issue($user));

        return self::SUCCESS;
    }
}
