<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_access_the_panel_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->actingAs(User::factory()->create())
            ->get('/donnees')
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/donnees')->assertRedirect('/login');
    }
}
