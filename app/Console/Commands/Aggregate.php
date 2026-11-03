<?php

namespace App\Console\Commands;

use App\Analysis\DayAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('okovision:aggregate {from? : Date de début (défaut : hier)} {to? : Date de fin (défaut : from)}')]
#[Description('Recalcule cycles brûleur et synthèses journalières')]
class Aggregate extends Command
{
    public function handle(DayAggregator $aggregator): int
    {
        $from = CarbonImmutable::parse($this->argument('from') ?? 'yesterday')->startOfDay();
        $to = CarbonImmutable::parse($this->argument('to') ?? $from)->startOfDay();

        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $stat = $aggregator->aggregate($d);
            $this->line($d->format('Y-m-d').' : '.($stat ? "{$stat->cycles} cycles, {$stat->pellets_kg} kg" : 'pas de données'));
        }

        return self::SUCCESS;
    }
}
