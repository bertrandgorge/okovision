<?php

namespace Tests\Feature;

use App\Models\BoilerParameterChange;
use App\Okofen\BoilerClient;
use App\Okofen\BoilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BoilerClientTest extends TestCase
{
    use RefreshDatabase;

    private array $values = ['CAPPL:FA[0].pe_einschalthysterese_smart' => '50'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['okovision.boiler.url' => 'http://boiler.test']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($url === 'http://boiler.test/index.cgi') {
                return Http::response('', 303, ['Set-Cookie' => 'session=abc; path=/']);
            }
            if (str_contains($url, 'action=get')) {
                $this->assertSame('fr', $request->header('Accept-Language')[0]);
                $names = json_decode($request->body(), true);

                return Http::response(array_map(fn ($n) => [
                    'name' => $n, 'value' => $this->values[$n] ?? '???', 'divisor' => '10', 'lowerLimit' => '20', 'upperLimit' => '150',
                    'unitText' => 'K', 'formatTexts' => '', 'shortText' => 'Hyst', 'status' => isset($this->values[$n]) ? 'OK' : 'ERROR',
                ], $names));
            }
            if (str_contains($url, 'action=set')) {
                foreach (json_decode($request->body(), true) as $n => $raw) {
                    $this->values[$n] = $raw;
                }

                return Http::response('', 200);
            }

            return Http::response('', 404);
        });
    }

    public function test_reads_values_with_divisor(): void
    {
        $v = app(BoilerClient::class)->getOne('CAPPL:FA[0].pe_einschalthysterese_smart');

        $this->assertSame(5, $v->value());
        $this->assertSame(2.0, $v->min);
        $this->assertSame(15.0, $v->max);
    }

    public function test_apply_writes_raw_value_verifies_and_logs(): void
    {
        $results = app(BoilerService::class)->apply(['CAPPL:FA[0].pe_einschalthysterese_smart' => 12]);

        $this->assertSame('applied', $results[0]['status']);
        $this->assertSame('120', $this->values['CAPPL:FA[0].pe_einschalthysterese_smart']);
        $this->assertDatabaseHas('boiler_parameter_changes', ['old_value' => '5', 'new_value' => '12', 'status' => 'applied']);
    }

    public function test_apply_refuses_out_of_range_values(): void
    {
        $results = app(BoilerService::class)->apply(['CAPPL:FA[0].pe_einschalthysterese_smart' => 30]);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('50', $this->values['CAPPL:FA[0].pe_einschalthysterese_smart']);
        $this->assertSame(1, BoilerParameterChange::query()->count());
    }

    public function test_dry_run_does_not_write(): void
    {
        $results = app(BoilerService::class)->apply(['CAPPL:FA[0].pe_einschalthysterese_smart' => 10], 'optimizer', null, true);

        $this->assertSame('dry_run', $results[0]['status']);
        $this->assertSame('50', $this->values['CAPPL:FA[0].pe_einschalthysterese_smart']);
    }
}
