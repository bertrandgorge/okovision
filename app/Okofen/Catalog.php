<?php

namespace App\Okofen;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Catalogue des champs de la chaudière (resources/okofen/catalog.yaml).
 */
class Catalog
{
    private ?array $data = null;

    public function __construct(private readonly ?string $path = null) {}

    public function raw(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $path = $this->path ?? config('okovision.catalog_path');
        $key = 'okofen.catalog.'.md5($path.'|'.@filemtime($path));

        return $this->data = Cache::store('array')->rememberForever($key, fn () => Yaml::parseFile($path));
    }

    /** Colonnes de la table measurements, indexées par nom de colonne. */
    public function measurements(): Collection
    {
        return collect($this->raw()['measurements'])->keyBy('column');
    }

    /** Correspondance entête CSV (normalisée) → colonne. */
    public function csvMap(): array
    {
        return $this->measurements()
            ->mapWithKeys(fn (array $m) => [self::normalizeHeader($m['csv']) => $m['column']])
            ->all();
    }

    public function measurement(string $column): ?array
    {
        return $this->measurements()->get($column);
    }

    /** Paramètres réglables, indexés par nom de variable API. */
    public function parameters(): Collection
    {
        return collect($this->raw()['parameters'])->keyBy('name');
    }

    public function parameter(string $name): ?array
    {
        return $this->parameters()->get($name);
    }

    public function groups(): array
    {
        return $this->raw()['groups'];
    }

    /** Valeurs « live » utiles au tableau de bord. */
    public function live(): Collection
    {
        return collect($this->raw()['live'])->keyBy('key');
    }

    public function label(array $item, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $item['labels'][$locale] ?? $item['labels']['fr'] ?? $item['name'] ?? $item['column'] ?? '?';
    }

    /**
     * Valeur conseillée d'un réglage (bloc « advice » du catalogue : range [min, max] ou allowed [valeurs], factory, why),
     * mise en forme, et position de la valeur actuelle par rapport au conseil.
     *
     * @return array{text: ?string, factory: ?string, why: ?string, outside: bool}|null
     */
    public function advice(array $param, mixed $value = null): ?array
    {
        $a = $param['advice'] ?? null;
        if (! $a) {
            return null;
        }
        $unit = $param['unit'] ?? null;
        $options = $param['options'] ?? [];
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ' '), '0'), ',');
        $show = fn ($v) => $options[(int) $v] ?? ($num($v).($unit ? ' '.$unit : ''));

        $text = null;
        $outside = false;
        $known = $value !== null && $value !== '' && is_numeric($value);
        if (isset($a['range'])) {
            [$lo, $hi] = $a['range'];
            $text = $lo == $hi ? $show($lo) : $num($lo).' à '.$show($hi);
            $outside = $known && ((float) $value < $lo - 1e-9 || (float) $value > $hi + 1e-9);
        } elseif (isset($a['allowed'])) {
            $allowed = array_map('intval', $a['allowed']);
            $text = count($allowed) === 1 ? $show($allowed[0])
                : 'autre que '.implode(', ', array_map($show, array_diff(array_keys($options), $allowed)));
            $outside = $known && ! in_array((int) $value, $allowed, true);
        }

        return [
            'text' => $text,
            'factory' => array_key_exists('factory', $a) ? $show($a['factory']) : null,
            'why' => $a['why'] ?? null,
            'outside' => $outside,
        ];
    }

    public function statusLabel(?int $status, ?string $locale = null): string
    {
        if ($status === null) {
            return '—';
        }

        $locale ??= app()->getLocale();
        $options = $this->measurement('pe1_status')['options'] ?? [];
        $labels = $options[$locale] ?? $options['fr'] ?? [];

        return $labels[$status] ?? ('État '.$status);
    }

    public static function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));

        return preg_replace('/\s+/', '', $header);
    }
}
