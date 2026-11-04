<?php

namespace App\Diagnostics;

/**
 * Jeton secret du rapport pour LLM (/diagnostic.md?token=…), qui permet d'ouvrir le rapport sans session.
 * Il est créé à la volée la première fois qu'on a besoin du lien partageable.
 */
class ReportToken
{
    public static function get(): string
    {
        $token = (string) settings('diagnostic.token', '');

        return $token !== '' ? $token : self::regenerate();
    }

    /** Nouveau jeton : l'ancienne URL cesse de fonctionner. */
    public static function regenerate(): string
    {
        $token = bin2hex(random_bytes(24));
        settings()->set('diagnostic.token', $token);

        return $token;
    }

    public static function url(): string
    {
        return route('diagnostic.markdown', ['token' => self::get()]);
    }
}
