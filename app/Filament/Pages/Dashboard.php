<?php

namespace App\Filament\Pages;

use App\Analysis\Advisor;
use App\Analysis\StockEstimator;
use App\Charts\ChartFactory;
use App\Diagnostics\ReportToken;
use App\Models\AlertEvent;
use App\Models\DailyStat;
use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use App\Okofen\MeasurementImporter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Tableau de bord';

    protected static string|\UnitEnum|null $navigationGroup = 'Suivi';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.dashboard';

    public function getHeaderActions(): array
    {
        return [
            Action::make('llm')
                ->label('Rapport pour LLM')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                // Lien partageable (avec le jeton, créé au besoin) : à copier tel quel pour un assistant IA, sans session
                ->url(fn () => ReportToken::url(), shouldOpenInNewTab: true)
                ->tooltip('Document Markdown complet à fournir à un assistant IA pour diagnostic'),
            Action::make('refresh')
                ->label('Actualiser')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (MeasurementImporter $importer, BoilerService $boiler) {
                    $boiler->live(true);
                    try {
                        $importer->fetchFromBoiler();
                        Notification::make()->title('Données mises à jour')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Chaudière injoignable')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function getViewData(): array
    {
        $live = app(BoilerService::class)->live();
        $last = DB::table('measurements')->orderByDesc('ts')->first();
        $today = DailyStat::query()->find(now()->format('Y-m-d'));
        $yesterday = DailyStat::query()->find(now()->subDay()->format('Y-m-d'));
        $stock = app(StockEstimator::class);
        $charts = app(ChartFactory::class);

        $day = $last ? substr($last->ts, 0, 10) : now()->format('Y-m-d');
        $range = $this->dayRange($live['values'] ?? [], $day);
        $dayCharts = $charts->day($day);

        // Les recommandations ne nécessitent pas la chaudière : on utilise les derniers réglages connus (cache)
        $advice = Cache::remember('okovision.dashboard.advice', now()->addHours(3), function () {
            try {
                $boiler = app(BoilerService::class);
                $params = $boiler->read(array_values(Advisor::P));
                $values = array_map(fn ($n) => isset($params[$n]) ? $params[$n]->value() : null, Advisor::P);

                return app(Advisor::class)->analyse($values, $boiler->currentCurve());
            } catch (\Throwable) {
                return null;
            }
        });

        $status = $live['values']['status'] ?? ($last?->pe1_status);

        return [
            'live' => $live,
            'last' => $last,
            'status' => $status,
            'statusLabel' => $live['labels']['status'] ?? app(Catalog::class)->statusLabel($status === null ? null : (int) $status),
            'statusColor' => match (true) {
                $status === null => 'gray',
                in_array((int) $status, [8, 9, 11], true) => 'danger',
                (int) $status === 4 => 'success',
                in_array((int) $status, [1, 2, 3, 5], true) => 'warning',
                default => 'gray',
            },
            'today' => $today,
            'yesterday' => $yesterday,
            'silo' => $stock->silo(),
            'ash' => $stock->ashBox(),
            'day' => $day,
            'range' => $range,
            'dayCharts' => $dayCharts,
            'charts' => $charts,
            'alerts' => AlertEvent::query()->whereNull('resolved_at')->latest('triggered_at')->limit(5)->get(),
            'advice' => $advice,
        ];
    }

    /**
     * Min/max du jour des T extérieure et ambiance, cohérents avec les valeurs affichées.
     * Avec une lecture directe (valeur affichée = maintenant), on prend les mesures d'aujourd'hui et on y ajoute cette
     * lecture : les CSV n'arrivent que toutes les 30 min à 2 h, la valeur en direct peut sortir de leur plage.
     * Sans lecture directe, la valeur affichée est la dernière mesure : on garde la plage de son jour.
     *
     * @param  array<string, mixed>  $live
     */
    private function dayRange(array $live, string $lastDay): object
    {
        $hasLive = isset($live['outside_temp']) || isset($live['hc1_room_temp']);
        $day = $hasLive ? now()->format('Y-m-d') : $lastDay;

        $r = DB::table('measurements')->whereBetween('ts', [$day.' 00:00:00', $day.' 23:59:59'])
            ->selectRaw('MIN(outside_temp) out_min, MAX(outside_temp) out_max, MIN(hc1_room_temp) room_min, MAX(hc1_room_temp) room_max')
            ->first();

        $extend = function (?float $min, ?float $max, mixed $value): array {
            if ($value === null) {
                return [$min, $max];
            }
            $value = (float) $value;

            return [$min === null ? $value : min($min, $value), $max === null ? $value : max($max, $value)];
        };

        [$outMin, $outMax] = $extend($r?->out_min === null ? null : (float) $r->out_min, $r?->out_max === null ? null : (float) $r->out_max, $live['outside_temp'] ?? null);
        [$roomMin, $roomMax] = $extend($r?->room_min === null ? null : (float) $r->room_min, $r?->room_max === null ? null : (float) $r->room_max, $live['hc1_room_temp'] ?? null);

        return (object) ['out_min' => $outMin, 'out_max' => $outMax, 'room_min' => $roomMin, 'room_max' => $roomMax];
    }
}
