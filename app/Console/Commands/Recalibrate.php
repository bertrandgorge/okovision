<?php

namespace App\Console\Commands;

use App\Analysis\PelletCalibration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('okovision:recalibrate')]
#[Description('Recalcule le débit de la vis (g/min) à partir des livraisons et met à jour les consommations en kg')]
class Recalibrate extends Command
{
    public function handle(PelletCalibration $calibration): int
    {
        $calibration->flush();
        $intervals = $calibration->intervals();

        $this->table(
            ['Du', 'Au', 'Consommé (kg)', 'Minutes de vis', 'Couverture', 'g/min', 'Retenu'],
            $intervals->map(fn ($i) => [
                $i['from'], ($i['current'] ?? false) ? 'en cours' : $i['to'], $i['consumed_kg'] ?? '—', $i['auger_min'], round($i['coverage'] * 100).' %',
                $i['grams_per_minute'] ?? '—', $i['valid'] ? 'oui' : 'non',
            ])
        );

        $current = $calibration->current();
        $this->info('Débit actuel retenu : '.($current ?? settings('pellets.grams_per_auger_minute')).' g/min');

        // Mise à jour des kg journaliers et par cycle (le débit dépend de l'intervalle de livraison)
        $default = $calibration->gramsPerMinute();
        $cases = $intervals->where('valid', true)
            ->map(fn ($i) => sprintf("WHEN %s >= '%s' AND %s < '%s' THEN %F", '%col', $i['from'], '%col', $i['to'], $i['grams_per_minute']))
            ->implode(' ');
        $expr = fn (string $col) => $cases ? 'CASE '.str_replace('%col', $col, $cases).' ELSE '.$default.' END' : (string) $default;

        DB::update('UPDATE daily_stats SET grams_per_auger_minute = '.$expr('day').',
            pellets_kg = ROUND(auger_min * grams_per_auger_minute / 1000, 2),
            pellets_kg_dhw = ROUND(auger_min_dhw * grams_per_auger_minute / 1000, 2)');
        DB::update('UPDATE burner_cycles SET pellets_kg = ROUND(auger_min * ('.$expr('day').') / 1000, 2)');

        cache()->forget('okovision.signature.730');

        return self::SUCCESS;
    }
}
