<?php

namespace App\Archive;

use App\Models\MeasurementArchive;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Archivage des mesures minute : un fichier CSV gzip par mois (≈ 400 Ko au lieu de ≈ 3 Mo en base),
 * puis suppression des lignes de la table measurements. Les synthèses journalières et les cycles
 * restent en base : les graphiques d'historique fonctionnent sans les mesures minute, et la vue
 * « journée » relit le fichier archivé à la volée.
 */
class MeasurementArchiver
{
    private function disk()
    {
        return Storage::disk(settings('archive.disk', 'local'));
    }

    private function path(string $month): string
    {
        return trim((string) settings('archive.path', 'archives'), '/')."/measurements-{$month}.csv.gz";
    }

    /** Mois éligibles à l'archivage (plus anciens que la durée de conservation). */
    public function eligibleMonths(?int $keepMonths = null): array
    {
        $keep = $keepMonths ?? (int) settings('archive.keep_months', 24);
        $limit = CarbonImmutable::today()->startOfMonth()->subMonths($keep)->format('Y-m-d');

        return DB::table('measurements')
            ->where('ts', '<', $limit)
            ->selectRaw("DATE_FORMAT(ts, '%Y-%m') as m")
            ->groupBy('m')->orderBy('m')->pluck('m')->all();
    }

    /**
     * Archive un mois. Les lignes ne sont supprimées qu'après relecture et contrôle du fichier.
     */
    public function archive(string $month, bool $purge = true): MeasurementArchive
    {
        [$from, $to] = $this->bounds($month);
        $columns = Schema::getColumnListing('measurements');
        $path = $this->path($month);

        $tmp = tempnam(sys_get_temp_dir(), 'oko');
        $gz = gzopen($tmp, 'wb6');
        gzwrite($gz, implode(';', $columns)."\n");
        $rows = 0;

        // Si une archive existe déjà (mois partiellement restauré), on fusionne : lignes de la base prioritaires
        $existing = $this->disk()->exists($path) ? $this->readRows($month) : [];

        $dbRows = DB::table('measurements')->where('ts', '>=', $from)->where('ts', '<', $to)->orderBy('ts')->cursor();
        $merged = [];
        foreach ($existing as $r) {
            $merged[$r['ts']] = $r;
        }
        foreach ($dbRows as $r) {
            $merged[$r->ts] = (array) $r;
        }
        ksort($merged);

        foreach ($merged as $r) {
            gzwrite($gz, implode(';', array_map(fn ($c) => $r[$c] ?? '', $columns))."\n");
            $rows++;
        }
        gzclose($gz);

        // Contrôle : relecture complète du fichier temporaire
        $check = 0;
        $h = gzopen($tmp, 'rb');
        gzgets($h);
        while (($line = gzgets($h)) !== false) {
            if (trim($line) !== '') {
                $check++;
            }
        }
        gzclose($h);
        if ($check !== $rows) {
            @unlink($tmp);
            throw new RuntimeException("Archive {$month} incohérente ({$check} lignes relues sur {$rows})");
        }

        $this->disk()->put($path, fopen($tmp, 'rb'));
        $bytes = filesize($tmp);
        $sha = hash_file('sha256', $tmp);
        @unlink($tmp);

        $archive = MeasurementArchive::query()->updateOrCreate(['month' => $month], [
            'path' => $path, 'rows' => $rows, 'bytes' => $bytes, 'sha256' => $sha, 'purged' => false,
        ]);

        if ($purge) {
            DB::table('measurements')->where('ts', '>=', $from)->where('ts', '<', $to)->delete();
            $archive->update(['purged' => true]);
        }

        return $archive;
    }

    /** Réinjecte un mois archivé dans la table measurements. */
    public function restore(string $month): int
    {
        $rows = $this->readRows($month);
        $count = 0;
        foreach (array_chunk($rows, max(1, intdiv(60000, max(1, count($rows[0] ?? [1]))))) as $chunk) {
            DB::table('measurements')->upsert($chunk, ['ts']);
            $count += count($chunk);
        }
        MeasurementArchive::query()->where('month', $month)->update(['purged' => false]);

        return $count;
    }

    /**
     * Lit les mesures d'une journée archivée (pour la vue minute par minute).
     *
     * @return list<object>
     */
    public function readDay(string $date): array
    {
        $month = substr($date, 0, 7);
        if (! MeasurementArchive::query()->where('month', $month)->exists()) {
            return [];
        }

        return array_map(fn ($r) => (object) $r, array_values(array_filter(
            $this->readRows($month, $date),
            fn ($r) => str_starts_with($r['ts'], $date)
        )));
    }

    /** @return list<array<string, mixed>> */
    public function readRows(string $month, ?string $prefix = null): array
    {
        $path = $this->path($month);
        if (! $this->disk()->exists($path)) {
            return [];
        }

        $h = gzopen($this->disk()->path($path), 'rb');
        $columns = explode(';', trim((string) gzgets($h)));
        $rows = [];
        while (($line = gzgets($h)) !== false) {
            if ($prefix !== null && ! str_starts_with($line, $prefix)) {
                continue;
            }
            $values = explode(';', rtrim($line, "\r\n"));
            if (count($values) !== count($columns)) {
                continue;
            }
            $rows[] = array_combine($columns, array_map(fn ($v) => $v === '' ? null : $v, $values));
        }
        gzclose($h);

        return $rows;
    }

    private function bounds(string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new RuntimeException("Mois invalide : {$month}");
        }
        $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        return [$from->format('Y-m-d H:i:s'), $from->addMonth()->format('Y-m-d H:i:s')];
    }
}
