<?php

namespace App\Okofen;

use App\Models\BoilerParameterChange;
use App\Models\BoilerProfile;

/**
 * Valeur d'un réglage à un instant passé, reconstituée à partir de deux sources :
 * - le journal des modifications faites par l'application (nouvelle valeur à l'heure de l'écriture) ;
 * - les relevés automatiques des réglages (ParameterSnapshot), qui captent aussi les changements faits sur la
 *   chaudière elle-même, à l'heure du relevé suivant.
 * On retient l'observation la plus récente avant l'instant demandé ; avant toute observation, l'ancienne valeur de
 * la première modification journalisée, sinon la première valeur relevée, sinon la valeur actuelle.
 */
class SettingsHistory
{
    /** @var array<string, array{0: list<array{0: string, 1: mixed}>, 1: mixed}> nom => [observations triées, valeur initiale] */
    private array $timeline = [];

    /** @var list<array{0: string, 1: array<string, mixed>}>|null relevés automatiques [horodatage, valeurs] */
    private ?array $snapshots = null;

    /**
     * @param  array<string, mixed>  $current  valeurs actuelles (nom de variable => valeur) en repli
     */
    public function __construct(private array $current = []) {}

    public function valueAt(string $name, string $ts): mixed
    {
        [$observations, $initial] = $this->timeline[$name] ??= $this->build($name);

        $value = null;
        $found = false;
        foreach ($observations as [$at, $v]) {
            if ($at > $ts) {
                break;
            }
            $value = $v;
            $found = true;
        }

        return $found ? $value : ($initial ?? ($this->current[$name] ?? null));
    }

    /** Horodatage de la dernière observation du réglage avant cet instant (null : aucune). */
    public function observedAt(string $name, string $ts): ?string
    {
        [$observations] = $this->timeline[$name] ??= $this->build($name);
        $at = null;
        foreach ($observations as [$t]) {
            if ($t > $ts) {
                break;
            }
            $at = $t;
        }

        return $at;
    }

    /** @return array{0: list<array{0: string, 1: mixed}>, 1: mixed} */
    private function build(string $name): array
    {
        $changes = BoilerParameterChange::query()
            ->where('name', $name)->where('status', 'applied')->orderBy('created_at')->orderBy('id')
            ->get(['created_at', 'old_value', 'new_value']);

        $obs = $changes->map(fn ($c) => [$c->created_at->format('Y-m-d H:i:s'), $c->new_value, 0])->all();
        foreach ($this->snapshots() as [$at, $values]) {
            if (array_key_exists($name, $values)) {
                $obs[] = [$at, $values[$name], 1];
            }
        }
        // ordre chronologique ; à égalité, la modification avant le relevé qui la suit
        usort($obs, fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        $first = $obs[0] ?? null;
        $initial = match (true) {
            $first === null => null,
            $first[2] === 0 => $changes->first()->old_value,
            default => $first[1],
        };

        return [array_map(fn ($o) => [$o[0], $o[1]], $obs), $initial];
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function snapshots(): array
    {
        return $this->snapshots ??= BoilerProfile::query()->automatic()->orderBy('created_at')->get(['created_at', 'values'])
            ->map(fn ($p) => [$p->created_at->format('Y-m-d H:i:s'), (array) $p->values])->all();
    }
}
