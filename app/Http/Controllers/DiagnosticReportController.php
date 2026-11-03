<?php

namespace App\Http\Controllers;

use App\Diagnostics\MarkdownReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /diagnostic.md : rapport Markdown complet pour un LLM.
 * Accès : session ouverte, ou ?token=… (jeton généré dans Paramètres).
 * Options : days (1–365, défaut 30), sample (minutes entre deux lignes de mesures sur 24 h, 0 = aucune, défaut 15),
 *           expert=0 pour exclure les réglages technicien.
 */
class DiagnosticReportController extends Controller
{
    public function __invoke(Request $request, MarkdownReport $report): Response
    {
        $token = (string) settings('diagnostic.token', '');
        $authorized = auth()->check() || ($token !== '' && hash_equals($token, (string) $request->query('token', '')));
        abort_unless($authorized, 403, 'Jeton invalide');

        $days = max(1, min(365, (int) $request->query('days', 30)));
        $sample = max(0, min(60, (int) $request->query('sample', 15)));
        $expert = $request->query('expert', '1') !== '0';

        return response($report->build($days, $sample, $expert), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="okovision-diagnostic-'.now()->format('Y-m-d').'.md"',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
