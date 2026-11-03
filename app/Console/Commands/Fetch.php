<?php

namespace App\Console\Commands;

use App\Okofen\MeasurementImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('okovision:fetch {--force : Retélécharge tous les fichiers disponibles}')]
#[Description('Télécharge les fichiers CSV de la chaudière, met à jour les mesures, cycles et synthèses')]
class Fetch extends Command
{
    public function handle(MeasurementImporter $importer): int
    {
        try {
            $report = $importer->fetchFromBoiler((bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report as $file => $rows) {
            $this->line(sprintf('%s : %s', $file, $rows < 0 ? 'erreur' : $rows.' lignes'));
        }

        return self::SUCCESS;
    }
}
