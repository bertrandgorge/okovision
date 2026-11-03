<?php

namespace App\Filament\Pages;

use App\Charts\ChartFactory;
use App\Models\BurnerCycle;
use App\Models\DailyStat;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class DayView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Journée';

    protected static ?string $title = 'Journée minute par minute';

    protected static string|\UnitEnum|null $navigationGroup = 'Suivi';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'journee';

    protected string $view = 'filament.pages.day-view';

    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        if (! $this->date || ! strtotime($this->date)) {
            $last = DB::table('measurements')->max('ts');
            $this->date = $last ? substr($last, 0, 10) : now()->format('Y-m-d');
        }
    }

    public function previous(): void
    {
        $this->date = CarbonImmutable::parse($this->date)->subDay()->format('Y-m-d');
    }

    public function next(): void
    {
        $this->date = CarbonImmutable::parse($this->date)->addDay()->format('Y-m-d');
    }

    protected function getViewData(): array
    {
        $charts = app(ChartFactory::class)->day($this->date);

        return [
            'charts' => $charts,
            'stat' => DailyStat::query()->find($this->date),
            'cycles' => BurnerCycle::query()->where('day', $this->date)->orderBy('started_at')->get(),
        ];
    }
}
