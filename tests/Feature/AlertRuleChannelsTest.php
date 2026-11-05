<?php

namespace Tests\Feature;

use App\Alerts\AlertType;
use App\Filament\Resources\AlertRules\Pages\ManageAlertRules;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AlertRuleChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_can_be_toggled_from_the_rule_list(): void
    {
        $this->actingAs(User::factory()->create());

        $rule = AlertRule::create(['name' => 'Silo bas', 'type' => AlertType::SiloLow, 'params' => [], 'severity' => 'warning', 'enabled' => true]);
        $telegram = NotificationChannel::create(['name' => 'Mon Telegram', 'type' => 'telegram', 'config' => ['bot_token' => 'x', 'chat_id' => '1'], 'enabled' => true]);
        $mail = NotificationChannel::create(['name' => 'Mon e-mail', 'type' => 'email', 'config' => ['to' => 'a@b.c'], 'enabled' => false]);

        $page = Livewire::test(ManageAlertRules::class)
            ->assertOk()
            ->assertSee('Mon Telegram (Telegram (bot)) : non notifié', false)
            ->assertSee('canal désactivé', false)
            ->assertSee('opacity: 0.35', false);

        $page->call('toggleChannel', $rule->id, $telegram->id)
            ->assertSee('Mon Telegram (Telegram (bot)) : notifié', false);
        $this->assertEquals([$telegram->id], $rule->channels()->pluck('notification_channels.id')->all());

        $page->call('toggleChannel', $rule->id, $telegram->id);
        $this->assertCount(0, $rule->channels()->get());
        $this->assertNotNull($mail);
    }

    public function test_rule_can_be_evaluated_from_the_edit_modal(): void
    {
        $this->actingAs(User::factory()->create());
        $rule = AlertRule::create(['name' => 'Silo bas', 'type' => AlertType::SiloLow, 'params' => [], 'severity' => 'warning', 'enabled' => true]);

        Livewire::test(ManageAlertRules::class)
            ->assertTableActionDoesNotExist('check')
            ->mountTableAction('edit', $rule)
            ->callMountedAction()
            ->assertHasNoErrors();

        Livewire::test(ManageAlertRules::class)
            ->mountAction(\Filament\Actions\Testing\TestAction::make('edit')->table($rule))
            ->callAction(\Filament\Actions\Testing\TestAction::make('check')->table($rule)->schemaComponent(null))
            ->assertNotified();
    }
}
