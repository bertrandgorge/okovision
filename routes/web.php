<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HeatingCurvePage;
use App\Http\Controllers\DiagnosticReportController;
use App\Http\Controllers\MagicLinkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// L'interface est servie par le panneau Filament (App\Providers\Filament\AdminPanelProvider), à la racine du site.

// Connexion par lien magique (envoyé par la page de connexion ou affiché par `php artisan okovision:user`)
Route::get('/connexion/{token}', [MagicLinkController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('magic-link.show');
Route::post('/connexion/{token}', [MagicLinkController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('magic-link.login');

// Rapport Markdown pour diagnostic par un LLM (session ou ?token=…)
Route::get('/diagnostic.md', DiagnosticReportController::class)
    ->middleware('throttle:20,1')
    ->name('diagnostic.markdown');

// Ancienne page « Journée », fusionnée dans le tableau de bord : on garde les liens existants
Route::get('/journee', fn (Request $request) => redirect(Dashboard::getUrl($request->only('date'))));
// Ancienne adresse de la page « Chauffage »
Route::get('/loi-d-eau', fn (Request $request) => redirect(HeatingCurvePage::getUrl($request->only('date', 'days'))));
