<x-filament-panels::page>
    @php
        $fmt = fn ($x, $d = 1) => $x === null ? null : number_format((float) $x, $d, ',', ' ');
        $days = \App\Okofen\TimeProgram::DAYS;
        $legioLabel = fn ($v) => $v === null ? '—' : ((int) $v === 7 ? 'arrêt' : mb_strtolower($params['legionella']['options'][(int) $v] ?? '?'));
        $help = [
            'mode' => 'Auto : suit le programme horaire ECS. Marche : ballon maintenu en permanence.',
            'set' => 'Pendant les plages horaires ECS. 52–55 °C : bon compromis confort / légionelles / énergie.',
            'min' => 'Hors plages : le ballon n\'est rechargé que s\'il descend sous cette valeur.',
            'hyst' => 'La charge démarre quand le ballon descend de cet écart sous la consigne. Plus grand = moins de démarrages.',
            'raise' => 'Écart chaudière / ballon pendant la charge.',
            'runon' => 'Après la charge, la pompe du ballon tourne encore ce temps pour y vider la chaleur résiduelle de la chaudière (≈ 80 °C) : c\'est ce qui fait dépasser la consigne au ballon.',
            'priority' => 'Marche : le ballon est chargé d\'abord, le chauffage est coupé pendant la charge. Arrêt : ballon et chauffage sont alimentés en même temps.',
            'legionella' => 'Une fois par semaine, le ballon monte à ~65 °C pour détruire les légionelles.',
        ];
        $steps = ['set' => 1, 'min' => 1, 'hyst' => 1, 'raise' => 0.5, 'runon' => 1];
    @endphp

    @if ($error)
        <div class="rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-200 dark:ring-warning-800">
            Chaudière injoignable ({{ $error }}). Valeurs affichées : dernier profil connu. L'application des réglages est désactivée.
        </div>
    @endif

    {{-- Indicateurs --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
        <x-kpi label="Ballon" icon="heroicon-o-beaker" color="primary" :value="$fmt($tank)" unit="°C"
               :hint="'consigne '.($fmt($current['set'], 0) ?? '—').' °C · mini '.($fmt($current['min'], 0) ?? '—').' °C'" />
        <x-kpi label="Dernière désinfection" icon="heroicon-o-shield-check" :color="$last && \Carbon\Carbon::parse($last['day'])->diffInDays(today()) <= 8 ? 'success' : 'danger'"
               :value="$last ? \Carbon\Carbon::parse($last['day'])->translatedFormat('D d M') : 'aucune'"
               :hint="$last ? 'max '.$fmt($last['max']).' °C · '.intdiv($last['minutes'], 60).' h '.str_pad($last['minutes'] % 60, 2, '0', STR_PAD_LEFT).' ≥ 60 °C' : 'depuis un an'" />
        <x-kpi label="Cycle anti-légionelles" icon="heroicon-o-calendar" :color="$next ? 'success' : 'danger'"
               :value="$next ? ($next->isToday() ? 'aujourd\'hui' : $next->translatedFormat('D d M')) : 'arrêt'"
               :hint="$next ? 'chaque '.$legioLabel($current['legionella']) : 'aucun cycle programmé'" />
        <x-kpi label="Désinfections" icon="heroicon-o-check-badge" :value="$disinfections" :unit="'/ '.intdiv($this::STATS_DAYS, 7).' sem.'"
               :hint="'sur les '.$this::STATS_DAYS.' derniers jours'" />
        <x-kpi label="Charges du ballon" icon="heroicon-o-arrow-path" color="warning" :value="$fmt($avgCharges)" unit="/ jour" hint="moyenne 30 jours" />
    </div>

    @foreach ($diagnosis as $d)
        <div @class([
            'flex items-start gap-3 rounded-xl p-4 text-sm ring-1',
            'bg-danger-50 ring-danger-200 dark:bg-danger-950/40 dark:ring-danger-800' => $d['severity'] === 'danger',
            'bg-warning-50 ring-warning-200 dark:bg-warning-950/40 dark:ring-warning-800' => $d['severity'] === 'warning',
            'bg-info-50 ring-info-200 dark:bg-info-950/40 dark:ring-info-800' => $d['severity'] === 'info',
        ])>
            <x-filament::icon :icon="$d['severity'] === 'info' ? 'heroicon-o-information-circle' : 'heroicon-o-exclamation-triangle'" class="h-5 w-5 shrink-0" />
            <div>{{ $d['text'] }}</div>
        </div>
    @endforeach

    {{-- Journée affichée --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" label="Jour précédent" tooltip="Jour précédent" color="gray" />
        <x-filament::input.wrapper class="w-48">
            <x-filament::input type="date" wire:model.live="date" :max="now()->format('Y-m-d')" />
        </x-filament::input.wrapper>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" label="Jour suivant" tooltip="Jour suivant" color="gray"
            :disabled="$date >= now()->format('Y-m-d')" />
        <span class="ms-2 text-lg font-medium">{{ \Carbon\Carbon::parse($date)->translatedFormat('l d F Y') }}</span>
        <div wire:loading wire:target="previous,next,date" class="ms-2"><x-filament::loading-indicator class="h-5 w-5" /></div>
    </div>

    <x-filament::section heading="Ballon d'eau chaude"
        description="Zones violettes : plages du programme horaire ECS. Pendant les plages, la chaudière vise la consigne et relance une charge quand le ballon passe sous « consigne − hystérésis » ; en dehors, elle ne recharge que sous la température mini (moins l'hystérésis). Le jour du cycle anti-légionelles, la consigne monte à 65 °C.">
        @if ($dayChart['empty'])
            <p class="text-sm text-gray-500">Aucune mesure pour cette journée.</p>
        @else
            <x-echart id="dhw-day-{{ $date }}" :option="$dayChart['option']" height="520px" />
        @endif
    </x-filament::section>

    <x-filament::section heading="Réglages de l'eau chaude" icon="heroicon-o-adjustments-horizontal"
        :description="$hasChanges ? 'Modifications non appliquées : « Appliquer » sur chaque ligne, ou tout appliquer en haut de page.' : 'Valeurs lues sur la chaudière.'">
        <x-slot name="afterHeader">
            @if ($hasChanges)
                <x-filament::button size="sm" color="gray" wire:click="resetEdit" icon="heroicon-o-arrow-uturn-left">Tout annuler</x-filament::button>
            @endif
        </x-slot>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($params as $key => $p)
                @continue($p === null)
                <x-setting-row :key="$key" :param="$p" :value="$edit[$key] ?? null" :current="$current[$key] ?? null"
                    :changed="$this->isSettingChanged($key)" :factory="$this->factoryValue($key)" :help="$help[$key] ?? null" :disabled="$error !== null">
                    <x-filament::input.wrapper :valid="! $this->isSettingChanged($key)" :suffix="$p['unit'] ?? null">
                        @if (($p['type'] ?? '') === 'enum')
                            <x-filament::input.select wire:model.live="edit.{{ $key }}">
                                @foreach ($p['options'] as $v => $label)
                                    <option value="{{ $v }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        @else
                            <x-filament::input type="number" wire:model.live.debounce.500ms="edit.{{ $key }}"
                                :step="$steps[$key] ?? 1" :min="$p['min'] ?? null" :max="$p['max'] ?? null" />
                        @endif
                    </x-filament::input.wrapper>
                </x-setting-row>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Programmes horaires de l'eau chaude et du bouclage --}}
    <x-filament::section heading="Programme horaire de l'eau chaude" icon="heroicon-o-clock" collapsible>
        <livewire:time-program-editor circuit="ww" />
    </x-filament::section>

    <x-filament::section heading="Programme du bouclage (circulation d'eau chaude)" icon="heroicon-o-arrow-path-rounded-square" collapsible collapsed>
        <livewire:time-program-editor circuit="zirkp" />
    </x-filament::section>
</x-filament-panels::page>
