<x-filament-panels::page>
    @php $fmt = fn ($x, $d = 0) => $x === null ? '—' : number_format((float) $x, $d, ',', ' '); @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section heading="Silo">
            <x-echart id="silo-gauge" :option="$siloGauge" height="220px" />
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Stock estimé</dt><dd class="text-end font-semibold">{{ $fmt($silo['level_kg']) }} kg / {{ $fmt($silo['capacity_kg']) }} kg</dd>
                <dt class="text-gray-500">Point de départ</dt><dd class="text-end">{{ $silo['anchor'] ? $silo['anchor']['type'].' du '.\Carbon\Carbon::parse($silo['anchor']['date'])->format('d/m/Y').' ('.$fmt($silo['anchor']['level_kg']).' kg)' : '—' }}</dd>
                <dt class="text-gray-500">Consommé depuis</dt><dd class="text-end">{{ $fmt($silo['consumed_since_kg']) }} kg</dd>
                <dt class="text-gray-500">Moyenne 14 jours</dt><dd class="text-end">{{ $fmt($silo['daily_avg_kg'], 1) }} kg/j</dd>
                <dt class="text-gray-500">Épuisement prévu</dt><dd class="text-end font-semibold">{{ $silo['empty_on'] ? \Carbon\Carbon::parse($silo['empty_on'])->translatedFormat('d F Y').' ('.$silo['days_left'].' j)' : '—' }}</dd>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Cendrier">
            <x-echart id="ash-gauge" :option="$ashGauge" height="220px" />
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Dernier vidage</dt><dd class="text-end">{{ $ash['since'] ? \Carbon\Carbon::parse($ash['since'])->format('d/m/Y') : '—' }}</dd>
                <dt class="text-gray-500">Granulés brûlés depuis</dt><dd class="text-end font-semibold">{{ $fmt($ash['burnt_kg']) }} kg</dd>
                <dt class="text-gray-500">Capacité estimée</dt><dd class="text-end">{{ $fmt($ash['capacity_kg']) }} kg de granulés</dd>
                <dt class="text-gray-500">À vider au plus tard</dt><dd class="text-end font-semibold">{{ $ash['days_left'] === 0 ? 'maintenant' : ($ash['full_on'] ? \Carbon\Carbon::parse($ash['full_on'])->translatedFormat('d F Y').' ('.$ash['days_left'].' j)' : '—') }}</dd>
            </dl>
            <p class="mt-2 text-xs text-gray-500">Capacité exprimée en kg de granulés brûlés entre deux vidages (≈ 0,5 % de cendres). Ajustable dans Paramètres.</p>
        </x-filament::section>

        <x-filament::section heading="Calcul de la consommation">
            <p class="text-sm">La consommation est déduite du fonctionnement de la vis du brûleur (taux marche/pause minute par minute) multiplié par son débit :</p>
            <p class="my-3 text-2xl font-semibold">{{ $fmt($grams, 1) }} <span class="text-base font-normal text-gray-500">g par minute de vis</span></p>
            <p class="text-sm text-gray-500">Ce débit est recalibré automatiquement à chaque livraison ou contrôle de niveau : entre deux niveaux connus, quantité consommée ÷ minutes de vis.</p>
        </x-filament::section>
    </div>

    <x-filament::section heading="Évolution du stock et projection" description="Projection basée sur la signature énergétique et les températures normales de saison.">
        <x-echart id="silo-history" :option="$history" height="340px" />
    </x-filament::section>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section heading="Calibration entre deux niveaux connus">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500"><tr><th class="py-2">Période</th><th class="text-end">Consommé</th><th class="text-end">Minutes vis</th><th class="text-end">Données</th><th class="text-end">g/min</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($intervals as $i)
                        <tr @class(['opacity-50' => ! $i['valid']])>
                            <td class="py-1.5">{{ \Carbon\Carbon::parse($i['from'])->format('d/m/y') }} → {{ \Carbon\Carbon::parse($i['to'])->format('d/m/y') }}</td>
                            <td class="text-end">{{ $fmt($i['consumed_kg']) }} kg</td>
                            <td class="text-end">{{ $fmt($i['auger_min']) }}</td>
                            <td class="text-end">{{ $fmt($i['coverage'] * 100) }} %</td>
                            <td class="text-end font-medium">{{ $i['grams_per_minute'] !== null ? $fmt($i['grams_per_minute'], 1) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-2 text-xs text-gray-500">Les périodes grisées sont ignorées (données manquantes &gt; 10 % ou valeur aberrante).</p>
        </x-filament::section>

        <x-filament::section heading="Derniers événements">
            <x-slot name="afterHeader"><x-filament::link :href="\App\Filament\Resources\StockEvents\StockEventResource::getUrl()">Tout voir</x-filament::link></x-slot>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ($events as $e)
                    <li class="flex items-center justify-between py-2">
                        <span class="flex items-center gap-2">
                            <x-filament::icon :icon="$e->type->getIcon()" class="h-4 w-4 text-gray-400" />
                            {{ $e->occurred_on->format('d/m/Y') }} · {{ $e->type->getLabel() }}
                        </span>
                        <span class="text-gray-500">
                            @if ($e->quantity_kg) {{ $fmt($e->quantity_kg) }} kg @endif
                            @if ($e->remaining_kg !== null) (reste {{ $fmt($e->remaining_kg) }} kg) @endif
                            @if ($e->price) · {{ $fmt($e->price) }} € @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
