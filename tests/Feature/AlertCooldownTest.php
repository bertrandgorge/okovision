<?php

namespace Tests\Feature;

use App\Alerts\AlertEvaluator;
use App\Alerts\AlertResult;
use App\Alerts\AlertType;
use App\Alerts\Notifier;
use App\Analysis\StockEstimator;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use App\Okofen\TimeProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function evaluatorAlwaysTriggered(): AlertEvaluator
    {
        // Mock partiel construit avec les vraies dépendances (dont le Notifier, éventuellement simulé)
        $mock = \Mockery::mock(AlertEvaluator::class, [
            app(BoilerService::class), app(StockEstimator::class), app(Catalog::class), app(Notifier::class), app(TimeProgram::class),
        ])->makePartial();
        $mock->shouldReceive('evaluate')->andReturn(new AlertResult(true, 'Silo bas', 'Il reste 50 kg'));

        return $mock;
    }

    public function test_cooldown_starts_only_once_a_message_was_delivered(): void
    {
        $rule = AlertRule::create(['name' => 'Silo bas', 'type' => AlertType::SiloLow, 'params' => [], 'severity' => 'warning', 'enabled' => true, 'cooldown_minutes' => 720]);

        // Sans canal : l'alerte est ouverte mais aucun rappel n'est compté
        $this->evaluatorAlwaysTriggered()->run();
        $rule->refresh();
        $this->assertTrue($rule->active);
        $this->assertNull($rule->last_notified_at);

        // Un canal est ajouté pendant que l'alerte est en cours : il est notifié dès le passage suivant
        $channel = NotificationChannel::create(['name' => 'Telegram', 'type' => 'telegram', 'config' => ['bot_token' => 'x', 'chat_id' => '1'], 'enabled' => true]);
        $rule->channels()->attach($channel);

        $notifier = $this->mock(Notifier::class);
        $notifier->shouldReceive('send')->once()->andReturn([['channel' => 'Telegram', 'ok' => true, 'error' => null, 'at' => now()->toIso8601String()]]);

        $this->assertSame(['Silo bas : déclenchée (notifiée)'], $this->evaluatorAlwaysTriggered()->run());
        $this->assertNotNull($rule->refresh()->last_notified_at);
    }

    public function test_failed_delivery_does_not_start_the_cooldown(): void
    {
        $rule = AlertRule::create(['name' => 'Silo bas', 'type' => AlertType::SiloLow, 'params' => [], 'severity' => 'warning', 'enabled' => true, 'cooldown_minutes' => 720]);

        $notifier = $this->mock(Notifier::class);
        $notifier->shouldReceive('send')->twice()->andReturn([['channel' => 'Telegram', 'ok' => false, 'error' => 'TLS', 'at' => now()->toIso8601String()]]);

        $this->evaluatorAlwaysTriggered()->run();
        $this->assertNull($rule->refresh()->last_notified_at);
        $this->evaluatorAlwaysTriggered()->run(); // nouvel essai au passage suivant
    }
}
