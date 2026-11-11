<?php

namespace App\Analysis;

use App\Okofen\BoilerService;
use App\Okofen\BoilerValue;
use App\Okofen\TimeProgram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pourquoi le circuit de chauffage tourne (ou pas) en ce moment, d'après les règles du régulateur
 * (docs/REGULATION-OKOFEN.md §2) :
 * - mode de l'installation et du circuit ;
 * - plage confort / réduit du programme actif (ou soirée, vacances) ;
 * - limite de chauffe : arrêt si T ext moyenne > limite, relance sous limite − 2 °C ;
 * - hystérésis d'ambiance : arrêt si T ambiante > consigne + hyst., relance sous consigne + hyst. − 1 °C.
 * Le circuit est considéré à l'arrêt quand la consigne d'ambiance calculée par la chaudière vaut 8 °C (hors-gel).
 */
class HeatingStatus
{
    public const P = [
        'system_mode' => 'CAPPL:LOCAL.anlage_betriebsart',
        'mode' => 'CAPPL:LOCAL.hk[0].betriebsart[1]',
        'comfort' => 'CAPPL:LOCAL.hk[0].raumtemp_heizen',
        'reduced' => 'CAPPL:LOCAL.hk[0].raumtemp_absenken',
        'hyst' => 'CAPPL:LOCAL.hk[0].raumtemp_plus',
        'limit_comfort' => 'CAPPL:LOCAL.hk[0].heizgrenze_heizen',
        'limit_reduced' => 'CAPPL:LOCAL.hk[0].heizgrenze_absenken',
        'average' => 'CAPPL:LOCAL.aussentemperatur_mittelungszeit',
        'party' => 'CAPPL:LOCAL.hk[0].partyprg_aktiviert',
        'party_end' => 'CAPPL:LOCAL.hk[0].partyprg_endzeit',
        'vacation' => 'CAPPL:LOCAL.hk[0].urlaubsprg_aktiviert',
        'vacation_temp' => 'CAPPL:LOCAL.hk[0].raumtemp_urlaub',
    ];

    /** Consigne d'ambiance relevée quand le circuit est arrêté */
    public const OFF_SET = 8.0;

    public function __construct(private readonly BoilerService $boiler, private readonly TimeProgram $programs) {}

    /**
     * @return array{running: bool, phase: string, setpoint: ?float, room: ?float, outside_avg: ?float,
     *               headline: string, reasons: list<string>, restart: ?string}|null null si rien n'est connu
     */
    public function explain(?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();
        $live = $this->boiler->live();
        $v = $live['values'] ?? [];
        $last = DB::table('measurements')->orderByDesc('ts')->first();

        $room = $v['hc1_room_temp'] ?? $last?->hc1_room_temp;
        $roomSet = $v['hc1_room_set'] ?? $last?->hc1_room_set;
        if ($roomSet === null) {
            return null;
        }

        try {
            $read = $this->boiler->read(array_values(self::P));
            $p = array_map(fn ($n) => isset($read[$n]) ? $read[$n]->value() : null, self::P);
            $p['party_end_raw'] = isset($read[self::P['party_end']]) && $read[self::P['party_end']]->ok ? $read[self::P['party_end']]->raw : null;
        } catch (\Throwable) {
            $p = array_fill_keys(array_keys(self::P), null) + ['party_end_raw' => null];
        }
        try {
            $windows = $this->programs->windows('hk');
        } catch (\Throwable) {
            $windows = null;
        }

        $outsideAvg = $this->outsideAverage((int) ($p['average'] ?? 60), $v['outside_temp'] ?? null);
        [$phase, $setpoint, $limit] = $this->phase($p, $windows, $now);
        $running = (float) $roomSet > self::OFF_SET;
        $hyst = (float) ($p['hyst'] ?? 0);
        $fmt = fn (?float $x) => $x === null ? '—' : number_format($x, 1, ',', ' ').' °C';
        $phaseLabel = ['comfort' => 'confort', 'reduced' => 'réduit', 'party' => 'soirée (confort forcé)', 'vacation' => 'vacances', 'off' => 'arrêt'][$phase] ?? $phase;

        $result = ['running' => $running, 'phase' => $phase, 'setpoint' => $setpoint, 'room' => $room === null ? null : (float) $room,
            'outside_avg' => $outsideAvg, 'reasons' => [], 'restart' => null];

        if ($running) {
            $stop = $setpoint !== null ? $setpoint + $hyst : (float) $roomSet + $hyst;
            $result['headline'] = "Chauffage en marche ({$phaseLabel}, consigne ".$fmt((float) $roomSet).')';
            $result['restart'] = 'Il s\'arrêtera si la pièce dépasse '.$fmt($stop).' (consigne + hystérésis d\'ambiance)'
                .($limit !== null ? ' ou si la T ext moyenne dépasse '.$fmt($limit).' (limite de chauffe)' : '').'.';

            return $result;
        }

        $result['headline'] = "Chauffage à l'arrêt (plage {$phaseLabel})";
        $reasons = [];
        if ($p['system_mode'] !== null && (int) $p['system_mode'] !== 1) {
            $reasons[] = 'Le mode de l\'installation est sur « '.((int) $p['system_mode'] === 0 ? 'Arrêt' : 'ECS seule').' » : le chauffage est inactif.';
        }
        if ($phase === 'off') {
            $reasons[] = 'Le mode du circuit de chauffage est sur « Arrêt ».';
        }
        if ($limit !== null && $outsideAvg !== null) {
            if ($outsideAvg > $limit) {
                $reasons[] = 'T extérieure moyenne '.$fmt($outsideAvg).' au-dessus de la limite de chauffe '.$phaseLabel.' ('.$fmt($limit).').';
                $result['restart'] = 'Relance quand la T ext moyenne passera sous '.$fmt($limit - 2).' (limite − 2 °C).';
            } elseif ($outsideAvg > $limit - 2) {
                $reasons[] = 'T extérieure moyenne '.$fmt($outsideAvg).' entre la limite de chauffe ('.$fmt($limit).') et son seuil de relance ('.$fmt($limit - 2).') : si la limite a été dépassée récemment, le circuit attend ce seuil.';
            }
        }
        if ($setpoint !== null && $room !== null) {
            $threshold = $setpoint + $hyst - 1;
            if ((float) $room >= $threshold) {
                $reasons[] = 'Pièce à '.$fmt((float) $room).', au-dessus du seuil de relance '.$fmt($threshold)
                    .' (consigne '.$fmt($setpoint).' + hystérésis '.number_format($hyst, 1, ',', ' ').' − 1 °C).';
                $result['restart'] ??= 'Relance quand la pièce descendra sous '.$fmt($threshold).'.';
            }
        }
        if ($reasons === []) {
            $reasons[] = 'Aucune des règles connues ne l\'explique (mode, plage, limite de chauffe, hystérésis d\'ambiance) : vérifier le circuit sur la chaudière.';
        }
        $result['reasons'] = $reasons;

        return $result;
    }

