<?php

namespace App\Charts;

use App\Analysis\DayAggregator;
use App\Analysis\EnergySignature;
use App\Analysis\HeatingCurve;
use App\Analysis\HotWater;
use App\Analysis\PelletCalibration;
use App\Archive\MeasurementArchiver;
use App\Okofen\Catalog;
use App\Okofen\SettingsHistory;
use App\Okofen\TimeProgram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Options ECharts construites côté serveur (rendues par resources/js/filament/echart.js).
 * Les chaînes « @xxx » sont des formateurs JavaScript nommés.
 */
class ChartFactory
{
    public const COLORS = [
        'outside' => '#0ea5e9', 'boiler' => '#ef4444', 'flow' => '#f59e0b', 'flow_set' => '#fbbf24',
        'room' => '#10b981', 'room_set' => '#6ee7b7', 'dhw' => '#8b5cf6', 'flame' => '#f97316',
        'fan' => '#64748b', 'auger' => '#a16207', 'flue' => '#94a3b8', 'heating' => '#f59e0b', 'dhw_kg' => '#8b5cf6',
    ];

    /** Couleurs des phases du brûleur */
    public const STATUS_COLORS = [
        0 => '#16a34a', 1 => '#38bdf8', 2 => '#ef4444', 3 => '#fb923c', 4 => '#f59e0b', 5 => '#a8a29e',
        7 => '#60a5fa', 8 => '#7f1d1d', 9 => '#7f1d1d', 11 => '#7f1d1d',
    ];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly MeasurementArchiver $archiver,
        private readonly EnergySignature $signature,
    ) {}

    // ------------------------------------------------------------------ Journée

    /** Mesures d'une journée (base, ou archive si purgée). */
    public function dayRows(string $date): array
    {
        $rows = DB::table('measurements')
            ->where('ts', '>=', $date.' 00:00:00')->where('ts', '<=', $date.' 23:59:59')
            ->orderBy('ts')->get()->all();

        return $rows ?: $this->archiver->readDay($date);
    }

    /**
     * @return array{temperatures: array, combustion: array, states: array, empty: bool}
     */
    public function day(string $date, ?array $rows = null): array
    {
        $rows ??= $this->dayRows($date);
        $s = fn (string $col, ?callable $map = null) => array_map(
            fn ($r) => [$r->ts, $r->{$col} === null ? null : ($map ? $map($r) : (float) $r->{$col})], $rows);
        $setOrNull = fn (string $col) => fn ($r) => (float) $r->{$col} <= 8 ? null : (float) $r->{$col};

        $cycles = DB::table('burner_cycles')->where('day', $date)->orderBy('started_at')->get();
        $areas = $cycles->map(fn ($c) => [
            ['xAxis' => $c->started_at, 'itemStyle' => ['color' => $c->cold_start ? 'rgba(239,68,68,.08)' : 'rgba(245,158,11,.08)']],
            ['xAxis' => $c->ended_at],
        ])->values()->all();

        $xAxis = ['type' => 'time', 'min' => $date.' 00:00:00', 'max' => $date.' 23:59:59', 'axisLabel' => ['formatter' => '@hour']];
        // Mêmes composants de zoom (mêmes id) sur les graphiques d'une journée : echarts.connect les synchronise
        $zoom = self::dayZoom();
        $tooltip = ['trigger' => 'axis', 'valueFormatter' => '@num', 'axisPointer' => ['type' => 'cross', 'label' => ['precision' => 1]]];
        $line = fn (string $name, array $data, string $color, array $extra = []) => array_merge([
            'name' => $name, 'type' => 'line', 'data' => $data, 'showSymbol' => false, 'sampling' => 'lttb',
            'lineStyle' => ['width' => 1.6, 'color' => $color], 'itemStyle' => ['color' => $color], 'connectNulls' => false,
        ], $extra);

        $temperatures = [
            // deux rangées : eau (chaudière, départ, ECS) puis maison (extérieur, pièce)
            'legend' => [
                ['top' => 0, 'left' => 110, 'data' => ['T chaudière', 'T départ', 'Consigne départ', 'T ECS']],
                ['top' => 22, 'left' => 110, 'data' => ['T extérieure', 'T ambiante', 'Consigne ambiante']],
            ],
            'grid' => ['left' => 110, 'right' => 150, 'top' => 70, 'bottom' => 56],
            'tooltip' => $tooltip,
            'xAxis' => $xAxis,
            'yAxis' => [
                ['type' => 'value', 'name' => '°C', 'scale' => true],
                // axe de droite écarté de la grille : place pour les noms en bout de courbe
                ['type' => 'value', 'name' => 'Ext. / ambiance °C', 'scale' => true, 'splitLine' => ['show' => false], 'minInterval' => 0.5, 'offset' => 86],
            ],
            'dataZoom' => $zoom,
            'series' => [
                $line('T chaudière', $s('boiler_temp'), self::COLORS['boiler'], ['markArea' => ['silent' => true, 'data' => $areas]] + self::tag('chaudière', self::COLORS['boiler'])),
                $line('T départ', $s('hc1_flow_temp'), self::COLORS['flow'], self::tag('départ', self::COLORS['flow'])),
                $line('Consigne départ', $s('hc1_flow_set', $setOrNull('hc1_flow_set')), self::COLORS['flow_set'], ['lineStyle' => ['type' => 'dashed', 'width' => 1.2, 'color' => self::COLORS['flow_set']]]),
                $line('T ECS', $s('dhw1_temp_on'), self::COLORS['dhw'], self::tag('ECS', self::COLORS['dhw'])),
                $line('T extérieure', $s('outside_temp'), self::COLORS['outside'], ['yAxisIndex' => 1] + self::tag('extérieur', self::COLORS['outside'])),
                $line('T ambiante', $s('hc1_room_temp'), self::COLORS['room'], ['yAxisIndex' => 1, 'lineStyle' => ['width' => 2.2, 'color' => self::COLORS['room']]] + self::tag('pièce', self::COLORS['room'])),
                $line('Consigne ambiante', $s('hc1_room_set', $setOrNull('hc1_room_set')), self::COLORS['room_set'], ['yAxisIndex' => 1, 'step' => 'end', 'lineStyle' => ['type' => 'dashed', 'color' => self::COLORS['room_set']]]),
            ],
        ];

        $combustion = [
            'legend' => ['top' => 0, 'left' => 110],
            'grid' => ['left' => 110, 'right' => 150, 'top' => 52, 'bottom' => 56],
            'tooltip' => $tooltip,
            'xAxis' => $xAxis,
            'yAxis' => [
                ['type' => 'value', 'name' => 'T flamme °C', 'min' => 0],
                ['type' => 'value', 'name' => '%', 'min' => 0, 'max' => 100, 'splitLine' => ['show' => false], 'offset' => 86],
            ],
            'dataZoom' => $zoom,
            'series' => [
                $line('T flamme', $s('pe1_flame_temp'), self::COLORS['flame'], ['areaStyle' => ['opacity' => 0.12, 'color' => self::COLORS['flame']]] + self::tag('flamme', self::COLORS['flame'])),
                $line('Consigne flamme', $s('pe1_flame_set', $setOrNull('pe1_flame_set')), '#fdba74', ['lineStyle' => ['type' => 'dashed', 'color' => '#fdba74']]),
                $line('Ventilateur combustion', $s('pe1_fan'), self::COLORS['fan'], ['yAxisIndex' => 1] + self::tag('ventilateur', self::COLORS['fan'])),
                $line('Extracteur fumées', $s('pe1_flue_fan'), self::COLORS['flue'], ['yAxisIndex' => 1, 'lineStyle' => ['width' => 1, 'type' => 'dotted', 'color' => self::COLORS['flue']]] + self::tag('extracteur', self::COLORS['flue'])),
                $line('Alimentation vis', $s('pe1_auger_run', fn ($r) => round(DayAggregator::augerRatio($r) * 100, 1)), self::COLORS['auger'], ['yAxisIndex' => 1, 'step' => 'end'] + self::tag('vis', self::COLORS['auger'])),
            ],
        ];

        return [
            'temperatures' => $temperatures,
            'combustion' => $combustion,
            'states' => $this->states($rows, $date),
            'empty' => $rows === [],
        ];
    }

    /**
     * Nom de la courbe au bout de la ligne, dans sa couleur ; les étiquettes qui se chevauchent sont décalées.
     * Prévoir la place à droite de la grille (et décaler un éventuel axe de droite avec « offset »).
     */
    public static function tag(string $text, string $color): array
    {
        return ['endLabel' => ['show' => true, 'formatter' => $text, 'color' => $color, 'fontSize' => 11, 'distance' => 6],
            'labelLayout' => ['moveOverlap' => 'shiftY']];
    }

    /** Zoom horizontal commun aux graphiques d'une journée (molette + réglette). */
    public static function dayZoom(bool $showSlider = true): array
    {
        return [
            ['id' => 'day-inside', 'type' => 'inside', 'xAxisIndex' => 0, 'filterMode' => 'weakFilter'],
            ['id' => 'day-slider', 'type' => 'slider', 'xAxisIndex' => 0, 'height' => 18, 'bottom' => 4, 'show' => $showSlider, 'filterMode' => 'weakFilter'],
        ];
    }

    /** Frise des états : brûleur (par phase), allumeur, pompe chauffage, pompe ECS. */
    private function states(array $rows, string $date): array
    {
        $lanes = ['Brûleur', 'Allumeur', 'Pompe chauffage', 'Pompe ECS'];
        $data = [];
        $segment = function (int $lane, callable $key, callable $label, callable $color) use ($rows, &$data) {
            $cur = null;
            $start = null;
            $last = null;
            foreach ($rows as $r) {
                $k = $key($r);
                if ($k !== $cur) {
                    if ($cur !== null && $cur !== false) {
                        $data[] = ['value' => [$lane, $start, $r->ts, $label($cur)], 'itemStyle' => ['color' => $color($cur)]];
                    }
                    $cur = $k;
                    $start = $r->ts;
                }
                $last = $r->ts;
            }
            if ($cur !== null && $cur !== false && $last) {
                $data[] = ['value' => [$lane, $start, $last, $label($cur)], 'itemStyle' => ['color' => $color($cur)]];
            }
        };

        $segment(0,
            fn ($r) => $r->pe1_status !== null && in_array((int) $r->pe1_status, [0, 1, 2, 3, 4, 5, 7, 8, 9, 11], true) ? (int) $r->pe1_status : false,
            fn ($k) => $this->catalog->statusLabel($k),
            fn ($k) => self::STATUS_COLORS[$k] ?? '#999');
        $segment(1, fn ($r) => (int) $r->pe1_motor_ignition === 1 ? 1 : false, fn () => 'Allumeur', fn () => '#ef4444');
        $segment(2, fn ($r) => (int) $r->hc1_pump === 1 ? 1 : false, fn () => 'Pompe chauffage', fn () => self::COLORS['heating']);
        $segment(3, fn ($r) => (int) $r->dhw1_pump === 1 ? 1 : false, fn () => 'Pompe ECS', fn () => self::COLORS['dhw']);

        return [
            'grid' => ['left' => 110, 'right' => 64, 'top' => 8, 'bottom' => 24],
            'tooltip' => ['formatter' => '@ganttTooltip'],
            'xAxis' => ['type' => 'time', 'min' => $date.' 00:00:00', 'max' => $date.' 23:59:59', 'axisLabel' => ['formatter' => '@hour']],
            'yAxis' => ['type' => 'category', 'data' => $lanes, 'inverse' => true, 'splitLine' => ['show' => false]],
            'dataZoom' => self::dayZoom(showSlider: false),
            'series' => [[
                'type' => 'custom', 'renderItem' => '@ganttRender', 'encode' => ['x' => [1, 2], 'y' => 0], 'data' => $data,
            ]],
        ];
    }

    // ------------------------------------------------------------------ Historique

    /**
     * Changements de réglages sur une période, fusionnés par jour. Sources : relevés automatiques (changements
     * faits depuis l'application ou sur la chaudière), écritures de l'application, profils de l'ancienne application.
     * Un même réglage modifié plusieurs fois dans la journée n'apparaît qu'une fois (première → dernière valeur).
     *
     * @return list<array{n: int, day: string, from: string, to: string, titles: list<string>, lines: list<array{label: string, old: string, new: string, reverted: bool}>, sources: list<string>}>
     */
    public function settingChanges(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $events = []; // [at, source, name|null, old, new, title|null]

        // Relevés automatiques : un événement par réglage modifié
        DB::table('boiler_profiles')->where('source', 'auto')->whereNotNull('changes')->whereBetween('created_at', $range)
            ->orderBy('created_at')->get(['created_at', 'changes'])
            ->each(function ($p) use (&$events) {
                foreach ((array) json_decode((string) $p->changes, true) as $name => $c) {
                    if (! ($this->catalog->parameter($name)['snapshot'] ?? true)) {
                        continue; // horloge de l'automate, commandes…
                    }
                    $events[] = [(string) $p->created_at, 'auto', $name, $c['old'] ?? null, $c['new'] ?? null, null];
                }
            });

        // Écritures faites depuis Okovision
        DB::table('boiler_parameter_changes')->where('status', 'applied')->whereBetween('created_at', $range)
            ->orderBy('created_at')->get()
            ->each(function ($c) use (&$events) {
                $events[] = [(string) $c->created_at, 'app', $c->name, $c->old_value, $c->new_value, null];
            });

        // Profils de l'ancienne application : titre + différences avec le profil précédent
        $previous = null;
        foreach (DB::table('boiler_profiles')->where('source', 'legacy')->orderBy('created_at')->get(['created_at', 'name', 'values']) as $p) {
            $values = (array) json_decode((string) $p->values, true);
            if ($p->created_at >= $range[0] && $p->created_at <= $range[1]) {
                $events[] = [(string) $p->created_at, 'legacy', null, null, null, trim(preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} – /', '', $p->name))];
                foreach ($previous ? $values : [] as $name => $v) {
                    if (array_key_exists($name, $previous) && (string) $previous[$name] !== (string) $v) {
                        $events[] = [(string) $p->created_at, 'legacy', $name, $previous[$name], $v, null];
                    }
                }
            }
            $previous = $values;
        }

        usort($events, fn ($a, $b) => strcmp($a[0], $b[0]));

        $days = [];
        foreach ($events as [$at, $source, $name, $old, $new, $title]) {
            $d = &$days[substr($at, 0, 10)];
            $d['from'] ??= $at;
            $d['to'] = $at;
            $d['sources'][$source] = true;
            if ($title !== null) {
                $d['titles'][] = $title;
            }
            if ($name !== null) {
                // première valeur d'origine, dernière valeur obtenue (relevé et écriture d'un même changement fusionnés)
                $d['params'][$name]['old'] ??= $old;
                $d['params'][$name]['new'] = $new;
            }
            unset($d);
        }
        ksort($days);

        $notes = [];
        foreach ($days as $day => $d) {
            $lines = [];
            foreach ($d['params'] ?? [] as $name => $c) {
                $p = $this->catalog->parameter($name);
                $lines[] = [
                    'label' => $p ? $this->catalog->label($p) : str_replace('CAPPL:', '', $name),
                    'old' => $this->formatValue($p, $c['old']),
                    'new' => $this->formatValue($p, $c['new']),
                    'reverted' => (string) $c['old'] === (string) $c['new'],
                ];
            }
            $notes[] = [
                'n' => count($notes) + 1,
                'day' => $day,
                'from' => $d['from'],
                'to' => $d['to'],
                'titles' => array_values(array_unique($d['titles'] ?? [])),
                'lines' => $lines,
                'sources' => array_keys($d['sources']),
            ];
        }

        return $notes;
    }

    private function formatValue(?array $param, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (isset($param['options'][$value])) {
            return $param['options'][$value];
        }
        $v = is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',') : (string) $value;

        return $v.(isset($param['unit']) ? ' '.$param['unit'] : '');
    }

    /** Ajoute des traits verticaux numérotés (changements de réglages) sur un graphique à axe temporel journalier. */
    public function withChangeMarkers(array $option, array $notes): array
    {
        if ($notes === [] || empty($option['series'])) {
            return $option;
        }

        $option['grid']['top'] = max(48, (int) ($option['grid']['top'] ?? 36) + 12);
        $option['series'][0]['markLine'] = [
            'symbol' => ['none', 'none'],
            'silent' => false,
            'animation' => false,
            'lineStyle' => ['color' => '#7c3aed', 'type' => 'dashed', 'width' => 1.5],
            'label' => ['show' => true, 'position' => 'end', 'formatter' => '{b}', 'color' => '#7c3aed', 'fontWeight' => 'bold'],
            'tooltip' => ['show' => false],
            'data' => array_map(fn ($n) => ['xAxis' => $n['day'].' 12:00:00', 'name' => '⚙ '.$n['n']], $notes),
        ];

        return $option;
    }

    /** Consommation journalière (chauffage / ECS) et température extérieure. */
    public function dailyConsumption(string $from, string $to): array
    {
        $rows = DB::table('daily_stats')->whereBetween('day', [$from, $to])->orderBy('day')->get();
        $day = fn ($r) => (string) $r->day;

        return [
            'legend' => ['top' => 0],
            'grid' => ['left' => 48, 'right' => 48, 'top' => 36, 'bottom' => 56],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@num'],
            'xAxis' => ['type' => 'time', 'min' => $from, 'max' => $to.' 23:59:59', 'axisLabel' => ['formatter' => '@day', 'hideOverlap' => true]],
            'yAxis' => [
                ['type' => 'value', 'name' => 'kg/jour', 'min' => 0],
                ['type' => 'value', 'name' => '°C', 'splitLine' => ['show' => false]],
            ],
            'dataZoom' => [['type' => 'inside'], ['type' => 'slider', 'height' => 18, 'bottom' => 4]],
            'series' => [
                ['name' => 'Chauffage', 'type' => 'bar', 'stack' => 'kg', 'itemStyle' => ['color' => self::COLORS['heating']],
                    'data' => $rows->map(fn ($r) => [$day($r), round($r->pellets_kg - $r->pellets_kg_dhw, 1)])->all()],
                ['name' => 'Eau chaude', 'type' => 'bar', 'stack' => 'kg', 'itemStyle' => ['color' => self::COLORS['dhw_kg']],
                    'data' => $rows->map(fn ($r) => [$day($r), round((float) $r->pellets_kg_dhw, 1)])->all()],
                ['name' => 'T ext moyenne', 'type' => 'line', 'yAxisIndex' => 1, 'showSymbol' => false, 'itemStyle' => ['color' => self::COLORS['outside']],
                    'data' => $rows->map(fn ($r) => [$day($r), $r->outside_avg === null ? null : (float) $r->outside_avg])->all()],
            ],
        ];
    }

    /** Cycles par jour, démarrages à froid et durée de combustion moyenne. */
    public function dailyCycles(string $from, string $to): array
    {
        $rows = DB::table('daily_stats')->whereBetween('day', [$from, $to])->orderBy('day')->get();
        $avg = DB::table('burner_cycles')->whereBetween('day', [$from, $to])->where('complete', true)
            ->selectRaw('day, AVG(combustion_min) as m')->groupBy('day')->pluck('m', 'day');

        return [
            'legend' => ['top' => 0],
            'grid' => ['left' => 48, 'right' => 48, 'top' => 36, 'bottom' => 56],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@num'],
            'xAxis' => ['type' => 'time', 'min' => $from, 'max' => $to.' 23:59:59', 'axisLabel' => ['formatter' => '@day', 'hideOverlap' => true]],
            'yAxis' => [
                ['type' => 'value', 'name' => 'cycles', 'min' => 0],
                ['type' => 'value', 'name' => 'min', 'min' => 0, 'splitLine' => ['show' => false]],
            ],
            'dataZoom' => [['type' => 'inside'], ['type' => 'slider', 'height' => 18, 'bottom' => 4]],
            'series' => [
                ['name' => 'Cycles', 'type' => 'bar', 'itemStyle' => ['color' => '#f59e0b'], 'data' => $rows->map(fn ($r) => [(string) $r->day, (int) $r->cycles])->all()],
                ['name' => 'Allumages à froid', 'type' => 'bar', 'itemStyle' => ['color' => '#ef4444'], 'data' => $rows->map(fn ($r) => [(string) $r->day, (int) $r->cold_starts])->all()],
                ['name' => 'Combustion moyenne par cycle', 'type' => 'line', 'yAxisIndex' => 1, 'showSymbol' => false, 'itemStyle' => ['color' => '#16a34a'],
                    'data' => $rows->map(fn ($r) => [(string) $r->day, isset($avg[$r->day]) ? round((float) $avg[$r->day], 1) : null])->all()],
            ],
        ];
    }

    // ------------------------------------------------------------------ Eau chaude

    /**
     * Ballon d'eau chaude sur une journée, avec les réglages en vigueur minute par minute (journal des modifications) :
     * - pendant les plages du programme ECS, la chaudière vise la consigne (≈ 65 °C le jour anti-légionelles) ;
     * - hors plages, elle ne vise que la température mini ;
     * - une charge démarre quand le ballon passe sous la cible moins l'hystérésis (seuil de relance).
     * La série « Seuil relevé » (dhw1_set, masquée par défaut) est ce seuil tel que la chaudière le rapporte :
     * cible − hystérésis au repos, cible pendant une charge.
     *
     * @param  array<int, list<array{0: int, 1: int}>>|null  $windows  programme actuel, en repli
     */
    public function dhwDay(string $date, SettingsHistory $history, ?array $windows): array
    {
        $rows = $this->dayRows($date);
        $day = CarbonImmutable::parse($date);
        $p = fn (string $k) => 'CAPPL:LOCAL.ww[0].'.$k;

        // Réglages minute par minute (seulement là où ils changent)
        $set = $min = $relaunch = [];
        $areas = [];
        $start = null;
        $last = null;
        $programs = [];
        for ($m = 0; $m <= 1440; $m++) {
            $ts = $day->addMinutes(min($m, 1439))->format('Y-m-d H:i:s');
            if ($m === 1440) {
                $in = false;
            } else {
                $active = (int) ($history->valueAt(TimeProgram::activeVariable('ww'), $ts) ?? 0);
                $summary = $history->valueAt($p("zeitprogramm[{$active}]"), $ts);
                $slots = ($programs[$summary ?? ''] ??= TimeProgram::parseSummary($summary)) ?? $windows;
                $minute = $m;
                $in = $slots === null || collect($slots[$day->dayOfWeek] ?? [])->contains(fn ($w) => $minute >= $w[0] && $minute < $w[1]);
                $legio = $history->valueAt($p('legionellen_wochentag'), $ts);
                $target = $in
                    ? ($legio !== null && (int) $legio === $day->dayOfWeek ? HotWater::LEGIONELLA_TEMP : (float) $history->valueAt($p('temp_heizen'), $ts))
                    : (float) $history->valueAt($p('temp_absenken'), $ts);
                $hyst = (float) $history->valueAt($p('hysterese'), $ts);
                $state = [$in, $target, $hyst];
                if ($state !== $last) {
                    $set[] = [$ts, $in ? $target : null];
                    $min[] = [$ts, $in ? null : $target];
                    $relaunch[] = [$ts, $target - $hyst];
                    $last = $state;
                }
            }
            if ($in && $start === null) {
                $start = $ts;
            } elseif (! $in && $start !== null) {
                $areas[] = [['xAxis' => $start, 'itemStyle' => ['color' => 'rgba(139,92,246,.10)']], ['xAxis' => $ts]];
                $start = null;
            }
        }
        $end = $date.' 23:59:59';
        foreach ([&$set, &$min, &$relaunch] as &$serie) {
            $serie[] = [$end, end($serie)[1]];
        }
        unset($serie);

        // Mesures, coupées sur les trous de plus de 20 min
        $tank = $measured = [];
        $prev = null;
        foreach ($rows as $r) {
            $t = strtotime($r->ts);
            if ($prev !== null && $t - $prev > 1200) {
                $tank[] = $measured[] = [date('Y-m-d H:i:s', $prev + 60), null];
            }
            $prev = $t;
            $tank[] = [$r->ts, $r->dhw1_temp_on === null ? null : (float) $r->dhw1_temp_on];
            $measured[] = [$r->ts, $r->dhw1_set === null ? null : (float) $r->dhw1_set];
        }

        $line = fn (string $name, array $data, string $color, array $extra = []) => array_merge([
            'name' => $name, 'type' => 'line', 'data' => $data, 'symbol' => 'none', 'connectNulls' => false,
            'lineStyle' => ['width' => 1.6, 'color' => $color], 'itemStyle' => ['color' => $color],
        ], $extra);
        $step = fn (string $color, string $type = 'dashed', float $width = 1.6) => ['step' => 'end', 'lineStyle' => ['type' => $type, 'width' => $width, 'color' => $color]];
        $tag = self::tag(...);

        return [
            'empty' => $rows === [],
            'option' => [
                'legend' => ['top' => 0, 'left' => 48, 'itemWidth' => 28, 'itemHeight' => 10, 'itemGap' => 14, 'selected' => ['Seuil relevé par la chaudière' => false]],
                'grid' => ['left' => 48, 'right' => 110, 'top' => 40, 'bottom' => 56],
                'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@temp', 'axisPointer' => ['type' => 'cross', 'label' => ['precision' => 1]]],
                'xAxis' => ['type' => 'time', 'min' => $date.' 00:00:00', 'max' => $end, 'axisLabel' => ['formatter' => '@hour']],
                'yAxis' => ['type' => 'value', 'name' => '°C', 'scale' => true],
                'dataZoom' => self::dayZoom(),
                'series' => [
                    $line('T ballon', $tank, self::COLORS['dhw'], [
                        'lineStyle' => ['width' => 2.4, 'color' => self::COLORS['dhw']],
                        'markArea' => ['silent' => true, 'data' => $areas],
                    ] + $tag('ballon', self::COLORS['dhw'])),
                    $line('Consigne', $set, '#ef4444', $step('#ef4444')),
                    $line('T mini (hors plages)', $min, '#0ea5e9', $step('#0ea5e9') + $tag('T mini', '#0ea5e9')),
                    $line('Seuil de relance (− hystérésis)', $relaunch, '#f59e0b', $step('#f59e0b', 'dotted') + $tag('relance', '#f59e0b')),
                    $line('Seuil relevé par la chaudière', $measured, '#94a3b8', $step('#94a3b8', 'solid', 1)),
                ],
            ],
        ];
    }

    /** Consommation mensuelle comparée par saison de chauffe (septembre → août). */
    public function seasonsMonthly(int $seasons = 5): array
    {
        $rows = DB::table('daily_stats')
            ->selectRaw('IF(MONTH(day) >= 9, YEAR(day), YEAR(day) - 1) as season, MONTH(day) as m, SUM(pellets_kg) as kg')
            ->groupBy('season', 'm')->orderBy('season')->get();
        $months = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8];
        $labels = ['Sep', 'Oct', 'Nov', 'Déc', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août'];
        $bySeason = $rows->groupBy('season')->sortKeys()->take(-$seasons);

        return [
            'legend' => ['top' => 0],
            'grid' => ['left' => 56, 'right' => 16, 'top' => 36, 'bottom' => 28],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@kg'],
            'xAxis' => ['type' => 'category', 'data' => $labels],
            'yAxis' => ['type' => 'value', 'name' => 'kg'],
            'series' => $bySeason->map(fn ($rows, $season) => [
                'name' => $season.'-'.($season + 1), 'type' => 'bar',
                'data' => array_map(fn ($m) => round((float) ($rows->firstWhere('m', $m)->kg ?? 0)), $months),
            ])->values()->all(),
        ];
    }

    /** Tableau de synthèse par saison. */
    public function seasonsTable(): array
    {
        $price = (float) settings('pellets.price_per_ton', 450);

        return DB::table('daily_stats')
            ->selectRaw('IF(MONTH(day) >= 9, YEAR(day), YEAR(day) - 1) as season, COUNT(*) as days, SUM(pellets_kg) as kg,
                SUM(pellets_kg_dhw) as kg_dhw, SUM(dju) as dju, SUM(cycles) as cycles, SUM(cold_starts) as cold, AVG(outside_avg) as t,
                SUM(combustion_min) as comb')
            ->groupBy('season')->orderByDesc('season')->get()
            ->map(fn ($r) => [
                'season' => $r->season.'-'.($r->season + 1),
                'days' => (int) $r->days,
                'kg' => round((float) $r->kg),
                'kg_dhw' => round((float) $r->kg_dhw),
                'cost' => round((float) $r->kg * $price / 1000),
                'dju' => round((float) $r->dju),
                'kg_per_dju' => $r->dju > 0 ? round(((float) $r->kg - (float) $r->kg_dhw) / (float) $r->dju, 2) : null,
                'cycles' => (int) $r->cycles,
                'cold' => (int) $r->cold,
                'kg_per_cycle' => $r->cycles > 0 ? round((float) $r->kg / $r->cycles, 2) : null,
                't' => $r->t !== null ? round((float) $r->t, 1) : null,
            ])->all();
    }

    /** Signature énergétique : nuage kg/jour vs T ext, une couleur par saison, et le modèle. */
    public function signature(int $seasons = 3): array
    {
        $series = [];
        $last = now()->month >= 9 ? now()->year : now()->year - 1;
        for ($s = $last - $seasons + 1; $s <= $last; $s++) {
            $series[] = [
                'name' => $s.'-'.($s + 1), 'type' => 'scatter', 'symbolSize' => 5,
                'data' => $this->signature->points($s.'-09-01', ($s + 1).'-08-31'),
            ];
        }
        $model = $this->signature->model();
        if ($model) {
            $line = [];
            for ($t = -5; $t <= 25; $t++) {
                $line[] = [$t, round($this->signature->predictDailyKg($t), 1)];
            }
            $series[] = ['name' => 'Modèle', 'type' => 'line', 'data' => $line, 'showSymbol' => false,
                'lineStyle' => ['width' => 2.5, 'color' => '#ef4444'], 'itemStyle' => ['color' => '#ef4444'], 'tooltip' => ['show' => false]];
        }

        return [
            'legend' => ['top' => 0],
            'grid' => ['left' => 48, 'right' => 16, 'top' => 56, 'bottom' => 40],
            'tooltip' => ['trigger' => 'item', 'formatter' => '@signatureTooltip'],
            'xAxis' => ['type' => 'value', 'name' => 'T ext moyenne (°C)', 'nameLocation' => 'middle', 'nameGap' => 26],
            'yAxis' => ['type' => 'value', 'name' => 'kg/jour', 'min' => 0],
            'series' => $series,
        ];
    }

    /** Distribution des durées de combustion par cycle, selon la T extérieure. */
    public function cycleHistogram(string $from, string $to): array
    {
        $bins = [0, 10, 20, 30, 45, 60, 90, 120, 180, 240, 10000];
        $labels = ['<10', '10–20', '20–30', '30–45', '45–60', '60–90', '90–120', '2–3 h', '3–4 h', '>4 h'];
        $classes = ['< 5 °C' => [-99, 5], '5–10 °C' => [5, 10], '10–15 °C' => [10, 15], '> 15 °C' => [15, 99]];
        $cycles = DB::table('burner_cycles')->whereBetween('day', [$from, $to])->where('complete', true)
            ->get(['combustion_min', 'outside_temp_avg']);

        $series = [];
        foreach ($classes as $name => [$lo, $hi]) {
            $counts = array_fill(0, count($labels), 0);
            foreach ($cycles as $c) {
                if ($c->outside_temp_avg === null || $c->outside_temp_avg < $lo || $c->outside_temp_avg >= $hi) {
                    continue;
                }
                for ($i = 0; $i < count($labels); $i++) {
                    if ($c->combustion_min >= $bins[$i] && $c->combustion_min < $bins[$i + 1]) {
                        $counts[$i]++;
                        break;
                    }
                }
            }
            $series[] = ['name' => $name, 'type' => 'bar', 'stack' => 'n', 'data' => $counts];
        }

        return [
            'legend' => ['top' => 0],
            'color' => ['#1d4ed8', '#0ea5e9', '#f59e0b', '#ef4444'],
            'grid' => ['left' => 48, 'right' => 16, 'top' => 36, 'bottom' => 40],
            'tooltip' => ['trigger' => 'axis'],
            'xAxis' => ['type' => 'category', 'data' => $labels, 'name' => 'minutes de combustion par cycle', 'nameLocation' => 'middle', 'nameGap' => 26],
            'yAxis' => ['type' => 'value', 'name' => 'cycles'],
            'series' => $series,
        ];
    }

    // ------------------------------------------------------------------ Loi d'eau

    /**
     * Loi d'eau : courbes actuelle / modifiée (confort et réduit), consignes réellement calculées (mesures) et moyenne par
     * °C extérieur. Chaque série porte « oko » : les réglages à mettre en exergue au survol.
     */
    public function curve(HeatingCurve $current, ?HeatingCurve $edited, array $scatter, array $buckets): array
    {
        $comfortKeys = ['slope', 'foot', 'room_comfort', 'flow_min', 'flow_max'];
        $reducedKeys = ['slope', 'foot', 'room_reduced', 'flow_min', 'flow_max'];
        $line = fn (string $id, string $name, array $data, array $style, array $oko, int $z) => [
            'id' => $id, 'name' => $name, 'type' => 'line', 'data' => $data, 'showSymbol' => false, 'triggerEvent' => true,
            'lineStyle' => $style, 'itemStyle' => ['color' => $style['color']], 'z' => $z, 'oko' => $oko,
        ];
        $limits = fn (HeatingCurve $c, string $color) => ['silent' => false, 'symbol' => 'none', 'data' => [
            ['xAxis' => $c->limitComfort, 'oko' => ['limit_comfort'], 'lineStyle' => ['color' => $color, 'type' => 'dashed'],
                'label' => ['formatter' => 'limite confort', 'position' => 'insideEndTop', 'color' => $color]],
            ['xAxis' => $c->limitReduced, 'oko' => ['limit_reduced'], 'lineStyle' => ['color' => $color, 'type' => 'dotted'],
                'label' => ['formatter' => 'limite réduit', 'position' => 'insideEndBottom', 'color' => $color]],
        ]];

        $series = [
            $line('current', 'Courbe actuelle (confort)', $current->series(), ['width' => 2.5, 'color' => '#64748b'], $comfortKeys, 3)
                + ['markLine' => $limits($edited ?? $current, '#94a3b8')],
            $line('current_reduced', 'Courbe actuelle (réduit)', $current->series(true), ['width' => 1.5, 'type' => 'dotted', 'color' => '#64748b'], $reducedKeys, 3),
        ];
        if ($edited) {
            $series[] = $line('edited', 'Nouvelle courbe (confort)', $edited->series(), ['width' => 3, 'color' => '#f97316'], $comfortKeys, 4);
            $series[] = $line('edited_reduced', 'Nouvelle courbe (réduit)', $edited->series(true), ['width' => 1.5, 'type' => 'dotted', 'color' => '#f97316'], $reducedKeys, 4);
        }
        if ($scatter) {
            $series[] = ['id' => 'measured', 'name' => 'Consignes calculées (mesures)', 'type' => 'scatter', 'data' => $scatter, 'symbolSize' => 3,
                'encode' => ['x' => 0, 'y' => 1], 'tooltip' => ['formatter' => '@curveTooltip'], 'z' => 1, 'oko' => ['influence']];
        }
        if ($buckets) {
            $series[] = ['id' => 'learned', 'name' => 'Moyenne par °C extérieur', 'type' => 'line', 'symbolSize' => 7,
                'data' => array_map(fn ($b) => [$b['t'], $b['flow_set']], $buckets),
                'lineStyle' => ['width' => 0], 'itemStyle' => ['color' => '#0ea5e9'], 'z' => 2, 'oko' => ['slope', 'foot', 'influence']];
        }

        return [
            'legend' => ['top' => 0, 'type' => 'scroll'],
            'grid' => ['left' => 64, 'right' => 24, 'top' => 64, 'bottom' => 48],
            'tooltip' => ['trigger' => 'axis', 'formatter' => '@curveAxisTooltip', 'axisPointer' => ['type' => 'cross']],
            'xAxis' => ['type' => 'value', 'name' => 'Température extérieure (°C)', 'nameLocation' => 'middle', 'nameGap' => 28,
                'min' => -15, 'max' => 20, 'inverse' => true, 'interval' => 5],
            'yAxis' => ['type' => 'value', 'name' => 'Température de l\'eau dans les radiateurs (°C)', 'nameLocation' => 'middle', 'nameGap' => 40,
                'nameRotate' => 90, 'min' => 15, 'max' => 85],
            // Dégradé de couleur des points : écart ambiance (rouge = trop chaud, bleu = trop froid)
            'visualMap' => $scatter ? [[
                'seriesId' => 'measured', 'dimension' => 3, 'min' => -1.5, 'max' => 1.5, 'show' => true, 'orient' => 'horizontal',
                'right' => 32, 'top' => 72, 'itemWidth' => 10, 'itemHeight' => 80, 'text' => ['trop chaud', 'trop froid'],
                'inRange' => ['color' => ['#2563eb', '#a3e635', '#dc2626']], 'calculable' => false,
            ]] : null,
            'series' => $series,
        ];
    }

    /**
     * Réglages en jeu selon la T extérieure survolée sur la loi d'eau : au froid la pente, à mi-saison le pied ;
     * départ borné par le mini / maxi ; au-delà de la limite de chauffe, plus de chauffage.
     *
     * @return list<array{0: float, 1: list<string>}>
     */
    public function curveHover(HeatingCurve $c): array
    {
        $points = [];
        for ($t = -15; $t <= 20; $t++) {
            $raw = $c->rawFlow($t);
            $keys = match (true) {
                $t > $c->limitComfort => ['limit_comfort'],
                $raw >= $c->flowMax => ['flow_max', 'slope'],
                $raw <= $c->flowMin => ['flow_min', 'foot'],
                $t < 5 => ['slope', 'room_comfort'],
                default => ['foot', 'room_comfort'],
            };
            if ($t > $c->limitReduced && $t <= $c->limitComfort) {
                $keys[] = 'limit_reduced';
            }
            $points[] = [(float) $t, $keys];
        }

        return $points;
    }

    /**
     * Chauffage sur une journée, avec les réglages en vigueur minute par minute (journal des modifications) :
     * T extérieure (mesurée et moyennée) face aux limites de chauffe et à leur seuil de relance (− 2 °C) ;
     * T ambiante face à la consigne et au tunnel de l'hystérésis d'ambiance (arrêt au-dessus de consigne + hyst.,
     * relance sous ce seuil − 1 °C) ; plages confort et marche du circulateur.
     *
     * @param  array<int, list<array{0: int, 1: int}>>|null  $windows  programme actuel, en repli
     */
    public function heatingDay(string $date, SettingsHistory $history, ?array $windows): array
    {
        $rows = $this->dayRows($date);
        $day = CarbonImmutable::parse($date);
        $p = fn (string $k) => 'CAPPL:LOCAL.'.$k;
        $end = $date.' 23:59:59';

        // Réglages minute par minute (points seulement aux changements)
        $set = $stop = $restart = $band = $limit = $limitRestart = [];
        $areas = [];
        $start = null;
        $last = null;
        $programs = [];
        $avgMinutes = 60;
        // consigne relevée par la chaudière (> 8 °C quand le circuit tourne) : elle prime sur la reconstitution
        $measuredSet = [];
        $seen = [];
        foreach ($rows as $r) {
            if ($r->hc1_room_set !== null && (float) $r->hc1_room_set > 8) {
                $measuredSet[(int) substr($r->ts, 11, 2) * 60 + (int) substr($r->ts, 14, 2)] = (float) $r->hc1_room_set;
            }
        }
        for ($m = 0; $m <= 1440; $m++) {
            $ts = $day->addMinutes(min($m, 1439))->format('Y-m-d H:i:s');
            $in = false;
            if ($m < 1440) {
                $active = (int) ($history->valueAt(TimeProgram::activeVariable('hk'), $ts) ?? 0);
                $summary = $history->valueAt($p("hk[0].zeitprogramm[{$active}]"), $ts);
                $slots = ($programs[$summary ?? ''] ??= TimeProgram::parseSummary($summary)) ?? $windows;
                $minute = $m;
                $in = $slots === null || collect($slots[$day->dayOfWeek] ?? [])->contains(fn ($w) => $minute >= $w[0] && $minute < $w[1]);
                // consigne : relevée si le circuit tourne ; sinon la dernière relevée dans la même phase si elle est plus
                // récente que la dernière observation du réglage (changement fait sur la chaudière), sinon l'historique
                $setName = $p($in ? 'hk[0].raumtemp_heizen' : 'hk[0].raumtemp_absenken');
                if (isset($measuredSet[$m])) {
                    $seen[$in ? 1 : 0] = [$ts, $measuredSet[$m]];
                    $target = $measuredSet[$m];
                } elseif (isset($seen[$in ? 1 : 0]) && $seen[$in ? 1 : 0][0] > ($history->observedAt($setName, $ts) ?? '')) {
                    $target = $seen[$in ? 1 : 0][1];
                } else {
                    $target = (float) $history->valueAt($setName, $ts);
                }
                $hyst = (float) $history->valueAt($p('hk[0].raumtemp_plus'), $ts);
                $lim = (float) $history->valueAt($p($in ? 'hk[0].heizgrenze_heizen' : 'hk[0].heizgrenze_absenken'), $ts);
                $avgMinutes = (int) ($history->valueAt($p('aussentemperatur_mittelungszeit'), $ts) ?? 60);
                $state = [$target, $hyst, $lim];
                if ($state !== $last) {
                    $set[] = [$ts, $target];
                    $stop[] = [$ts, $target + $hyst];
                    $restart[] = [$ts, $target + $hyst - 1];
                    $band[] = [$ts, 1];
                    $limit[] = [$ts, $lim];
                    $limitRestart[] = [$ts, $lim - 2];
                    $last = $state;
                }
            }
            if ($in && $start === null) {
                $start = $ts;
            } elseif (! $in && $start !== null) {
                $areas[] = [['xAxis' => $start, 'itemStyle' => ['color' => 'rgba(16,185,129,.07)']], ['xAxis' => $ts]];
                $start = null;
            }
        }
        foreach ([&$set, &$stop, &$restart, &$band, &$limit, &$limitRestart] as &$serie) {
            $serie[] = [$end, end($serie)[1]];
        }
        unset($serie);

        // Mesures (coupées sur les trous de plus de 20 min) et T ext moyennée sur la durée réglée
        $outside = $avg = $room = $pump = [];
        $window = [];
        $prev = null;
        foreach ($rows as $r) {
            $t = strtotime($r->ts);
            if ($prev !== null && $t - $prev > 1200) {
                $gap = date('Y-m-d H:i:s', $prev + 60);
                $outside[] = $avg[] = $room[] = $pump[] = [$gap, null];
            }
            $prev = $t;
            if ($r->outside_temp !== null) {
                $window[] = [$t, (float) $r->outside_temp];
            }
            while ($window && $window[0][0] <= $t - $avgMinutes * 60) {
                array_shift($window);
            }
            $outside[] = [$r->ts, $r->outside_temp === null ? null : (float) $r->outside_temp];
            $avg[] = [$r->ts, $window ? round(array_sum(array_column($window, 1)) / count($window), 2) : null];
            $room[] = [$r->ts, $r->hc1_room_temp === null ? null : (float) $r->hc1_room_temp];
            $pump[] = [$r->ts, ($r->hc1_pump ?? 0) > 0 ? 1 : 0];
        }

        $line = fn (string $name, array $data, string $color, array $oko, array $extra = []) => array_merge([
            'name' => $name, 'type' => 'line', 'data' => $data, 'symbol' => 'none', 'connectNulls' => false, 'triggerEvent' => true,
            'lineStyle' => ['width' => 1.6, 'color' => $color], 'itemStyle' => ['color' => $color], 'oko' => $oko,
        ], $extra);
        $step = fn (string $color, string $type = 'dashed', float $width = 1.4) => ['step' => 'end', 'lineStyle' => ['type' => $type, 'width' => $width, 'color' => $color]];
        $tag = self::tag(...);
        $roomKeys = ['room_comfort', 'room_reduced'];
        $limitKeys = ['limit_comfort', 'limit_reduced'];
        // légende : la ligne (style réel) pour les courbes, un rectangle pour les zones ; deux rangées, pièce puis extérieur
        $zone = fn (string $name, string $color) => ['name' => $name, 'icon' => 'rect', 'itemStyle' => ['color' => $color]];
        $legend = fn (int $top, array $data) => ['top' => $top, 'left' => 52, 'data' => $data, 'itemWidth' => 28, 'itemHeight' => 10, 'itemGap' => 14];

        return [
            'empty' => $rows === [],
            'option' => [
                'legend' => [
                    $legend(0, ['T ambiante', 'Consigne', 'Arrêt thermostat', 'Relance thermostat', $zone('Tunnel du thermostat', 'rgba(163,163,163,.45)'), $zone('Plage confort', 'rgba(16,185,129,.25)')]),
                    $legend(22, ['T extérieure', 'T ext moyennée', 'Limite de chauffe', 'Relance limite', $zone('Pompe chauffage', 'rgba(245,158,11,.45)')]),
                ],
                'grid' => ['left' => 52, 'right' => 120, 'top' => 60, 'bottom' => 56],
                'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@num', 'axisPointer' => ['type' => 'cross', 'label' => ['precision' => 1]]],
                'xAxis' => ['type' => 'time', 'min' => $date.' 00:00:00', 'max' => $end, 'axisLabel' => ['formatter' => '@hour']],
                // une seule échelle pour toutes les températures ; la pompe sur un axe caché (bandeau en bas)
                'yAxis' => [
                    ['type' => 'value', 'name' => '°C', 'scale' => true, 'minInterval' => 1],
                    ['type' => 'value', 'min' => 0, 'max' => 12, 'show' => false],
                ],
                'dataZoom' => self::dayZoom(),
                'series' => [
                    // Pièce
                    $line('T ambiante', $room, self::COLORS['room'], $roomKeys, ['lineStyle' => ['width' => 2.4, 'color' => self::COLORS['room']]] + $tag('pièce', self::COLORS['room'])),
                    $line('Consigne', $set, '#047857', $roomKeys, $step('#047857', 'solid', 1.4) + $tag('consigne', '#047857')),
                    $line('Relance thermostat', $restart, '#737373', ['hyst'], $step('#737373', 'dotted') + ['stack' => 'tunnel'] + $tag('relance', '#737373')),
                    $line('Tunnel du thermostat', $band, '#a3a3a3', ['hyst'], ['step' => 'end', 'stack' => 'tunnel', 'lineStyle' => ['width' => 0],
                        'areaStyle' => ['color' => 'rgba(163,163,163,.18)'], 'tooltip' => ['valueFormatter' => '@kelvin']]),
                    $line('Arrêt thermostat', $stop, '#525252', ['hyst'], $step('#525252') + $tag('arrêt', '#525252')),
                    // zones des plages confort, rattachées à leur entrée de légende (clic = masquer)
                    $line('Plage confort', [[$date.' 00:00:00', null]], 'rgba(16,185,129,.25)', ['room_comfort'], ['markArea' => ['silent' => true, 'data' => $areas]]),
                    $line('Pompe chauffage', $pump, '#f59e0b', ['hyst', ...$limitKeys], ['yAxisIndex' => 1, 'step' => 'end', 'sampling' => 'max',
                        'lineStyle' => ['width' => 0], 'areaStyle' => ['opacity' => 0.25, 'color' => '#f59e0b']]),
                    // Extérieur
                    $line('T extérieure', $outside, self::COLORS['outside'], ['average'], ['lineStyle' => ['width' => 1, 'opacity' => 0.6, 'color' => self::COLORS['outside']]]),
                    $line('T ext moyennée', $avg, '#0369a1', ['average', ...$limitKeys], ['lineStyle' => ['width' => 2.2, 'color' => '#0369a1']] + $tag('ext. moyenne', '#0369a1')),
                    $line('Limite de chauffe', $limit, '#dc2626', $limitKeys, $step('#dc2626') + $tag('limite', '#dc2626')),
                    $line('Relance limite', $limitRestart, '#f87171', $limitKeys, $step('#f87171', 'dotted') + $tag('relance limite', '#f87171')),
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ Silo

    public function gauge(?float $pct, string $name, string $unit = '%', array $thresholds = [[0.15, '#ef4444'], [0.35, '#f59e0b'], [1, '#16a34a']]): array
    {
        return [
            'series' => [[
                'type' => 'gauge', 'startAngle' => 200, 'endAngle' => -20, 'min' => 0, 'max' => 100,
                'radius' => '95%', 'center' => ['50%', '60%'],
                'axisLine' => ['lineStyle' => ['width' => 14, 'color' => $thresholds]],
                'pointer' => ['width' => 4, 'length' => '60%'],
                'axisTick' => ['show' => false], 'splitLine' => ['length' => 10],
                'axisLabel' => ['distance' => 18, 'fontSize' => 10],
                'title' => ['offsetCenter' => [0, '35%'], 'fontSize' => 12],
                'detail' => ['valueAnimation' => true, 'formatter' => '{value} '.$unit, 'fontSize' => 20, 'offsetCenter' => [0, '65%']],
                'data' => [['value' => $pct === null ? 0 : round($pct), 'name' => $name]],
            ]],
        ];
    }

    /**
     * Évolution du stock estimé (reconstitution jour par jour) et projection jusqu'à épuisement.
     */
    public function stockHistory(array $silo, array $normals, int $days = 400, ?array $ash = null): array
    {
        $from = now()->subDays($days)->format('Y-m-d');
        $events = DB::table('stock_events')->whereIn('type', ['refill', 'level_check'])->where('occurred_on', '>=', $from)
            ->orderBy('occurred_on')->get();
        $daily = DB::table('daily_stats')->where('day', '>=', $from)->orderBy('day')->pluck('pellets_kg', 'day');

        // Reconstitution à rebours depuis le niveau actuel
        $level = (float) ($silo['level_kg'] ?? 0);
        $points = [[now()->format('Y-m-d'), round($level)]];
        $byDay = $events->groupBy(fn ($e) => (string) $e->occurred_on);
        for ($d = CarbonImmutable::today()->subDay(); $d->format('Y-m-d') >= $from; $d = $d->subDay()) {
            $key = $d->format('Y-m-d');
            $next = $d->addDay()->format('Y-m-d');
            $level += (float) ($daily[$key] ?? 0);
            foreach ($byDay[$next] ?? [] as $e) {
                $level = $e->type === 'level_check' ? (float) $e->remaining_kg : max(0, $level - (float) $e->quantity_kg);
            }
            $points[] = [$key, round($level)];
        }
        $points = array_reverse($points);

        // Projection
        $forecast = [];
        $stock = (float) ($silo['level_kg'] ?? 0);
        for ($i = 0; $i <= 365 && $stock > 0; $i++) {
            $d = CarbonImmutable::today()->addDays($i);
            $forecast[] = [$d->format('Y-m-d'), round($stock)];
            $stock -= (float) ($this->signature->expectedDailyKg($d, $normals[$d->dayOfYear] ?? null) ?? 0);
        }
        if ($stock <= 0 && $forecast) {
            $forecast[] = [now()->addDays(count($forecast))->format('Y-m-d'), 0];
        }

        $capacity = (float) settings('equipment.silo_capacity_kg', 3500);
        [$ashPoints, $ashForecast] = $ash !== null ? $this->ashHistory($ash, $normals, $from) : [[], []];

        $series = [
            ['name' => 'Stock estimé', 'type' => 'line', 'data' => $points, 'showSymbol' => false, 'areaStyle' => ['opacity' => 0.15],
                'itemStyle' => ['color' => '#16a34a'], ...self::tag('stock', '#16a34a'),
                'markLine' => ['symbol' => 'none', 'data' => [['yAxis' => (float) settings('alerts.silo_low_kg', 500), 'name' => 'Seuil d\'alerte']],
                    'lineStyle' => ['color' => '#ef4444', 'type' => 'dashed'], 'label' => ['formatter' => 'seuil d\'alerte', 'position' => 'insideStartTop']]],
            ['name' => 'Projection', 'type' => 'line', 'data' => $forecast, 'showSymbol' => false,
                'lineStyle' => ['type' => 'dashed'], 'itemStyle' => ['color' => '#f59e0b'], ...self::tag('projection', '#d97706')],
        ];
        if ($ashPoints !== []) {
            $series[] = ['name' => 'Cendrier', 'type' => 'line', 'yAxisIndex' => 1, 'data' => $ashPoints, 'showSymbol' => false,
                'connectNulls' => false, 'itemStyle' => ['color' => '#78716c'], 'lineStyle' => ['width' => 1.5],
                'areaStyle' => ['opacity' => 0.12], 'tooltip' => ['valueFormatter' => '@pct']];
            // Débordement : surface rouge entre 100 % et la courbe quand le cendrier dépasse sa capacité
            $series[] = ['name' => 'Débordement', 'type' => 'line', 'yAxisIndex' => 1, 'showSymbol' => false, 'silent' => true,
                'data' => array_map(fn ($p) => [$p[0], $p[1] === null ? null : max(100, $p[1])], $ashPoints),
                'lineStyle' => ['width' => 0], 'itemStyle' => ['color' => '#dc2626'],
                'areaStyle' => ['origin' => 100, 'opacity' => 0.45, 'color' => '#dc2626'], 'tooltip' => ['show' => false]];
            $series[] = ['name' => 'Projection cendrier', 'type' => 'line', 'yAxisIndex' => 1, 'data' => $ashForecast, 'showSymbol' => false,
                'itemStyle' => ['color' => '#78716c'], 'lineStyle' => ['type' => 'dotted', 'width' => 1.5],
                'tooltip' => ['valueFormatter' => '@pct'], ...self::tag('cendrier', '#78716c')];
        }

        $ashMax = max(array_map(fn ($p) => (float) ($p[1] ?? 0), [...$ashPoints, [null, 0]]));

        return [
            'legend' => ['top' => 0, 'data' => array_values(array_filter(array_column($series, 'name'), fn ($n) => $n !== 'Débordement'))],
            'grid' => ['left' => 56, 'right' => $ashPoints !== [] ? 150 : 90, 'top' => 36, 'bottom' => 48],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@kg'],
            'xAxis' => ['type' => 'time', 'axisLabel' => ['formatter' => '@month']],
            'yAxis' => array_values(array_filter([
                ['type' => 'value', 'name' => 'kg', 'min' => 0, 'max' => max($capacity, 100)],
                // Cendrier dans le tiers bas du graphique : 100 % à 30 % de la hauteur, graduations limitées à 0–100 %
                $ashPoints !== [] ? ['type' => 'value', 'min' => 0, 'max' => max(round(100 / 0.3, 2), ceil($ashMax / 50) * 50), 'interval' => 50,
                    'splitLine' => ['show' => false], 'axisLabel' => ['formatter' => '@pctUpTo100'],
                    'axisTick' => ['show' => false], 'offset' => 86] : null,
            ])),
            'dataZoom' => [['type' => 'inside']],
            'series' => $series,
        ];
    }

    /**
     * Remplissage du cendrier (% de sa capacité) jour par jour depuis les vidages, puis projection jusqu'à ce qu'il soit plein.
     *
     * @return array{0: list<array{0: string, 1: float|null}>, 1: list<array{0: string, 1: float}>}
     */
    private function ashHistory(array $ash, array $normals, string $from): array
    {
        $capacity = (float) ($ash['capacity_kg'] ?? 0);
        if ($capacity <= 0 || ($ash['since'] ?? null) === null) {
            return [[], []];
        }

        $emptied = DB::table('stock_events')->where('type', 'ash_emptied')->orderBy('occurred_on')
            ->pluck('occurred_on')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values();
        $grams = app(PelletCalibration::class)->gramsPerMinute();
        $first = $emptied->first(fn ($d) => true);
        $start = max($from, $first);
        // Granulés brûlés entre le dernier vidage antérieur à la période et son début
        $before = $emptied->filter(fn ($d) => $d <= $start)->last();
        $burnt = (float) DB::table('daily_stats')->where('day', '>=', $before)->where('day', '<', $start)->sum('auger_min') * $grams / 1000;
        $daily = DB::table('daily_stats')->where('day', '>=', $start)->orderBy('day')->pluck('auger_min', 'day');
        $isEmptied = array_flip($emptied->all());

        $points = [];
        $pct = fn (float $kg) => round(100 * $kg / $capacity, 1); // > 100 % : le cendrier déborde
        $today = now()->format('Y-m-d');
        if ($start > $from) {
            $points[] = [$from, null];
        }
        $points[] = [$start, $pct($burnt)];
        for ($d = CarbonImmutable::parse($start); $d->format('Y-m-d') < $today; $d = $d->addDay()) {
            $key = $d->format('Y-m-d');
            if (isset($isEmptied[$key])) {
                $points[] = [$key, $pct($burnt)];   // niveau juste avant le vidage, puis chute à zéro
                $burnt = 0;
                $points[] = [$key, 0.0];
            }
            $burnt += (float) ($daily[$key] ?? 0) * $grams / 1000;
            $points[] = [$d->addDay()->format('Y-m-d'), $pct($burnt)];
        }
        // Point du jour aligné sur la jauge
        $current = (float) ($ash['burnt_kg'] ?? $burnt);
        $points[] = [$today, $pct($current)];

        $forecast = [[$today, $pct($current)]];
        $kg = $current;
        for ($i = 1; $i <= 730 && $kg < $capacity; $i++) {
            $d = CarbonImmutable::today()->addDays($i);
            $kg += (float) ($this->signature->expectedDailyKg($d, $normals[$d->dayOfYear] ?? null) ?? 0);
            if ($i % 3 === 0 || $kg >= $capacity) {
                $forecast[] = [$d->format('Y-m-d'), $pct($kg)];
            }
        }

        return [$points, $forecast];
    }
}
