<?php

namespace App\Console\Commands;

use App\Alerts\AlertEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('okovision:alerts')]
#[Description('Évalue les règles d\'alerte et envoie les notifications')]
class CheckAlerts extends Command
{
    public function handle(AlertEvaluator $evaluator): int
    {
        foreach ($evaluator->run() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
