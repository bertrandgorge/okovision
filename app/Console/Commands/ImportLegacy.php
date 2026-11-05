<?php

namespace App\Console\Commands;

use App\Analysis\DayAggregator;
use App\Analysis\PelletCalibration;
use App\Enums\StockEventType;
use App\Models\BoilerProfile;
use App\Models\StockEvent;
use App\Okofen\Catalog;
use App\Okofen\CsvParser;
use App\Okofen\MeasurementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('okovision:import-legacy
    {--from= : Première date à importer (YYYY-MM-DD)}
    {--to= : Dernière date à importer (YYYY-MM-DD)}
    {--skip-measurements : Ne pas importer les mesures minute}
    {--skip-aggregate : Ne pas recalculer cycles et synthèses}')]
#[Description("Importe l'historique de l'ancienne application Okovision (connexion « legacy »)")]
class ImportLegacy extends Command
{
    private const EVENT_TYPES = [
        'PELLET' => StockEventType::Refill,
        'ASHES' => StockEventType::AshEmptied,
        'SWEEP' => StockEventType::Sweep,
        'MAINT' => StockEventType::Maintenance,
    ];

    public function handle(Catalog $catalog, MeasurementImporter $importer, DayAggregator $aggregator, PelletCalibration $calibration): int
    {
        $legacy = DB::connection('legacy');

        $this->importEvents($legacy);
        $this->importProfiles($legacy);

        $range = $legacy->table('oko_historique_full')->selectRaw('MIN(jour) as a, MAX(jour) as b')->first();
        $from = CarbonImmutable::parse($this->option('from') ?: $range->a)->startOfMonth();
        $to = CarbonImmutable::parse($this->option('to') ?: $range->b);

        if (! $this->option('skip-measurements')) {
            $this->importMeasurements($legacy, $catalog, $importer, $from, $to);
        }

        if (! $this->option('skip-aggregate')) {
            // 1er passage : minutes de vis par jour ; puis calibration ; puis 2e passage pour les kg
            $this->aggregate($aggregator, $from, $to, 380.0);
            $calibration->flush();
            $this->call('okovision:recalibrate');
        }

        return self::SUCCESS;
    }

    private function importEvents($legacy): void
    {
        $rows = $legacy->table('oko_silo_events')->orderBy('event_date')->get();
        $n = 0;
        foreach ($rows as $r) {
            $type = self::EVENT_TYPES[trim($r->event_type)] ?? StockEventType::Other;
            StockEvent::query()->updateOrCreate(
                ['occurred_on' => $r->event_date, 'type' => $type],
                [
                    'quantity_kg' => $type === StockEventType::Refill ? (int) $r->quantity : null,
                    'remaining_kg' => $type === StockEventType::Refill ? (int) $r->remaining : null,
                    'price' => $r->price > 0 ? (float) $r->price : null,
                ]
            );
            $n++;
        }
        $this->info("Événements silo/entretien : {$n}");
    }

    private function importProfiles($legacy): void
    {
        $map = [
            'hk0_' => 'CAPPL:LOCAL.hk[0].',
            'FA0_' => 'CAPPL:FA[0].',
        ];
        $n = 0;
        foreach ($legacy->table('oko_boiler')->orderBy('timestamp')->get() as $r) {
            $values = [];
            foreach ((array) json_decode($r->config, true) as $key => $value) {
                foreach ($map as $prefix => $name) {
                    if (str_starts_with($key, $prefix)) {
                        $values[$name.substr($key, strlen($prefix))] = (float) str_replace(',', '.', explode(' ', (string) $value)[0]);
                    }
                }
            }
            $date = CarbonImmutable::createFromTimestamp($r->timestamp, config('app.timezone'));
            BoilerProfile::query()->updateOrCreate(
                ['name' => $date->format('Y-m-d H:i').' – '.($r->description ?: 'Configuration')],
                ['description' => 'Importé de l\'ancienne application', 'values' => $values, 'created_at' => $date]
            );
            $n++;
        }
        $this->info("Profils de réglages : {$n}");
    }

    private function importMeasurements($legacy, Catalog $catalog, MeasurementImporter $importer, CarbonImmutable $from, CarbonImmutable $to): void
    {
        // Colonnes legacy col_N → colonnes du catalogue via l'entête CSV d'origine
        $csvMap = $catalog->csvMap();
        $types = $catalog->measurements()->map(fn ($m) => $m['type'])->all();
        $columns = [];
        foreach ($legacy->table('oko_capteur')->get() as $c) {
            $key = Catalog::normalizeHeader((string) $c->original_name);
            if (isset($csvMap[$key])) {
                $columns['col_'.$c->column_oko] = $csvMap[$key];
            }
        }
        $this->info('Colonnes reconnues : '.count($columns).' / '.$legacy->table('oko_capteur')->count());

        $months = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $months[] = $m;
        }

        $bar = $this->output->createProgressBar(count($months));
        $total = 0;
        foreach ($months as $month) {
            $rows = $legacy->table('oko_historique_full')
                ->whereBetween('jour', [$month->format('Y-m-d'), min($month->endOfMonth(), $to)->format('Y-m-d')])
                ->orderBy('jour')->orderBy('heure')
                ->select(array_merge(['jour', 'heure'], array_keys($columns)))
                ->cursor();

            $total += $importer->store((function () use ($rows, $columns, $types) {
                foreach ($rows as $r) {
                    $row = ['ts' => $r->jour.' '.$r->heure];
                    foreach ($columns as $legacyColumn => $column) {
                        $v = $r->{$legacyColumn};
                        $row[$column] = $v === null ? null : CsvParser::value((string) $v, $types[$column]);
                    }
                    yield $row;
                }
            })(), 2000);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Mesures importées : {$total}");
    }

    private function aggregate(DayAggregator $aggregator, CarbonImmutable $from, CarbonImmutable $to, float $g): void
    {
        $days = DB::table('measurements')
            ->whereBetween('ts', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DISTINCT DATE(ts) as d')->orderBy('d')->pluck('d');

        $this->info('Calcul des cycles et synthèses : '.$days->count().' jours');
        $bar = $this->output->createProgressBar($days->count());
        foreach ($days as $d) {
            $aggregator->aggregate(CarbonImmutable::parse($d), $g);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }
}
