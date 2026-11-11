<?php

namespace App\Analysis;

use App\Okofen\TimeProgram;
use Illuminate\Support\Facades\DB;

/**
 * Analyse du fonctionnement et recommandations de réglage.
 *
 * Chaque recommandation : id, title, severity (info|warning|critical), category, summary, details (liste),
 * changes (variable => valeur proposée, applicables depuis l'interface), level (basic|expert|hardware).
 */
class Advisor
{
    public const P = [
        'kt_set' => 'CAPPL:FA[0].pe_kesseltemperatur_soll',
        'kt_off' => 'CAPPL:FA[0].pe_abschalttemperatur',
        'kt_hyst' => 'CAPPL:FA[0].pe_einschalthysterese_smart',
        'power' => 'CAPPL:FA[0].pe_kesselleistung',
        'kt_min' => 'CAPPL:LOCAL.ke_temp_min',
        'pump_release' => 'CAPPL:FA[0].uw_freigabetemperatur',
        'kt_protect' => 'CAPPL:LOCAL.ke_schutztemp_max',
        'dhw_set' => 'CAPPL:LOCAL.ww[0].temp_heizen',
        'dhw_min' => 'CAPPL:LOCAL.ww[0].temp_absenken',
        'dhw_hyst' => 'CAPPL:LOCAL.ww[0].hysterese',
        'legionella' => 'CAPPL:LOCAL.ww[0].legionellen_wochentag',
        'limit_comfort' => 'CAPPL:LOCAL.hk[0].heizgrenze_heizen',
        'limit_reduced' => 'CAPPL:LOCAL.hk[0].heizgrenze_absenken',
        'room_comfort' => 'CAPPL:LOCAL.hk[0].raumtemp_heizen',
        'room_reduced' => 'CAPPL:LOCAL.hk[0].raumtemp_absenken',
        'room_hyst' => 'CAPPL:LOCAL.hk[0].raumtemp_plus',
        'room_influence' => 'CAPPL:LOCAL.hk[0].raumfuehler_einfluss',
    ];

    public function __construct(private readonly EnergySignature $signature) {}

