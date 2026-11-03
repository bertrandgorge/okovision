<?php

namespace App\Okofen;

use App\Models\BoilerProfile;
use Carbon\CarbonInterface;

/**
 * Relevé automatique de tous les réglages de la chaudière.
 *
 * Une ligne n'est enregistrée que si au moins un réglage diffère du dernier relevé : la table forme ainsi
 * un historique compact des réglages, et sert de valeur de repli quand la chaudière est injoignable.
 */
class ParameterSnapshot
{
    public function __construct(
        private readonly BoilerService $boiler,
        private readonly Catalog $catalog,
    ) {}

    /**
     * Lit les réglages et enregistre un relevé s'ils ont changé.
     *
     * @return BoilerProfile|null le nouveau relevé, ou null si rien n'a changé
     */
    public function take(): ?BoilerProfile
    {
        $read = $this->boiler->read($this->trackedNames(), true);
        $current = [];
        foreach ($read as $name => $value) {
            if ($value->ok && $value->value() !== null) {
                $current[$name] = $value->value();
            }
        }
        if ($current === []) {
            return null;
        }

        $last = BoilerProfile::query()->automatic()->latest('id')->first();
        $previous = array_intersect_key($last?->values ?? [], array_flip($this->trackedNames()));

        // Une variable illisible ponctuellement ne doit pas être vue comme un changement : on garde l'ancienne valeur
        $values = array_merge($previous, $current);
        $changes = $this->diff($previous, $values);

        if ($last && $changes === []) {
            return null;
        }

        return BoilerProfile::query()->create([
            'source' => 'auto',
            'name' => 'Relevé automatique du '.now()->format('d/m/Y H:i'),
            'description' => $last ? $this->describe($changes) : 'Premier relevé ('.count($values).' réglages)',
            'values' => $values,
            'changes' => $last ? $changes : null,
        ]);
    }

    /**
     * Dernière valeur connue d'un réglage (relevés automatiques, puis profils manuels).
     *
     * @return array{value: mixed, at: CarbonInterface}|null
     */
    public function lastKnown(string $name): ?array
    {
        // Peu de lignes (un relevé par changement) : recherche en PHP, les noms de variables
        // contiennent des crochets que les chemins JSON SQL interpréteraient comme des index
        foreach (BoilerProfile::query()->whereIn('source', ['auto', 'manual'])->latest('id')->lazy(50) as $profile) {
            if (isset($profile->values[$name])) {
                return ['value' => $profile->values[$name], 'at' => $profile->created_at];
            }
        }

        return null;
    }

    /**
     * Réglages suivis : tout le catalogue sauf les valeurs instantanées et commandes (snapshot: false),
     * comme l'horloge de l'automate qui change à chaque lecture.
     *
     * @return list<string>
     */
    public function trackedNames(): array
    {
        return $this->catalog->parameters()->filter(fn ($p) => $p['snapshot'] ?? true)->keys()->all();
    }

    public function isTracked(string $name): bool
    {
        return (bool) ($this->catalog->parameter($name)['snapshot'] ?? true);
    }

    /** @return array<string, array{old: mixed, new: mixed}> */
    public function diff(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $name => $value) {
            $before = $old[$name] ?? null;
            $same = is_numeric($before) && is_numeric($value)
                ? abs((float) $before - (float) $value) < 1e-9
                : (string) $before === (string) $value;
            if (! $same) {
                $changes[$name] = ['old' => $before, 'new' => $value];
            }
        }

        return $changes;
    }

    public function describe(array $changes): string
    {
        $labels = array_map(function ($name) use ($changes) {
            $p = $this->catalog->parameter($name);
            $c = $changes[$name];

            return ($p ? $this->catalog->label($p) : $name).' : '.($c['old'] ?? '—').' → '.$c['new'];
        }, array_keys($changes));

        return count($changes).' réglage(s) modifié(s) : '.implode(' ; ', array_slice($labels, 0, 10)).(count($labels) > 10 ? ' ; …' : '');
    }
}