    /**
     * Phase en cours et ses consigne / limite de chauffe.
     *
     * @return array{0: string, 1: ?float, 2: ?float}
     */
    private function phase(array $p, ?array $windows, CarbonImmutable $now): array
    {
        $comfort = $p['comfort'] === null ? null : (float) $p['comfort'];
        $reduced = $p['reduced'] === null ? null : (float) $p['reduced'];
        $lc = $p['limit_comfort'] === null ? null : (float) $p['limit_comfort'];
        $lr = $p['limit_reduced'] === null ? null : (float) $p['limit_reduced'];

        if ((int) ($p['vacation'] ?? 0) === 1) {
            return ['vacation', $p['vacation_temp'] === null ? null : (float) $p['vacation_temp'], $lr];
        }
        if ((int) ($p['party'] ?? 0) === 1 && $p['party_end_raw'] !== null && BoilerValue::fromBoilerTime($p['party_end_raw'])->isAfter($now)) {
            return ['party', $comfort, $lc];
        }

        return match ((int) ($p['mode'] ?? 1)) {
            0 => ['off', null, null],
            2 => ['comfort', $comfort, $lc],
            3 => ['reduced', $reduced, $lr],
            default => $this->inWindow($windows, $now) ? ['comfort', $comfort, $lc] : ['reduced', $reduced, $lr],
        };
    }

    /** Dans une plage confort du programme actif ; programme inconnu : confort. */
    private function inWindow(?array $windows, CarbonImmutable $now): bool
    {
        if ($windows === null) {
            return true;
        }
        $m = $now->hour * 60 + $now->minute;
        foreach ($windows[$now->dayOfWeek] ?? [] as [$a, $b]) {
            if ($m >= $a && $m < $b) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moyenne glissante de la T ext sur la durée réglée, depuis les mesures en base ; si elles sont plus anciennes que
     * cette durée (CSV pas encore relevé), la lecture directe.
     */
    private function outsideAverage(int $minutes, mixed $liveOutside): ?float
    {
        $live = $liveOutside === null ? null : (float) $liveOutside;
        $since = CarbonImmutable::now()->subMinutes(max($minutes, 1))->format('Y-m-d H:i:s');
        if ($minutes <= 0 || DB::table('measurements')->max('ts') < $since) {
            return $live;
        }
        $avg = DB::table('measurements')->where('ts', '>', $since)->avg('outside_temp');

        return $avg === null ? $live : round((float) $avg, 1);
    }
}
