<?php

namespace App\Filament\Pages;

use App\Analysis\EnergySignature;
use App\Charts\ChartFactory;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class History extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Historique';

    protected static ?string $title = 'Historique et consommation';

    protected static string|\UnitEnum|null $navigationGroup = 'Suivi';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'historique';

    protected string $view = 'filament.pages.history';

    #[Url]
    public string $period = '90d';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function getPeriods(): array
    {
        $season = now()->month >= 9 ? now()->year : now()->year - 1;

        return [
            '30d' => '30 jours',
            '90d' => '90 jours',
            'season' => 'Saison '.$season.'-'.($season + 1),
            'last_season' => 'Saison '.($season - 1).'-'.$season,
            '365d' => '12 mois',
            'all' => 'Tout l\'historique',
            'custom' => 'Personnalisée',
        ];
    }

    /** @return array{0: string, 1: string} */
    public function range(): array
    {
        $season = now()->month >= 9 ? now()->year : now()->year - 1;
        $today = CarbonImmutable::today();

        return match ($this->period) {
            '30d' => [$today->subDays(30)->format('Y-m-d'), $today->format('Y-m-d')],
            'season' => [$season.'-09-01', $today->format('Y-m-d')],
            'last_season' => [($season - 1).'-09-01', $season.'-08-31'],
            '365d' => [$today->subYear()->format('Y-m-d'), $today->format('Y-m-d')],
            'all' => [(string) (\DB::table('daily_stats')->min('day') ?? $today->format('Y-m-d')), $today->format('Y-m-d')],
            'custom' => [$this->from ?: $today->subDays(90)->format('Y-m-d'), $this->to ?: $today->format('Y-m-d')],
            default => [$today->subDays(90)->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    protected function getViewData(): array
    {
        [$from, $to] = $this->range();
        $charts = app(ChartFactory::class);
        $totals = \DB::table('daily_stats')->whereBetween('day', [$from, $to])
            ->selectRaw('SUM(pellets_kg) kg, SUM(pellets_kg_dhw) kg_dhw, SUM(dju) dju, SUM(cycles) cycles, SUM(cold_starts) cold, AVG(outside_avg) t, COUNT(*) n')
            ->first();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'price' => (float) settings('pellets.price_per_ton', 450),
            'daily' => $charts->withChangeMarkers($charts->dailyConsumption($from, $to), $notes = $charts->settingChanges($from, $to)),
            'cycles' => $charts->withChangeMarkers($charts->dailyCycles($from, $to), $notes),
            'notes' => $notes,
            'histogram' => $charts->cycleHistogram($from, $to),
            'seasonsMonthly' => $charts->seasonsMonthly(),
            'seasons' => $charts->seasonsTable(),
            'signature' => $charts->signature(),
            'model' => app(EnergySignature::class)->model(),
        ];
    }
}
