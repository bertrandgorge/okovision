<?php

namespace Tests\Feature;

use App\Models\BoilerProfile;
use App\Okofen\BoilerService;
use App\Okofen\ParameterSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParameterSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private array $values = [
        'CAPPL:FA[0].pe_kesselleistung' => '21',
        'CAPPL:LOCAL.hk[0].heizkurve_steigung' => '13',
        'CAPPL:LOCAL.L_fernwartung_uhrzeit_neu' => '1793744119.176',
    ];

    private bool $online = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['okovision.boiler.url' => 'http://boiler.test']);

        Http::fake(function (Request $request) {
            if (! $this->online) {
                return Http::response('', 500);
            }
            if ($request->url() === 'http://boiler.test/index.cgi') {
                return Http::response('', 303, ['Set-Cookie' => 'session=abc; path=/']);
            }

            return Http::response(array_map(fn ($n) => [
                'name' => $n, 'value' => $this->values[$n] ?? '???', 'divisor' => str_contains($n, 'steigung') ? '10' : '',
                'status' => isset($this->values[$n]) ? 'OK' : 'ERROR',
            ], json_decode($request->body(), true)));
        });
    }

    private function take(): ?BoilerProfile
    {
        app(BoilerService::class)->flushCaches();

        return app(ParameterSnapshot::class)->take();
    }

    public function test_stores_a_row_only_when_a_setting_changes(): void
    {
        $this->assertNotNull($this->take());
        $this->assertNull($this->take());
        $this->assertSame(1, BoilerProfile::query()->automatic()->count());

        $this->values['CAPPL:LOCAL.hk[0].heizkurve_steigung'] = '15';
        $profile = $this->take();

        $this->assertNotNull($profile);
        $this->assertSame(['CAPPL:LOCAL.hk[0].heizkurve_steigung' => ['old' => 1.3, 'new' => 1.5]], $profile->changes);
        $this->assertStringContainsString('1.3 → 1.5', $profile->description);
        $this->assertSame(2, BoilerProfile::query()->automatic()->count());
    }

    public function test_boiler_clock_is_not_a_setting_change(): void
    {
        $first = $this->take();
        $this->values['CAPPL:LOCAL.L_fernwartung_uhrzeit_neu'] = '1793744228.546';

        $this->assertNull($this->take());
        $this->assertArrayNotHasKey('CAPPL:LOCAL.L_fernwartung_uhrzeit_neu', $first->values);
    }

    public function test_temporarily_unreadable_value_is_not_a_change(): void
    {
        $this->take();
        unset($this->values['CAPPL:LOCAL.hk[0].heizkurve_steigung']);

        $this->assertNull($this->take());
    }

    public function test_power_falls_back_to_last_snapshot_when_offline(): void
    {
        $this->take();
        $boiler = app(BoilerService::class);
        $this->assertSame(['value' => 21, 'live' => true], array_intersect_key($boiler->valueOrLastKnown(BoilerService::POWER), ['value' => 1, 'live' => 1]));

        $this->online = false;
        $boiler->flushCaches();
        Cache::flush();
        $fallback = $boiler->valueOrLastKnown(BoilerService::POWER);

        $this->assertSame(21, $fallback['value']);
        $this->assertFalse($fallback['live']);
        $this->assertSame(21.0, $boiler->powerKw());
    }
}
