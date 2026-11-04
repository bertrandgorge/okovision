<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiagnosticReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['okovision.boiler.url' => 'http://boiler.test']);
        Http::fake(['*' => Http::response('', 500)]); // chaudière injoignable : le rapport doit quand même sortir
    }

    public function test_requires_session_or_token(): void
    {
        $this->get('/diagnostic.md')->assertForbidden();
        $this->get('/diagnostic.md?token=nope')->assertForbidden();

        settings()->set('diagnostic.token', 'secret-token');
        $this->get('/diagnostic.md?token=secret-token')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    }

    public function test_authenticated_user_gets_full_report(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/diagnostic.md?days=7&sample=0')
            ->assertOk()
            ->assertSee('# Diagnostic chaudière', false)
            ->assertSee('## Installation', false)
            ->assertSee('## Dictionnaire des mesures', false)
            ->assertSee('Chaudière injoignable', false);
    }

    public function test_dashboard_link_includes_the_token_when_there_is_one(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertOk()->assertSee(route('diagnostic.markdown'), false)->assertDontSee('diagnostic.md?token=', false);

        settings()->set('diagnostic.token', 'secret-token');
        $this->get('/')->assertOk()->assertSee(route('diagnostic.markdown', ['token' => 'secret-token']), false);
    }
}
