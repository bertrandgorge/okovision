<?php

namespace App\Filament\Pages;

use App\Analysis\HotWater;
use App\Charts\ChartFactory;
use App\Filament\Concerns\AppliesSettings;
use App\Models\BoilerProfile;
use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use App\Okofen\SettingsHistory;
use App\Okofen\TimeProgram;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class HotWaterPage extends Page
{
    use AppliesSettings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Eau chaude';

    protected static ?string $title = 'Eau chaude sanitaire';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'eau-chaude';

    protected string $view = 'filament.pages.hot-water';

    /** Réglages ECS modifiables sur cette page (clé locale => variable chaudière) */
    public const PARAMS = [
        'mode' => 'CAPPL:LOCAL.ww[0].betriebsart[1]',
        'set' => 'CAPPL:LOCAL.ww[0].temp_heizen',
        'min' => 'CAPPL:LOCAL.ww[0].temp_absenken',
        'hyst' => 'CAPPL:LOCAL.ww[0].hysterese',
        'raise' => 'CAPPL:LOCAL.ww[0].ueberhoehung',
        'runon' => 'CAPPL:LOCAL.ww[0].nachlaufzeit',
        'priority' => 'CAPPL:LOCAL.ww[0].prioritaet',
        'legionella' => 'CAPPL:LOCAL.ww[0].legionellen_wochentag',
    ];

    private const BOOST = 'CAPPL:LOCAL.ww[0].einmal_aufbereiten';

    /** Valeurs en cours d'édition / lues sur la chaudière (clés de PARAMS) */
    public array $edit = [];

    public array $current = [];

    public ?string $error = null;

    /** Fenêtre des indicateurs (désinfections, moyennes) et des points d'attention */
    public const STATS_DAYS = 90;

    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        if (! $this->date || ! strtotime($this->date)) {
            $last = DB::table('measurements')->max('ts');
            $this->date = $last ? substr($last, 0, 10) : now()->format('Y-m-d');
        }
        $this->load();
    }

    /** Après l'écriture d'un programme ECS : les plages du graphique et du diagnostic changent. */
    #[On('time-program-saved')]
    public function refreshAfterProgram(): void {}

    public function previous(): void
    {
        $this->date = CarbonImmutable::parse($this->date)->subDay()->format('Y-m-d');
    }

    public function next(): void
    {
        $next = CarbonImmutable::parse($this->date)->addDay();
        if (! $next->isAfter(now()->endOfDay())) {
            $this->date = $next->format('Y-m-d');
        }
    }

    public function load(bool $fresh = false): void
    {
        try {
            $values = app(BoilerService::class)->read(array_values(self::PARAMS), $fresh);
            $this->current = array_map(fn ($name) => isset($values[$name]) ? $values[$name]->value() : null, self::PARAMS);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $profile = BoilerProfile::query()->latest()->first();
            $this->current = array_map(fn ($name) => $profile?->values[$name] ?? null, self::PARAMS);
        }
        $this->edit = $this->current;
    }

    public function resetEdit(): void
    {
        $this->edit = $this->current;
    }

    /** @return array<string, float|int> variable => nouvelle valeur */
    public function changes(): array
    {
        $changes = [];
        foreach (self::PARAMS as $key => $name) {
            $new = $this->edit[$key] ?? null;
            if ($new !== null && $new !== '' && is_numeric($new) && (string) (float) $new !== (string) (float) ($this->current[$key] ?? '')) {
                $changes[$name] = (float) $new == (int) $new ? (int) $new : (float) $new;
            }
        }

        return $changes;
    }

    protected function settingParams(): array
    {
        return self::PARAMS;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('boost')
                ->label('Charger le ballon maintenant')
                ->icon(Heroicon::OutlinedBolt)
                ->color('gray')
                ->disabled(fn () => $this->error !== null)
                ->requiresConfirmation()
                ->modalDescription('Charge unique du ballon jusqu\'à la consigne, même hors plage horaire.')
                ->action(function (BoilerService $boiler) {
                    $r = $boiler->apply([self::BOOST => 1], 'manual', auth()->id());
                    collect($r)->contains('status', 'failed')
                        ? Notification::make()->title('Échec')->body(collect($r)->pluck('message')->filter()->implode("\n"))->danger()->send()
                        : Notification::make()->title('Charge du ballon demandée')->success()->send();
                }),
            Action::make('reload')
                ->label('Relire la chaudière')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load(true)),
            Action::make('apply')
                ->label('Appliquer à la chaudière')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn () => $this->changes() === [] || $this->error !== null)
                ->requiresConfirmation()
                ->modalHeading('Appliquer les réglages d\'eau chaude ?')
                ->modalDescription(fn () => new HtmlString($this->changesDescription()))
                ->action(function (BoilerService $boiler) {
                    $failed = array_filter($boiler->apply($this->changes(), 'manual', auth()->id()), fn ($r) => $r['status'] === 'failed');
                    $failed
                        ? Notification::make()->title('Certaines valeurs n\'ont pas été appliquées')
                            ->body(implode("\n", array_map(fn ($r) => $r['name'].' : '.$r['message'], $failed)))->danger()->persistent()->send()
                        : Notification::make()->title('Réglages appliqués')->success()->send();
                    $this->load(true);
                }),
        ];
    }

    private function changesDescription(): string
    {
        $catalog = app(Catalog::class);
        $lines = [];
        foreach (self::PARAMS as $key => $name) {
            if (array_key_exists($name, $this->changes())) {
                $p = $catalog->parameter($name);
                $show = fn ($v) => $v === null ? '—' : ($p['options'][(int) $v] ?? $v.(isset($p['unit']) ? ' '.$p['unit'] : ''));
                $lines[] = '<li><strong>'.e($catalog->label($p)).'</strong> : '.e($show($this->current[$key])).' → '.e($show($this->edit[$key])).'</li>';
            }
        }

        return '<ul class="text-start">'.implode('', $lines).'</ul>';
    }

    protected function getViewData(): array
    {
        $analysis = app(HotWater::class);
        $charts = app(ChartFactory::class);
        $catalog = app(Catalog::class);
        $to = CarbonImmutable::today()->format('Y-m-d');
        $from = CarbonImmutable::today()->subDays(self::STATS_DAYS - 1)->format('Y-m-d');

        $daily = $analysis->daily($from, $to);
        $last = $analysis->lastDisinfection();

        try {
            $windows = app(TimeProgram::class)->dhwWindows();
        } catch (\Throwable) {
            $windows = null;
        }

        $month = array_slice($daily, -30);
        $tank = app(BoilerService::class)->live()['values']['dhw1_temp'] ?? DB::table('measurements')->orderByDesc('ts')->value('dhw1_temp_on');

        return [
            'params' => array_map(fn ($name) => $catalog->parameter($name), self::PARAMS),
            'catalog' => $catalog,
            'hasChanges' => $this->changes() !== [],
            'tank' => $tank,
            'last' => $last,
            'next' => HotWater::nextCycle($this->current['legionella'] === null ? null : (int) $this->current['legionella']),
            'avgCharges' => $month ? array_sum(array_map(fn ($d) => $d->charges, $month)) / count($month) : null,
            'disinfections' => count(array_filter($daily, [HotWater::class, 'isDisinfected'])),
            'diagnosis' => $analysis->diagnose($this->current, $last, $daily),
            'windows' => $windows,
            'dayChart' => $charts->dhwDay($this->date, new SettingsHistory(array_merge(
                array_combine(array_values(self::PARAMS), array_values($this->current)),
                $windows === null ? [] : ['CAPPL:LOCAL.ww[0].zeitprogramm[0]' => TimeProgram::summary($windows)],
            )), $windows),
        ];
    }
}
