<?php

namespace App\Livewire;

use App\Analysis\HotWater;
use App\Okofen\BoilerService;
use App\Okofen\SettingsHistory;
use App\Okofen\TimeProgram;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Éditeur d'un programme horaire (chauffage, eau chaude ou bouclage) : aperçu de la semaine, plages jour par jour,
 * activation du programme 1 ou 2 et écriture sur la chaudière. Émet « time-program-saved » après une écriture.
 */
class TimeProgramEditor extends Component
{
    #[Locked]
    public string $circuit = 'hk';

    public int $program = 0;

    /** jour => 3 plages ['start' => 'HH:MM' ou '', 'end' => …] (édition) */
    public array $edit = [];

    /** jour => plages en minutes, telles que lues sur la chaudière */
    public array $current = [];

    public ?int $active = null;

    public ?string $error = null;

    /** Réglage jour par jour (sinon : une seule série de plages pour tous les jours, éditée sur le lundi) */
    public bool $perDay = true;

    /** Jours qui différaient quand on est passé à « tous les jours » (avertissement) */
    public bool $merged = false;

    /** Jour qui porte les plages en mode « tous les jours » */
    public const ALL_DAYS_SOURCE = 1;

    public function mount(string $circuit): void
    {
        abort_unless(array_key_exists($circuit, TimeProgram::CIRCUITS), 404);
        $this->circuit = $circuit;
        $this->load();
        // ouvrir directement le programme utilisé par la chaudière
        if ($this->active !== null && $this->active !== $this->program) {
            $this->program = $this->active;
            $this->load();
        }
    }

    public function updatedProgram(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->program = $this->program === 1 ? 1 : 0;

        try {
            $read = app(TimeProgram::class)->read($this->circuit, $this->program);
            $this->current = $read['days'];
            $this->active = $read['active'];
            $this->error = null;
        } catch (\Throwable $e) {
            $this->current = [];
            $this->active = null;
            $this->error = $e->getMessage();
        }
        $this->edit = self::toEdit($this->current);
        $this->perDay = ! self::sameEveryDay($this->current);
        $this->merged = false;
    }

    public function resetEdit(): void
    {
        $this->edit = self::toEdit($this->current);
        $this->perDay = ! self::sameEveryDay($this->current);
        $this->merged = false;
    }

    /** Passage à « tous les jours » : les plages du lundi s'appliquent à toute la semaine. */
    public function updatedPerDay(): void
    {
        if ($this->perDay) {
            $this->merged = false;

            return;
        }
        $source = $this->edit[self::ALL_DAYS_SOURCE] ?? [];
        $this->merged = collect($this->edit)->contains(fn ($slots) => self::slotsKey($slots) !== self::slotsKey($source));
        foreach (array_keys(TimeProgram::DAYS) as $d) {
            $this->edit[$d] = $source;
        }
    }

    /** @param  array<int, list<array{0: int, 1: int}>>  $days */
    private static function sameEveryDay(array $days): bool
    {
        $first = TimeProgram::normalize($days)[self::ALL_DAYS_SOURCE] ?? [];

        return collect(array_keys(TimeProgram::DAYS))->every(fn ($d) => (TimeProgram::normalize($days)[$d] ?? []) === $first);
    }

    /** Signature des plages saisies d'un jour (plages vides ignorées) */
    private static function slotsKey(array $slots): string
    {
        return json_encode(array_values(array_filter($slots, fn ($s) => filled($s['start'] ?? null) || filled($s['end'] ?? null))));
    }

    public function clearDay(int $day): void
    {
        $this->edit[$day] = self::emptySlots();
    }

    /** Ajoute une plage au jour (dans le premier emplacement libre), après la dernière existante. */
    public function addSlot(int $day): void
    {
        $slots = array_values(array_filter($this->edit[$day] ?? [], fn ($s) => filled($s['start'] ?? null) || filled($s['end'] ?? null)));
        if (count($slots) >= TimeProgram::SLOTS) {
            return;
        }
        $lastEnd = $slots === [] ? null : TimeProgram::minutes(end($slots)['end'] ?? null);
        [$a, $b] = $lastEnd === null ? [360, 1320] : [min($lastEnd + 120, 1380), min($lastEnd + 240, 1439)];
        if ($b <= $a) {
            [$a, $b] = [max(0, $lastEnd - 60), 1439];
        }
        $slots[] = ['start' => TimeProgram::time($a), 'end' => TimeProgram::time($b)];
        $this->edit[$day] = array_pad($slots, TimeProgram::SLOTS, ['start' => '', 'end' => '']);
    }

    /** Supprime une plage du jour (les suivantes remontent). */
    public function removeSlot(int $day, int $index): void
    {
        $slots = $this->edit[$day] ?? [];
        unset($slots[$index]);
        $slots = array_values(array_filter($slots, fn ($s) => filled($s['start'] ?? null) || filled($s['end'] ?? null)));
        $this->edit[$day] = array_pad($slots, TimeProgram::SLOTS, ['start' => '', 'end' => '']);
    }

    /**
     * Remarques sur les plages saisies, par jour : coupures et plages de moins de 2 h (chauffage), plage vide.
     *
     * @return array<int, list<string>>
     */
    private function hints(array|string $parsed): array
    {
        if (is_string($parsed) || $this->circuit !== 'hk') {
            return [];
        }
        $hints = [];
        foreach ($parsed as $d => $slots) {
            foreach ($slots as $i => [$a, $b]) {
                if ($b - $a < 120) {
                    $hints[$d][] = 'Plage de moins de 2 h : le circuit a à peine le temps de monter en température.';
                }
                if ($i > 0 && $a - $slots[$i - 1][1] < 120) {
                    $hints[$d][] = 'Coupure de '.($a - $slots[$i - 1][1]).' min : le circuit passe en réduit, s\'arrête, et peut ne pas repartir au retour du confort (hystérésis d\'ambiance). Préférer une plage continue.';
                }
            }
        }

        return $hints;
    }

