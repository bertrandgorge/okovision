<?php

use Illuminate\Support\Facades\Schedule;

// Une seule tâche cron, au choix (toutes les tâches quotidiennes tombent sur des heures pleines) :
//   */5 * * * *   cd ~/okovision && php artisan schedule:run >> /dev/null 2>&1    ← recommandé
//   */30 * * * *  alertes avec 30 min de décalage au plus, pilotage par blocs déconseillé
//   0 * * * *     alertes et données avec 1 h de décalage au plus, pas de pilotage par blocs
// Les tâches « toutes les 5 minutes » s'exécutent alors à chaque passage du cron.

// Récupération des CSV de la chaudière toutes les 30 min (le fichier du jour est relu en entier : aucune mesure
// n'est perdue, les courbes ont au plus 30 min de retard ; bouton « Actualiser » du tableau de bord pour forcer)
Schedule::command('okovision:fetch')->everyThirtyMinutes()->withoutOverlapping(25);

// Synthèse complète de la veille (le fichier de la veille est finalisé à minuit)
Schedule::command('okovision:aggregate')->dailyAt('01:00');
Schedule::command('okovision:recalibrate')->dailyAt('02:00');

// Relevé des réglages (une ligne seulement si un réglage a changé) et compteurs internes
Schedule::command('okovision:snapshot')->dailyAt('03:00');
Schedule::command('okovision:counters')->dailyAt('23:00');

// Alertes et pilotage
Schedule::command('okovision:alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('okovision:optimize')->everyFiveMinutes()->withoutOverlapping(10);

// Archivage mensuel des mesures minute anciennes
Schedule::command('okovision:archive --optimize')->monthlyOn(2, '04:00');

// Ménage
Schedule::command('queue:prune-failed --hours=720')->weekly();