    /**
     * @param  array<string, float|int|null>  $params  valeurs actuelles (clés de self::P)
     */
    public function analyse(array $params, HeatingCurve $curve, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->period($from, $to);
        $stats = $this->cycleStats($from, $to);
        $recs = [];

        if ($stats['days'] === 0) {
            return ['period' => compact('from', 'to'), 'stats' => $stats, 'recommendations' => [], 'load' => null];
        }

        $load = $this->loadAnalysis($params, $stats);

        // 1. Cycles courts : hystérésis de redémarrage
        $off = (float) ($params['kt_off'] ?? 80);
        $hyst = (float) ($params['kt_hyst'] ?? 5);
        $floor = max((float) ($params['kt_min'] ?? 60), (float) ($params['pump_release'] ?? 60)) + 5;
        if ($stats['cycles_per_day'] >= 8 && $stats['median_combustion_min'] < 40) {
            // 12 K en première étape (redémarrage vers 68 °C), sans descendre sous T mini + 5 K
            $target = min(12, max($hyst, $off - $floor));
            if ($target >= $hyst + 2) {
                $restartNow = $off - $hyst;
                $restartNew = $off - $target;
                $recs[] = [
                    'id' => 'restart_hysteresis',
                    'category' => 'cycles',
                    'severity' => 'warning',
                    'level' => 'basic',
                    'title' => "Allonger les cycles : hystérésis de redémarrage {$hyst} K → {$target} K",
                    'summary' => sprintf("La chaudière fait %.1f cycles par jour avec une combustion médiane de %d min. Elle redémarre dès que l'eau descend à %s °C (coupure %s °C − %s K) : la plage de stockage n'est que de %s K.",
                        $stats['cycles_per_day'], $stats['median_combustion_min'], $restartNow, $off, $hyst, $hyst),
                    'details' => [
                        "Redémarrage à {$restartNew} °C au lieu de {$restartNow} °C : la chaudière stocke ".round($target / max(1, $hyst), 1).'× plus d\'énergie entre deux cycles.',
                        "Reste au-dessus de la T chaudière mini et de la T de libération de la pompe (≥ {$floor} °C) : pas de risque de condensation.",
                        "Le départ chauffage reste assuré par la vanne mélangeuse (consigne de départ < {$restartNew} °C la majeure partie de la saison).",
                        'Effet attendu : environ '.round(100 * (1 - $hyst / $target)).' % de démarrages en moins à mi-saison, moins de phases de post-ventilation (qui refroidissent le foyer).',
                    ],
                    'changes' => [self::P['kt_hyst'] => $target],
                ];
            }
        }

        // 2. Chaudière surdimensionnée par rapport aux besoins
        if ($load && $load['share_below_min'] >= 0.4) {
            $power = (float) $load['power_kw'];
            $proposed = max(8, (int) ceil($load['design_kw'] * 1.15));
            if ($proposed < $power - 2) {
                $recs[] = [
                    'id' => 'boiler_power',
                    'category' => 'cycles',
                    'severity' => 'warning',
                    'level' => 'expert',
                    'title' => "Réduire la puissance paramétrée de la chaudière ({$power} → {$proposed} kW)",
                    'summary' => sprintf('Besoin estimé : %.1f kW à %d °C extérieur, %.1f kW en moyenne pendant la saison de chauffe. Puissance minimale de modulation actuelle : ~%.1f kW. %d %% des jours de chauffe, le besoin moyen est inférieur à cette puissance minimale : la chaudière ne peut que fonctionner par à-coups.',
                        $load['design_kw'], $load['design_temp'], $load['season_avg_kw'], $load['pmin_kw'], round($load['share_below_min'] * 100)),
                    'details' => [
                        sprintf('Avec %d kW, la puissance minimale descendrait vers %.1f kW : combustion continue plus fréquente à mi-saison.', $proposed, $proposed * (float) settings('equipment.min_modulation_pct', 30) / 100),
                        'La puissance reste suffisante pour la T extérieure de base, avec 15 % de marge (relance après réduit de nuit plus lente).',
                        'Réglage « technicien » : à faire valider par l\'installateur (la chaudière n\'accepte que des puissances dans la gamme apprise).',
                        ...((int) settings('equipment.turbulators_removed', 0) > 0 ? [
                            sprintf('%d ressort(s) turbulateur(s) ont été retirés de l\'échangeur : ils modifient l\'échange thermique et la température des fumées. Configuration des turbulateurs et puissance paramétrée sont à revoir ensemble avec l\'installateur.', (int) settings('equipment.turbulators_removed')),
                        ] : []),
                    ],
                    'changes' => [self::P['power'] => $proposed],
                ];
            }
        }

        // 3. Eau chaude sanitaire : légionelles
        $dhwSet = $params['dhw_set'] ?? null;
        $legio = $params['legionella'] ?? null;
        if ($dhwSet !== null && $dhwSet < 50) {
            $changes = [self::P['dhw_set'] => 52];
            if ($legio === null || (int) $legio === 7) {
                $changes[self::P['legionella']] = 0; // dimanche
            }
            $recs[] = [
                'id' => 'dhw_legionella',
                'category' => 'dhw',
                'severity' => 'critical',
                'level' => 'basic',
                'title' => "Eau chaude trop basse ({$dhwSet} °C) : risque de légionelles",
                'summary' => "Entre 25 et 45 °C, les légionelles prolifèrent dans le ballon. Il est recommandé de stocker l'eau au moins à 50–55 °C, et de faire monter le ballon à 60 °C ou plus une fois par semaine.",
                'details' => [
                    'Consigne ECS proposée : 52 °C (mitigeur thermostatique conseillé aux points de puisage).',
                    $legio === null || (int) $legio === 7 ? 'Activer le cycle anti-légionelles hebdomadaire (proposé : dimanche).' : 'Cycle anti-légionelles déjà actif.',
                    "Une consigne plus haute stocke plus d'énergie : moins de charges ECS, donc moins de démarrages en été.",
                ],
                'changes' => $changes,
            ];
        }

        // 4. Hystérésis ECS
        $dhwHyst = $params['dhw_hyst'] ?? null;
        if ($dhwHyst !== null && $dhwHyst < 8 && $stats['dhw_only_cycles_per_day'] >= 1) {
            $recs[] = [
                'id' => 'dhw_hysteresis',
                'category' => 'dhw',
                'severity' => 'info',
                'level' => 'basic',
                'title' => "Espacer les charges d'eau chaude (hystérésis ECS {$dhwHyst} → 10 K)",
                'summary' => sprintf('%.1f cycles par jour sont lancés uniquement pour l\'eau chaude. Avec une hystérésis de %s K, le ballon est rechargé dès qu\'il perd %s °C.', $stats['dhw_only_cycles_per_day'], $dhwHyst, $dhwHyst),
                'details' => [
                    'Une hystérésis de 10 K permet de recharger le ballon en une seule fois après les puisages du matin ou du soir.',
                    'Idéalement, limiter les plages ECS du programme horaire à 1 ou 2 créneaux (ex. 5h–7h et 17h–19h), alignés avec les démarrages du chauffage.',
                ],
                'changes' => [self::P['dhw_hyst'] => 10],
            ];
        }

        // 5. Loi d'eau apprise
        $learned = $curve->learn($from, $to);
        if ($learned['suggestion']) {
            $s = $learned['suggestion'];
            $now0 = $curve->flow(0);
            $new = $curve->with(['slope' => $s['slope'], 'foot' => $s['foot']]);
            $diff0 = $new->flow(0) - $now0;
            $diff10 = $new->flow(10) - $curve->flow(10);
            if (abs($diff0) >= 1.5 || abs($diff10) >= 1.5) {
                $recs[] = [
                    'id' => 'heating_curve',
                    'category' => 'comfort',
                    'severity' => 'info',
                    'level' => 'basic',
                    'title' => sprintf('Ajuster la loi d\'eau : pente %.1f → %.1f, pied %.1f → %.1f °C', $curve->slope, $s['slope'], $curve->foot, $s['foot']),
                    'summary' => sprintf("La sonde d'ambiance corrige en permanence la consigne de départ (écart de %+.1f °C à 0 °C extérieur, %+.1f °C à 10 °C). La courbe proposée reproduit ce que le régulateur calcule réellement : la correction d'ambiance n'aura plus qu'à affiner.", $diff0, $diff10),
                    'details' => [
                        'Calcul sur '.$learned['points'].' mesures en confort, pompe en marche, entre le '.$from.' et le '.$to.'.',
                        'À ajuster au plus une fois par jour, par petits pas : le bâtiment réagit lentement.',
                        'Voir la page « Loi d\'eau » pour visualiser et affiner la courbe.',
                    ],
                    'changes' => [HeatingCurve::PARAMS['slope'] => $s['slope'], HeatingCurve::PARAMS['foot'] => $s['foot']],
                ];
            }
        }

        // 6. Ballon tampon
        if ($stats['cycles_per_day'] >= 10 && (int) settings('equipment.buffer_tank_l', 0) === 0) {
            // Dimensionnement sur la puissance paramétrée (à défaut : besoin de base + 20 %)
            $power = isset($params['power']) && is_numeric($params['power']) ? (float) $params['power'] : round(($load['design_kw'] ?? 15) * 1.2);
            $volume = (int) (round($power * 30 / 100) * 100);
            $kwh = round($volume * 1.163 * 20 / 1000, 1);
            $avgKw = $load['season_avg_kw'] ?? 5;
            $hours = $avgKw > 0 ? round($kwh / $avgKw, 1) : null;
            $recs[] = [
                'id' => 'buffer_tank',
                'category' => 'hardware',
                'severity' => 'info',
                'level' => 'hardware',
                'title' => "Installer un ballon tampon (~{$volume} L)",
                'summary' => "Sans hydro-accumulation, la seule réserve d'énergie est l'eau de la chaudière (~".settings('equipment.boiler_water_l', 70)." L). Un ballon tampon permet à la chaudière de fonctionner longtemps à bonne puissance puis de s'arrêter plusieurs heures.",
                'details' => [
                    "Règle usuelle : 25 à 50 L par kW pour une chaudière à granulés ; {$volume} L pour {$power} kW.",
                    "Énergie stockée entre 60 et 80 °C : {$kwh} kWh, soit ~{$hours} h d'autonomie au besoin moyen de saison.",
                    'Les études de terrain (Autriche) mesurent des rendements réels inférieurs de 7 à 24 points aux rendements nominaux à cause des cycles ; le stockage réduit cycles et émissions.',
                    'Contreparties : coût (1 500 à 3 000 € posé), place, pertes du ballon (à bien isoler), régulation à reconfigurer (sondes TPO/TPM).',
                ],
                'changes' => [],
            ];
        }

        // 7. Limite de chauffe haute
        $limit = $params['limit_comfort'] ?? null;
        if ($limit !== null && $limit >= 17 && $stats['mild_short_cycles_share'] > 0.5) {
            $recs[] = [
                'id' => 'heating_limit',
                'category' => 'cycles',
                'severity' => 'info',
                'level' => 'basic',
                'title' => "Abaisser la limite de chauffe ({$limit} → 15 °C)",
                'summary' => 'Au-delà de 14–15 °C extérieur, la maison a très peu de besoins : la chaudière enchaîne des cycles très courts pour quelques kWh.',
                'details' => ['Les apports solaires et internes suffisent souvent au-dessus de 15 °C pour une maison isolée.'],
                'changes' => [self::P['limit_comfort'] => 15],
            ];
        }

        array_push($recs, ...$this->heatingCircuitAdvice($params));

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($recs, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return ['period' => compact('from', 'to'), 'stats' => $stats, 'load' => $load, 'recommendations' => $recs];
    }

    /**
     * Réglages du circuit de chauffage au regard des règles du régulateur et des conseils ÖkoFEN
     * (docs/REGULATION-OKOFEN.md §2).
     *
     * @param  array<string, float|int|null>  $params
     * @return list<array<string, mixed>>
     */
    public function heatingCircuitAdvice(array $params, ?array $windows = null): array
    {
        $recs = [];
        $num = fn (string $k) => isset($params[$k]) && is_numeric($params[$k]) ? (float) $params[$k] : null;
        $comfort = $num('room_comfort');
        $reduced = $num('room_reduced');
        $hyst = $num('room_hyst');
        $influence = $num('room_influence');
        $limitReduced = $num('limit_reduced');
        $fmt = fn (float $x) => rtrim(rtrim(number_format($x, 1, ',', ''), '0'), ',');

        // Hystérésis d'ambiance à 0 : arrêt dès la consigne, relance 1 °C dessous
        if ($hyst !== null && $hyst < 0.5 && $comfort !== null && ($influence ?? 0) > 0) {
            $newComfort = $comfort - 0.5;
            $recs[] = [
                'id' => 'room_hysteresis',
                'category' => 'comfort',
                'severity' => 'warning',
                'level' => 'basic',
                'title' => 'Hystérésis d\'ambiance à '.$fmt($hyst).' °C : le chauffage s\'arrête dès la consigne atteinte',
                'summary' => 'Le circuit s\'arrête quand la pièce dépasse consigne + hystérésis, et ne repart que 1 °C sous ce seuil. Avec 0, il se coupe à '.$fmt($comfort).' °C et attend '.$fmt($comfort - 1).' °C : une coupure du programme, un peu de soleil ou un retour de réduit suffit à le laisser éteint pendant des heures.',
                'details' => [
                    'Le manuel ÖkoFEN (E1389FR p. 23) ne conseille 0 qu\'en cas de forts apports (soleil, poêle) dans la pièce de la sonde ; « dans les autres cas, une valeur supérieure est recommandée ».',
                    'Proposé : hystérésis 1 °C et consigne confort '.$fmt($newComfort).' °C. Le circuit reste en marche entre '.$fmt($newComfort).' et '.$fmt($newComfort + 1).' °C ; à l\'intérieur, c\'est l\'influence de la sonde d\'ambiance (×'.$fmt((float) $influence).') qui ajuste en continu la température de départ, au lieu d\'un marche/arrêt.',
                    'La plage tenue garde la même largeur (1 °C) : elle est seulement centrée sur la température voulue.',
                ],
                'changes' => [self::P['room_hyst'] => 1, self::P['room_comfort'] => $newComfort],
            ];
        }

        // Plages de programme hachées
        try {
            $windows ??= app(TimeProgram::class)->windows('hk');
        } catch (\Throwable) {
            $windows = null;
        }
        $issues = [];
        foreach (TimeProgram::DAYS as $d => $name) {
            $slots = $windows[$d] ?? [];
            foreach ($slots as $i => [$a, $b]) {
                if ($b - $a < 120) {
                    $issues[$name][] = 'plage '.TimeProgram::time($a).'–'.TimeProgram::time($b).' de moins de 2 h';
                }
                if ($i > 0 && $a - $slots[$i - 1][1] < 120) {
                    $issues[$name][] = 'coupure '.TimeProgram::time($slots[$i - 1][1]).'–'.TimeProgram::time($a).' de moins de 2 h';
                }
            }
        }
        if ($issues !== []) {
            $first = array_key_first($issues);
            $recs[] = [
                'id' => 'fragmented_program',
                'category' => 'comfort',
                'severity' => 'warning',
                'level' => 'basic',
                'title' => 'Programme de chauffage haché ('.count($issues).' jour'.(count($issues) > 1 ? 's' : '').')',
                'summary' => 'ÖkoFEN déconseille de hacher les plages de confort et de réduit. Une courte coupure bascule le circuit en réduit : il s\'arrête, puis ne repart pas forcément au retour du confort (hystérésis d\'ambiance, limite de chauffe), et chaque relance coûte un démarrage du brûleur.',
                'details' => [
                    $first.' : '.implode(', ', array_unique($issues[$first])).(count($issues) > 1 ? ' (et '.(count($issues) - 1).' autre(s) jour(s)).' : '.'),
                    'Préférer une plage de confort continue ; la durée d\'anticipation fait démarrer la chaudière assez tôt pour être à la consigne au début de la plage.',
                    'Le programme se modifie sur la page « Programmes horaires ».',
                ],
                'changes' => [],
            ];
        }

        // Confort élevé
        if ($comfort !== null && $comfort > 21) {
            $recs[] = [
                'id' => 'comfort_high',
                'category' => 'consumption',
                'severity' => 'info',
                'level' => 'basic',
                'title' => 'Consigne confort élevée ('.$fmt($comfort).' °C)',
                'summary' => 'Chaque degré de plus coûte environ 7 % de granulés. Au-delà de 21 °C, ÖkoFEN conseille plutôt de revoir la loi d\'eau.',
                'details' => [],
                'changes' => [self::P['room_comfort'] => 21],
            ];
        }

        // Écart confort / réduit
        if ($comfort !== null && $reduced !== null && $comfort - $reduced > 5) {
            $recs[] = [
                'id' => 'setback_large',
                'category' => 'comfort',
                'severity' => 'info',
                'level' => 'basic',
                'title' => 'Écart confort / réduit de '.$fmt($comfort - $reduced).' °C',
                'summary' => 'ÖkoFEN conseille au plus 5 °C d\'écart sur radiateurs (2 °C sur plancher chauffant) : au-delà, la relance du matin est longue et puissante.',
                'details' => [],
                'changes' => [self::P['room_reduced'] => $comfort - 5],
            ];
        }

        // Limite de réduit haute
        if ($limitReduced !== null && $limitReduced >= 14) {
            $recs[] = [
                'id' => 'reduced_limit_high',
                'category' => 'consumption',
                'severity' => 'info',
                'level' => 'basic',
                'title' => 'Limite de chauffe en réduit haute ('.$fmt($limitReduced).' °C → 10 °C)',
                'summary' => 'Avec '.$fmt($limitReduced).' °C, le circuit peut chauffer presque toutes les nuits de la saison. Il est souvent inutile de chauffer la nuit : la maison est chaude en début de nuit et la chaudière redémarre de toute façon avant la plage de confort.',
                'details' => [
                    'Repères ÖkoFEN : 0 °C (maison très isolée), 5 °C (bien isolée), 10 °C (moyennement isolée), 14 °C (peu isolée).',
                    'Avec une sonde d\'ambiance, la consigne de réduit protège déjà la nuit : la limite ne fait que l\'autoriser à chauffer.',
                    'Si la pièce est trop froide au réveil, allonger plutôt la durée d\'anticipation.',
                ],
                'changes' => [self::P['limit_reduced'] => 10],
            ];
        }

        return $recs;
    }

    /**
     * Statistiques de cycles sur une période.
     */
    public function cycleStats(string $from, string $to): array
    {
        $days = (int) DB::table('daily_stats')->whereBetween('day', [$from, $to])->where('samples', '>', 1000)->count();
        $cycles = DB::table('burner_cycles')->whereBetween('day', [$from, $to])->where('complete', true)
            ->get(['combustion_min', 'duration_min', 'off_before_min', 'cold_start', 'dhw', 'heating', 'outside_temp_avg', 'pellets_kg']);

        $comb = $cycles->pluck('combustion_min')->sort()->values();
        $median = fn ($c) => $c->count() ? (int) $c[(int) floor(($c->count() - 1) / 2)] : 0;
        $mild = $cycles->filter(fn ($c) => $c->outside_temp_avg !== null && $c->outside_temp_avg >= 12);

        return [
            'days' => $days,
            'cycles' => $cycles->count(),
            'cycles_per_day' => $days ? round($cycles->count() / $days, 1) : 0,
            'cold_starts_per_day' => $days ? round($cycles->where('cold_start', 1)->count() / $days, 1) : 0,
            'median_combustion_min' => $median($comb),
            'median_off_min' => $median($cycles->pluck('off_before_min')->filter(fn ($v) => $v !== null)->sort()->values()),
            'short_share' => $cycles->count() ? round($cycles->filter(fn ($c) => $c->combustion_min < 30)->count() / $cycles->count(), 2) : 0,
            'combustion_ratio' => $cycles->sum('duration_min') ? round($cycles->sum('combustion_min') / $cycles->sum('duration_min'), 2) : null,
            'dhw_only_cycles_per_day' => $days ? round($cycles->filter(fn ($c) => $c->dhw && ! $c->heating)->count() / $days, 1) : 0,
            'kg_per_cycle' => $cycles->count() ? round($cycles->avg('pellets_kg'), 2) : null,
            'mild_short_cycles_share' => $mild->count() ? round($mild->filter(fn ($c) => $c->combustion_min < 30)->count() / $mild->count(), 2) : 0,
        ];
    }

    private function loadAnalysis(array $params, array $stats): ?array
    {
        $model = $this->signature->model();
        if (! $model) {
            return null;
        }

        // Puissance lue sur la chaudière : sans elle, pas d'analyse de charge
        if (! isset($params['power']) || ! is_numeric($params['power'])) {
            return null;
        }
        $power = (float) $params['power'];
        $pmin = $power * (float) settings('equipment.min_modulation_pct', 30) / 100;
        $designT = (float) settings('climate.design_outside_temp', -5);

        $heatingDays = DB::table('daily_stats')->where('day', '>=', now()->subDays(730)->format('Y-m-d'))
            ->where('samples', '>', 1380)->where('outside_avg', '<', $model['t0'])->pluck('outside_avg');
        $below = $heatingDays->filter(fn ($t) => $this->signature->loadKw((float) $t) < $pmin)->count();
        $avgT = $heatingDays->avg();

        return [
            'design_temp' => $designT,
            'design_kw' => $this->signature->loadKw($designT),
            'season_avg_kw' => $avgT !== null ? $this->signature->loadKw((float) $avgT) : null,
            'season_avg_temp' => $avgT !== null ? round((float) $avgT, 1) : null,
            'pmin_kw' => round($pmin, 1),
            'power_kw' => $power,
            'share_below_min' => $heatingDays->count() ? round($below / $heatingDays->count(), 2) : 0,
            'model' => $model,
        ];
    }

    /** Période par défaut : 30 derniers jours, ou la dernière saison de chauffe s'il fait trop doux. */
    private function period(?string $from, ?string $to): array
    {
        if ($from && $to) {
            return [$from, $to];
        }

        $to = now()->subDay()->format('Y-m-d');
        $from = now()->subDays(30)->format('Y-m-d');
        $heating = DB::table('daily_stats')->whereBetween('day', [$from, $to])->where('outside_avg', '<', 12)->count();
        if ($heating < 10) {
            $year = now()->month >= 9 ? now()->year : now()->year - 1;
            $from = ($year - 1).'-12-01';
            $to = $year.'-02-28';
        }

        return [$from, $to];
    }
}
