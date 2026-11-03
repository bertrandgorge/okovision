<x-filament-panels::page>
    @php $fmt = fn ($x, $d = 0) => $x === null ? '—' : number_format((float) $x, $d, ',', ' '); @endphp

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::tabs>
            @foreach ($this->getPeriods() as $key => $label)
                <x-filament::tabs.item :active="$period === $key" wire:click="$set('period', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        @if ($period === 'custom')
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
        @endif
        <div wire:loading><x-filament::loading-indicator class="h-5 w-5" /></div>
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        <x-kpi label="Granulés" :value="$fmt($totals->kg)" unit="kg" :hint="$fmt($totals->kg * $price / 1000).' € au prix actuel'" />
        <x-kpi label="dont eau chaude" :value="$fmt($totals->kg_dhw)" unit="kg" :hint="$totals->kg > 0 ? $fmt(100 * $totals->kg_dhw / $totals->kg).' %' : null" />
        <x-kpi label="Énergie" :value="$fmt($totals->kg * settings('pellets.lhv_kwh_per_kg'))" unit="kWh" hint="PCI des granulés" />
        <x-kpi label="DJU" :value="$fmt($totals->dju)" :hint="'base '.settings('climate.dju_base').' °C'" />
        <x-kpi label="kg / DJU (chauffage)" :value="$totals->dju > 0 ? $fmt(($totals->kg - $totals->kg_dhw) / $totals->dju, 2) : null" hint="indicateur de performance, comparable d'une année à l'autre" />
        <x-kpi label="Cycles" :value="$fmt($totals->cycles)" :hint="$totals->n ? $fmt($totals->cycles / $totals->n, 1).' / jour, '.$fmt($totals->cold).' à froid' : null" />
    </div>

    <x-filament::section heading="Consommation journalière" description="Répartition chauffage / eau chaude (pompe ECS en marche) et température extérieure moyenne.">
        <x-echart id="hist-daily-{{ $from }}-{{ $to }}" :option="$daily" height="340px" group="hist" />
    </x-filament::section>

    <x-filament::section heading="Cycles brûleur" description="Un cycle efficace dure longtemps : chaque démarrage coûte une phase d'allumage et une post-ventilation qui refroidit la chaudière.">
        <x-echart id="hist-cycles-{{ $from }}-{{ $to }}" :option="$cycles" height="300px" group="hist" />
    </x-filament::section>

    @if ($notes)
        <x-filament::section heading="Changements de réglages sur la période" icon="heroicon-o-adjustments-horizontal"
            description="Un repère par jour (traits violets numérotés sur les graphiques). Comparez le comportement avant et après chaque changement : cycles, combustion, consommation à température équivalente." collapsible>
            @php
                $sourceLabels = ['auto' => ['relevé automatique', 'info'], 'app' => ['Okovision', 'primary'], 'legacy' => ['ancienne appli', 'gray']];
            @endphp
            <ol class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($notes as $note)
                    <li class="grid gap-x-4 gap-y-2 py-3 md:grid-cols-[3rem_11rem_1fr_auto]">
                        <div class="font-semibold text-[#7c3aed]">⚙ {{ $note['n'] }}</div>
                        <div class="text-sm">
                            <x-filament::link :href="\App\Filament\Pages\DayView::getUrl(['date' => $note['day']])">
                                {{ \Carbon\Carbon::parse($note['from'])->translatedFormat('D d M Y') }}
                            </x-filament::link>
                            <div class="text-xs text-gray-500">
                                {{ \Carbon\Carbon::parse($note['from'])->format('H:i') }}@if (substr($note['to'], 11, 5) !== substr($note['from'], 11, 5)) – {{ \Carbon\Carbon::parse($note['to'])->format('H:i') }}@endif
                            </div>
                        </div>
                        <div class="text-sm">
                            @foreach ($note['titles'] as $title)
                                <div class="font-medium">{{ $title }}</div>
                            @endforeach
                            @if ($note['lines'])
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($note['lines'] as $line)
                                        <li @class(['flex flex-wrap gap-x-2', 'text-gray-400 line-through' => $line['reverted']])>
                                            <span class="text-gray-600 dark:text-gray-300">{{ $line['label'] }}</span>
                                            <span class="whitespace-nowrap">
                                                <span class="text-gray-500">{{ $line['old'] }}</span>
                                                <span class="text-gray-400">→</span>
                                                <span class="font-semibold">{{ $line['new'] }}</span>
                                            </span>
                                            @if ($line['reverted'])<span class="text-xs no-underline">(revenu à la valeur initiale)</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-start justify-end gap-1">
                            @foreach ($note['sources'] as $source)
                                <x-filament::badge size="sm" :color="$sourceLabels[$source][1] ?? 'gray'">{{ $sourceLabels[$source][0] ?? $source }}</x-filament::badge>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section heading="Signature énergétique" description="Consommation journalière selon la température extérieure (jours où la chaudière a fonctionné ; les jours à l'arrêt sont exclus). La pente traduit les déperditions de la maison ; un nuage qui descend d'une saison à l'autre = progrès.">
            <x-echart id="hist-signature" :option="$signature" height="380px" />
            @if ($model)
                <p class="mt-2 text-sm text-gray-500">
                    Modèle (médiane par °C, 2 dernières années) : chauffage arrêté au-dessus de {{ $fmt($model['t0'], 0) }} °C ·
                    par temps froid {{ $fmt($model['slope_kg_per_k'], 2) }} kg/j par °C en moins, soit des déperditions ≈ {{ $fmt($model['loss_w_per_k']) }} W/K · R² {{ $fmt($model['r2'], 2) }}
                </p>
            @endif
        </x-filament::section>
        <x-filament::section heading="Durée de combustion des cycles" description="Distribution par classe de température extérieure.">
            <x-echart id="hist-histo-{{ $from }}-{{ $to }}" :option="$histogram" height="380px" />
        </x-filament::section>
    </div>

    <x-filament::section heading="Comparaison des saisons" description="Saison de chauffe de septembre à août.">
        <x-echart id="hist-seasons" :option="$seasonsMonthly" height="320px" />
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="py-2">Saison</th><th class="text-end">Jours mesurés</th><th class="text-end">Granulés</th><th class="text-end">dont ECS</th>
                        <th class="text-end">Coût estimé</th><th class="text-end">DJU</th><th class="text-end">kg/DJU</th><th class="text-end">T ext moy.</th>
                        <th class="text-end">Cycles</th><th class="text-end">à froid</th><th class="text-end">kg/cycle</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($seasons as $s)
                        <tr>
                            <td class="py-1.5 font-medium">{{ $s['season'] }}</td>
                            <td class="text-end">{{ $s['days'] }}</td>
                            <td class="text-end">{{ $fmt($s['kg']) }} kg</td>
                            <td class="text-end">{{ $fmt($s['kg_dhw']) }} kg</td>
                            <td class="text-end">{{ $fmt($s['cost']) }} €</td>
                            <td class="text-end">{{ $fmt($s['dju']) }}</td>
                            <td class="text-end">{{ $fmt($s['kg_per_dju'], 2) }}</td>
                            <td class="text-end">{{ $fmt($s['t'], 1) }} °C</td>
                            <td class="text-end">{{ $fmt($s['cycles']) }}</td>
                            <td class="text-end">{{ $fmt($s['cold']) }}</td>
                            <td class="text-end">{{ $fmt($s['kg_per_cycle'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-2 text-xs text-gray-500">Les saisons incomplètes (jours mesurés &lt; 365) sous-estiment la consommation réelle. Coût calculé au prix actuel ({{ $fmt($price) }} €/t).</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
