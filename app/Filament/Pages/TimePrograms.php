<?php

namespace App\Filament\Pages;

use App\Okofen\Catalog;
use App\Okofen\SpecialPrograms;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class TimePrograms extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Programmes horaires';

    protected static ?string $title = 'Programmes horaires du chauffage';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'programmes';

    protected string $view = 'filament.pages.time-programs';

    /** Programmes soirée / vacances tels que lus (dates en 'Y-m-d\TH:i'), null si illisibles */
    public ?array $specials = null;

    /** Saisie des programmes soirée / vacances */
    public string $partyEnd = '';

    public string $vacationStart = '';

    public string $vacationEnd = '';

    public ?string $vacationTemp = null;

    public function mount(): void
    {
        $this->loadSpecials();
    }

    public function loadSpecials(bool $fresh = false): void
    {
        $f = fn (?CarbonImmutable $t) => $t?->format('Y-m-d\TH:i');
        try {
            $r = app(SpecialPrograms::class)->read($fresh);
        } catch (\Throwable) {
            $this->specials = null;

            return;
        }
        $this->specials = [
            'party' => ['active' => $r['party']['active'], 'end' => $f($r['party']['end'])],
            'vacation' => ['active' => $r['vacation']['active'], 'on' => $r['vacation']['on'], 'start' => $f($r['vacation']['start']), 'end' => $f($r['vacation']['end']), 'temp' => $r['vacation']['temp']],
        ];
        $now = CarbonImmutable::now();
        $this->partyEnd = $r['party']['active'] ? $f($r['party']['end']) : $now->setTime(23, 59)->format('Y-m-d\TH:i');
        $future = $r['vacation']['on'] && $r['vacation']['end']?->isAfter($now);
        $this->vacationStart = $future ? $f($r['vacation']['start']) : $now->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->vacationEnd = $future ? $f($r['vacation']['end']) : $now->addDays(8)->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->vacationTemp = $r['vacation']['temp'] === null ? '15' : (string) $r['vacation']['temp'];
    }

    public function startParty(): void
    {
        $programs = app(SpecialPrograms::class);
        $end = $this->parseLocal($this->partyEnd);
        $this->notifySpecial($end ? $programs->startParty($end, auth()->id()) : ['Heure de fin invalide.'],
            'Soirée activée jusqu\'au '.($end?->translatedFormat('d M à H:i') ?? ''));
    }

    public function stopParty(): void
    {
        $programs = app(SpecialPrograms::class);
        $this->notifySpecial($programs->stopParty(auth()->id()), 'Soirée arrêtée');
    }

    public function startVacation(): void
    {
        $programs = app(SpecialPrograms::class);
        $start = $this->parseLocal($this->vacationStart);
        $end = $this->parseLocal($this->vacationEnd);
        $temp = is_numeric($this->vacationTemp) ? (float) $this->vacationTemp : null;
        $errors = match (true) {
            $start === null || $end === null => ['Dates invalides.'],
            $temp === null || $temp < 5 || $temp > 25 => ['Température vacances entre 5 et 25 °C.'],
            default => $programs->startVacation($start, $end, $temp, auth()->id()),
        };
        $this->notifySpecial($errors, 'Vacances programmées du '.($start?->translatedFormat('d M H:i') ?? '').' au '.($end?->translatedFormat('d M H:i') ?? ''));
    }

    public function stopVacation(): void
    {
        $programs = app(SpecialPrograms::class);
        $this->notifySpecial($programs->stopVacation(auth()->id()), 'Programme vacances arrêté');
    }

    private function parseLocal(string $value): ?CarbonImmutable
    {
        try {
            return $value === '' ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, config('app.timezone'))->startOfMinute();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $errors */
    private function notifySpecial(array $errors, string $success): void
    {
        $errors
            ? Notification::make()->title('Échec')->body(implode("\n", $errors))->danger()->persistent()->send()
            : Notification::make()->title($success)->success()->send();
        $this->loadSpecials(true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')->label('Relire')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(fn () => $this->redirect(self::getUrl(), navigate: true)),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'vacationParam' => app(Catalog::class)->parameter(SpecialPrograms::VACATION_TEMP),
        ];
    }
}
