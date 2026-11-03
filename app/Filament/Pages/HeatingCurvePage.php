<?php

namespace App\Filament\Pages;

use App\Analysis\HeatingCurve;
use App\Charts\ChartFactory;
use App\Models\BoilerProfile;
use App\Okofen\BoilerService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class HeatingCurvePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?string $navigationLabel = 'Loi d\'eau';

    protected static ?string $title = 'Loi d\'eau (courbe de chauffe)';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'loi-d-eau';

    protected string $view = 'filament.pages.heating-curve';

    /** Valeurs en cours d'édition (clés de HeatingCurve::PARAMS) */
    public array $edit = [];

    /** Valeurs actuelles de la chaudière */
    public array $current = [];

    public ?string $error = null;

    #[Url]
    public int $days = 60;

    public function mount(): void
    {
        $this->load();
    }

    public function load(bool $fresh = false): void
    {
        try {
            $this->current = app(BoilerService::class)->currentCurve($fresh)->toArray();
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            // à défaut : dernier profil connu, sinon valeurs par défaut
            $profile = BoilerProfile::query()->latest()->first();
            $values = [];
            foreach (HeatingCurve::PARAMS as $key => $name) {
                $values[$key] = $profile?->values[$name] ?? null;
            }
            $this->current = HeatingCurve::fromArray($values)->toArray();
        }
        $this->edit = $this->current;
    }

    public function resetEdit(): void
    {
        $this->edit = $this->current;
    }

    /** Appelé quand on lâche une poignée sur le graphique : deux points (T ext, T départ). */
    public function dragCurve(array $points): void
    {
        if (count($points) !== 2) {
            return;
        }
        [[$t1, $f1], [$t2, $f2]] = $points;
        $r = HeatingCurve::fromTwoPoints((float) $t1, (float) $f1, (float) $t2, (float) $f2, (float) ($this->edit['room_comfort'] ?? 20));
        $this->edit['slope'] = max(0, min(4, round($r['slope'], 1)));
        $this->edit['foot'] = max(20, min(45, round($r['foot'] * 2) / 2));
    }

    public function applySuggestion(float $slope, float $foot): void
    {
        $this->edit['slope'] = $slope;
        $this->edit['foot'] = $foot;
    }

    public function step(string $key, float $delta): void
    {
        $limits = ['slope' => [0, 4], 'foot' => [20, 45], 'room_comfort' => [5, 40], 'room_reduced' => [10, 40], 'influence' => [0, 10],
            'flow_min' => [0, 80], 'flow_max' => [0, 80], 'limit_comfort' => [-40, 60], 'limit_reduced' => [-40, 60], 'raise' => [0, 50]];
        [$lo, $hi] = $limits[$key] ?? [-100, 100];
        $this->edit[$key] = round(max($lo, min($hi, (float) ($this->edit[$key] ?? 0) + $delta)), 1);
    }

    public function changes(): array
    {
        $changes = [];
        foreach (HeatingCurve::PARAMS as $key => $name) {
            if (isset($this->edit[$key], $this->current[$key]) && abs((float) $this->edit[$key] - (float) $this->current[$key]) > 1e-6) {
                $changes[$name] = (float) $this->edit[$key];
            }
        }

        return $changes;
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
                ->label('Appliquer à la chaudière')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn () => $this->changes() === [] || $this->error !== null)
                ->requiresConfirmation()
                ->modalHeading('Appliquer la nouvelle loi d\'eau ?')
                ->modalDescription(fn () => new HtmlString($this->changesDescription()))
                ->action(function () {
                    $results = app(BoilerService::class)->apply($this->changes(), 'curve', auth()->id());
                    $failed = array_filter($results, fn ($r) => $r['status'] !== 'applied');
                    if ($failed) {
                        Notification::make()->title('Certaines valeurs n\'ont pas été appliquées')
                            ->body(implode("\n", array_map(fn ($r) => $r['name'].' : '.$r['message'], $failed)))->danger()->persistent()->send();
                    } else {
                        Notification::make()->title('Loi d\'eau appliquée')->body('Attendez au moins 24 h avant un nouvel ajustement.')->success()->send();
                    }
                    $this->load(true);
                }),
        ];
    }

    private function changesDescription(): string
    {
        $labels = [
            'slope' => 'Pente', 'foot' => 'Pied de courbe', 'room_comfort' => 'Consigne confort', 'room_reduced' => 'Consigne réduit',
            'influence' => 'Influence sonde d\'ambiance', 'flow_min' => 'Départ mini', 'flow_max' => 'Départ maxi',
            'limit_comfort' => 'Limite de chauffe confort', 'limit_reduced' => 'Limite de chauffe réduit', 'raise' => 'Surélévation',
        ];
        $lines = [];
        foreach (HeatingCurve::PARAMS as $key => $name) {
            if (isset($this->changes()[$name])) {
                $lines[] = '<li><strong>'.e($labels[$key]).'</strong> : '.e($this->current[$key]).' → '.e($this->edit[$key]).'</li>';
            }
        }

        return '<ul class="text-start">'.implode('', $lines).'</ul>';
    }

    protected function getViewData(): array
    {
        $current = HeatingCurve::fromArray($this->current);
        $edited = HeatingCurve::fromArray($this->edit);
        $to = CarbonImmutable::today()->format('Y-m-d');
        $from = CarbonImmutable::today()->subDays($this->days)->format('Y-m-d');

        $learned = $current->learn($from, $to);
        $scatter = $current->scatter($from, $to, 3000);

        // Poignées : T ext −5 °C et +15 °C sur la courbe éditée en confort (sans les bornes min/max)
        $handles = [[-5.0, round($edited->rawFlow(-5), 1)], [15.0, round($edited->rawFlow(15), 1)]];
        $option = app(ChartFactory::class)->curve($current, $edited, $scatter, $learned['buckets'], $handles);

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

        return [
            'option' => $option,
            'handles' => $handles,
            'learned' => $learned,
            'table' => $table,
            'from' => $from,
            'to' => $to,
            'hasChanges' => $this->changes() !== [],
        ];
    }
}
