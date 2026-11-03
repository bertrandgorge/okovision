<?php

namespace App\Alerts;

use App\Analysis\DayAggregator;
use App\Analysis\StockEstimator;
use App\Enums\StockEventType;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\StockEvent;
use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use Illuminate\Support\Facades\DB;

/**
 * Évalue les règles d'alerte, crée/résout les événements et envoie les notifications.
 */
class AlertEvaluator
{
    private ?array $live = null;

    private ?array $lastMeasurement = null;

    public function __construct(
        private readonly BoilerService $boiler,
        private readonly StockEstimator $stock,
        private readonly Catalog $catalog,
        private readonly Notifier $notifier,
    ) {}

    /** @return list<string> journal des actions */
    public function run(): array
    {
        $log = [];
        foreach (AlertRule::query()->where('enabled', true)->with('channels')->get() as $rule) {
            try {
                $result = $this->evaluate($rule);
            } catch (\Throwable $e) {
                $log[] = "{$rule->name} : erreur d'évaluation ({$e->getMessage()})";

                continue;
            }
            $log[] = $this->handle($rule, $result);
        }

        return array_filter($log);
    }

    public function evaluate(AlertRule $rule): AlertResult
    {
        $p = fn (string $k) => $rule->param($k, $rule->type->defaults()[$k] ?? null);

        return match ($rule->type) {
            AlertType::BoilerFault => $this->boilerFault(),
            AlertType::BoilerUnreachable => $this->unreachable(),
            AlertType::NoData => $this->noData((int) $p('minutes')),
            AlertType::SiloLow => $this->siloLow((float) $p('kg'), (int) $p('days')),
            AlertType::AshFull => $this->ashFull((float) $p('pct')),
            AlertType::RoomTempLow => $this->threshold('hc1_room_temp', 'room_temp_low', (float) $p('temp'), 'Température ambiante basse'),
            AlertType::DhwTempLow => $this->threshold('dhw1_temp_on', 'dhw_temp_low', (float) $p('temp'), 'Eau chaude sanitaire froide'),
            AlertType::ShortCycling => $this->shortCycling((int) $p('cycles')),
            AlertType::MaintenanceDue => $this->due(StockEventType::Maintenance, (int) $p('days'), 'Entretien annuel'),
            AlertType::SweepDue => $this->due(StockEventType::Sweep, (int) $p('days'), 'Ramonage'),
        };
    }

    private function handle(AlertRule $rule, AlertResult $result): ?string
    {
        $open = AlertEvent::query()->where('alert_rule_id', $rule->id)->whereNull('resolved_at')->latest('triggered_at')->first();

        if ($result->triggered) {
            $cooldownOver = ! $rule->last_notified_at || $rule->last_notified_at->diffInMinutes(now()) >= $rule->cooldown_minutes;
            if (! $open) {
                $open = AlertEvent::query()->create([
                    'alert_rule_id' => $rule->id,
                    'type' => $rule->type->value,
                    'severity' => $rule->severity,
                    'title' => $result->title,
                    'message' => $result->message,
                    'context' => $result->context,
                    'triggered_at' => now(),
                ]);
            }
            $rule->forceFill(['active' => true, 'last_triggered_at' => now()]);

            if ($cooldownOver) {
                $open->update(['deliveries' => array_merge($open->deliveries ?? [], $this->notifier->send($rule, $result->title, $result->message, $rule->severity, $result->context))]);
                $rule->last_notified_at = now();
            }
            $rule->save();

            return "{$rule->name} : déclenchée".($cooldownOver ? ' (notifiée)' : '');
        }

        if ($open) {
            $open->update(['resolved_at' => now()]);
            if ($rule->notify_resolved) {
                $this->notifier->send($rule, '✅ Résolu : '.$open->title, 'La condition n\'est plus vérifiée.', 'info', $result->context);
            }
        }
        if ($rule->active) {
            $rule->update(['active' => false]);

            return "{$rule->name} : résolue";
        }

        return null;
    }

    private function live(): array
    {
        return $this->live ??= $this->boiler->live();
    }

