<?php

namespace App\Okofen;

use App\Analysis\DayAggregator;
use App\Models\DailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Récupère les CSV de la chaudière et les enregistre dans la table measurements.
 */
class MeasurementImporter
{
    public function __construct(
        private readonly BoilerClient $client,
        private readonly CsvParser $parser,
        private readonly DayAggregator $aggregator,
    ) {}

    /**
     * Télécharge les fichiers disponibles qui ne sont pas encore complets en base.
     *
     * @return array<string, int> fichier => nombre de lignes importées
     */
    public function fetchFromBoiler(bool $force = false): array
    {
        $report = [];
        $today = CarbonImmutable::today()->format('Ymd');

        foreach ($this->client->listCsvFiles() as $file => $ymd) {
            $day = CarbonImmutable::createFromFormat('Ymd', $ymd)->startOfDay();

            if (! $force && $ymd !== $today && $this->isDayComplete($day)) {
                continue;
            }

            try {
                $rows = $this->importContent($this->client->downloadCsv($file));
                $report[$file] = $rows;
                $this->aggregator->aggregate($day);
            } catch (\Throwable $e) {
                Log::warning("Import {$file} : ".$e->getMessage());
                $report[$file] = -1;
            }
        }

        return $report;
    }

    /** Importe le contenu d'un fichier CSV (téléchargé ou envoyé par l'utilisateur). Retourne le nombre de lignes. */
    public function importContent(string $content): int
    {
        return $this->store($this->parser->parse($content));
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function store(iterable $rows, int $chunk = 1000): int
    {
        $count = 0;
        $buffer = [];

        foreach ($rows as $row) {
            $buffer[] = $row;
            if (count($buffer) >= $chunk) {
                $count += $this->flush($buffer);
                $buffer = [];
            }
        }

        return $count + $this->flush($buffer);
    }

    public function isDayComplete(CarbonImmutable $day): bool
    {
        $stat = DailyStat::query()->find($day->format('Y-m-d'));

        // 1440 minutes, on tolère quelques trous (redémarrage automate, changement d'heure)
        return $stat && $stat->samples >= 1420 && $stat->updated_at?->greaterThan($day->addDay());
    }

    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        // Toutes les lignes d'un lot doivent avoir les mêmes colonnes
        $columns = array_keys(array_merge(...array_map(fn ($r) => array_fill_keys(array_keys($r), true), $rows)));
        $rows = array_map(fn ($r) => array_merge(array_fill_keys($columns, null), $r), $rows);

        // MariaDB limite une requête préparée à 65 535 paramètres
        $perQuery = max(1, intdiv(60000, count($columns)));
        foreach (array_chunk($rows, $perQuery) as $part) {
            DB::table('measurements')->upsert($part, ['ts'], array_values(array_diff($columns, ['ts'])));
        }

        return count($rows);
    }
}
