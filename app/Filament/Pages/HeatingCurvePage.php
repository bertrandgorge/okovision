<?php

namespace App\Filament\Pages;

use App\Analysis\HeatingCurve;
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
use Livewire\Attributes\Url;

class HeatingCurvePage extends Page
{
    use AppliesSettings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?string $navigationLabel = 'Chauffage';

    protected static ?string $title = 'Chauffage';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'chauffage';

    protected string $view = 'filament.pages.heating-curve';

    /** Réglages de la page : loi d'eau + thermostat d'ambiance et moyenne de la T ext */
    public const PARAMS = HeatingCurve::PARAMS + [
        'hyst' => 'CAPPL:LOCAL.hk[0].raumtemp_plus',
        'lead' => 'CAPPL:LOCAL.hk[0].vorhaltezeit',
        'average' => 'CAPPL:LOCAL.aussentemperatur_mittelungszeit',
    ];

    /** Valeurs en cours d'édition (clés de self::PARAMS) */
    public array $edit = [];

    /** Valeurs actuelles de la chaudière */
    public array $current = [];

    public ?string $error = null;

    #[Url]
    public int $days = 60;

    /** Journée du graphique temporel */
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
        $extra = array_diff_key(self::PARAMS, HeatingCurve::PARAMS);
        try {
            $boiler = app(BoilerService::class);
            $this->current = $boiler->currentCurve($fresh)->toArray();
            $read = $boiler->read(array_values($extra), $fresh);
            foreach ($extra as $key => $name) {
                $this->current[$key] = isset($read[$name]) ? $read[$name]->value() : null;
            }
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            // à défaut : dernier profil connu, sinon valeurs par défaut
            $profile = BoilerProfile::query()->latest()->first();
            $values = array_map(fn ($name) => $profile?->values[$name] ?? null, self::PARAMS);
            $this->current = HeatingCurve::fromArray($values)->toArray() + array_intersect_key($values, $extra);
        }
        $this->edit = $this->current;
    }

    public function resetEdit(): void
    {
        $this->edit = $this->current;
    }

    public function applySuggestion(float $slope, float $foot): void
    {
        $this->edit['slope'] = $slope;
        $this->edit['foot'] = $foot;
    }

    public function changes(): array
    {
        $changes = [];
        foreach (self::PARAMS as $key => $name) {
            if ($this->isSettingChanged($key)) {
                $changes[$name] = (float) $this->edit[$key];
            }
        }

        return $changes;
    }

    protected function settingParams(): array
    {
        return self::PARAMS;
    }

    protected function settingSource(): string
    {
        return 'curve';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')
                ->label('Relire la chaudière')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->load(true)),
            Action::make('apply')
                ->label('Tout appliquer')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn () => $this->changes() === [] || $this->error !== null)
                ->requiresConfirmation()
                ->modalHeading('Appliquer ces réglages de chauffage ?')
                ->modalDescription(fn () => new HtmlString($this->changesDescription()))
                ->action(function () {
                    $results = app(BoilerService::class)->apply($this->changes(), 'curve', auth()->id());
                    $failed = array_filter($results, fn ($r) => $r['status'] !== 'applied');
                    if ($failed) {
                        Notification::make()->title('Certaines valeurs n\'ont pas été appliquées')
                            ->body(implode("\n", array_map(fn ($r) => $r['name'].' : '.$r['message'], $failed)))->danger()->persistent()->send();
                    } else {
                        Notification::make()->title('Réglages appliqués')->body('Attendez au moins 24 h avant un nouvel ajustement de la loi d\'eau.')->success()->send();
                    }
                    $this->load(true);
                }),
        ];
    }

    private function changesDescription(): string
    {
        $catalog = app(Catalog::class);
        $lines = [];
        foreach (self::PARAMS as $key => $name) {
            if (isset($this->changes()[$name])) {
                $lines[] = '<li><strong>'.e($catalog->label($catalog->parameter($name) ?? ['name' => $name])).'</strong> : '.e($this->current[$key]).' → '.e($this->edit[$key]).'</li>';
            }
        }

        return '<ul class="text-start">'.implode('', $lines).'</ul>';
    }

    protected function getViewData(): array
    {
        $charts = app(ChartFactory::class);
        $current = HeatingCurve::fromArray($this->current);
        $edited = HeatingCurve::fromArray($this->edit);
        $to = CarbonImmutable::today()->format('Y-m-d');
        $from = CarbonImmutable::today()->subDays($this->days)->format('Y-m-d');

        $learned = $current->learn($from, $to);
        $scatter = $current->scatter($from, $to, 3000);

        $table = [];
        foreach ([-10, -5, 0, 5, 10, 15] as $t) {
            $table[] = [
                't' => $t,
                'current' => $current->flow($t),
                'edited' => $edited->flow($t),
                'boiler' => $edited->boilerSet($edited->flow($t)),
                'measured' => collect($learned['buckets'])->firstWhere('t', (float) $t)['flow_set'] ?? null,
                'deviation' => collect($learned['buckets'])->firstWhere('t', (float) $t)['deviation'] ?? null,
            ];
        }

        // Journée : réglages en vigueur à chaque instant (journal des modifications, valeurs actuelles en repli)
        try {
            $windows = app(TimeProgram::class)->windows('hk');
        } catch (\Throwable) {
            $windows = null;
        }
        $fallback = array_combine(array_values(self::PARAMS), array_map(fn ($k) => $this->current[$k] ?? null, array_keys(self::PARAMS)));
        if ($windows !== null) {
            $fallback['CAPPL:LOCAL.hk[0].zeitprogramm[0]'] = TimeProgram::summary($windows);
        }

        return [
            'option' => $charts->curve($current, $this->changes() !== [] ? $edited : null, $scatter, $learned['buckets']),
            'curveHover' => $charts->curveHover($edited),
            'dayChart' => $charts->heatingDay($this->date, new SettingsHistory($fallback), $windows),
            'learned' => $learned,
            'table' => $table,
            'from' => $from,
            'to' => $to,
            'hasChanges' => $this->changes() !== [],
        ];
    }
}