    private function lastMeasurement(): ?array
    {
        if ($this->lastMeasurement === null) {
            $row = DB::table('measurements')->orderByDesc('ts')->first();
            $this->lastMeasurement = $row ? (array) $row : [];
        }

        return $this->lastMeasurement ?: null;
    }

    private function boilerFault(): AlertResult
    {
        $live = $this->live();
        $status = $live['values']['status'] ?? ($this->lastMeasurement()['pe1_status'] ?? null);
        if ($status !== null && in_array((int) $status, DayAggregator::FAULTS, true)) {
            $label = $live['labels']['status'] ?? $this->catalog->statusLabel((int) $status);

            return new AlertResult(true, "Défaut chaudière : {$label}", "La chaudière est en état « {$label} ». Vérifiez l'écran de la chaudière.", ['status' => $status]);
        }

        return AlertResult::ok(['status' => $status]);
    }

    private function unreachable(): AlertResult
    {
        $error = $this->live()['error'] ?? null;

        return $error
            ? new AlertResult(true, 'Chaudière injoignable', $error)
            : AlertResult::ok();
    }

    private function noData(int $minutes): AlertResult
    {
        $last = $this->lastMeasurement()['ts'] ?? null;
        $age = $last ? (int) now()->diffInMinutes($last, true) : null;

        return $age === null || $age > $minutes
            ? new AlertResult(true, 'Plus de données de la chaudière', $last
                ? "Dernière mesure reçue le {$last} (il y a {$age} min, seuil {$minutes} min). La chaudière écrit son fichier toutes les 2 h environ : au-delà, vérifiez la récupération (php artisan okovision:fetch)."
                : 'Aucune mesure en base.', ['last' => $last])
            : AlertResult::ok(['last' => $last]);
    }

    private function siloLow(float $kg, int $days): AlertResult
    {
        $s = $this->stock->silo();
        if ($s['level_kg'] === null) {
            return AlertResult::ok();
        }
        $low = $s['level_kg'] <= $kg || ($s['days_left'] !== null && $s['days_left'] <= $days);

        return $low
            ? new AlertResult(true, 'Silo bientôt vide', sprintf('Stock estimé : %d kg (%s %%). Épuisement prévu %s. Pensez à commander des granulés.',
                $s['level_kg'], $s['pct'], $s['empty_on'] ? 'vers le '.$s['empty_on'].' ('.$s['days_left'].' jours)' : 'non estimé'), $s)
            : AlertResult::ok($s);
    }

    private function ashFull(float $pct): AlertResult
    {
        $a = $this->stock->ashBox();

        return $a['pct'] !== null && $a['pct'] >= $pct
            ? new AlertResult(true, 'Cendrier bientôt plein', sprintf('%d kg de granulés brûlés depuis le dernier vidage (%s), soit %s %% de la capacité estimée.', $a['burnt_kg'], $a['since'], $a['pct']), $a)
            : AlertResult::ok($a);
    }

    private function threshold(string $column, string $key, float $limit, string $title): AlertResult
    {
        $value = $this->lastMeasurement()[$column] ?? null;

        return $value !== null && (float) $value < $limit
            ? new AlertResult(true, $title, sprintf('%s : %.1f °C (seuil %.1f °C).', $title, $value, $limit), [$key => $value])
            : AlertResult::ok([$key => $value]);
    }

    private function shortCycling(int $max): AlertResult
    {
        $row = DB::table('daily_stats')->where('day', now()->subDay()->format('Y-m-d'))->first();

        return $row && $row->cycles > $max
            ? new AlertResult(true, 'Trop de cycles brûleur', "Hier : {$row->cycles} cycles (seuil {$max}). Consultez la page Optimisation.", ['cycles' => $row->cycles])
            : AlertResult::ok();
    }

    private function due(StockEventType $type, int $days, string $what): AlertResult
    {
        $last = StockEvent::query()->where('type', $type)->latest('occurred_on')->first();
        $age = $last ? (int) $last->occurred_on->diffInDays(now()) : null;

        return $age === null || $age > $days
            ? new AlertResult(true, "{$what} à prévoir", $last ? "{$what} : dernier le ".$last->occurred_on->format('d/m/Y')." (il y a {$age} jours)." : "Aucun {$what} enregistré.")
            : AlertResult::ok(['days' => $age]);
    }
}
