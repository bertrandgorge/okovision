<?php

namespace App\Http\Controllers;

use App\Auth\MagicLink;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Le lien reçu par e-mail ouvre une page de confirmation (GET), et la connexion se fait au clic (POST).
 * Les antivirus et messageries qui ouvrent les liens pour les analyser ne consomment donc pas le jeton.
 */
class MagicLinkController extends Controller
{
    public function show(string $token, MagicLink $links): View
    {
        return view('auth.magic-link', ['token' => $token, 'user' => $links->peek($token)]);
    }

    public function login(Request $request, string $token, MagicLink $links): RedirectResponse
    {
        $user = $links->consume($token);
        $panel = Filament::getPanel('admin');

        if (! $user || ! $user->canAccessPanel($panel)) {
            return redirect()->route('magic-link.show', ['token' => $token]);
        }

        // « Se souvenir de moi » systématique : cookie longue durée, prolongé à chaque visite (RefreshRememberCookie)
        Auth::guard($panel->getAuthGuard())->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }
}
