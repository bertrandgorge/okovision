<?php

namespace App\Filament\Pages;

use App\Models\BoilerParameterChange;
use App\Models\BoilerProfile;
use App\Okofen\BoilerService;
use App\Okofen\Catalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class BoilerSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Réglages chaudière';

    protected static ?string $title = 'Réglages de la chaudière';

    protected static string|\UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'reglages';

    protected string $view = 'filament.pages.boiler-settings';

    #[Url]
    public string $level = 'basic';

    #[Url]
    public string $group = 'all';

    public string $search = '';

    /** clé sûre => valeur actuelle */
    public array $values = [];

    /** clé sûre => valeur éditée */
    public array $edit = [];

    /** clé sûre => métadonnées lues (bornes, options) */
    public array $meta = [];

    public ?string $error = null;

    public bool $loadedExpert = false;

    public static function key(string $name): string
    {
        return 'p_'.substr(md5($name), 0, 12);
    }

    public function mount(): void
    {
        $this->load('basic');
        if ($this->level === 'expert') {
            $this->load('expert');
        }
    }

    public function updatedLevel(): void
    {
        if ($this->level === 'expert' && ! $this->loadedExpert) {
            $this->load('expert');
        }
    }

    public function load(string $level, bool $fresh = false): void
    {
        try {
            $read = app(BoilerService::class)->readLevel($level, $fresh);
            foreach ($read as $name => $value) {
                $k = self::key($name);
                $this->values[$k] = $value->ok ? $value->value() : null;
                if (! array_key_exists($k, $this->edit) || $fresh) {
                    $this->edit[$k] = $this->values[$k];
                }
                $this->meta[$k] = ['min' => $value->min, 'max' => $value->max, 'unit' => $value->unit, 'options' => $value->options, 'ok' => $value->ok];
            }
            if ($level === 'expert') {
                $this->loadedExpert = true;
            }
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function reload(): void
    {
        $this->load('basic', true);
        if ($this->loadedExpert) {
            $this->load('expert', true);
        }
    }

    public function resetEdit(): void
    {
        $this->edit = $this->values;
    }

    /** @return array<string, float|int|string> nom de variable => nouvelle valeur */
    public function changes(): array
    {
        $changes = [];
        foreach (app(Catalog::class)->parameters() as $name => $p) {
            $k = self::key($name);
            if (! array_key_exists($k, $this->edit) || ! array_key_exists($k, $this->values)) {
                continue;
            }
            $new = $this->edit[$k];
            $old = $this->values[$k];
            if ($new === null || $new === '' || (string) $new === (string) $old) {
                continue;
            }
            if (is_numeric($new) && is_numeric($old) && abs((float) $new - (float) $old) < 1e-9) {
                continue;
            }
            $changes[$name] = is_numeric($new) ? $new + 0 : $new;
        }

        return $changes;
    }

    protected function getHeaderActions(): array
    {
        $catalog = app(Catalog::class);

        return [
            Action::make('reload')->label('Relire')->icon(Heroicon::OutlinedArrowPath)->color('gray')->action(fn () => $this->reload()),

            Action::make('saveProfile')
                ->label('Sauver un profil')
                ->icon(Heroicon::OutlinedBookmark)
                ->color('gray')
                ->disabled(fn () => $this->values === [])
                ->schema([
                    TextInput::make('name')->label('Nom')->required()->default('Profil du '.now()->format('d/m/Y H:i')),
                    Textarea::make('description')->label('Description'),
                ])
                ->action(function (array $data) use ($catalog) {
                    $values = [];
                    foreach ($catalog->parameters() as $name => $p) {
                        $k = self::key($name);
                        if (($p['writable'] ?? false) && isset($this->values[$k])) {
                            $values[$name] = $this->values[$k];
                        }
                    }
                    BoilerProfile::query()->create(['source' => 'manual', 'name' => $data['name'], 'description' => $data['description'] ?? null, 'values' => $values]);
                    Notification::make()->title('Profil enregistré')->body(count($values).' réglages sauvegardés.')->success()->send();
                }),

            Action::make('loadProfile')
                ->label('Charger un profil')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->schema([
                    Select::make('profile')->label('Profil')->required()
                        ->options(fn () => BoilerProfile::query()->latest('id')->get()
                            ->mapWithKeys(fn ($p) => [$p->id => $p->name.($p->source === 'auto' ? ' · '.str($p->description)->limit(80) : '')])),
                ])
                ->action(function (array $data) {
                    $profile = BoilerProfile::query()->find($data['profile']);
                    $n = 0;
                    foreach ($profile->values as $name => $value) {
                        $k = self::key($name);
                        if (array_key_exists($k, $this->values)) {
                            $this->edit[$k] = $value;
                            $n++;
                        }
                    }
                    Notification::make()->title('Profil chargé dans l\'éditeur')->body("{$n} valeurs. Vérifiez puis appliquez.")->info()->send();
                }),

            Action::make('apply')
                ->label(fn () => 'Appliquer'.(($n = count($this->changes())) ? " ({$n})" : ''))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn () => $this->changes() === [] || $this->error !== null)
                ->requiresConfirmation()
                ->modalHeading('Écrire ces réglages sur la chaudière ?')
                ->modalDescription(fn () => new HtmlString($this->describe($this->changes())))
                ->action(function () {
                    $results = app(BoilerService::class)->apply($this->changes(), 'manual', auth()->id());
                    $failed = array_filter($results, fn ($r) => $r['status'] !== 'applied');
                    $failed
                        ? Notification::make()->title(count($failed).' réglage(s) refusé(s)')->body(implode("\n", array_map(fn ($r) => $r['message'], $failed)))->danger()->persistent()->send()
                        : Notification::make()->title(count($results).' réglage(s) appliqué(s)')->success()->send();
                    $this->reload();
                }),
        ];
    }

    private function describe(array $changes): string
    {
        $catalog = app(Catalog::class);
        $lines = [];
        foreach ($changes as $name => $new) {
            $p = $catalog->parameter($name);
            $old = $this->values[self::key($name)] ?? null;
            $fmt = fn ($v) => isset($p['options'][$v]) ? $p['options'][$v] : $v.(isset($p['unit']) ? ' '.$p['unit'] : '');
            $lines[] = '<li><strong>'.e($catalog->label($p)).'</strong> : '.e($fmt($old)).' → '.e($fmt($new)).'</li>';
        }

        return '<ul class="text-start">'.implode('', $lines).'</ul>';
    }

    protected function getViewData(): array
    {
        $catalog = app(Catalog::class);
        $search = mb_strtolower(trim($this->search));
        $params = $catalog->parameters()
            ->filter(fn ($p) => $p['level'] === $this->level)
            ->filter(fn ($p) => $this->group === 'all' || $p['group'] === $this->group)
            ->filter(fn ($p) => $search === '' || str_contains(mb_strtolower($catalog->label($p).' '.($p['short_fr'] ?? '').' '.$p['name'].' '.($p['description'] ?? '')), $search))
            ->filter(fn ($p) => array_key_exists(self::key($p['name']), $this->values))
            ->groupBy('group');

        $groupsWithItems = $catalog->parameters()->filter(fn ($p) => $p['level'] === $this->level)->pluck('group')->unique();

        return [
            'params' => $params,
            'groups' => collect($catalog->groups())->only($groupsWithItems->all())->all(),
            'catalog' => $catalog,
            'changes' => $this->changes(),
            'history' => BoilerParameterChange::query()->with('user')->latest()->limit(30)->get(),
        ];
    }
}
