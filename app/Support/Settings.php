<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres applicatifs : valeur en base (table settings) sinon valeur par défaut de config/okovision.php.
 * Les clés sont « pointées » : equipment.silo_capacity_kg, pellets.grams_per_auger_minute…
 */
class Settings
{
    private const CACHE_KEY = 'okovision.settings';

    /** Clés dont la valeur est chiffrée en base. */
    private const ENCRYPTED = ['boiler.password', 'notifications.telegram_bot_token'];

    private ?array $overrides = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return config('okovision.'.$key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $stored = in_array($key, self::ENCRYPTED, true) && $value !== null ? encrypt($value) : $value;
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        $this->flush();
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach (Arr::dot($values) as $key => $value) {
            $this->set($key, $value);
        }
    }

    /** Toutes les valeurs d'une section (défauts + surcharges). */
    public function section(string $section): array
    {
        $values = Arr::dot((array) config('okovision.'.$section, []));

        foreach ($this->overrides() as $key => $value) {
            if (str_starts_with($key, $section.'.')) {
                $values[substr($key, strlen($section) + 1)] = $value;
            }
        }

        return Arr::undot($values);
    }

    public function flush(): void
    {
        $this->overrides = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return $this->overrides = [];
            }
        } catch (\Throwable) {
            return $this->overrides = [];
        }

        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        foreach (self::ENCRYPTED as $key) {
            if (isset($rows[$key]) && is_string($rows[$key])) {
                try {
                    $rows[$key] = decrypt($rows[$key]);
                } catch (\Throwable) {
                    // valeur non chiffrée (saisie manuelle en base) : on la garde telle quelle
                }
            }
        }

        return $this->overrides = $rows;
    }
}
