<?php

namespace App\Okofen;

use Carbon\CarbonImmutable;

/**
 * Valeur lue sur l'API KEBA (index.cgi?action=get&attr=1).
 */
final class BoilerValue
{
    public function __construct(
        public readonly string $name,
        public readonly string $raw,
        public readonly ?float $divisor,
        public readonly ?string $unit,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly array $options,
        public readonly ?string $shortText,
        public readonly bool $ok,
    ) {}

    public static function fromApi(array $row): self
    {
        $div = self::number($row['divisor'] ?? null);
        $scale = $div && $div != 0.0 ? $div : 1.0;
        $lo = self::number($row['lowerLimit'] ?? null);
        $hi = self::number($row['upperLimit'] ?? null);

        $options = [];
        if (! empty($row['formatTexts'])) {
            foreach (explode('|', $row['formatTexts']) as $i => $text) {
                $text = trim($text);
                if ($text !== '' && $text !== '1') {
                    $options[$i] = $text;
                }
            }
        }

        $unit = $row['unitText'] ?? null;

        return new self(
            name: $row['name'],
            raw: (string) ($row['value'] ?? ''),
            divisor: $div,
            unit: ($unit === '???' || $unit === '') ? null : $unit,
            min: $lo !== null ? $lo / $scale : null,
            max: $hi !== null ? $hi / $scale : null,
            options: $options,
            shortText: ($row['shortText'] ?? '') === '???' ? null : ($row['shortText'] ?? null),
            ok: ($row['status'] ?? '') === 'OK',
        );
    }

    /** Représentation scalaire (mise en cache : le cache Laravel ne désérialise pas les objets). */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self(...$a);
    }

    /** Valeur « humaine » (brute / diviseur). */
    public function value(): float|int|string|null
    {
        if (! $this->ok) {
            return null;
        }

        if (! is_numeric($this->raw)) {
            return $this->raw;
        }

        $v = (float) $this->raw;
        if ($this->divisor && $this->divisor != 0.0) {
            $v /= $this->divisor;
        }

        // valeurs « capteur absent » de l'automate
        if (in_array((int) $this->raw, [-32768, 32767, 32766], true)) {
            return null;
        }

        return floor($v) == $v && abs($v) < PHP_INT_MAX ? (int) $v : round($v, 3);
    }

    public function label(): ?string
    {
        $v = $this->value();

        return is_int($v) ? ($this->options[$v] ?? null) : null;
    }

    /** Convertit une valeur humaine en valeur brute à envoyer à l'API. */
    public static function toRaw(float|int|string $value, ?float $divisor): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        $raw = $divisor && $divisor != 0.0 ? $value * $divisor : $value;

        return (string) (int) round($raw);
    }

    /**
     * Horodatages de la chaudière (type datetime du catalogue) : secondes depuis 1970 en heure LOCALE comptée comme
     * de l'UTC (l'horloge de la chaudière, CAPPL:LOCAL.L_fernwartung_uhrzeit_neu, est en avance d'une heure sur
     * time() en hiver).
     */
    public static function toBoilerTime(\DateTimeInterface $local): int
    {
        return CarbonImmutable::parse($local->format('Y-m-d H:i:s'), 'UTC')->timestamp;
    }

    public static function fromBoilerTime(float|int|string $raw): CarbonImmutable
    {
        return CarbonImmutable::parse(gmdate('Y-m-d H:i:s', (int) $raw), config('app.timezone'));
    }

    private static function number(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
