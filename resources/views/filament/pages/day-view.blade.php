<x-filament-panels::page>
    @php $fmt = fn ($x, $d = 1) => $x === null ? '—' : number_format((float) $x, $d, ',', ' '); @endphp

    <div class="flex flex-wrap items-center gap-2">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" label="Jour précédent" color="gray" />
        <x-filament::input.wrapper class="w-48">
            <x-filament::input type="date" wire:model.live="date" />
        </x-filament::input.wrapper>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" label="Jour suivant" color="gray" />
        <span class="ms-2 text-lg font-medium">{{ \Carbon\Carbon::parse($date)->translatedFormat('l d F Y') }}</span>
        <div wire:loading class="ms-2"><x-filament::loading-indicator class="h-5 w-5" /></div>
    </div>

    @if ($stat)
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-8">
            <x-kpi label="Granulés" :value="$fmt($stat->pellets_kg)" unit="kg" :hint="'dont ECS '.$fmt($stat->pellets_kg_dhw).' kg'" />
            <x-kpi label="T extérieure" :value="$fmt($stat->outside_avg)" unit="°C" :hint="'min '.$fmt($stat->outside_min).' / max '.$fmt($stat->outside_max)" />
            <x-kpi label="T ambiante moy." :value="$fmt($stat->room_avg)" unit="°C" :hint="'consigne '.$fmt($stat->room_set_avg)" />
            <x-kpi label="Cycles" :value="$stat->cycles" :hint="$stat->cold_starts.' allumage(s) à froid'" />
            <x-kpi label="Brûleur actif" :value="intdiv($stat->burner_min, 60).' h '.str_pad($stat->burner_min % 60, 2, '0', STR_PAD_LEFT)" :hint="'dont combustion '.intdiv($stat->combustion_min, 60).' h '.str_pad($stat->combustion_min % 60, 2, '0', STR_PAD_LEFT)" />
            <x-kpi label="Ventilateur moyen" :value="$fmt($stat->fan_avg, 0)" unit="%" hint="en combustion (≈ modulation)" />
            <x-kpi label="DJU" :value="$fmt($stat->dju)" :hint="'base '.settings('climate.dju_base').' °C'" />
            <x-kpi label="Mesures" :value="$stat->samples" unit="/ 1440" />
        </div>
    @endif

    @if ($charts['empty'])
        <x-filament::section>
            <p class="text-sm text-gray-500">Aucune mesure pour cette journée (ni en base, ni dans les archives).</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Températures" description="Zones colorées : cycles brûleur (rouge = allumage à froid). Molette pour zoomer, les trois graphiques sont synchronisés.">
            <x-echart id="day-temp-{{ $date }}" :option="$charts['temperatures']" height="380px" group="day" />
        </x-filament::section>
        <x-filament::section heading="États du brûleur et des pompes">
            <x-echart id="day-states-{{ $date }}" :option="$charts['states']" height="150px" group="day" />
        </x-filament::section>
        <x-filament::section heading="Combustion" description="Le taux d'alimentation de la vis et la vitesse du ventilateur traduisent la puissance de modulation.">
            <x-echart id="day-comb-{{ $date }}" :option="$charts['combustion']" height="320px" group="day" />
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
</x-filament-panels::page>
