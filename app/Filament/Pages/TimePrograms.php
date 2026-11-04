<?php

namespace App\Filament\Pages;

use App\Okofen\BoilerService;
use App\Okofen\TimeProgram;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class TimePrograms extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Programmes horaires';

    protected static ?string $title = 'Programmes horaires';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'programmes';

    protected string $view = 'filament.pages.time-programs';

    #[Url]
    public string $circuit = 'hk';

    #[Url]
    public int $program = 0;

    /** jour => 3 plages ['start' => 'HH:MM' ou '', 'end' => …] (édition) */
    public array $edit = [];

    /** jour => plages en minutes, telles que lues sur la chaudière */
    public array $current = [];

    public ?int $active = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    public function updatedCircuit(): void
    {
        $this->circuit = array_key_exists($this->circuit, TimeProgram::CIRCUITS) ? $this->circuit : 'hk';
        $this->load();
    }

    public function updatedProgram(): void
    {
        $this->program = $this->program === 1 ? 1 : 0;
        $this->load();
    }

    public function load(): void
    {
        $this->circuit = array_key_exists($this->circuit, TimeProgram::CIRCUITS) ? $this->circuit : 'hk';
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
    }

    public function resetEdit(): void
    {
        $this->edit = self::toEdit($this->current);
    }

    public function clearDay(int $day): void
    {
        $this->edit[$day] = self::emptySlots();
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
            foreach ($this->edit[$d] ?? [] as $slot) {
                $start = TimeProgram::minutes($slot['start'] ?? null);
                $end = TimeProgram::minutes($slot['end'] ?? null);
                if (blank($slot['start'] ?? null) && blank($slot['end'] ?? null)) {
                    continue;
                }
                if ($start === null || $end === null) {
                    return TimeProgram::DAYS[$d].' : plage incomplète';
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

    protected function getHeaderActions(): array
    {
        $label = fn () => TimeProgram::CIRCUITS[$this->circuit].' – programme '.($this->program + 1);

        return [
            Action::make('reload')->label('Relire')->icon(Heroicon::OutlinedArrowPath)->color('gray')->action(fn () => $this->load()),

            Action::make('activate')
                ->label(fn () => 'Activer le programme '.($this->program + 1))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->visible(fn () => $this->error === null && $this->active !== null && $this->active !== $this->program)
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Utiliser le programme '.($this->program + 1).' pour : '.TimeProgram::CIRCUITS[$this->circuit].' ?')
                ->action(function () {
                    $results = app(BoilerService::class)->apply([TimeProgram::activeVariable($this->circuit) => $this->program], 'manual', auth()->id());
                    $failed = array_filter($results, fn ($r) => $r['status'] !== 'applied');
                    $failed
                        ? Notification::make()->title('Changement refusé')->body(implode("\n", array_column($failed, 'message')))->danger()->persistent()->send()
                        : Notification::make()->title('Programme '.($this->program + 1).' activé')->success()->send();
                    $this->load();
                }),

            Action::make('apply')
                ->label('Appliquer')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn () => $this->error !== null || ! $this->hasChanges() || is_string($this->parsed()))
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Écrire ce programme sur la chaudière ? ('.$label().')')
                ->modalDescription(fn () => is_string($p = $this->parsed()) ? $p : new HtmlString(
                    '<div class="text-start text-sm space-y-2"><div><strong>Actuel</strong> : '.e(TimeProgram::summary($this->current)).'</div>'
                    .'<div><strong>Nouveau</strong> : '.e(TimeProgram::summary($p)).'</div></div>'))
                ->action(function () {
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
                }),
        ];
    }

    protected function getViewData(): array
    {
        $parsed = $this->parsed();

        return [
            'circuits' => TimeProgram::CIRCUITS,
            'days' => TimeProgram::DAYS,
            'parsed' => $parsed,
            'invalid' => is_string($parsed) ? $parsed : null,
            'changed' => $this->error === null && $this->hasChanges(),
            'summary' => TimeProgram::summary($this->current),
        ];
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
