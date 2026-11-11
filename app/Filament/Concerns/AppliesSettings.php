<?php

namespace App\Filament\Concerns;

use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use Filament\Notifications\Notification;

/**
 * Actions par réglage pour les pages qui éditent une liste de réglages ($edit / $current, clés locales) :
 * appliquer un seul réglage modifié, ou remettre et appliquer aussitôt sa valeur d'usine.
 *
 * La page fournit settingParams() (clé locale => variable chaudière), load(bool $fresh) et $error.
 */
trait AppliesSettings
{
    /** @return array<string, string> clé locale => variable chaudière */
    abstract protected function settingParams(): array;

    protected function settingSource(): string
    {
        return 'manual';
    }

    /** Le réglage diffère-t-il de la valeur lue sur la chaudière ? */
    public function isSettingChanged(string $key): bool
    {
        $new = $this->edit[$key] ?? null;
        $cur = $this->current[$key] ?? null;

        return $new !== null && $new !== '' && is_numeric($new) && ($cur === null || abs((float) $new - (float) $cur) > 1e-6);
    }

    /** Valeur d'usine connue (catalogue, d'après le manuel ÖkoFEN), null sinon. */
    public function factoryValue(string $key): float|int|null
    {
        $name = $this->settingParams()[$key] ?? null;
        $factory = $name ? (app(Catalog::class)->parameter($name)['advice']['factory'] ?? null) : null;

        return is_numeric($factory) ? $factory + 0 : null;
    }

    public function applyOne(string $key): void
    {
        $name = $this->settingParams()[$key] ?? null;
        if ($name === null || $this->error !== null || ! $this->isSettingChanged($key)) {
            return;
        }
        $value = (float) $this->edit[$key];
        $value = $value == (int) $value ? (int) $value : $value;

        $results = app(BoilerService::class)->apply([$name => $value], $this->settingSource(), auth()->id());
        $failed = array_filter($results, fn ($r) => $r['status'] === 'failed');
        $label = app(Catalog::class)->label(app(Catalog::class)->parameter($name) ?? ['name' => $name]);
        $failed
            ? Notification::make()->title($label.' : non appliqué')->body(implode("\n", array_column($failed, 'message')))->danger()->persistent()->send()
            : Notification::make()->title($label.' appliqué')->success()->send();

        // relit la chaudière sans perdre les autres modifications en cours
        $pending = $this->edit;
        $this->load(true);
        foreach ($pending as $k => $v) {
            if ($k !== $key) {
                $this->edit[$k] = $v;
            }
        }
    }

    /** Remet la valeur d'usine et l'applique aussitôt sur la chaudière (le bouton demande confirmation). */
    public function factoryOne(string $key): void
    {
        $factory = $this->factoryValue($key);
        if ($factory === null) {
            return;
        }
        $this->edit[$key] = $factory;
        $this->applyOne($key);
    }

    public function resetOne(string $key): void
    {
        $this->edit[$key] = $this->current[$key] ?? null;
    }
}
