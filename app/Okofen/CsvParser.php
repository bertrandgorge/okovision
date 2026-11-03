<?php

namespace App\Okofen;

use Carbon\CarbonImmutable;
use Generator;

/**
 * Lecture des fichiers touch_YYYYMMDD.csv : ISO-8859-1, séparateur « ; », virgule décimale,
 * colonnes Datum (dd.mm.yyyy) et Zeit (HH:MM:SS) puis les mesures.
 */
class CsvParser
{
    public function __construct(private readonly Catalog $catalog) {}

    /**
     * @return Generator<int, array<string, mixed>> lignes prêtes pour la table measurements
     */
    public function parse(string $content): Generator
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (! $lines) {
            return;
        }

        $header = str_getcsv(array_shift($lines), ';', '"', '');
        $map = $this->catalog->csvMap();
        $types = $this->catalog->measurements()->map(fn ($m) => $m['type'])->all();

        $columns = [];
        foreach ($header as $i => $name) {
            $key = Catalog::normalizeHeader($name);
            if (isset($map[$key])) {
                $columns[$i] = $map[$key];
            }
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, ';', '"', '');
            $ts = $this->timestamp($cells[0] ?? '', $cells[1] ?? '');
            if (! $ts) {
                continue;
            }

            $row = ['ts' => $ts];
            foreach ($columns as $i => $column) {
                $row[$column] = self::value($cells[$i] ?? null, $types[$column] ?? 'number');
            }

            yield $row;
        }
    }

    public static function value(?string $cell, string $type): float|int|null
    {
        if ($cell === null) {
            return null;
        }

        $cell = trim($cell);
        if ($cell === '' || $cell === '-') {
            return null;
        }

        $number = (float) str_replace(',', '.', $cell);

        return match ($type) {
            'bool' => $number > 0 ? 1 : 0,
            'percent', 'enum' => (int) max(0, min(255, round($number))),
            'temperature' => abs($number) >= 999 ? null : round($number, 1),
            default => $number,
        };
    }

    private function timestamp(string $date, string $time): ?string
    {
        $date = trim($date);
        $time = trim($time);
        if (! preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('d.m.Y H:i:s', $date.' '.(strlen($time) === 5 ? $time.':00' : $time))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
