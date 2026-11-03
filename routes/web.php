<?php

use App\Http\Controllers\DiagnosticReportController;
use Illuminate\Support\Facades\Route;

// L'interface est servie par le panneau Filament (App\Providers\Filament\AdminPanelProvider), à la racine du site.

// Rapport Markdown pour diagnostic par un LLM (session ou ?token=…)
Route::get('/diagnostic.md', DiagnosticReportController::class)
    ->middleware('throttle:20,1')
    ->name('diagnostic.markdown');
