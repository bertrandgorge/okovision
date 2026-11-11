<x-filament-panels::page>
    @php
        $v = $live['values'] ?? [];
        $fmt = fn ($x, $d = 1) => $x === null ? null : number_format((float) $x, $d, ',', ' ');
        $fmtd = fn ($x, $d = 1) => $fmt($x, $d) ?? '—';
        $outside = $v['outside_temp'] ?? $last?->outside_temp;
        $room = $v['hc1_room_temp'] ?? $last?->hc1_room_temp;
        $boilerT = $v['boiler_temp'] ?? $last?->boiler_temp;
        $dhw = $v['dhw1_temp'] ?? $last?->dhw1_temp_on;
    @endphp

    <div wire:poll.120s class="space-y-6">
        {{-- Choix de la journée détaillée plus bas (comme l'ancienne page « Journée ») --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" label="Jour précédent" tooltip="Jour précédent" color="gray" />
            <x-filament::input.wrapper class="w-48">
                <x-filament::input type="date" wire:model.live="date" :max="now()->format('Y-m-d')" />
            </x-filament::input.wrapper>
            <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" label="Jour suivant" tooltip="Jour suivant" color="gray"
                :disabled="$date >= now()->format('Y-m-d')" />
            <span class="ms-2 text-lg font-medium">Journée du {{ \Carbon\Carbon::parse($date)->translatedFormat('l d F Y') }}</span>
            <div wire:loading wire:target="previous,next,date" class="ms-2"><x-filament::loading-indicator class="h-5 w-5" /></div>
        </div>

        {{-- État --}}
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="$statusColor" size="lg" icon="heroicon-o-fire">
                {{ $statusLabel }}
            </x-filament::badge>
            @if ($live['error'] ?? null)
                <x-filament::badge color="danger" icon="heroicon-o-signal-slash">Chaudière injoignable</x-filament::badge>
            @endif
            <span class="text-sm text-gray-500">
                Dernière mesure : {{ $last ? \Carbon\Carbon::parse($last->ts)->translatedFormat('d M Y à H:i') : 'aucune' }}
                @if (! ($live['error'] ?? null) && isset($live['at']))
                    · lecture directe {{ \Carbon\Carbon::parse($live['at'])->format('H:i:s') }}
                @endif
            </span>
        </div>

        {{-- Pourquoi le chauffage tourne ou non (règles du régulateur, docs/REGULATION-OKOFEN.md) --}}
        @if ($heating)
            <div @class([
                'flex items-start gap-3 rounded-xl p-4 text-sm ring-1',
                'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => $heating['running'],
                'bg-gray-50 ring-gray-200 dark:bg-white/5 dark:ring-white/10' => ! $heating['running'],
            ])>
                <x-filament::icon :icon="$heating['running'] ? 'heroicon-o-fire' : 'heroicon-o-pause-circle'"
                    @class(['h-5 w-5 shrink-0', 'text-success-600' => $heating['running'], 'text-gray-500' => ! $heating['running']]) />
                <div class="space-y-1">
                    <div class="font-medium">{{ $heating['headline'] }}</div>
                    @if ($heating['reasons'])
                        <ul class="list-disc ps-4 text-gray-600 dark:text-gray-300">
                            @foreach ($heating['reasons'] as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($heating['restart'])
                        <div class="text-gray-500 dark:text-gray-400">{{ $heating['restart'] }}</div>
                    @endif
                </div>
            </div>
        @endif

        @foreach ($alerts as $alert)
            <div @class([
                'flex items-start gap-3 rounded-xl p-4 ring-1',
                'bg-danger-50 ring-danger-200 dark:bg-danger-950/40 dark:ring-danger-800' => $alert->severity === 'critical',
                'bg-warning-50 ring-warning-200 dark:bg-warning-950/40 dark:ring-warning-800' => $alert->severity === 'warning',
                'bg-info-50 ring-info-200 dark:bg-info-950/40 dark:ring-info-800' => $alert->severity === 'info',
            ])>
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0" />
                <div>
                    <div class="font-medium">{{ $alert->title }}</div>
                    <div class="text-sm opacity-80">{{ $alert->message }}</div>
                </div>
            </div>
        @endforeach

        {{-- Indicateurs --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-8">
            {{-- Aujourd'hui : valeur courante et plage du jour ; jour passé : moyenne et plage de ce jour --}}
            <x-kpi label="T° extérieure" icon="heroicon-o-cloud" color="info" unit="°C"
                   :value="$isToday ? $fmt($outside) : $fmt($dayStat?->outside_avg)"
                   :hint="$isToday
                       ? ($range?->out_min !== null ? 'min '.$fmt($range->out_min).' · max '.$fmt($range->out_max).' °C' : null)
                       : ($dayStat ? 'moyenne · min '.$fmtd($dayStat->outside_min).' · max '.$fmtd($dayStat->outside_max).' °C' : null)">
                @if ($dayStat?->dju !== null)
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $fmt($dayStat->dju) }} DJU (base {{ settings('climate.dju_base') }} °C)</div>
                @endif
            </x-kpi>
            <x-kpi label="T° ambiance" icon="heroicon-o-home" color="success" :value="$fmt($room)" unit="°C"
                   :hint="$range?->room_min !== null ? 'min '.$fmt($range->room_min).' · max '.$fmt($range->room_max).' °C' : null">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $last && $last->hc1_room_set > 8 ? 'consigne '.$fmt($last->hc1_room_set).' °C' : 'circuit à l\'arrêt' }}</div>
            </x-kpi>
            <x-kpi label="Chaudière" icon="heroicon-o-fire" color="danger" :value="$fmt($boilerT)" unit="°C"
                   :hint="isset($v['flame_temp']) ? 'flamme '.$fmt($v['flame_temp'], 0).' °C' : null" />
            <x-kpi label="Eau chaude" icon="heroicon-o-beaker" color="primary" :value="$fmt($dhw)" unit="°C"
                   :hint="$last ? 'consigne '.$fmt($last->dhw1_set, 0).' °C' : null" />
            <x-kpi :label="$isToday ? 'Granulés aujourd\'hui' : 'Granulés'" :href="\App\Filament\Pages\History::getUrl()" icon="heroicon-o-scale" :value="$fmt($dayStat?->pellets_kg)" unit="kg"
                   :hint="$dayStat ? 'dont ECS '.$fmtd($dayStat->pellets_kg_dhw).' kg' : null">
                @if ($prevStat)
                    <div class="text-xs text-gray-500 dark:text-gray-400">veille {{ $fmt($prevStat->pellets_kg) }} kg</div>
                @endif
            </x-kpi>
            <x-kpi :label="$isToday ? 'Cycles aujourd\'hui' : 'Cycles'" :href="\App\Filament\Pages\Optimization::getUrl()" icon="heroicon-o-arrow-path" color="warning" :value="$dayStat?->cycles"
                   :hint="$dayStat ? $dayStat->cold_starts.' allumage(s) à froid' : null">
                @if ($prevStat)
                    <div class="text-xs text-gray-500 dark:text-gray-400">veille {{ $prevStat->cycles }} ({{ $prevStat->cold_starts }} à froid)</div>
                @endif
            </x-kpi>
            <x-kpi label="Silo" :href="\App\Filament\Pages\Silo::getUrl()" icon="heroicon-o-archive-box" :color="($silo['pct'] ?? 100) < 15 ? 'danger' : 'success'"
                   :value="$silo['level_kg'] !== null ? number_format($silo['level_kg'], 0, ',', ' ') : null" unit="kg"
                   :hint="$silo['days_left'] !== null ? '≈ '.$silo['days_left'].' jours ('.\Carbon\Carbon::parse($silo['empty_on'])->translatedFormat('d M').')' : ($silo['pct'] !== null ? $silo['pct'].' %' : 'saisir une livraison')" />
            <x-kpi label="Cendrier" :href="\App\Filament\Pages\Silo::getUrl()" icon="heroicon-o-trash" :color="($ash['pct'] ?? 0) >= 85 ? 'danger' : 'gray'"
                   :value="$ash['pct'] !== null ? $fmt($ash['pct'], 0) : null" unit="%"
                   :hint="match (true) {
                       ! $ash['since'] => 'saisir un vidage',
                       $ash['days_left'] === 0 => 'à vider maintenant',
                       $ash['full_on'] !== null => 'à vider au plus tard dans '.$ash['days_left'].' j ('.\Carbon\Carbon::parse($ash['full_on'])->translatedFormat('d M').')',
                       default => 'plein dans plus de 2 ans',
                   }">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $ash['since'] ? $fmt($ash['burnt_kg'], 0).' kg brûlés depuis le '.\Carbon\Carbon::parse($ash['since'])->format('d/m') : '' }}</div>
            </x-kpi>
        </div>

        @if ($dayCharts['empty'])
            <x-filament::section>
                <p class="text-sm text-gray-500">Aucune mesure pour cette journée (ni en base, ni dans les archives).</p>
            </x-filament::section>
        @else
            <x-filament::section heading="Températures" description="Zones colorées : cycles brûleur (rouge = allumage à froid). Molette pour zoomer, les trois graphiques sont synchronisés.">
                <x-echart id="day-temp-{{ $date }}" :option="$dayCharts['temperatures']" height="380px" group="day" />
            </x-filament::section>
            <x-filament::section heading="États du brûleur et des pompes">
                <x-echart id="day-states-{{ $date }}" :option="$dayCharts['states']" height="150px" group="day" />
            </x-filament::section>
            <x-filament::section heading="Combustion" description="Le taux d'alimentation de la vis et la vitesse du ventilateur traduisent la puissance de modulation." collapsible>
                <x-echart id="day-comb-{{ $date }}" :option="$dayCharts['combustion']" height="320px" group="day" />
            </x-filament::section>
        @endif

        @if ($cycles->isNotEmpty())
            <x-filament::section heading="Cycles brûleur" collapsible collapsed>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500">
                            <tr>
                                <th class="py-2">Début</th><th>Fin</th><th class="text-end">Durée</th><th class="text-end">Allumage</th>
                                <th class="text-end">Combustion</th><th class="text-end">Fin comb.</th><th class="text-end">Arrêt avant</th>
                                <th class="text-end">Granulés</th><th class="text-end">T chaudière</th><th>Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($cycles as $c)
                                <tr>
                                    <td class="py-1.5">{{ $c->started_at->format('H:i') }}</td>
                                    <td>{{ $c->ended_at?->format('H:i') }}</td>
                                    <td class="text-end">{{ $c->duration_min }} min</td>
                                    <td class="text-end">{{ $c->ignition_min }} min</td>
                                    <td class="text-end font-medium">{{ $c->combustion_min }} min</td>
                                    <td class="text-end">{{ $c->runon_min }} min</td>
                                    <td class="text-end">{{ $c->off_before_min !== null ? $c->off_before_min.' min' : '—' }}</td>
                                    <td class="text-end">{{ $fmt($c->pellets_kg, 2) }} kg</td>
                                    <td class="text-end">{{ $fmt($c->boiler_temp_start) }} → {{ $fmt($c->boiler_temp_max) }} °C</td>
                                    <td class="space-x-1">
                                        @if ($c->cold_start)<x-filament::badge color="danger" size="sm">à froid</x-filament::badge>@endif
                                        @if ($c->heating)<x-filament::badge color="warning" size="sm">chauffage</x-filament::badge>@endif
                                        @if ($c->dhw)<x-filament::badge color="info" size="sm">ECS</x-filament::badge>@endif
                                        @unless ($c->complete)<x-filament::badge color="gray" size="sm">incomplet</x-filament::badge>@endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Recommandations --}}
        <x-filament::section heading="Recommandations" icon="heroicon-o-light-bulb">
            <x-slot name="afterHeader">
                <x-filament::link :href="\App\Filament\Pages\Optimization::getUrl()">Voir l'analyse</x-filament::link>
            </x-slot>
            @if (! $advice)
                <p class="text-sm text-gray-500">Analyse indisponible (chaudière injoignable ou historique insuffisant).</p>
            @else
                <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                    <x-kpi label="Cycles / jour" :value="$fmt($advice['stats']['cycles_per_day'])" />
                    <x-kpi label="Combustion médiane" :value="$advice['stats']['median_combustion_min']" unit="min" />
                    <x-kpi label="Arrêt médian" :value="$advice['stats']['median_off_min']" unit="min" />
                    <x-kpi label="Part combustion" :value="$advice['stats']['combustion_ratio'] !== null ? $fmt($advice['stats']['combustion_ratio'] * 100, 0) : null" unit="%" hint="du temps de cycle" />
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse (array_slice($advice['recommendations'], 0, 4) as $rec)
                        <li class="flex items-start gap-3 py-3">
                            <x-filament::badge class="shrink-0" :color="['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'][$rec['severity']]">
                                {{ ['critical' => 'Important', 'warning' => 'Conseillé', 'info' => 'À étudier'][$rec['severity']] }}
                            </x-filament::badge>
                            <div>
                                <div class="font-medium">{{ $rec['title'] }}</div>
                                <div class="text-sm text-gray-500">{{ $rec['summary'] }}</div>
                            </div>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-gray-500">Rien à signaler sur la période analysée.</li>
                    @endforelse
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