    /** Recopie les plages d'un jour sur un ensemble de jours. */
    public function copyDay(int $from, string $to): void
    {
        $targets = match ($to) {
            'weekdays' => [1, 2, 3, 4, 5],
            'weekend' => [6, 0],
            default => [0, 1, 2, 3, 4, 5, 6],
        };
        foreach ($targets as $d) {
            $this->edit[$d] = $this->edit[$from];
        }
    }

    /** @return array<int, list<array{0: int, 1: int}>>|string  plages en minutes, ou message d'erreur */
    public function parsed(): array|string
    {
        $days = [];
        foreach (array_keys(TimeProgram::DAYS) as $d) {
            $days[$d] = [];
            foreach ($this->edit[$this->perDay ? $d : self::ALL_DAYS_SOURCE] ?? [] as $slot) {
                $start = TimeProgram::minutes($slot['start'] ?? null);
                $end = TimeProgram::minutes($slot['end'] ?? null);
                if (blank($slot['start'] ?? null) && blank($slot['end'] ?? null)) {
                    continue;
                }
                if ($start === null || $end === null) {
                    return ($this->perDay ? TimeProgram::DAYS[$d] : 'Tous les jours').' : plage incomplète';
                }
                $days[$d][] = [$start, $end];
            }
        }
        $days = TimeProgram::normalize($days);

        return TimeProgram::validate($days) ?? $days;
    }

    public function hasChanges(): bool
    {
        $parsed = $this->parsed();

        return is_string($parsed) || $parsed !== TimeProgram::normalize($this->current);
    }

    public function activate(): void
    {
        $results = app(BoilerService::class)->apply([TimeProgram::activeVariable($this->circuit) => $this->program], 'manual', auth()->id());
        $failed = array_filter($results, fn ($r) => $r['status'] === 'failed');
        $failed
            ? Notification::make()->title('Changement refusé')->body(implode("\n", array_column($failed, 'message')))->danger()->persistent()->send()
            : Notification::make()->title('Programme '.($this->program + 1).' activé')->success()->send();
        $this->load();
        $this->dispatch('time-program-saved', circuit: $this->circuit);
    }

    public function apply(): void
    {
        $parsed = $this->parsed();
        if (is_string($parsed)) {
            Notification::make()->title($parsed)->danger()->send();

            return;
        }
        $r = app(TimeProgram::class)->write($this->circuit, $this->program, $parsed, auth()->id());
        $r['ok']
            ? Notification::make()->title('Programme enregistré sur la chaudière')->body($r['changed'].' valeur(s) écrite(s) et vérifiée(s).')->success()->send()
            : Notification::make()->title('Écriture incomplète')->body($r['message'])->danger()->persistent()->send();
        $this->load();
        $this->dispatch('time-program-saved', circuit: $this->circuit);
    }

    public function render(): View
    {
        $parsed = $this->parsed();

        return view('livewire.time-program-editor', [
            'days' => TimeProgram::DAYS,
            'parsed' => $parsed,
            'invalid' => is_string($parsed) ? $parsed : null,
            'changed' => $this->error === null && $this->hasChanges(),
            'summary' => TimeProgram::summary($this->current),
            'newSummary' => is_string($parsed) ? null : TimeProgram::summary($parsed),
            'hints' => $this->hints($parsed),
            'chargeDelay' => $this->circuit === 'ww' ? $this->chargeDelay() : null,
        ]);
    }

    /** Délai mesuré entre le début d'une plage ECS et un ballon chaud (programme en vigueur chaque jour). */
    private function chargeDelay(): ?array
    {
        return rescue(function () {
            $history = new SettingsHistory(['CAPPL:LOCAL.ww[0].zeitprogramm[0]' => TimeProgram::summary($this->current)]);
            $cache = [];
            $slots = function (CarbonImmutable $day) use ($history, &$cache) {
                $ts = $day->format('Y-m-d').' 12:00:00';
                $active = (int) ($history->valueAt(TimeProgram::activeVariable('ww'), $ts) ?? 0);
                $summary = $history->valueAt("CAPPL:LOCAL.ww[0].zeitprogramm[{$active}]", $ts);
                $days = ($cache[$summary ?? ''] ??= TimeProgram::parseSummary($summary)) ?? TimeProgram::normalize($this->current);

                return $days[$day->dayOfWeek] ?? [];
            };
            $r = app(HotWater::class)->chargeDelay($slots);

            return $r['count'] >= 2 ? $r : null;
        }, null, report: false);
    }

    /** @return list<array{start: string, end: string}> */
    private static function emptySlots(): array
    {
        return array_fill(0, TimeProgram::SLOTS, ['start' => '', 'end' => '']);
    }

    /** @param  array<int, list<array{0: int, 1: int}>>  $days */
    private static function toEdit(array $days): array
    {
        $edit = [];
        foreach (array_keys(TimeProgram::DAYS) as $d) {
            $slots = self::emptySlots();
            foreach (array_values($days[$d] ?? []) as $i => [$a, $b]) {
                $slots[$i] = ['start' => TimeProgram::time($a), 'end' => TimeProgram::time($b)];
            }
            $edit[$d] = $slots;
        }

        return $edit;
    }
}
