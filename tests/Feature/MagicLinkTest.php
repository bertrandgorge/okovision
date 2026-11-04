<?php

namespace Tests\Feature;

use App\Auth\MagicLink;
use App\Filament\Auth\MagicLinkLogin;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFrom(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_login_page_has_no_password_field(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('type="password"', false);
    }

    public function test_requesting_a_link_emails_known_users_only(): void
    {
        Notification::fake();
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create(['email' => 'moi@example.com']);

        Livewire::test(MagicLinkLogin::class)->set('data.email', 'MOI@example.com')->call('authenticate')->assertHasNoErrors();
        Notification::assertSentTo($user, MagicLinkNotification::class);

        Livewire::test(MagicLinkLogin::class)->set('data.email', 'inconnu@example.com')->call('authenticate')->assertHasNoErrors();
        Notification::assertSentTimes(MagicLinkNotification::class, 1);
        $this->assertGuest();
    }

    public function test_link_logs_in_once_with_a_long_lived_remember_cookie(): void
    {
        $user = User::factory()->create();
        $url = app(MagicLink::class)->issue($user);
        $token = $this->tokenFrom($url);

        // Ouvrir le lien (antivirus, aperçu) ne connecte pas et ne consomme pas le jeton
        $this->get($url)->assertOk()->assertSee('Se connecter');
        $this->assertGuest();

        $response = $this->post(route('magic-link.login', ['token' => $token]));
        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $recaller = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($recaller, 'cookie « se souvenir de moi » absent');
        $this->assertGreaterThan(now()->addDays(399)->getTimestamp(), $recaller->getExpiresTime());

        // Usage unique
        auth()->logout();
        $this->post(route('magic-link.login', ['token' => $token]))->assertRedirect(route('magic-link.show', ['token' => $token]));
        $this->assertGuest();
        $this->get($url)->assertOk()->assertSee('expiré');
    }

    public function test_link_expires(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFrom(app(MagicLink::class)->issue($user));

        $this->travel(MagicLink::TTL_MINUTES + 1)->minutes();
        $this->post(route('magic-link.login', ['token' => $token]));
        $this->assertGuest();
    }

    public function test_remember_cookie_is_refreshed_on_visits(): void
    {
        $user = User::factory()->create(['remember_token' => 'abc']);

        $cookie = collect($this->actingAs($user)->get('/donnees')->assertOk()->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(now()->addDays(399)->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_command_creates_user_and_prints_a_link(): void
    {
        $this->artisan('okovision:user', ['email' => 'nouveau@example.com', '--name' => 'Nouveau'])
            ->expectsOutputToContain('/connexion/')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'nouveau@example.com', 'name' => 'Nouveau']);
    }
}
