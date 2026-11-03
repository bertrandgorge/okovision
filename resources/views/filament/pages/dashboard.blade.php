<x-filament-panels::page>
    @php
        $v = $live['values'] ?? [];
        $fmt = fn ($x, $d = 1) => $x === null ? null : number_format((float) $x, $d, ',', ' ');
        $outside = $v['outside_temp'] ?? $last?->outside_temp;
        $room = $v['hc1_room_temp'] ?? $last?->hc1_room_temp;
        $boilerT = $v['boiler_temp'] ?? $last?->boiler_temp;
        $dhw = $v['dhw1_temp'] ?? $last?->dhw1_temp_on;
    @endphp

    <div wire:poll.120s class="space-y-6">
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
            <x-kpi label="T° extérieure" icon="heroicon-o-cloud" color="info" :value="$fmt($outside)" unit="°C"
                   :hint="$range?->out_min !== null ? 'min '.$fmt($range->out_min).' · max '.$fmt($range->out_max).' °C' : null" />
            <x-kpi label="T° ambiance" icon="heroicon-o-home" color="success" :value="$fmt($room)" unit="°C"
                   :hint="$range?->room_min !== null ? 'min '.$fmt($range->room_min).' · max '.$fmt($range->room_max).' °C' : null">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $last && $last->hc1_room_set > 8 ? 'consigne '.$fmt($last->hc1_room_set).' °C' : 'circuit à l\'arrêt' }}</div>
            </x-kpi>
            <x-kpi label="Chaudière" icon="heroicon-o-fire" color="danger" :value="$fmt($boilerT)" unit="°C"
                   :hint="isset($v['flame_temp']) ? 'flamme '.$fmt($v['flame_temp'], 0).' °C' : null" />
            <x-kpi label="Eau chaude" icon="heroicon-o-beaker" color="primary" :value="$fmt($dhw)" unit="°C"
                   :hint="$last ? 'consigne '.$fmt($last->dhw1_set, 0).' °C' : null" />
            <x-kpi label="Granulés aujourd'hui" icon="heroicon-o-scale" :value="$fmt($today?->pellets_kg)" unit="kg"
                   :hint="$yesterday ? 'hier '.$fmt($yesterday->pellets_kg).' kg' : null" />
            <x-kpi label="Cycles aujourd'hui" icon="heroicon-o-arrow-path" color="warning" :value="$today?->cycles"
                   :hint="$yesterday ? 'hier '.$yesterday->cycles.' ('.$yesterday->cold_starts.' à froid)' : null" />
            <x-kpi label="Silo" icon="heroicon-o-archive-box" :color="($silo['pct'] ?? 100) < 15 ? 'danger' : 'success'"
                   :value="$silo['level_kg'] !== null ? number_format($silo['level_kg'], 0, ',', ' ') : null" unit="kg"
                   :hint="$silo['days_left'] !== null ? '≈ '.$silo['days_left'].' jours ('.\Carbon\Carbon::parse($silo['empty_on'])->translatedFormat('d M').')' : ($silo['pct'] !== null ? $silo['pct'].' %' : 'saisir une livraison')" />
            <x-kpi label="Cendrier" icon="heroicon-o-trash" :color="($ash['pct'] ?? 0) >= 85 ? 'danger' : 'gray'"
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

        {{-- Journée en cours --}}
        <x-filament::section :heading="'Journée du '.\Carbon\Carbon::parse($day)->translatedFormat('l d F')" collapsible>
            <x-slot name="afterHeader">
                <x-filament::link :href="\App\Filament\Pages\DayView::getUrl(['date' => $day])" icon="heroicon-o-arrow-top-right-on-square">Détail</x-filament::link>
            </x-slot>
            @if ($dayCharts['empty'])
                <p class="text-sm text-gray-500">Pas de mesures pour cette journée.</p>
            @else
                <x-echart id="dash-temp" :option="$dayCharts['temperatures']" height="320px" group="dash" />
                <x-echart id="dash-states" :option="$dayCharts['states']" height="130px" group="dash" />
            @endif
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Recommandations --}}
            <x-filament::section heading="Recommandations" class="lg:col-span-2" icon="heroicon-o-light-bulb">
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

            {{-- Silo --}}
            <x-filament::section heading="Silo" icon="heroicon-o-archive-box">
                <x-slot name="afterHeader">
                    <x-filament::link :href="\App\Filament\Pages\Silo::getUrl()">Détail</x-filament::link>
                </x-slot>
                <x-echart id="dash-silo" :option="$charts->gauge($silo['pct'], 'stock')" height="200px" />
                <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-gray-500">Stock estimé</dt><dd class="text-end font-medium">{{ $silo['level_kg'] !== null ? number_format($silo['level_kg'], 0, ',', ' ').' kg' : '—' }}</dd>
                    <dt class="text-gray-500">Consommation récente</dt><dd class="text-end font-medium">{{ $silo['daily_avg_kg'] !== null ? $fmt($silo['daily_avg_kg']).' kg/j' : '—' }}</dd>
                    <dt class="text-gray-500">Épuisement estimé</dt><dd class="text-end font-medium">{{ $silo['empty_on'] ? \Carbon\Carbon::parse($silo['empty_on'])->translatedFormat('d F Y') : '—' }}</dd>
                    <dt class="text-gray-500">Dernier point connu</dt><dd class="text-end">{{ $silo['anchor'] ? \Carbon\Carbon::parse($silo['anchor']['date'])->format('d/m/Y') : '—' }}</dd>
                </dl>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
