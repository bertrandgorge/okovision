<?php

namespace App\Console\Commands;

use App\Okofen\BoilerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('okovision:counters')]
#[Description('Relève les compteurs internes de la chaudière (démarrages, heures de fonctionnement)')]
class RecordCounters extends Command
{
    public function handle(BoilerService $boiler): int
    {
        $c = $boiler->recordCounters();
        $this->line("Démarrages : {$c?->burner_starts}, allumages : {$c?->ignitions}, heures : {$c?->burner_hours}");

        return self::SUCCESS;
    }
}
