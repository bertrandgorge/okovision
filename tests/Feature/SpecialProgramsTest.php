<?php

namespace Tests\Feature;

use App\Okofen\BoilerService;
use App\Okofen\BoilerValue;
use App\Okofen\SpecialPrograms;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SpecialProgramsTest extends TestCase
{
    /** @return list<array<string, mixed>> écritures reçues, dans l'ordre */
    private function capture(callable $action): array
    {
        $calls = [];
        $this->mock(BoilerService::class)->shouldReceive('apply')->andReturnUsing(function (array $changes) use (&$calls) {
            $calls[] = $changes;

            return array_map(fn ($n) => ['name' => $n, 'status' => 'applied', 'message' => null], array_keys($changes));
        });
        $errors = $action(app(SpecialPrograms::class));
        $this->assertSame([], $errors);

        return $calls;
    }

    public function test_vacation_writes_dates_and_temperature_before_activating_separately(): void
    {
        CarbonImmutable::setTestNow('2026-11-11 12:00');
        $start = CarbonImmutable::parse('2026-11-20 08:00');
        $end = CarbonImmutable::parse('2026-11-27 18:00');

        $calls = $this->capture(fn (SpecialPrograms $p) => $p->startVacation($start, $end, 14.5));

        $this->assertSame([
            [SpecialPrograms::VACATION_START => BoilerValue::toBoilerTime($start), SpecialPrograms::VACATION_END => BoilerValue::toBoilerTime($end), SpecialPrograms::VACATION_TEMP => 14.5],
            [SpecialPrograms::VACATION_ON => 1],
        ], $calls);
    }

    public function test_party_writes_end_time_then_activation(): void
    {
        CarbonImmutable::setTestNow('2026-11-11 12:00');
        $end = CarbonImmutable::parse('2026-11-11 23:59');

        $calls = $this->capture(fn (SpecialPrograms $p) => $p->startParty($end));

        $this->assertSame([[SpecialPrograms::PARTY_END => BoilerValue::toBoilerTime($end)], [SpecialPrograms::PARTY_ON => 1]], $calls);
    }

    public function test_invalid_dates_are_refused_without_writing(): void
    {
        CarbonImmutable::setTestNow('2026-11-11 12:00');
        $this->mock(BoilerService::class)->shouldNotReceive('apply');
        $p = app(SpecialPrograms::class);

        $this->assertNotEmpty($p->startParty(CarbonImmutable::parse('2026-11-11 10:00')));
        $this->assertNotEmpty($p->startVacation(CarbonImmutable::parse('2026-11-20'), CarbonImmutable::parse('2026-11-19'), 15));
    }
}
