<?php

namespace App\Console\Commands;

use App\Okofen\ParameterSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('okovision:snapshot')]
#[Description('Relève tous les réglages de la chaudière et enregistre un instantané s\'ils ont changé')]
class Snapshot extends Command
{
    public function handle(ParameterSnapshot $snapshot): int
    {
        try {
            $profile = $snapshot->take();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($profile ? $profile->description : 'Aucun changement depuis le dernier relevé');

        return self::SUCCESS;
    }
}
