<?php

use App\Support\Settings;

if (! function_exists('settings')) {
    /** Lecture d'un paramètre applicatif (base > config/okovision.php). */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $settings = app(Settings::class);

        return $key === null ? $settings : $settings->get($key, $default);
    }
}
