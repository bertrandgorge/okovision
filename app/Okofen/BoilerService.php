<?php

namespace App\Okofen;

use App\Analysis\HeatingCurve;
use App\Models\BoilerCounter;
use App\Models\BoilerParameterChange;
use App\Okofen\Exceptions\BoilerException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Opérations de haut niveau sur la chaudière : lecture des réglages, application de modifications
 * (avec contrôle des bornes, vérification et journalisation), instantané « live ».
 */
class BoilerService
{
    private const LIVE_CACHE = 'okofen.live';

    private const PARAMS_CACHE = 'okofen.params';

    public const POWER = 'CAPPL:FA[0].pe_kesselleistung';

    public function __construct(
        private readonly BoilerClient $client,
        private readonly Catalog $catalog,
    ) {}

    public function client(): BoilerClient
    {
        return $this->client;
    }

    /**
     * Valeurs instantanées pour le tableau de bord (cache 60 s).
     *
     * @return array{at: string, values: array<string, mixed>, labels: array<string, string|null>, error: string|null}
     */
    public function live(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::LIVE_CACHE);
            foreach (array_keys(TimeProgram::CIRCUITS) as $circuit) {
                Cache::forget(TimeProgram::windowsCacheKey($circuit));
            }
        }

        return Cache::remember(self::LIVE_CACHE, 60, function () {
            try {
                $live = $this->catalog->live();
                $values = $this->client->get($live->pluck('name')->all());
                $out = [];
                $labels = [];
                foreach ($live as $key => $item) {
                    $v = $values[$item['name']] ?? null;
                    $out[$key] = $v?->value();
                    $labels[$key] = $v?->label();
                }

                return ['at' => now()->toIso8601String(), 'values' => $out, 'labels' => $labels, 'error' => null];
            } catch (BoilerException $e) {
                return ['at' => now()->toIso8601String(), 'values' => [], 'labels' => [], 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Lit des réglages (valeurs humaines).
     *
     * @param  list<string>  $names
     * @return array<string, BoilerValue>
     */
    public function read(array $names, bool $fresh = false): array
    {
        $key = self::PARAMS_CACHE.'.'.Cache::get(self::PARAMS_CACHE.'.version', 0).'.'.md5(implode(',', $names));
        if ($fresh) {
            Cache::forget($key);
        }

        $rows = Cache::remember($key, 120, fn () => array_map(fn (BoilerValue $v) => $v->toArray(), $this->client->get($names)));

        return array_map(fn (array $a) => BoilerValue::fromArray($a), $rows);
    }

    /** Lit tous les réglages d'un niveau (basic / expert) du catalogue. */
    public function readLevel(?string $level = null, bool $fresh = false): array
    {
        $names = $this->catalog->parameters()
            ->filter(fn ($p) => $level === null || $p['level'] === $level)
            ->keys()->all();

        return $this->read($names, $fresh);
    }

    /** Puissance paramétrée sur la chaudière (kW), null si inconnue. */
    public function powerKw(): ?float
    {
        return $this->valueOrLastKnown(self::POWER)['value'];
    }

    /**
     * Valeur d'un réglage lue sur la chaudière ; si elle est injoignable, dernière valeur relevée.
     *
     * @return array{value: mixed, at: CarbonInterface|null, live: bool}
     */
    public function valueOrLastKnown(string $name): array
    {
        try {
            $v = $this->read([$name])[$name] ?? null;
            if ($v?->ok && $v->value() !== null) {
                return ['value' => $v->value(), 'at' => now(), 'live' => true];
            }
        } catch (\Throwable) {
            // chaudière injoignable : repli sur le dernier relevé
        }
        $known = app(ParameterSnapshot::class)->lastKnown($name);

        return ['value' => $known['value'] ?? null, 'at' => $known['at'] ?? null, 'live' => false];
    }

    public function currentCurve(bool $fresh = false): HeatingCurve
    {
        $values = $this->read(array_values(HeatingCurve::PARAMS), $fresh);
        $array = [];
        foreach (HeatingCurve::PARAMS as $key => $name) {
            $array[$key] = isset($values[$name]) ? $values[$name]->value() : null;
        }

        return HeatingCurve::fromArray($array);
    }

    /**
     * Applique des réglages. Chaque modification est validée (variable connue, modifiable, dans les bornes),
     * écrite, relue puis journalisée.
     *
     * @param  array<string, float|int|string>  $changes  nom de variable => valeur humaine
     * @return list<array{name: string, old: mixed, new: mixed, status: string, message: string|null}>
     */
    public function apply(array $changes, string $source = 'manual', ?int $userId = null, bool $dryRun = false): array
    {
        if ($changes === []) {
            return [];
        }

        $current = $this->client->get(array_keys($changes));
        $raw = [];
        $results = [];

        foreach ($changes as $name => $value) {
            $param = $this->catalog->parameter($name);
            $cur = $current[$name] ?? null;
            $error = match (true) {
                $param === null => 'Variable inconnue du catalogue',
                ! ($param['writable'] ?? false) => 'Variable en lecture seule',
                $cur === null || ! $cur->ok => 'Variable illisible sur la chaudière',
                default => $this->validate($value, $cur, $param),
            };

            if ($error === null && (string) $cur->value() === (string) $value) {
                continue; // inchangé
            }

            if ($error !== null) {
                $results[] = $this->log($name, $cur?->value(), $value, $source, 'failed', $error, $userId);

                continue;
            }

            $raw[$name] = BoilerValue::toRaw($value, $cur->divisor);
            $results[] = ['name' => $name, 'old' => $cur->value(), 'new' => $value, 'status' => 'pending', 'message' => null];
        }

        if ($dryRun) {
            return array_map(fn ($r) => $r['status'] === 'pending'
                ? $this->log($r['name'], $r['old'], $r['new'], $source, 'dry_run', 'Simulation : non appliqué', $userId)
                : $r, $results);
        }

        if ($raw !== []) {
            try {
                $this->client->setRaw($raw);
                $verify = $this->client->get(array_keys($raw));
            } catch (BoilerException $e) {
                $verify = [];
                $failure = $e->getMessage();
            }

            foreach ($results as $i => $r) {
                if ($r['status'] !== 'pending') {
                    continue;
                }
                $after = isset($verify[$r['name']]) ? $verify[$r['name']]->value() : null;
                $ok = $after !== null && abs((float) $after - (float) $r['new']) < 1e-6;
                $results[$i] = $this->log($r['name'], $r['old'], $r['new'], $source, $ok ? 'applied' : 'failed',
                    $ok ? null : ($failure ?? "Valeur relue : {$after}"), $userId);
            }
        }

        $this->flushCaches();

        if (! $dryRun && collect($results)->contains('status', 'applied')) {
            try {
                app(ParameterSnapshot::class)->take();
            } catch (\Throwable) {
                // le relevé quotidien rattrapera
            }
        }

        return $results;
    }

    /** Relevé quotidien des compteurs internes (démarrages, heures). */
    public function recordCounters(): ?BoilerCounter
    {
        $names = [
            'burner_starts' => 'CAPPL:FA[0].L_brennerstarts',
            'ignitions' => 'CAPPL:FA[0].L_anzahl_zuendung',
            'burner_hours' => 'CAPPL:FA[0].L_brennerlaufzeit_anzeige',
            'mean_run_minutes' => 'CAPPL:FA[0].L_mittlere_laufzeit',
        ];
        $values = $this->client->get(array_values($names));
        $data = [];
        foreach ($names as $key => $name) {
            $data[$key] = isset($values[$name]) ? $values[$name]->value() : null;
        }

        return BoilerCounter::query()->updateOrCreate(['day' => now()->format('Y-m-d')], $data);
    }

    public function flushCaches(): void
    {
        Cache::forget(self::LIVE_CACHE);
        // les lectures de paramètres sont indexées par hash : on invalide en changeant de version
        Cache::forever(self::PARAMS_CACHE.'.version', (int) Cache::get(self::PARAMS_CACHE.'.version', 0) + 1);
    }

    private function validate(mixed $value, BoilerValue $cur, array $param): ?string
    {
        if (! is_numeric($value)) {
            return ($param['type'] ?? '') === 'text' ? null : 'Valeur non numérique';
        }
        $min = $cur->min ?? ($param['min'] ?? null);
        $max = $cur->max ?? ($param['max'] ?? null);
        if ($min !== null && $value < $min - 1e-9) {
            return "Valeur inférieure au minimum ({$min})";
        }
        if ($max !== null && $value > $max + 1e-9) {
            return "Valeur supérieure au maximum ({$max})";
        }
        if (($param['type'] ?? '') === 'enum' && ! empty($param['options']) && ! array_key_exists((int) $value, $param['options'])) {
            return 'Choix invalide';
        }

        return null;
    }

    private function log(string $name, mixed $old, mixed $new, string $source, string $status, ?string $message, ?int $userId): array
    {
        BoilerParameterChange::query()->create([
            'name' => $name,
            'old_value' => $old === null ? null : (string) $old,
            'new_value' => (string) $new,
            'source' => $source,
            'status' => $status,
            'message' => $message,
            'user_id' => $userId,
        ]);

        return ['name' => $name, 'old' => $old, 'new' => $new, 'status' => $status, 'message' => $message];
    }
}
