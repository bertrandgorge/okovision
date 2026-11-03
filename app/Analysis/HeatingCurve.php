<?php

namespace App\Analysis;

use Illuminate\Support\Facades\DB;

/**
 * Loi d'eau du régulateur Okofen / KEBA Touch.
 *
 * Formule identifiée sur 12 ans de mesures (écart résiduel < 0,05 °C) :
 *
 *   VLsoll = Pied + (RTsoll − 20) + Pente × (RTsoll − 5 − Text) + Influence × (RTsoll − RTist)
 *
 * puis bornée entre « T départ min » et « T départ max ». Le pied de courbe est donc la température
 * de départ pour Text = 15 °C et une consigne ambiante de 20 °C. Au-dessus de la limite de chauffe
 * (T ext moyennée), le circuit s'arrête.
 */
final class HeatingCurve
{
    public const PARAMS = [
        'slope' => 'CAPPL:LOCAL.hk[0].heizkurve_steigung',
        'foot' => 'CAPPL:LOCAL.hk[0].heizkurve_fusspunkt',
        'room_comfort' => 'CAPPL:LOCAL.hk[0].raumtemp_heizen',
        'room_reduced' => 'CAPPL:LOCAL.hk[0].raumtemp_absenken',
        'influence' => 'CAPPL:LOCAL.hk[0].raumfuehler_einfluss',
        'flow_min' => 'CAPPL:LOCAL.hk[0].vorlauftemp_min',
        'flow_max' => 'CAPPL:LOCAL.hk[0].vorlauftemp_max',
        'limit_comfort' => 'CAPPL:LOCAL.hk[0].heizgrenze_heizen',
        'limit_reduced' => 'CAPPL:LOCAL.hk[0].heizgrenze_absenken',
        'raise' => 'CAPPL:LOCAL.hk[0].ueberhoehung',
    ];

    public function __construct(
        public readonly float $slope = 1.3,
        public readonly float $foot = 32,
        public readonly float $roomComfort = 20,
        public readonly float $roomReduced = 18,
        public readonly float $influence = 3,
        public readonly float $flowMin = 30,
        public readonly float $flowMax = 75,
        public readonly float $limitComfort = 16,
        public readonly float $limitReduced = 14,
        public readonly float $raise = 5,
    ) {}

    /** @param array<string, float|int|string|null> $values clés de self::PARAMS */
    public static function fromArray(array $values): self
    {
        $d = new self;
        $get = fn (string $k, float $def) => isset($values[$k]) && is_numeric($values[$k]) ? (float) $values[$k] : $def;

        return new self(
            slope: $get('slope', $d->slope),
            foot: $get('foot', $d->foot),
            roomComfort: $get('room_comfort', $d->roomComfort),
            roomReduced: $get('room_reduced', $d->roomReduced),
            influence: $get('influence', $d->influence),
            flowMin: $get('flow_min', $d->flowMin),
            flowMax: $get('flow_max', $d->flowMax),
            limitComfort: $get('limit_comfort', $d->limitComfort),
            limitReduced: $get('limit_reduced', $d->limitReduced),
            raise: $get('raise', $d->raise),
        );
    }

    public function toArray(): array
    {
        return [
            'slope' => $this->slope, 'foot' => $this->foot, 'room_comfort' => $this->roomComfort,
            'room_reduced' => $this->roomReduced, 'influence' => $this->influence, 'flow_min' => $this->flowMin,
            'flow_max' => $this->flowMax, 'limit_comfort' => $this->limitComfort, 'limit_reduced' => $this->limitReduced,
            'raise' => $this->raise,
        ];
    }

    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    /** Consigne de départ non bornée (loi d'eau pure + correction d'ambiance). */
    public function rawFlow(float $outside, ?float $roomSet = null, ?float $roomActual = null): float
    {
        $roomSet ??= $this->roomComfort;
        $correction = $roomActual !== null ? $this->influence * ($roomSet - $roomActual) : 0.0;

        return $this->foot + ($roomSet - 20) + $this->slope * ($roomSet - 5 - $outside) + $correction;
    }

    /** Consigne de départ telle que calculée par le régulateur (null = circuit arrêté). */
    public function flow(float $outside, ?float $roomSet = null, ?float $roomActual = null, bool $reduced = false): ?float
    {
        $limit = $reduced ? $this->limitReduced : $this->limitComfort;
        if ($outside > $limit) {
            return null;
        }

        $roomSet ??= $reduced ? $this->roomReduced : $this->roomComfort;

        return round(max($this->flowMin, min($this->flowMax, $this->rawFlow($outside, $roomSet, $roomActual))), 1);
    }

    /** Consigne chaudière correspondante (surélévation, T chaudière mini). */
    public function boilerSet(?float $flow, float $boilerMin = 60): ?float
    {
        return $flow === null ? null : max($boilerMin, $flow + $this->raise);
    }

