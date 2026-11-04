<?php

namespace App\Okofen;

use App\Models\BoilerParameterChange;
use App\Okofen\Exceptions\BoilerException;

/**
 * Programmes horaires du Pelletronic Touch (chauffage, eau chaude, circulation), 2 programmes par circuit.
 *
 * Structure sur l'automate (déduite de l'interface web du Touch, vérifiée sur la chaudière) :
 *   CAPPL:LOCAL.hk[0].zeitprogramm[P].tag[J].block          bloc du jour J (0 = dimanche … 6 = samedi), −1 = aucune plage
 *   CAPPL:LOCAL.hk[0].zeitprogramm[P].tag[J].zeitreihe[I,0] début de la plage I (0–2), en secondes depuis minuit
 *   CAPPL:LOCAL.hk[0].zeitprogramm[P].tag[J].zeitreihe[I,1] fin de la plage I ; 0–0 = plage vide
 * Un « bloc » correspond à un cadre de l'écran du Touch : les jours d'un même bloc partagent les mêmes plages
 * (l'écran les recopie dans chaque jour). Ici on édite jour par jour et on regroupe à l'écriture les jours identiques.
 */
class TimeProgram
{
    public const CIRCUITS = [
        'hk' => 'Chauffage',
        'ww' => 'Eau chaude',
        'zirkp' => 'Circulation',
    ];

    /** Ordre d'affichage (lundi d'abord) => libellé ; les clés sont les index de jour de l'automate. */
    public const DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche'];

    public const SHORT_DAYS = [1 => 'Lu', 2 => 'Ma', 3 => 'Me', 4 => 'Je', 5 => 'Ve', 6 => 'Sa', 0 => 'Di'];

    public const SLOTS = 3;

    public function __construct(private BoilerClient $client, private BoilerService $boiler) {}

    public static function base(string $circuit, int $program): string
    {
        abort_unless(isset(self::CIRCUITS[$circuit]) && in_array($program, [0, 1], true), 404);

        return "CAPPL:LOCAL.{$circuit}[0].zeitprogramm[{$program}]";
    }

    /** Libellé d'une variable de programme (journal des modifications), null si ce n'en est pas une. */
    public static function labelFor(string $name): ?string
    {
        if (! preg_match('/^CAPPL:LOCAL\.(hk|ww|zirkp)\[0\]\.zeitprogramm\[([01])\]$/', $name, $m)) {
            return null;
        }

        return 'Programme horaire '.mb_strtolower(self::CIRCUITS[$m[1]]).' '.($m[2] + 1);
    }

    public static function activeVariable(string $circuit): string
    {
        return "CAPPL:LOCAL.{$circuit}[0].aktives_zeitprogramm";
    }

    /** @return list<string> */
    public static function variables(string $circuit, int $program): array
    {
        $base = self::base($circuit, $program);
        $names = [];
        for ($d = 0; $d < 7; $d++) {
            $names[] = "{$base}.tag[{$d}].block";
            for ($i = 0; $i < self::SLOTS; $i++) {
                $names[] = "{$base}.tag[{$d}].zeitreihe[{$i},0]";
                $names[] = "{$base}.tag[{$d}].zeitreihe[{$i},1]";
            }
        }

        return $names;
    }

    /**
     * Lit un programme.
     *
     * @return array{days: array<int, list<array{0: int, 1: int}>>, active: ?int} jour => plages [début, fin] en minutes
     */
    public function read(string $circuit, int $program): array
    {
        $raw = $this->readRaw($circuit, $program);
        $active = $this->client->getOne(self::activeVariable($circuit));

        return ['days' => self::decode($raw, self::base($circuit, $program)), 'active' => $active?->ok ? (int) $active->value() : null];
    }

