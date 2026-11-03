<?php

namespace App\Charts;

use App\Analysis\DayAggregator;
use App\Analysis\EnergySignature;
use App\Analysis\HeatingCurve;
use App\Archive\MeasurementArchiver;
use App\Okofen\Catalog;
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
            'legend' => ['top' => 0, 'type' => 'scroll'],
            'grid' => ['left' => 110, 'right' => 64, 'top' => 36, 'bottom' => 56],
            'tooltip' => $tooltip,
            'xAxis' => $xAxis,
            'yAxis' => [
                ['type' => 'value', 'name' => '°C', 'scale' => true],
                ['type' => 'value', 'name' => 'Ambiance °C', 'scale' => true, 'splitLine' => ['show' => false], 'minInterval' => 0.5],
            ],
            'dataZoom' => $zoom,
            'series' => [
                $line('T chaudière', $s('boiler_temp'), self::COLORS['boiler'], ['markArea' => ['silent' => true, 'data' => $areas]]),
                $line('T départ', $s('hc1_flow_temp'), self::COLORS['flow']),
                $line('Consigne départ', $s('hc1_flow_set', $setOrNull('hc1_flow_set')), self::COLORS['flow_set'], ['lineStyle' => ['type' => 'dashed', 'width' => 1.2, 'color' => self::COLORS['flow_set']]]),
                $line('T ECS', $s('dhw1_temp_on'), self::COLORS['dhw']),
                $line('T extérieure', $s('outside_temp'), self::COLORS['outside']),
                $line('T ambiante', $s('hc1_room_temp'), self::COLORS['room'], ['yAxisIndex' => 1, 'lineStyle' => ['width' => 2.2, 'color' => self::COLORS['room']]]),
                $line('Consigne ambiante', $s('hc1_room_set', $setOrNull('hc1_room_set')), self::COLORS['room_set'], ['yAxisIndex' => 1, 'step' => 'end', 'lineStyle' => ['type' => 'dashed', 'color' => self::COLORS['room_set']]]),
            ],
        ];

        $combustion = [
            'legend' => ['top' => 0],
            'grid' => ['left' => 110, 'right' => 64, 'top' => 36, 'bottom' => 56],
            'tooltip' => $tooltip,
            'xAxis' => $xAxis,
            'yAxis' => [
                ['type' => 'value', 'name' => 'T flamme °C', 'min' => 0],
                ['type' => 'value', 'name' => '%', 'min' => 0, 'max' => 100, 'splitLine' => ['show' => false]],
            ],
            'dataZoom' => $zoom,
            'series' => [
                $line('T flamme', $s('pe1_flame_temp'), self::COLORS['flame'], ['areaStyle' => ['opacity' => 0.12, 'color' => self::COLORS['flame']]]),
                $line('Consigne flamme', $s('pe1_flame_set', $setOrNull('pe1_flame_set')), '#fdba74', ['lineStyle' => ['type' => 'dashed', 'color' => '#fdba74']]),
                $line('Ventilateur combustion', $s('pe1_fan'), self::COLORS['fan'], ['yAxisIndex' => 1]),
                $line('Extracteur fumées', $s('pe1_flue_fan'), self::COLORS['flue'], ['yAxisIndex' => 1, 'lineStyle' => ['width' => 1, 'type' => 'dotted', 'color' => self::COLORS['flue']]]),
                $line('Alimentation vis', $s('pe1_auger_run', fn ($r) => round(DayAggregator::augerRatio($r) * 100, 1)), self::COLORS['auger'], ['yAxisIndex' => 1, 'step' => 'end']),
            ],
        ];

        return [
            'temperatures' => $temperatures,
            'combustion' => $combustion,
            'states' => $this->states($rows, $date),
            'empty' => $rows === [],
        ];
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

    /** Carte de chaleur : minutes de combustion par heure et par jour. */
    public function heatmap(string $from, string $to): array
    {
        $rows = DB::table('measurements')
            ->whereBetween('ts', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('pe1_status', DayAggregator::COMBUSTION)
            ->selectRaw('DATE(ts) as d, HOUR(ts) as h, COUNT(*) as n')
            ->groupByRaw('DATE(ts), HOUR(ts)')->get();
        $days = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $days[] = date('Y-m-d', $d);
        }
        $index = array_flip($days);

        return [
            'grid' => ['left' => 80, 'right' => 16, 'top' => 8, 'bottom' => 64],
            'tooltip' => ['formatter' => '@heatmapTooltip'],
            'xAxis' => ['type' => 'category', 'data' => range(0, 23), 'name' => 'heure', 'splitArea' => ['show' => true]],
            'yAxis' => ['type' => 'category', 'data' => $days, 'inverse' => true],
            'visualMap' => ['min' => 0, 'max' => 60, 'calculable' => true, 'orient' => 'horizontal', 'left' => 'center', 'bottom' => 0,
                'inRange' => ['color' => ['#fff7ed', '#fdba74', '#f97316', '#c2410c', '#7c2d12']]],
            'series' => [[
                'type' => 'heatmap',
                'data' => $rows->map(fn ($r) => [(int) $r->h, $index[$r->d] ?? 0, (int) $r->n])->all(),
            ]],
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

    public function curve(HeatingCurve $current, ?HeatingCurve $edited, array $scatter, array $buckets, ?array $handles): array
    {
        $series = [
            ['id' => 'current', 'name' => 'Courbe actuelle (confort)', 'type' => 'line', 'data' => $current->series(), 'showSymbol' => false,
                'lineStyle' => ['width' => 2.5, 'color' => '#64748b'], 'itemStyle' => ['color' => '#64748b'], 'z' => 3],
            ['id' => 'current_reduced', 'name' => 'Courbe actuelle (réduit)', 'type' => 'line', 'data' => $current->series(true), 'showSymbol' => false,
                'lineStyle' => ['width' => 1.5, 'type' => 'dotted', 'color' => '#64748b'], 'itemStyle' => ['color' => '#64748b'], 'z' => 3],
        ];
        if ($edited) {
            $series[] = ['id' => 'edited', 'name' => 'Nouvelle courbe (confort)', 'type' => 'line', 'data' => $edited->series(), 'showSymbol' => false,
                'lineStyle' => ['width' => 3, 'color' => '#f97316'], 'itemStyle' => ['color' => '#f97316'], 'z' => 4];
            $series[] = ['id' => 'edited_reduced', 'name' => 'Nouvelle courbe (réduit)', 'type' => 'line', 'data' => $edited->series(true), 'showSymbol' => false,
                'lineStyle' => ['width' => 1.5, 'type' => 'dotted', 'color' => '#f97316'], 'itemStyle' => ['color' => '#f97316'], 'z' => 4];
        }
        if ($scatter) {
            $series[] = ['id' => 'measured', 'name' => 'Consignes calculées (mesures)', 'type' => 'scatter', 'data' => $scatter, 'symbolSize' => 3,
                'encode' => ['x' => 0, 'y' => 1], 'tooltip' => ['formatter' => '@curveTooltip'], 'z' => 1];
        }
        if ($buckets) {
            $series[] = ['id' => 'learned', 'name' => 'Moyenne par °C extérieur', 'type' => 'line', 'symbolSize' => 7,
                'data' => array_map(fn ($b) => [$b['t'], $b['flow_set']], $buckets),
                'lineStyle' => ['width' => 0], 'itemStyle' => ['color' => '#0ea5e9'], 'z' => 2];
        }

        return [
            'legend' => ['top' => 0, 'type' => 'scroll'],
            'grid' => ['left' => 56, 'right' => 24, 'top' => 64, 'bottom' => 48],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@temp', 'axisPointer' => ['type' => 'cross']],
            'xAxis' => ['type' => 'value', 'name' => 'Température extérieure (°C)', 'nameLocation' => 'middle', 'nameGap' => 28,
                'min' => -15, 'max' => 20, 'inverse' => true, 'interval' => 5],
            'yAxis' => ['type' => 'value', 'name' => 'Départ (°C)', 'min' => 15, 'max' => 85],
            // Dégradé de couleur des points : écart ambiance (rouge = trop chaud, bleu = trop froid)
            'visualMap' => $scatter ? [[
                'seriesId' => 'measured', 'dimension' => 3, 'min' => -1.5, 'max' => 1.5, 'show' => true, 'orient' => 'horizontal',
                'right' => 32, 'top' => 72, 'itemWidth' => 10, 'itemHeight' => 80, 'text' => ['trop chaud', 'trop froid'],
                'inRange' => ['color' => ['#2563eb', '#a3e635', '#dc2626']], 'calculable' => false,
            ]] : null,
            'series' => $series,
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
    public function stockHistory(array $silo, array $normals, int $days = 400): array
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

        return [
            'legend' => ['top' => 0],
            'grid' => ['left' => 56, 'right' => 16, 'top' => 36, 'bottom' => 48],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@kg'],
            'xAxis' => ['type' => 'time', 'axisLabel' => ['formatter' => '@month']],
            'yAxis' => ['type' => 'value', 'name' => 'kg', 'min' => 0, 'max' => max($capacity, 100)],
            'dataZoom' => [['type' => 'inside']],
            'series' => [
                ['name' => 'Stock estimé', 'type' => 'line', 'data' => $points, 'showSymbol' => false, 'areaStyle' => ['opacity' => 0.15],
                    'itemStyle' => ['color' => '#16a34a'],
                    'markLine' => ['symbol' => 'none', 'data' => [['yAxis' => (float) settings('alerts.silo_low_kg', 500), 'name' => 'Seuil d\'alerte']],
                        'lineStyle' => ['color' => '#ef4444', 'type' => 'dashed'], 'label' => ['formatter' => 'seuil d\'alerte']]],
                ['name' => 'Projection', 'type' => 'line', 'data' => $forecast, 'showSymbol' => false,
                    'lineStyle' => ['type' => 'dashed'], 'itemStyle' => ['color' => '#f59e0b']],
            ],
        ];
    }
}