    /**
     * Points de la courbe pour le graphique.
     *
     * @return list<array{0: float, 1: float|null}>
     */
    public function series(bool $reduced = false, float $from = -15, float $to = 20, float $step = 0.5): array
    {
        $points = [];
        for ($t = $from; $t <= $to + 1e-9; $t += $step) {
            $points[] = [round($t, 1), $this->flow($t, reduced: $reduced)];
        }

        return $points;
    }

    /**
     * Déduit pente et pied à partir de deux points (T ext, T départ) pour une consigne ambiante donnée.
     *
     * @return array{slope: float, foot: float}
     */
    public static function fromTwoPoints(float $t1, float $flow1, float $t2, float $flow2, float $roomSet = 20): array
    {
        $slope = ($flow1 - $flow2) / ($t2 - $t1);
        $foot = $flow1 - ($roomSet - 20) - $slope * ($roomSet - 5 - $t1);

        return ['slope' => round($slope, 2), 'foot' => round($foot, 1)];
    }

    /**
     * Apprentissage sur l'historique : en régime établi, la correction d'ambiance compense l'erreur de la courbe.
     * La « courbe idéale » est donc la régression des consignes réellement calculées (en confort, pompe en marche),
     * ramenées à la consigne ambiante de référence. On renvoie aussi l'écart d'ambiance moyen par tranche de T ext.
     *
     * @return array{buckets: list<array>, suggestion: array|null, points: int}
     */
    public function learn(string $from, string $to, ?float $roomSet = null): array
    {
        $roomSet ??= $this->roomComfort;

        $rows = DB::table('measurements')
            ->whereBetween('ts', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('hc1_pump', 1)
            ->where('hc1_flow_set', '>', 15)
            ->where('hc1_room_set', $roomSet)
            ->whereRaw('MINUTE(ts) % 5 = 0')
            ->selectRaw('ROUND(outside_temp) as t, COUNT(*) as n, AVG(hc1_flow_set) as flow_set, AVG(hc1_flow_temp) as flow,
                AVG(hc1_room_temp - hc1_room_set) as dev, AVG(hc1_room_temp) as room')
            ->groupByRaw('ROUND(outside_temp)')
            ->orderBy('t')
            ->get();

        $buckets = $rows->map(fn ($r) => [
            't' => (float) $r->t,
            'n' => (int) $r->n,
            'flow_set' => round((float) $r->flow_set, 1),
            'flow' => round((float) $r->flow, 1),
            'deviation' => round((float) $r->dev, 2),
            'room' => round((float) $r->room, 1),
            'current' => $this->flow((float) $r->t, $roomSet),
        ])->values()->all();

        // Régression pondérée VLsoll observée = a + b·T, en excluant les tranches bornées par VLmin/VLmax
        $usable = array_filter($buckets, fn ($b) => $b['n'] >= 12 && $b['flow_set'] > $this->flowMin + 0.5 && $b['flow_set'] < $this->flowMax - 0.5);
        $suggestion = null;
        if (count($usable) >= 4) {
            $sw = $sx = $sy = $sxx = $sxy = 0.0;
            foreach ($usable as $b) {
                $w = $b['n'];
                $sw += $w;
                $sx += $w * $b['t'];
                $sy += $w * $b['flow_set'];
                $sxx += $w * $b['t'] ** 2;
                $sxy += $w * $b['t'] * $b['flow_set'];
            }
            $den = $sw * $sxx - $sx ** 2;
            if (abs($den) > 1e-9) {
                $b = ($sw * $sxy - $sx * $sy) / $den;
                $a = ($sy - $b * $sx) / $sw;
                $slope = -$b;
                // a + b·T = Pied + (RTs − 20) + Pente·(RTs − 5 − T)  ⇒  Pied = a − (RTs − 20) − Pente·(RTs − 5)
                $foot = $a - ($roomSet - 20) - $slope * ($roomSet - 5);
                $suggestion = ['slope' => round($slope, 1), 'foot' => round($foot, 1)];
            }
        }

        return ['buckets' => $buckets, 'suggestion' => $suggestion, 'points' => array_sum(array_column($buckets, 'n'))];
    }

    /**
     * Nuage de points (T ext, T départ consigne, T départ mesurée, écart ambiance) pour le graphique.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function scatter(string $from, string $to, int $limit = 4000): array
    {
        $count = DB::table('measurements')->whereBetween('ts', [$from.' 00:00:00', $to.' 23:59:59'])->where('hc1_pump', 1)->count();
        $every = max(1, (int) ceil($count / $limit));

        return DB::table('measurements')
            ->whereBetween('ts', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('hc1_pump', 1)
            ->where('hc1_flow_set', '>', 15)
            ->whereRaw('(UNIX_TIMESTAMP(ts) DIV 60) % ? = 0', [$every])
            ->get(['outside_temp', 'hc1_flow_set', 'hc1_flow_temp', 'hc1_room_temp', 'hc1_room_set'])
            ->map(fn ($r) => [(float) $r->outside_temp, (float) $r->hc1_flow_set, (float) $r->hc1_flow_temp, round((float) $r->hc1_room_temp - (float) $r->hc1_room_set, 1)])
            ->all();
    }
}
