<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Sur O2Switch, une seule tâche cron suffit :
 *   * * * * * cd ~/okovision && php artisan schedule:run >> /dev/null 2>&1
 */

// Récupération des CSV de la chaudière (fichier du jour mis à jour en continu par la chaudière)
Schedule::command('okovision:fetch')->everyFiveMinutes()->withoutOverlapping(10);

// Synthèse complète de la veille (le fichier de la veille est finalisé à minuit)
Schedule::command('okovision:aggregate')->dailyAt('00:20');
Schedule::command('okovision:recalibrate')->dailyAt('00:40');

// Compteurs internes et relevé des réglages (une ligne seulement si un réglage a changé)
Schedule::command('okovision:counters')->dailyAt('23:55');
Schedule::command('okovision:snapshot')->dailyAt('03:10');

// Alertes et pilotage
Schedule::command('okovision:alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('okovision:optimize')->everyFiveMinutes()->withoutOverlapping(10);

// Archivage mensuel des mesures minute anciennes
Schedule::command('okovision:archive --optimize')->monthlyOn(2, '03:30');

// Ménage
Schedule::command('queue:prune-failed --hours=720')->weekly();
