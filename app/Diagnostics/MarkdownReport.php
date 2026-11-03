<?php

namespace App\Diagnostics;

use App\Analysis\Advisor;
use App\Analysis\EnergySignature;
use App\Analysis\PelletCalibration;
use App\Analysis\StockEstimator;
use App\Models\BoilerParameterChange;
use App\Models\BoilerProfile;
use App\Models\StockEvent;
use App\Okofen\BoilerService;
use App\Okofen\BoilerValue;
use App\Okofen\Catalog;
use App\Okofen\ParameterSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rapport Markdown autoportant décrivant l'installation, ses réglages et son fonctionnement,
 * destiné à être fourni à un LLM pour diagnostic et propositions de réglages.
 */
class MarkdownReport
{
    private array $out = [];

    /** @var array<string, BoilerValue> */
    private array $values = [];

    private ?string $boilerError = null;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly BoilerService $boiler,
        private readonly Advisor $advisor,
        private readonly EnergySignature $signature,
        private readonly StockEstimator $stock,
        private readonly PelletCalibration $calibration,
    ) {}

    /**
     * @param  int  $days  période détaillée (synthèses journalières, cycles)
     * @param  int  $sampleMinutes  pas d'échantillonnage des mesures des dernières 24 h (0 = pas de mesures brutes)
     * @param  bool  $expert  inclure les réglages avancés (technicien)
     */
    public function build(int $days = 30, int $sampleMinutes = 15, bool $expert = true): string
    {
        $this->out = [];
        $this->loadValues($expert);

        $this->header($days);
        $this->guide();
        $this->installation();
        $this->live();
        $this->heatingCurve();
        $this->parameters('basic', 'Réglages principaux');
        if ($expert) {
            $this->parameters('expert', 'Réglages avancés (technicien)', compact: true);
        }
        $this->operation($days);
        $this->signatureSection();
        $this->seasons();
        $this->stockSection();
        $this->recommendations();
        $this->changes();
        $this->snapshots();
        $this->measurementsDictionary();
        if ($sampleMinutes > 0) {
            $this->recentSamples($sampleMinutes);
        }

        return implode("\n", $this->out)."\n";
    }

    // ------------------------------------------------------------------ sections

    private function header(int $days): void
    {
        $this->line('# Diagnostic chaudière à granulés : '.settings('equipment.boiler_model', 'Okofen Pellematic'));
        $this->line();
        $this->line('Rapport généré par Okovision le '.now()->translatedFormat('l d F Y à H:i').' (heure locale '.config('app.timezone').').');
        $this->line("Période détaillée : {$days} derniers jours. Unités SI, températures en °C, consommations en kg de granulés.");
        if ($this->boilerError) {
            $this->line();
            $this->line("> ⚠️ Chaudière injoignable lors de la génération ({$this->boilerError}) : les valeurs de réglage peuvent manquer.");
        }
        $this->line();
    }

    private function guide(): void
    {
        $this->h2('Comment lire ce document');
        $this->line(<<<'MD'
- **Objectif** : aider à diagnostiquer le fonctionnement de la chaudière et proposer des réglages (confort, consommation, nombre de cycles brûleur, eau chaude sanitaire).
- **Régulateur** : Okofen Pelletronic Touch (KEBA). Les réglages sont identifiés par leur nom de variable API (ex. `CAPPL:LOCAL.hk[0].heizkurve_steigung`) ; toute proposition doit citer ce nom et une valeur **dans la plage indiquée**.
- **Fonctionnement du brûleur** : il module pour atteindre la « température de régulation », s'arrête à la « température de coupure » et redémarre quand T chaudière < T coupure − « hystérésis de redémarrage » (s'il y a une demande). Sous la puissance minimale de modulation (~30 % du nominal), il ne peut que fonctionner par cycles.
- **Un cycle** = démarrage (allumage électrique si foyer froid) → stabilisation → combustion → post-combustion (~15 min de ventilation). Les cycles courts dégradent rendement et émissions.
- **Consommation** : calculée par le temps de marche de la vis brûleur × débit calibré sur les livraisons (g/min).
- Contraintes : ne pas proposer de T chaudière < T chaudière mini (risque de condensation), garder la cohérence entre T départ max et émetteurs, ajuster la loi d'eau par petits pas (une modification par jour).
MD);
        $this->line();
    }

    private function installation(): void
    {
        $e = settings()->section('equipment');
        $p = settings()->section('pellets');
        $labels = [
            'silo_type' => ['auger' => 'silo + vis sans fin', 'suction' => 'silo + aspiration', 'textile' => 'silo textile', 'hopper' => 'trémie manuelle'],
            'emitters' => ['convectors' => 'convecteurs à ailettes', 'radiators' => 'radiateurs', 'underfloor' => 'plancher chauffant', 'mixed' => 'mixte'],
        ];

        $this->h2('Installation');
        $this->table(['Élément', 'Valeur'], [
            ['Chaudière', ($e['boiler_model'] ?? '?').', puissance paramétrée '.(($kw = $this->powerKw()) !== null ? $this->num($kw).' kW' : 'inconnue (chaudière injoignable)').' (`CAPPL:FA[0].pe_kesselleistung`), modulation mini ~'.($e['min_modulation_pct'] ?? 30).' %'],
            ['Version régulateur', $this->v('CAPPL:LOCAL.touch[0].version') ?? '?'],
            ['Ressorts turbulateurs retirés de l\'échangeur', (int) ($e['turbulators_removed'] ?? 0) > 0 ? (int) $e['turbulators_removed'].' (échange thermique modifié : températures de fumées et rendement à surveiller)' : 'aucun'],
            ['Eau dans la chaudière', ($e['boiler_water_l'] ?? '?').' L'],
            ['Ballon tampon', ($e['buffer_tank_l'] ?? 0) > 0 ? $e['buffer_tank_l'].' L' : 'aucun'],
            ['Ballon eau chaude', ($e['dhw_tank_l'] ?? '?').' L'],
            ['Circuits de chauffage', '1 circuit (HK1), '.(($this->v('CAPPL:LOCAL.hk[0].mischer_vorhanden') ?? 1) ? 'avec vanne mélangeuse' : 'direct').', '.(($e['room_sensor'] ?? true) ? 'avec sonde d\'ambiance' : 'sans sonde d\'ambiance')],
            ['Émetteurs', $labels['emitters'][$e['emitters'] ?? ''] ?? ($e['emitters'] ?? '?')],
            ['Bâtiment', ($e['house_surface_m2'] ?? '?').' m², construit vers '.($e['house_year'] ?? '?')],
            ['Stockage granulés', ($labels['silo_type'][$e['silo_type'] ?? ''] ?? '?').', capacité '.($e['silo_capacity_kg'] ?? '?').' kg'],
            ['Granulés', 'PCI '.($p['lhv_kwh_per_kg'] ?? 4.8).' kWh/kg, rendement saisonnier estimé '.($p['boiler_efficiency'] ?? 0.88).', prix '.($p['price_per_ton'] ?? '?').' €/t'],
            ['Débit de vis calibré', $this->fmt($this->calibration->gramsPerMinute(), 1).' g par minute de vis'],
            ['Climat', 'T extérieure de base '.settings('climate.design_outside_temp').' °C, DJU base '.settings('climate.dju_base').' °C'],
        ]);
        if (filled($e['notes'] ?? null)) {
            $this->line('Notes sur l\'installation : '.$this->oneLine((string) $e['notes']));
            $this->line();
        }
    }

    private function live(): void
    {
        $live = $this->boiler->live();
        $last = DB::table('measurements')->orderByDesc('ts')->first();

        $this->h2('État actuel');
        if ($last) {
            $this->line('Dernière mesure : '.$last->ts.'.');
            $this->line();
            $rows = [];
            foreach ($this->catalog->measurements() as $col => $m) {
                $v = $last->{$col} ?? null;
                if ($v === null) {
                    continue;
                }
                $shown = $col === 'pe1_status' ? $this->catalog->statusLabel((int) $v).' ('.$v.')' : $this->num($v).(isset($m['unit']) ? ' '.$m['unit'] : '');
                $rows[] = [$this->catalog->label($m), "`{$col}`", $shown];
            }
            $this->table(['Mesure', 'Colonne', 'Valeur'], $rows);
        }
        $counters = DB::table('boiler_counters')->orderByDesc('day')->first();
        $vals = $live['values'] ?? [];
        $this->line('Compteurs internes : '.implode(' · ', array_filter([
            isset($vals['burner_starts']) ? 'démarrages brûleur '.$vals['burner_starts'] : ($counters ? 'démarrages brûleur '.$counters->burner_starts : null),
            isset($vals['ignitions']) ? 'allumages '.$vals['ignitions'] : null,
            isset($vals['burner_hours']) ? 'heures brûleur '.$vals['burner_hours'].' h' : null,
            isset($vals['mean_run_minutes']) ? 'durée moyenne de fonctionnement '.$vals['mean_run_minutes'].' min' : null,
        ])).'.');
        $this->line();
    }

    private function heatingCurve(): void
    {
        try {
            $curve = $this->boiler->currentCurve();
        } catch (\Throwable) {
            return;
        }

        $this->h2('Loi d\'eau (circuit 1)');
        $this->line('Formule du régulateur (identifiée sur 12 ans de mesures, écart < 0,05 °C) :');
        $this->line();
        $this->line('```');
        $this->line('T départ consigne = Pied + (RT consigne − 20) + Pente × (RT consigne − 5 − T ext) + Influence × (RT consigne − RT mesurée)');
        $this->line('                    bornée entre T départ mini et T départ maxi ; circuit arrêté si T ext moyennée > limite de chauffe');
        $this->line('T chaudière demandée = max(T départ consigne + surélévation, T chaudière mini)');
        $this->line('```');
        $this->line();
        $this->line(sprintf('Réglages actuels : pente %s, pied %s °C, consigne confort %s °C, réduit %s °C, influence sonde %s, départ %s–%s °C, limite de chauffe %s °C (confort) / %s °C (réduit), surélévation %s K.',
            $this->num($curve->slope), $this->num($curve->foot), $this->num($curve->roomComfort), $this->num($curve->roomReduced), $this->num($curve->influence),
            $this->num($curve->flowMin), $this->num($curve->flowMax), $this->num($curve->limitComfort), $this->num($curve->limitReduced), $this->num($curve->raise)));
        $this->line();

        $from = now()->subDays(60)->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $learned = $curve->learn($from, $to);
        $periodLabel = '60 derniers jours';
        // Trop peu de jours froids récents : on prend le dernier hiver (décembre → février)
        if (count(array_filter($learned['buckets'], fn ($b) => $b['n'] >= 12 && $b['t'] < 10)) < 3) {
            $year = now()->month >= 3 ? now()->year : now()->year - 1;
            $from = ($year - 1).'-12-01';
            $to = $year.'-02-28';
            $learned = $curve->learn($from, $to);
            $periodLabel = "hiver {$from} → {$to}";
        }
        $byT = collect($learned['buckets'])->keyBy(fn ($b) => (string) $b['t']);
        $rows = [];
        foreach ([-10, -5, 0, 3, 5, 8, 10, 12, 14, 15, 16] as $t) {
            $b = $byT->get((string) (float) $t);
            $rows[] = [
                "{$t} °C",
                $this->num($curve->flow($t)) ?? 'arrêt',
                $this->num($curve->flow($t, reduced: true)) ?? 'arrêt',
                $b ? $this->num($b['flow_set']) : '—',
                $b ? $this->num($b['flow']) : '—',
                $b ? sprintf('%+.2f K', $b['deviation']) : '—',
                $b ? (string) $b['n'] : '0',
            ];
        }
        $this->line("Courbe théorique (sans correction d'ambiance) et observations ({$periodLabel}) en confort, pompe en marche (moyennes par °C extérieur ; écart = T ambiante − consigne) :");
        $this->line();
        $this->table(['T ext', 'Départ confort', 'Départ réduit', 'Consigne observée', 'Départ mesuré', 'Écart ambiance', 'Mesures'], $rows);
        if ($learned['suggestion']) {
            $this->line('Courbe qui reproduirait les consignes réellement calculées (régression) : pente '.$learned['suggestion']['slope'].', pied '.$learned['suggestion']['foot'].' °C.');
            $this->line();
        }
    }

    private function parameters(string $level, string $title, bool $compact = false): void
    {
        $params = $this->catalog->parameters()->filter(fn ($p) => $p['level'] === $level)->groupBy('group');
        if ($params->isEmpty()) {
            return;
        }

        $this->h2($title);
        $groups = $this->catalog->groups();
        foreach ($params as $group => $items) {
            $rows = [];
            foreach ($items as $p) {
                $value = $this->values[$p['name']] ?? null;
                if ($compact && (! $value || ! $value->ok)) {
                    continue;
                }
                $v = $value?->value();
                $shown = $v === null ? '?' : (isset($p['options'][$v]) ? $p['options'][$v]." ({$v})" : $this->num($v).(isset($p['unit']) ? ' '.$p['unit'] : ''));
                $range = isset($p['options']) ? implode(' / ', array_map(fn ($k, $l) => "{$k}={$l}", array_keys($p['options']), $p['options']))
                    : (isset($p['min'], $p['max']) ? $this->num($p['min']).' … '.$this->num($p['max']) : '');
                $row = [$this->catalog->label($p), '`'.$p['name'].'`', $shown, $range];
                if (! $compact) {
                    $row[] = $this->oneLine($p['description'] ?? '');
                }
                $rows[] = $row;
            }
            if (! $rows) {
                continue;
            }
            $this->h3($groups[$group] ?? $group);
            $this->table($compact ? ['Réglage', 'Variable', 'Valeur', 'Plage / choix'] : ['Réglage', 'Variable', 'Valeur', 'Plage / choix', 'Rôle'], $rows);
        }
    }

    private function operation(int $days): void
    {
        $from = now()->subDays($days)->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $stats = $this->advisor->cycleStats($from, $to);

        $this->h2("Fonctionnement des {$days} derniers jours");
        $this->line(sprintf('%d jours mesurés · %s cycles/jour (%s allumages à froid/jour) · combustion médiane %d min par cycle · arrêt médian entre cycles %d min · %d %% de cycles < 30 min de combustion · temps en combustion %s %% du temps de cycle · %s cycles/jour pour l\'eau chaude seule · %s kg par cycle.',
            $stats['days'], $this->num($stats['cycles_per_day']), $this->num($stats['cold_starts_per_day']), $stats['median_combustion_min'], $stats['median_off_min'],
            round($stats['short_share'] * 100), $stats['combustion_ratio'] !== null ? round($stats['combustion_ratio'] * 100) : '?',
            $this->num($stats['dhw_only_cycles_per_day']), $this->num($stats['kg_per_cycle'])));
        $this->line();

        $rows = DB::table('daily_stats')->whereBetween('day', [$from, $to])->orderBy('day')->get()->map(fn ($r) => [
            (string) $r->day, $this->num($r->outside_min).' / '.$this->num($r->outside_avg).' / '.$this->num($r->outside_max),
            $this->num($r->room_avg), $this->num($r->room_set_avg), $this->num($r->flow_avg), $this->num($r->dhw_avg),
            $this->num($r->pellets_kg), $this->num($r->pellets_kg_dhw), (string) $r->cycles, (string) $r->cold_starts,
            (string) $r->combustion_min, (string) $r->burner_min, $this->num($r->fan_avg), $this->num($r->boiler_temp_max), (string) $r->samples,
        ])->all();
        $this->h3('Synthèse journalière');
        $this->table(['Jour', 'T ext min/moy/max', 'T amb.', 'Consigne amb.', 'T départ moy.', 'T ECS moy.', 'kg total', 'kg ECS', 'Cycles', 'À froid', 'Min combustion', 'Min brûleur', 'Ventil. moy. %', 'T chaud. max', 'Mesures'], $rows);

        $cycles = DB::table('burner_cycles')->where('day', '>=', now()->subDays(min($days, 3))->format('Y-m-d'))->orderBy('started_at')->get();
        if ($cycles->isNotEmpty()) {
            $this->h3('Détail des cycles (3 derniers jours)');
            $this->table(['Début', 'Fin', 'Durée', 'Allumage', 'Combustion', 'Post-comb.', 'Arrêt avant', 'kg', 'T ext', 'T chaud. début→max', 'Ventil. moy.', 'Type'],
                $cycles->map(fn ($c) => [
                    substr($c->started_at, 0, 16), substr((string) $c->ended_at, 11, 5), $c->duration_min.' min', $c->ignition_min.' min',
                    $c->combustion_min.' min', $c->runon_min.' min', $c->off_before_min !== null ? $c->off_before_min.' min' : '—',
                    $this->num($c->pellets_kg), $this->num($c->outside_temp_avg), $this->num($c->boiler_temp_start).' → '.$this->num($c->boiler_temp_max),
                    $this->num($c->fan_avg), implode(', ', array_filter([$c->cold_start ? 'à froid' : 'à chaud', $c->heating ? 'chauffage' : null, $c->dhw ? 'ECS' : null, $c->complete ? null : 'incomplet'])),
                ])->all());
        }
    }

    private function signatureSection(): void
    {
        $m = $this->signature->model();
        if (! $m) {
            return;
        }
        $this->h2('Signature énergétique (2 dernières années)');
        $this->line(sprintf('Médiane des kg/jour par °C de T ext moyenne journalière (R² %s sur %d jours). Par temps froid, +%s kg/jour par °C en moins, soit des déperditions ≈ %d W/K. Pas de chauffage au-dessus de ~%s °C.',
            $this->num($m['r2'], 2), $m['days'], $this->num($m['slope_kg_per_k'], 2), $m['loss_w_per_k'], $this->num($m['t0'], 0)));
        $this->line();
        $kw = $this->powerKw();
        $pmin = $kw !== null ? $kw * (float) settings('equipment.min_modulation_pct', 30) / 100 : null;
        $rows = [];
        foreach ($m['table'] as $t => $kg) {
            if ((float) $t > 20) {
                continue;
            }
            $kw = $this->signature->loadKw((float) $t);
            $rows[] = [$t.' °C', $this->num($kg), $this->num($kw), $pmin === null ? '?' : ($kw !== null && $kw < $pmin ? 'oui' : 'non')];
        }
        $this->table(['T ext moyenne', 'kg/jour', 'Besoin moyen (kW utiles)', 'Sous la puissance mini ('.($pmin === null ? 'inconnue' : '~'.$this->num($pmin).' kW').') ?'], $rows);
    }

    private function seasons(): void
    {
        $rows = DB::table('daily_stats')
            ->selectRaw('IF(MONTH(day) >= 9, YEAR(day), YEAR(day) - 1) as season, COUNT(*) n, SUM(pellets_kg) kg, SUM(pellets_kg_dhw) dhw, SUM(dju) dju, SUM(cycles) cycles, SUM(cold_starts) cold, AVG(outside_avg) t')
            ->groupBy('season')->orderByDesc('season')->limit(6)->get();
        $this->h2('Saisons de chauffe (septembre → août)');
        $this->table(['Saison', 'Jours mesurés', 'kg', 'dont ECS', 'DJU', 'kg chauffage / DJU', 'Cycles', 'À froid', 'T ext moy.'], $rows->map(fn ($r) => [
            $r->season.'-'.($r->season + 1), (string) $r->n, $this->num($r->kg, 0), $this->num($r->dhw, 0), $this->num($r->dju, 0),
            $r->dju > 0 ? $this->num(($r->kg - $r->dhw) / $r->dju, 2) : '—', (string) $r->cycles, (string) $r->cold, $this->num($r->t),
        ])->all());
        $this->line('Les saisons avec moins de 365 jours mesurés sont incomplètes.');
        $this->line();
    }

    private function stockSection(): void
    {
        $s = $this->stock->silo();
        $a = $this->stock->ashBox();
        $this->h2('Silo, cendrier et entretien');
        $this->line(sprintf('Silo : ~%s kg estimés (%s %% de %s kg), moyenne récente %s kg/jour, épuisement prévu %s. Cendrier : %s kg de granulés brûlés depuis le vidage du %s (%s %% de la capacité estimée), plein vers le %s.',
            $this->num($s['level_kg'], 0), $this->num($s['pct'], 0), $this->num($s['capacity_kg'], 0), $this->num($s['daily_avg_kg']),
            $s['empty_on'] ? 'le '.$s['empty_on'] : 'non estimé', $this->num($a['burnt_kg'], 0), $a['since'] ?? '?', $this->num($a['pct'], 0), $a['full_on'] ?? '?'));
        $this->line();
        $this->table(['Date', 'Événement', 'Quantité', 'Reste/niveau', 'Prix'], StockEvent::query()->latest('occurred_on')->limit(12)->get()->map(fn ($e) => [
            $e->occurred_on->format('Y-m-d'), $e->type->getLabel(), $e->quantity_kg ? $e->quantity_kg.' kg' : '', $e->remaining_kg !== null ? $e->remaining_kg.' kg' : '', $e->price ? $this->num($e->price, 0).' €' : '',
        ])->all());
    }

    private function recommendations(): void
    {
        try {
            $params = array_map(fn ($n) => ($this->values[$n] ?? null)?->value(), Advisor::P);
            $result = $this->advisor->analyse($params, $this->boiler->currentCurve());
        } catch (\Throwable) {
            return;
        }
        $this->h2('Recommandations calculées par Okovision');
        $this->line('Période d\'analyse : '.$result['period']['from'].' → '.$result['period']['to'].'. À considérer comme point de départ, pas comme vérité.');
        $this->line();
        foreach ($result['recommendations'] as $r) {
            $this->line("- **{$r['title']}** ({$r['severity']}, {$r['level']}) : ".$this->oneLine($r['summary']));
            foreach ($r['changes'] as $n => $v) {
                $this->line("  - `{$n}` → {$v}");
            }
        }
        $this->line();
    }

    private function snapshots(): void
    {
        $rows = BoilerProfile::query()->automatic()->latest('id')->limit(15)->get();
        if ($rows->isEmpty()) {
            return;
        }
        $this->h2('Historique des réglages (relevés automatiques)');
        $this->line('Un relevé n\'est enregistré que lorsqu\'au moins un réglage a changé (modification depuis l\'application ou directement sur la chaudière).');
        $this->line();
        $this->table(['Date', 'Changements'], $rows->map(fn ($p) => [$p->created_at->format('Y-m-d H:i'), $this->oneLine((string) $p->description)])->all());
    }

    private function changes(): void
    {
        $changes = BoilerParameterChange::query()->latest()->limit(20)->get();
        if ($changes->isEmpty()) {
            return;
        }
        $this->h2('Dernières modifications de réglages');
        $this->table(['Date', 'Variable', 'Avant', 'Après', 'Origine', 'Statut'], $changes->map(fn ($c) => [
            $c->created_at->format('Y-m-d H:i'), '`'.$c->name.'`', (string) $c->old_value, $c->new_value, $c->source, $c->status,
        ])->all());
    }

    private function measurementsDictionary(): void
    {
        $this->h2('Dictionnaire des mesures (fichiers CSV minute par minute)');
        $rows = [];
        foreach ($this->catalog->measurements() as $col => $m) {
            $rows[] = ['`'.$col.'`', $m['csv'], $this->catalog->label($m), $m['unit'] ?? ($m['type'] === 'bool' ? '0/1' : ''), $this->oneLine($m['description'] ?? '')];
        }
        $this->table(['Colonne', 'Entête CSV', 'Libellé', 'Unité', 'Signification'], $rows);
        $status = $this->catalog->measurement('pe1_status')['options']['fr'] ?? [];
        $this->line('Codes d\'état brûleur (`pe1_status`) : '.implode(', ', array_map(fn ($k, $v) => "{$k} = {$v}", array_keys($status), $status)).'.');
        $this->line('Taux d\'alimentation de la vis = pe1_auger_run / (pe1_auger_run + pe1_auger_pause) ; consommation par minute = taux × débit calibré.');
        $this->line();
    }

    private function recentSamples(int $step): void
    {
        $cols = ['outside_temp', 'boiler_temp', 'boiler_temp_set', 'hc1_flow_temp', 'hc1_flow_set', 'hc1_room_temp', 'hc1_room_set', 'hc1_pump',
            'dhw1_temp_on', 'dhw1_set', 'dhw1_pump', 'pe1_flame_temp', 'pe1_fan', 'pe1_auger_run', 'pe1_auger_pause', 'pe1_status'];
        $last = DB::table('measurements')->max('ts');
        if (! $last) {
            return;
        }
        $from = CarbonImmutable::parse($last)->subDay();
        $rows = DB::table('measurements')->where('ts', '>', $from)->whereRaw('MINUTE(ts) % ? = 0', [$step])->orderBy('ts')->get(array_merge(['ts'], $cols));

        $this->h2("Mesures des dernières 24 h (une ligne toutes les {$step} min)");
        $this->line('Valeurs instantanées échantillonnées : les cycles courts peuvent passer entre deux lignes, voir le détail des cycles plus haut.');
        $this->line();
        $this->table(array_merge(['Heure'], $cols), $rows->map(function ($r) use ($cols) {
            $row = [substr($r->ts, 5, 11)];
            foreach ($cols as $c) {
                $row[] = $c === 'pe1_status' ? $this->catalog->statusLabel((int) $r->{$c}) : ($this->num($r->{$c}) ?? '');
            }

            return $row;
        })->all());
    }

    // ------------------------------------------------------------------ utilitaires

    private function loadValues(bool $expert): void
    {
        try {
            $names = $this->catalog->parameters()->filter(fn ($p) => $expert || $p['level'] === 'basic')->keys()
                ->merge(['CAPPL:LOCAL.touch[0].version'])->all();
            $this->values = $this->boiler->read($names);
        } catch (\Throwable $e) {
            $this->boilerError = $e->getMessage();
        }
    }

    private function powerKw(): ?float
    {
        $v = $this->v(BoilerService::POWER);
        if (is_numeric($v)) {
            return (float) $v;
        }
        $known = app(ParameterSnapshot::class)->lastKnown(BoilerService::POWER);

        return is_numeric($known['value'] ?? null) ? (float) $known['value'] : null;
    }

    private function v(string $name): mixed
    {
        $v = $this->values[$name] ?? null;

        return $v?->ok ? $v->value() : null;
    }

    private function h2(string $t): void
    {
        $this->line("## {$t}");
        $this->line();
    }

    private function h3(string $t): void
    {
        $this->line("### {$t}");
        $this->line();
    }

    private function line(string $s = ''): void
    {
        $this->out[] = $s;
    }

    private function table(array $head, array $rows): void
    {
        if (! $rows) {
            $this->line('_(aucune donnée)_');
            $this->line();

            return;
        }
        $esc = fn ($v) => str_replace(['|', "\n"], ['\|', ' '], (string) $v);
        $this->line('| '.implode(' | ', array_map($esc, $head)).' |');
        $this->line('|'.str_repeat(' --- |', count($head)));
        foreach ($rows as $r) {
            $this->line('| '.implode(' | ', array_map($esc, $r)).' |');
        }
        $this->line();
    }

    private function num(mixed $v, int $d = 1): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return (string) $v;
        }
        $f = round((float) $v, $d);

        return rtrim(rtrim(number_format($f, $d, '.', ''), '0'), '.') ?: '0';
    }

    private function fmt(mixed $v, int $d = 1): string
    {
        return $this->num($v, $d) ?? '?';
    }

    private function oneLine(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
