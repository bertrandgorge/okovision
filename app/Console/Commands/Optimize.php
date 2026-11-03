<?php

namespace App\Console\Commands;

use App\Optimizer\BlockHeating;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('okovision:optimize')]
#[Description('Exécute le pilotage automatique (chauffage par blocs) s\'il est activé')]
class Optimize extends Command
{
    public function handle(BlockHeating $strategy): int
    {
        $action = $strategy->run();
        if ($action) {
            $this->line(($action->dry_run ? '[simulation] ' : '').$action->action.' : '.$action->reason);
        }

        return self::SUCCESS;
    }
}
