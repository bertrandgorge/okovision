<?php

namespace Tests\Feature;

use App\Models\BoilerParameterChange;
use App\Okofen\BoilerClient;
use App\Okofen\BoilerValue;
use App\Okofen\TimeProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeProgramWriteTest extends TestCase
{
    use RefreshDatabase;

    /** Chaudière simulée : mémoire des valeurs brutes, écritures enregistrées. */
    private function fakeBoiler(array &$memory, array &$writes): void
    {
        $client = $this->mock(BoilerClient::class);
        $client->shouldReceive('get')->andReturnUsing(function (array $names) use (&$memory) {
            $out = [];
            foreach ($names as $n) {
                $out[$n] = BoilerValue::fromApi(['name' => $n, 'value' => $memory[$n] ?? '0', 'status' => 'OK']);
            }

            return $out;
        });
        $client->shouldReceive('getOne')->andReturnUsing(fn ($n) => BoilerValue::fromApi(['name' => $n, 'value' => '0', 'status' => 'OK']));
        $client->shouldReceive('setRaw')->andReturnUsing(function (array $raw) use (&$memory, &$writes) {
            $this->assertLessThanOrEqual(25, count($raw), 'lots de 25 variables au plus');
            $writes[] = $raw;
            $memory = array_merge($memory, $raw);
        });
    }

    public function test_writes_only_changed_values_verifies_and_logs(): void
    {
        $base = TimeProgram::base('hk', 0);
        $memory = TimeProgram::encode(array_fill(0, 7, [[360, 1320]]), $base);
        $writes = [];
        $this->fakeBoiler($memory, $writes);

        // Samedi et dimanche : 07:30 – 23:00
        $days = array_fill(0, 7, [[360, 1320]]);
        $days[6] = $days[0] = [[450, 1380]];
        $result = app(TimeProgram::class)->write('hk', 0, $days, null);

        $this->assertTrue($result['ok'], (string) $result['message']);
        $sent = array_merge(...$writes);
        $this->assertSame('27000', $sent["$base.tag[0].zeitreihe[0,0]"]);
        $this->assertSame('82800', $sent["$base.tag[6].zeitreihe[0,1]"]);
        $this->assertSame('0', $memory["$base.tag[6].block"], 'samedi dans le bloc du dimanche');
        $this->assertArrayNotHasKey("$base.tag[1].zeitreihe[0,0]", $sent, 'le lundi est inchangé');
        $this->assertSame('1', $memory["$base.tag[1].block"], 'Lu-Ve regroupés dans le bloc du lundi');

        $log = BoilerParameterChange::query()->sole();
        $this->assertSame('applied', $log->status);
        $this->assertSame('Tous les jours 06:00-22:00', $log->old_value);
        $this->assertSame('Lu-Ve 06:00-22:00 · Sa-Di 07:30-23:00', $log->new_value);

        // Rien à écrire la seconde fois
        $writes = [];
        $this->assertSame(0, app(TimeProgram::class)->write('hk', 0, $days, null)['changed']);
        $this->assertSame([], $writes);
    }

    public function test_refuses_invalid_program_without_writing(): void
    {
        $memory = [];
        $writes = [];
        $this->fakeBoiler($memory, $writes);

        $result = app(TimeProgram::class)->write('hk', 0, [1 => [[600, 500]]], null);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $writes);
        $this->assertSame(0, BoilerParameterChange::query()->count());
    }
}
