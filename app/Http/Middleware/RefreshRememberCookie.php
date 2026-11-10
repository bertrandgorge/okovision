<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connexion « infinie » : les navigateurs plafonnent la durée d'un cookie (400 jours pour Chrome), on renvoie donc
 * le cookie « se souvenir de moi » une fois par jour, avec une nouvelle échéance. Tant qu'on revient au moins une
 * fois par période, on reste connecté. La déconnexion (qui renouvelle le jeton) l'invalide comme d'habitude.
 */
class RefreshRememberCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $guard = Filament::auth();
        if (! $guard instanceof SessionGuard || ! $request->hasSession() || ! $guard->check()) {
            return $response;
        }

        $user = $guard->user();
        $session = $request->session();
        if (blank($user->getRememberToken()) || $session->get('remember_refreshed_on') === today()->toDateString()) {
            return $response;
        }

        Cookie::queue(Cookie::make(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            (int) config('auth.guards.'.Filament::getAuthGuard().'.remember', 576000),
        ));
        $session->put('remember_refreshed_on', today()->toDateString());

        return $response;
    }
}