    /**
     * Écrit un programme (seules les variables modifiées sont envoyées), relit pour vérifier et journalise.
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $days  jour => plages en minutes
     * @return array{changed: int, ok: bool, message: ?string}
     */
    public function write(string $circuit, int $program, array $days, ?int $userId = null): array
    {
        $days = self::normalize($days);
        if ($error = self::validate($days)) {
            return ['changed' => 0, 'ok' => false, 'message' => $error];
        }

        $base = self::base($circuit, $program);
        $current = $this->readRaw($circuit, $program);
        $target = self::encode($days, $base);
        $diff = array_filter($target, fn ($v, $name) => ! isset($current[$name]) || (float) $current[$name] !== (float) $v, ARRAY_FILTER_USE_BOTH);

        if ($diff === []) {
            return ['changed' => 0, 'ok' => true, 'message' => null];
        }

        $old = self::summary(self::decode($current, $base));
        $message = null;
        try {
            foreach (array_chunk($diff, (int) settings('boiler.batch_size', 25), true) as $chunk) {
                $this->client->setRaw($chunk);
            }
            $after = $this->readRaw($circuit, $program);
            $bad = array_filter($diff, fn ($v, $name) => ! isset($after[$name]) || (float) $after[$name] !== (float) $v, ARRAY_FILTER_USE_BOTH);
            $ok = $bad === [];
            $message = $ok ? null : count($bad).' valeur(s) relue(s) différente(s), par exemple '.array_key_first($bad);
        } catch (BoilerException $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        BoilerParameterChange::query()->create([
            'name' => $base,
            'old_value' => mb_substr($old, 0, 255),
            'new_value' => mb_substr(self::summary($days), 0, 255),
            'source' => 'manual',
            'status' => $ok ? 'applied' : 'failed',
            'message' => $message,
            'user_id' => $userId,
        ]);
        $this->boiler->flushCaches();

        return ['changed' => count($diff), 'ok' => $ok, 'message' => $message];
    }

    /** @return array<string, string> */
    private function readRaw(string $circuit, int $program): array
    {
        $values = $this->client->get(self::variables($circuit, $program));
        $missing = array_diff(self::variables($circuit, $program), array_keys(array_filter($values, fn (BoilerValue $v) => $v->ok)));
        if ($missing !== []) {
            throw new BoilerException('Programme illisible sur la chaudière ('.count($missing).' variable(s), par exemple '.reset($missing).')');
        }

        return array_map(fn (BoilerValue $v) => (string) $v->raw, $values);
    }

    /**
     * @param  array<string, string>  $raw
     * @return array<int, list<array{0: int, 1: int}>>
     */
    public static function decode(array $raw, string $base): array
    {
        $slots = function (int $d) use ($raw, $base): array {
            $out = [];
            for ($i = 0; $i < self::SLOTS; $i++) {
                $a = (int) round((float) ($raw["{$base}.tag[{$d}].zeitreihe[{$i},0]"] ?? 0) / 60);
                $b = (int) round((float) ($raw["{$base}.tag[{$d}].zeitreihe[{$i},1]"] ?? 0) / 60);
                if ($a !== 0 || $b !== 0) {
                    $out[] = [$a, $b];
                }
            }

            return $out;
        };

        // Comme l'écran du Touch : les plages d'un bloc sont celles du premier jour (dimanche d'abord) de ce bloc
        $days = [];
        $blockSlots = [];
        for ($d = 0; $d < 7; $d++) {
            $block = (int) round((float) ($raw["{$base}.tag[{$d}].block"] ?? -1));
            if ($block < 0) {
                $days[$d] = [];

                continue;
            }
            $blockSlots[$block] ??= $slots($d);
            $days[$d] = $blockSlots[$block];
        }

        return $days;
    }

    /**
     * Valeurs brutes à écrire : les jours aux plages identiques forment un bloc (numéroté par son premier jour),
     * les jours sans plage ont le bloc −1.
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $days
     * @return array<string, string>
     */
    public static function encode(array $days, string $base): array
    {
        $blocks = [];
        $raw = [];
        for ($d = 0; $d < 7; $d++) {
            $slots = $days[$d] ?? [];
            $key = json_encode($slots);
            $block = $slots === [] ? -1 : ($blocks[$key] ??= $d);
            $raw["{$base}.tag[{$d}].block"] = (string) $block;
            for ($i = 0; $i < self::SLOTS; $i++) {
                $raw["{$base}.tag[{$d}].zeitreihe[{$i},0]"] = (string) (($slots[$i][0] ?? 0) * 60);
                $raw["{$base}.tag[{$d}].zeitreihe[{$i},1]"] = (string) (($slots[$i][1] ?? 0) * 60);
            }
        }

        return $raw;
    }

    /**
     * Trie les plages, retire les plages vides.
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $days
     * @return array<int, list<array{0: int, 1: int}>>
     */
    public static function normalize(array $days): array
    {
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $slots = array_values(array_filter($days[$d] ?? [], fn ($s) => ! ((int) $s[0] === 0 && (int) $s[1] === 0)));
            $slots = array_map(fn ($s) => [(int) $s[0], (int) $s[1]], $slots);
            usort($slots, fn ($a, $b) => $a[0] <=> $b[0]);
            $out[$d] = $slots;
        }

        return $out;
    }

    /** @param  array<int, list<array{0: int, 1: int}>>  $days */
    public static function validate(array $days): ?string
    {
        foreach ($days as $d => $slots) {
            $day = self::DAYS[$d] ?? "Jour {$d}";
            if (count($slots) > self::SLOTS) {
                return "{$day} : au plus ".self::SLOTS.' plages';
            }
            foreach ($slots as $i => [$a, $b]) {
                if ($a < 0 || $b > 23 * 60 + 59) {
                    return "{$day} : horaires entre 00:00 et 23:59";
                }
                if ($a >= $b) {
                    return "{$day} : le début (".self::time($a).') doit précéder la fin ('.self::time($b).')';
                }
                if ($i > 0 && $a < $slots[$i - 1][1]) {
                    return "{$day} : les plages se chevauchent";
                }
            }
        }

        return null;
    }

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function minutes(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * Résumé lisible, jours aux plages identiques regroupés : « Lu-Ma, Je-Ve 06:00-22:00 · Me, Sa-Di 07:00-23:00 ».
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $days
     */
    public static function summary(array $days): string
    {
        $groups = [];
        foreach (array_keys(self::DAYS) as $d) {
            $slots = $days[$d] ?? [];
            $text = $slots === [] ? 'aucune plage' : implode(', ', array_map(fn ($s) => self::time($s[0]).'-'.self::time($s[1]), $slots));
            $groups[$text][] = $d;
        }

        $parts = [];
        foreach ($groups as $text => $list) {
            $parts[] = self::dayList($list).' '.$text;
        }

        return implode(' · ', $parts);
    }

    /** @param  list<int>  $days  dans l'ordre d'affichage */
    private static function dayList(array $days): string
    {
        if (count($days) === 7) {
            return 'Tous les jours';
        }
        $order = array_keys(self::DAYS);
        $pos = array_map(fn ($d) => array_search($d, $order, true), $days);
        $runs = [];
        foreach ($pos as $p) {
            if ($runs !== [] && end($runs)[1] === $p - 1) {
                $runs[array_key_last($runs)][1] = $p;
            } else {
                $runs[] = [$p, $p];
            }
        }

        return implode(', ', array_map(fn ($r) => self::SHORT_DAYS[$order[$r[0]]].($r[1] > $r[0] ? '-'.self::SHORT_DAYS[$order[$r[1]]] : ''), $runs));
    }
}
