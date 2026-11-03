<?php

namespace App\Optimizer;

use App\Models\OptimizerAction;
use App\Okofen\BoilerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stratégie expérimentale « chauffage par blocs ».
 *
 * À mi-saison, le besoin de la maison est inférieur à la puissance minimale de la chaudière :
 * elle enchaîne des cycles courts (allumage, 15–20 min de combustion, post-ventilation…).
 * On utilise l'inertie du bâtiment comme stockage :
 *   - phase BLOC  : circuit en Auto, consigne confort relevée de `boost_k` → demande plus forte,
 *                   combustion longue à meilleure puissance ;
 *   - phase PAUSE : circuit à l'arrêt (hors gel) tant que l'ambiance reste dans la bande de confort.
 * L'ECS n'est pas affectée. Hors conditions (trop froid, trop doux, hors plage horaire, erreur),
 * les réglages d'origine sont restaurés.
 *
 * Par défaut en simulation (dry_run) : les décisions sont journalisées sans être appliquées.
 */
class BlockHeating
{
    public const MODE = 'CAPPL:LOCAL.hk[0].betriebsart[1]'; // 0 Arrêt, 1 Auto, 2 Confort, 3 Réduit

    public const ROOM = 'CAPPL:LOCAL.hk[0].raumtemp_heizen';

    private const STATE_KEY = 'optimizer.block_heating.state';

    public function __construct(private readonly BoilerService $boiler) {}

    public function enabled(): bool
    {
        return (bool) settings('optimizer.enabled', false);
    }

    public function state(): array
    {
        return (array) settings(self::STATE_KEY, ['phase' => 'idle', 'since' => null, 'original' => null]);
    }

    /** Exécution périodique (toutes les 5 minutes). */
    public function run(): ?OptimizerAction
    {
        if (! $this->enabled()) {
            return $this->state()['phase'] !== 'idle' ? $this->restore('Pilotage désactivé') : null;
        }

        $cfg = (array) settings('optimizer.block_heating', []);
        $state = $this->state();
        $now = CarbonImmutable::now();

        $context = $this->context();
        if ($context === null) {
            return $state['phase'] !== 'idle' ? $this->restore('Données indisponibles') : null;
        }

        $inWindow = $this->inWindow($now, $cfg['window_start'] ?? '06:00', $cfg['window_end'] ?? '22:00');
        $eligible = $inWindow
            && $context['outside_avg'] <= (float) ($cfg['max_outside_temp'] ?? 14)
            && $context['outside_avg'] >= (float) ($cfg['min_outside_temp'] ?? 4);

        if (! $eligible) {
            return $state['phase'] !== 'idle' ? $this->restore($inWindow ? 'Température extérieure hors plage' : 'Hors plage horaire') : null;
        }

        $setpoint = (float) ($state['original']['room'] ?? $context['room_set']);
        $deviation = $context['room'] - $setpoint;
        $since = $state['since'] ? CarbonImmutable::parse($state['since']) : null;
        $elapsed = $since ? (int) $since->diffInMinutes($now) : PHP_INT_MAX;

        return match ($state['phase']) {
            'idle' => $this->startBlock($context, 'Démarrage du pilotage (mi-saison)'),
            'block' => $deviation >= (float) ($cfg['room_band_high'] ?? 0.6) && $elapsed >= (int) ($cfg['min_block_minutes'] ?? 120)
                ? $this->startPause($context, sprintf('Ambiance %.1f °C (consigne %.1f) après %d min de bloc', $context['room'], $setpoint, $elapsed))
                : null,
            'pause' => $deviation <= (float) ($cfg['room_band_low'] ?? -0.4) || $elapsed >= (int) ($cfg['max_pause_minutes'] ?? 240)
                ? $this->startBlock($context, sprintf('Ambiance %.1f °C après %d min de pause', $context['room'], $elapsed))
                : null,
            default => null,
        };
    }

    public function restore(string $reason): ?OptimizerAction
    {
        $state = $this->state();
        $original = $state['original'] ?? null;
        $changes = $original ? [self::MODE => $original['mode'], self::ROOM => $original['room']] : [];
        $action = $this->act('restore', $reason, $changes, []);
        $this->saveState(['phase' => 'idle', 'since' => now()->toIso8601String(), 'original' => null]);

        return $action;
    }

    private function startBlock(array $context, string $reason): OptimizerAction
    {
        $state = $this->state();
        $original = $state['original'] ?? ['mode' => $context['mode'], 'room' => $context['room_set']];
        $boost = (float) settings('optimizer.block_heating.boost_k', 0.5);
        $changes = [self::MODE => 1, self::ROOM => round($original['room'] + $boost, 1)];
        $action = $this->act('block', $reason, $changes, $context);
        $this->saveState(['phase' => 'block', 'since' => now()->toIso8601String(), 'original' => $original]);

        return $action;
    }

    private function startPause(array $context, string $reason): OptimizerAction
    {
        $state = $this->state();
        $changes = [self::MODE => 0, self::ROOM => $state['original']['room'] ?? $context['room_set']];
        $action = $this->act('pause', $reason, $changes, $context);
        $this->saveState(['phase' => 'pause', 'since' => now()->toIso8601String(), 'original' => $state['original']]);

        return $action;
    }

    private function act(string $action, string $reason, array $changes, array $context): OptimizerAction
    {
        $dry = (bool) settings('optimizer.dry_run', true);
        $results = $changes ? $this->boiler->apply($changes, 'optimizer', null, $dry) : [];

        return OptimizerAction::query()->create([
            'strategy' => 'block_heating',
            'action' => $action,
            'reason' => $reason,
            'changes' => ['requested' => $changes, 'results' => $results],
            'context' => $context,
            'dry_run' => $dry,
        ]);
    }

    private function context(): ?array
    {
        $values = $this->boiler->read([self::MODE, self::ROOM, 'CAPPL:LOCAL.L_hk[0].raumtemp_ist'], true);
        $outside = DB::table('measurements')->where('ts', '>=', now()->subHour())->avg('outside_temp');
        $room = $values['CAPPL:LOCAL.L_hk[0].raumtemp_ist']?->value();

        if ($outside === null || $room === null || ! isset($values[self::MODE], $values[self::ROOM])) {
            return null;
        }

        return [
            'outside_avg' => round((float) $outside, 1),
            'room' => (float) $room,
            'room_set' => (float) $values[self::ROOM]->value(),
            'mode' => (int) $values[self::MODE]->value(),
        ];
    }

    private function inWindow(CarbonImmutable $now, string $start, string $end): bool
    {
        $t = $now->format('H:i');

        return $t >= $start && $t < $end;
    }

    private function saveState(array $state): void
    {
        settings()->set(self::STATE_KEY, $state);
    }
}
