<?php

namespace App\Console\Commands;

use App\Archive\MeasurementArchiver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('okovision:archive
    {month? : Mois à archiver (YYYY-MM) ; par défaut tous les mois éligibles}
    {--restore : Réinjecte le mois archivé dans la base}
    {--keep-in-db : Crée l\'archive sans supprimer les lignes}
    {--optimize : Lance OPTIMIZE TABLE après la purge pour libérer l\'espace disque}')]
#[Description('Archive les mesures minute anciennes (CSV gzip) et les supprime de la base')]
class Archive extends Command
{
    public function handle(MeasurementArchiver $archiver): int
    {
        $month = $this->argument('month');

        if ($this->option('restore')) {
            if (! $month) {
                $this->error('Précisez le mois à restaurer.');

                return self::FAILURE;
            }
            $this->info($archiver->restore($month).' lignes restaurées pour '.$month);

            return self::SUCCESS;
        }

        $months = $month ? [$month] : $archiver->eligibleMonths();
        foreach ($months as $m) {
            $a = $archiver->archive($m, ! $this->option('keep-in-db'));
            $this->line(sprintf('%s : %d lignes, %s Ko%s', $m, $a->rows, round($a->bytes / 1024), $a->purged ? ', supprimées de la base' : ''));
        }

        if ($months && $this->option('optimize')) {
            DB::statement('OPTIMIZE TABLE measurements');
        }

        return self::SUCCESS;
    }
}
