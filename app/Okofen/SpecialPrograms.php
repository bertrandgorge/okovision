<?php

namespace App\Okofen;

use Carbon\CarbonImmutable;

/**
 * Programmes soirée et vacances du circuit de chauffage (manuel E1389FR p. 20).
 *
 * - Soirée : force le confort jusqu'à l'heure de fin, puis la chaudière repasse seule sur Arrêt.
 * - Vacances : entre deux dates, remplace le programme horaire par une T ambiante réduite.
 *
 * Les dates s'écrivent en heure locale comptée comme de l'UTC (BoilerValue::toBoilerTime) et doivent être envoyées
 * AVANT l'activation, dans une écriture séparée : envoyées ensemble, l'activation est relue à 0.
 */
class SpecialPrograms
{
    public const PARTY_ON = 'CAPPL:LOCAL.hk[0].partyprg_aktiviert';

    public const PARTY_END = 'CAPPL:LOCAL.hk[0].partyprg_endzeit';

    public const VACATION_ON = 'CAPPL:LOCAL.hk[0].urlaubsprg_aktiviert';

    public const VACATION_START = 'CAPPL:LOCAL.hk[0].urlaubsprg_start';

    public const VACATION_END = 'CAPPL:LOCAL.hk[0].urlaubsprg_ende';

    public const VACATION_TEMP = 'CAPPL:LOCAL.hk[0].raumtemp_urlaub';

    /** Variables gérées ici (retirées de la page « Réglages chaudière ») */
    public const NAMES = [self::PARTY_ON, self::PARTY_END, self::VACATION_ON, self::VACATION_START, self::VACATION_END, self::VACATION_TEMP];

    public function __construct(private readonly BoilerService $boiler) {}

    /**
     * @return array{party: array{on: bool, active: bool, end: ?CarbonImmutable},
     *               vacation: array{on: bool, active: bool, start: ?CarbonImmutable, end: ?CarbonImmutable, temp: ?float}}
     */
    public function read(bool $fresh = false): array
    {
        $v = $this->boiler->read(self::NAMES, $fresh);
        $time = fn (string $n) => isset($v[$n]) && $v[$n]->ok && is_numeric($v[$n]->raw) && (float) $v[$n]->raw > 0 ? BoilerValue::fromBoilerTime($v[$n]->raw) : null;
        $on = fn (string $n) => isset($v[$n]) && (int) $v[$n]->value() === 1;
        $now = CarbonImmutable::now();

        $partyEnd = $time(self::PARTY_END);
        $vacStart = $time(self::VACATION_START);
        $vacEnd = $time(self::VACATION_END);

        return [
            'party' => ['on' => $on(self::PARTY_ON), 'active' => $on(self::PARTY_ON) && $partyEnd?->isAfter($now), 'end' => $partyEnd],
            'vacation' => [
                'on' => $on(self::VACATION_ON),
                'active' => $on(self::VACATION_ON) && $vacEnd?->isAfter($now) && ($vacStart === null || $vacStart->isBefore($now)),
                'start' => $vacStart,
                'end' => $vacEnd,
                'temp' => isset($v[self::VACATION_TEMP]) && $v[self::VACATION_TEMP]->value() !== null ? (float) $v[self::VACATION_TEMP]->value() : null,
            ],
        ];
    }

    /** @return list<string> messages d'erreur (vide = succès) */
    public function startParty(CarbonImmutable $end, ?int $userId = null): array
    {
        if (! $end->isAfter(CarbonImmutable::now())) {
            return ['L\'heure de fin doit être dans le futur.'];
        }

        return $this->steps([[self::PARTY_END => BoilerValue::toBoilerTime($end)], [self::PARTY_ON => 1]], $userId);
    }

    /** @return list<string> */
    public function stopParty(?int $userId = null): array
    {
        return $this->steps([[self::PARTY_ON => 0]], $userId);
    }

    /** @return list<string> */
    public function startVacation(CarbonImmutable $start, CarbonImmutable $end, float $temp, ?int $userId = null): array
    {
        if (! $end->isAfter($start)) {
            return ['La fin doit être après le début.'];
        }
        if (! $end->isAfter(CarbonImmutable::now())) {
            return ['La fin doit être dans le futur.'];
        }

        return $this->steps([
            [self::VACATION_START => BoilerValue::toBoilerTime($start), self::VACATION_END => BoilerValue::toBoilerTime($end), self::VACATION_TEMP => $temp],
            [self::VACATION_ON => 1],
        ], $userId);
    }

    /** @return list<string> */
    public function stopVacation(?int $userId = null): array
    {
        return $this->steps([[self::VACATION_ON => 0]], $userId);
    }

    /**
     * Écritures successives, arrêt au premier échec.
     *
     * @param  list<array<string, float|int>>  $steps
     * @return list<string>
     */
    private function steps(array $steps, ?int $userId): array
    {
        try {
            foreach ($steps as $changes) {
                $failed = array_filter($this->boiler->apply($changes, 'manual', $userId), fn ($r) => $r['status'] === 'failed');
                if ($failed) {
                    return array_values(array_map(fn ($r) => (string) $r['message'], $failed));
                }
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        return [];
    }
}
