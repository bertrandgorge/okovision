<?php

namespace App\Filament\Pages;

use App\Analysis\DayAggregator;
use App\Archive\MeasurementArchiver;
use App\Models\MeasurementArchive;
use App\Okofen\MeasurementImporter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DataArchives extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Données & archives';

    protected static ?string $title = 'Données et archivage';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'donnees';

    protected string $view = 'filament.pages.data-archives';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Importer des CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription('Fichiers touch_AAAAMMJJ.csv exportés de la chaudière (clé USB) ou récupérés manuellement.')
                ->schema([FileUpload::make('files')->label('Fichiers')->multiple()->disk('local')->directory('uploads')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->required()])
                ->action(function (array $data) {
                    $importer = app(MeasurementImporter::class);
                    $rows = 0;
                    $days = [];
                    foreach ($data['files'] as $path) {
                        $content = Storage::disk('local')->get($path);
                        $rows += $importer->importContent($content);
                        if (preg_match('/(\d{8})/', basename($path), $m)) {
                            $days[] = $m[1];
                        }
                        Storage::disk('local')->delete($path);
                    }
                    // Recalcul des journées concernées
                    $aggregator = app(DayAggregator::class);
                    foreach ($days ? array_map(fn ($d) => CarbonImmutable::createFromFormat('Ymd', $d), $days) : [] as $day) {
                        $aggregator->aggregate($day);
                    }
                    Notification::make()->title("{$rows} mesures importées")->body($days ? count($days).' journée(s) recalculée(s)' : 'Lancez « okovision:aggregate » pour recalculer les journées.')->success()->send();
                }),
            Action::make('archive')
                ->label('Archiver maintenant')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Mois concernés : '.(implode(', ', app(MeasurementArchiver::class)->eligibleMonths()) ?: 'aucun'))
                ->action(function () {
                    $archiver = app(MeasurementArchiver::class);
                    $n = 0;
                    foreach ($archiver->eligibleMonths() as $m) {
                        $archiver->archive($m);
                        $n++;
                    }
                    Notification::make()->title("{$n} mois archivé(s)")->success()->send();
                }),
        ];
    }

    public function restore(string $month): void
    {
        $n = app(MeasurementArchiver::class)->restore($month);
        Notification::make()->title("{$n} mesures restaurées ({$month})")->success()->send();
    }

    public function download(string $month)
    {
        $a = MeasurementArchive::query()->where('month', $month)->firstOrFail();

        return Storage::disk(settings('archive.disk', 'local'))->download($a->path);
    }

    protected function getViewData(): array
    {
        $db = DB::getDatabaseName();
        $tables = DB::table('information_schema.tables')->where('table_schema', $db)
            ->selectRaw('table_name as name, table_rows as rows_estimate, data_length + index_length as bytes')
            ->orderByDesc('bytes')->limit(10)->get();
        $range = DB::table('measurements')->selectRaw('MIN(ts) a, MAX(ts) b, COUNT(*) n')->first();

        return [
            'tables' => $tables,
            'range' => $range,
            'archives' => MeasurementArchive::query()->orderByDesc('month')->get(),
            'eligible' => app(MeasurementArchiver::class)->eligibleMonths(),
            'keep' => (int) settings('archive.keep_months', 24),
            'stats' => DB::table('daily_stats')->selectRaw('MIN(day) a, MAX(day) b, COUNT(*) n')->first(),
        ];
    }
}
