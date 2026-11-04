<?php

namespace App\Filament\Pages;

use App\Analysis\Advisor;
use App\Analysis\EnergySignature;
use App\Analysis\HeatingCurve;
use App\Models\OptimizerAction;
use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use App\Optimizer\BlockHeating;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Optimization extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Optimisation';

    protected static ?string $title = 'Optimisation du fonctionnement';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'optimisation';

    protected string $view = 'filament.pages.optimization';

    public ?string $from = null;

    public ?string $to = null;

    public array $pilot = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->pilot = [
            'enabled' => (bool) settings('optimizer.enabled'),
            'dry_run' => (bool) settings('optimizer.dry_run', true),
        ] + (array) settings('optimizer.block_heating');
        unset($this->pilot['state']);
        $this->pilot += ['window_start' => '06:00', 'window_end' => '22:00', 'boost_k' => 0.5, 'max_pause_minutes' => 240];
    }

    public function analyse(): array
    {
        $key = 'okovision.optimization.'.md5($this->from.$this->to);

        return Cache::remember($key, now()->addMinutes(30), function () {
            $boiler = app(BoilerService::class);
            try {
                $read = $boiler->read(array_values(Advisor::P));
                $params = array_map(fn ($n) => isset($read[$n]) ? $read[$n]->value() : null, Advisor::P);
                $curve = $boiler->currentCurve();
            } catch (\Throwable $e) {
                $this->error = $e->getMessage();
                $params = [];
                $curve = new HeatingCurve;
            }

            return ['params' => $params, 'result' => app(Advisor::class)->analyse($params, $curve, $this->from, $this->to)];
        });
    }

    public function refreshAnalysis(): void
    {
        Cache::forget('okovision.optimization.'.md5($this->from.$this->to));
        Cache::forget('okovision.dashboard.advice');
    }

    public function applyRecommendation(string $id): void
    {
        $rec = collect($this->analyse()['result']['recommendations'])->firstWhere('id', $id);
        if (! $rec || ! $rec['changes']) {
            return;
        }
        $results = app(BoilerService::class)->apply($rec['changes'], 'advisor', auth()->id());
        $failed = array_filter($results, fn ($r) => $r['status'] !== 'applied');
        $failed
            ? Notification::make()->title('Échec')->body(implode("\n", array_column($failed, 'message')))->danger()->persistent()->send()
            : Notification::make()->title('Recommandation appliquée')->body('Observez le résultat sur quelques jours dans l\'historique.')->success()->send();
        $this->refreshAnalysis();
    }

    public function savePilot(): void
    {
        $p = $this->pilot;
        settings()->set('optimizer.enabled', (bool) ($p['enabled'] ?? false));
        settings()->set('optimizer.dry_run', (bool) ($p['dry_run'] ?? true));
        foreach (['max_outside_temp', 'min_outside_temp', 'room_band_low', 'room_band_high', 'min_block_minutes', 'max_pause_minutes', 'boost_k'] as $k) {
            if (isset($p[$k]) && is_numeric($p[$k])) {
                settings()->set("optimizer.block_heating.$k", (float) $p[$k]);
            }
        }
        foreach (['window_start', 'window_end'] as $k) {
            if (! empty($p[$k])) {
                settings()->set("optimizer.block_heating.$k", $p[$k]);
            }
        }
        if (! ($p['enabled'] ?? false)) {
            app(BlockHeating::class)->run(); // restaure les réglages d'origine si une phase était en cours
        }
        Notification::make()->title('Pilotage enregistré')->success()->send();
    }

    protected function getViewData(): array
    {
        $data = $this->analyse();
        $result = $data['result'];
        $signature = app(EnergySignature::class);
        $load = $result['load'] ?? null;

        // Besoin moyen (kW) vs puissance minimale, selon la T ext
        $loadChart = null;
        if ($load) {
            $line = [];
            for ($t = -5; $t <= 18; $t++) {
                $line[] = [$t, $signature->loadKw($t)];
            }
            $days = DB::table('daily_stats')->where('day', '>=', now()->subDays(730)->format('Y-m-d'))->where('samples', '>', 1380)
                ->whereNotNull('outside_avg')->selectRaw('ROUND(outside_avg) t, COUNT(*) n')->groupByRaw('ROUND(outside_avg)')->pluck('n', 't');
            $loadChart = [
                'legend' => ['top' => 0],
                'grid' => ['left' => 48, 'right' => 48, 'top' => 72, 'bottom' => 40],
                'tooltip' => ['trigger' => 'axis', 'valueFormatter' => '@num'],
                'xAxis' => ['type' => 'value', 'name' => 'T ext moyenne journalière (°C)', 'nameLocation' => 'middle', 'nameGap' => 26, 'min' => -5, 'max' => 18],
                'yAxis' => [['type' => 'value', 'name' => 'kW', 'min' => 0], ['type' => 'value', 'name' => 'jours', 'splitLine' => ['show' => false]]],
                'series' => [
                    ['name' => 'Jours observés (2 ans)', 'type' => 'bar', 'yAxisIndex' => 1, 'itemStyle' => ['color' => 'rgba(100,116,139,.35)'],
                        'data' => $days->map(fn ($n, $t) => [(int) $t, (int) $n])->values()->all()],
                    ['name' => 'Besoin moyen de la maison', 'type' => 'line', 'data' => $line, 'showSymbol' => false, 'lineStyle' => ['width' => 3], 'itemStyle' => ['color' => '#f97316']],
                    ['name' => 'Puissance minimale chaudière', 'type' => 'line', 'data' => [[-5, $load['pmin_kw']], [18, $load['pmin_kw']]], 'showSymbol' => false,
                        'lineStyle' => ['type' => 'dashed', 'width' => 2], 'itemStyle' => ['color' => '#ef4444']],
                    ['name' => 'Puissance nominale', 'type' => 'line', 'data' => [[-5, $load['power_kw']], [18, $load['power_kw']]], 'showSymbol' => false,
                        'lineStyle' => ['type' => 'dotted'], 'itemStyle' => ['color' => '#64748b']],
                ],
            ];
        }

        // Durée de combustion par cycle selon la T ext
        $cycles = DB::table('burner_cycles')->whereBetween('day', [$result['period']['from'], $result['period']['to']])->where('complete', true)
            ->whereNotNull('outside_temp_avg')->get(['outside_temp_avg', 'combustion_min', 'cold_start', 'dhw']);
        $cyclesChart = [
            'legend' => ['top' => 0],
            'grid' => ['left' => 48, 'right' => 16, 'top' => 36, 'bottom' => 40],
            'tooltip' => ['trigger' => 'item'],
            'xAxis' => ['type' => 'value', 'name' => 'T ext (°C)', 'nameLocation' => 'middle', 'nameGap' => 26, 'scale' => true],
            'yAxis' => ['type' => 'log', 'name' => 'min de combustion', 'min' => 1],
            'series' => [
                ['name' => 'Redémarrage à chaud', 'type' => 'scatter', 'symbolSize' => 5, 'itemStyle' => ['color' => '#f59e0b'],
                    'data' => $cycles->where('cold_start', 0)->map(fn ($c) => [(float) $c->outside_temp_avg, max(1, (int) $c->combustion_min)])->values()->all()],
                ['name' => 'Allumage à froid', 'type' => 'scatter', 'symbolSize' => 7, 'itemStyle' => ['color' => '#ef4444'],
                    'data' => $cycles->where('cold_start', 1)->map(fn ($c) => [(float) $c->outside_temp_avg, max(1, (int) $c->combustion_min)])->values()->all()],
            ],
        ];

        return [
            'params' => $data['params'],
            'result' => $result,
            'loadChart' => $loadChart,
            'cyclesChart' => $cyclesChart,
            'catalog' => app(Catalog::class),
            'actions' => OptimizerAction::query()->latest()->limit(20)->get(),
            'pilotState' => app(BlockHeating::class)->state(),
        ];
    }
}
