<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Liens de connexion à usage unique (pas de mot de passe).
 *
 * Le jeton aléatoire n'est stocké qu'haché, dans le cache, avec l'id de l'utilisateur. Il est valable
 * {@see self::TTL_MINUTES} minutes et consommé au premier usage.
 */
class MagicLink
{
    public const TTL_MINUTES = 15;

    public function issue(User $user): string
    {
        $token = Str::random(64);
        Cache::put($this->key($token), $user->getKey(), now()->addMinutes(self::TTL_MINUTES));

        return route('magic-link.show', ['token' => $token]);
    }

    /** Utilisateur associé au jeton, sans le consommer (page de confirmation). */
    public function peek(string $token): ?User
    {
        $id = Cache::get($this->key($token));

        return $id ? User::find($id) : null;
    }

    /** Utilisateur associé au jeton ; le jeton n'est plus utilisable ensuite. */
    public function consume(string $token): ?User
    {
        $id = Cache::pull($this->key($token));

        return $id ? User::find($id) : null;
    }

    private function key(string $token): string
    {
        return 'magic-link:'.hash('sha256', $token);
    }
}
