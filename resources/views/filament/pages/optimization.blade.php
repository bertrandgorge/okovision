<x-filament-panels::page>
    @php
        $fmt = fn ($x, $d = 1) => $x === null ? '—' : number_format((float) $x, $d, ',', ' ');
        $s = $result['stats'];
        $load = $result['load'];
        $sev = ['critical' => ['danger', 'Important'], 'warning' => ['warning', 'Conseillé'], 'info' => ['info', 'À étudier']];
        $lvl = ['basic' => 'Réglage utilisateur', 'expert' => 'Réglage technicien', 'hardware' => 'Évolution de l\'installation'];
    @endphp

    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-500">
        <span>Période analysée : du {{ \Carbon\Carbon::parse($result['period']['from'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($result['period']['to'])->format('d/m/Y') }} ({{ $s['days'] }} jours)</span>
        <x-filament::link tag="button" wire:click="refreshAnalysis" icon="heroicon-o-arrow-path" size="sm">Recalculer</x-filament::link>
        @if ($error)<x-filament::badge color="warning">Chaudière injoignable : réglages actuels inconnus</x-filament::badge>@endif
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-8">
        <x-kpi label="Cycles / jour" :value="$fmt($s['cycles_per_day'])" :hint="$fmt($s['cold_starts_per_day']).' allumage(s) à froid / jour'" />
        <x-kpi label="Combustion médiane" :value="$s['median_combustion_min']" unit="min" hint="par cycle" />
        <x-kpi label="Arrêt médian" :value="$s['median_off_min']" unit="min" hint="entre deux cycles" />
        <x-kpi label="Cycles < 30 min" :value="$fmt($s['short_share'] * 100, 0)" unit="%" />
        <x-kpi label="Temps en combustion" :value="$s['combustion_ratio'] !== null ? $fmt($s['combustion_ratio'] * 100, 0) : null" unit="%" hint="le reste : allumage, post-ventilation" />
        <x-kpi label="Cycles ECS seule" :value="$fmt($s['dhw_only_cycles_per_day'])" unit="/ j" />
        <x-kpi label="Besoin moyen" :value="$load ? $fmt($load['season_avg_kw']) : null" unit="kW" :hint="$load ? 'à '.$fmt($load['season_avg_temp']).' °C ext' : null" />
        <x-kpi label="Puissance mini" :value="$load ? $fmt($load['pmin_kw']) : null" unit="kW" :hint="$load ? $fmt($load['share_below_min'] * 100, 0).' % des jours sous ce seuil' : null" />
    </div>

    <x-filament::section heading="Diagnostic" icon="heroicon-o-magnifying-glass">
        <div class="prose prose-sm max-w-none dark:prose-invert">
            <p>
                Une chaudière à granulés est d'autant plus efficace et propre qu'elle fonctionne <strong>longtemps à puissance stable</strong>.
                Chaque cycle comporte un démarrage (et parfois un allumage électrique), une phase de stabilisation, puis une <strong>post-ventilation</strong>
                (~15 min) qui évacue la chaleur du foyer par la cheminée. Les mesures de terrain montrent un rendement réel inférieur de 7 à 24 points
                au rendement nominal à cause des cycles, et des émissions (CO, particules) concentrées dans les phases de démarrage/arrêt.
            </p>
            @if ($load)
                <p>
                    Ici, le besoin moyen de la maison en saison de chauffe est d'environ <strong>{{ $fmt($load['season_avg_kw']) }} kW</strong>
                    ({{ $fmt($load['design_kw']) }} kW par {{ $load['design_temp'] }} °C), pour une chaudière de {{ $fmt($load['power_kw'], 0) }} kW
                    dont la puissance minimale est d'environ {{ $fmt($load['pmin_kw']) }} kW.
                    Lorsque le besoin est inférieur à cette puissance minimale, la chaudière ne peut que monter jusqu'à sa température de coupure,
                    s'arrêter, puis redémarrer quelques minutes plus tard : c'est le cas {{ $fmt($load['share_below_min'] * 100, 0) }} % des jours de chauffe.
                </p>
            @endif
            <p>Leviers, du plus simple au plus structurant : élargir la plage de température de la chaudière (hystérésis), réduire la puissance paramétrée,
                regrouper l'eau chaude, utiliser l'inertie de la maison (pilotage par blocs ci-dessous), et à terme un ballon tampon.</p>
        </div>
    </x-filament::section>

    <div class="grid gap-6 xl:grid-cols-2">
        @if ($loadChart)
            <x-filament::section heading="Besoin de la maison vs puissance de la chaudière">
                <x-echart id="opt-load" :option="$loadChart" height="340px" />
            </x-filament::section>
        @endif
        <x-filament::section heading="Durée de combustion des cycles selon la T extérieure" description="Échelle logarithmique.">
            <x-echart id="opt-cycles" :option="$cyclesChart" height="340px" />
        </x-filament::section>
    </div>

    <x-filament::section heading="Recommandations" icon="heroicon-o-light-bulb">
        <div class="space-y-4">
            @forelse ($result['recommendations'] as $rec)
                <div class="rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <x-filament::badge class="shrink-0" :color="$sev[$rec['severity']][0]">{{ $sev[$rec['severity']][1] }}</x-filament::badge>
                            <div>
                                <div class="font-semibold">{{ $rec['title'] }}</div>
                                <div class="text-xs text-gray-500">{{ $lvl[$rec['level']] ?? '' }}</div>
                            </div>
                        </div>
                        @if ($rec['changes'] && ! $error)
                            <x-filament::button size="sm" :color="$rec['level'] === 'expert' ? 'warning' : 'primary'"
                                wire:click="applyRecommendation('{{ $rec['id'] }}')"
                                wire:confirm="{{ $rec['level'] === 'expert' ? 'Réglage technicien : confirmez-vous l\'écriture sur la chaudière ?' : 'Appliquer cette recommandation sur la chaudière ?' }}"
                                icon="heroicon-o-check">
                                Appliquer
                            </x-filament::button>
                        @endif
                    </div>
                    <p class="mt-3 text-sm">{{ $rec['summary'] }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-400">
                        @foreach ($rec['details'] as $d)<li>{{ $d }}</li>@endforeach
                    </ul>
                    @if ($rec['changes'])
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($rec['changes'] as $name => $value)
                                @php $p = $catalog->parameter($name); @endphp
                                <x-filament::badge color="gray">{{ $p ? $catalog->label($p) : $name }} → {{ isset($p['options'][$value]) ? $p['options'][$value] : $value.(isset($p['unit']) ? ' '.$p['unit'] : '') }}</x-filament::badge>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">Aucune recommandation sur cette période.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section heading="Pilotage automatique : chauffage par blocs (expérimental)" icon="heroicon-o-cpu-chip" collapsible>
        <div class="prose prose-sm mb-4 max-w-none dark:prose-invert">
            <p>À mi-saison, au lieu de laisser la chaudière enchaîner des cycles courts, l'application alterne :
                <strong>des blocs de chauffe</strong> (consigne confort légèrement relevée → demande plus forte, combustion longue)
                et <strong>des pauses</strong> (circuit de chauffage à l'arrêt) tant que la température ambiante reste dans la bande de confort.
                L'inertie de la maison sert de stockage. L'eau chaude n'est pas concernée. Hors conditions ou en cas de problème, les réglages d'origine sont restaurés.</p>
            <p>Commencez en <strong>mode simulation</strong> : les décisions sont journalisées sans être envoyées à la chaudière. Chaque bascule écrit 2 valeurs dans l'automate ; avec des blocs d'au moins 2 h, cela reste limité (≈ 10 écritures par jour).</p>
        </div>
        <form wire:submit="savePilot" class="grid gap-4 md:grid-cols-4">
            <label class="flex items-center gap-2 text-sm"><x-filament::input.checkbox wire:model="pilot.enabled" /> Activer le pilotage</label>
            <label class="flex items-center gap-2 text-sm"><x-filament::input.checkbox wire:model="pilot.dry_run" /> Mode simulation</label>
            <div class="md:col-span-2 text-sm text-gray-500">État : <strong>{{ ['idle' => 'inactif', 'block' => 'bloc de chauffe', 'pause' => 'pause'][$pilotState['phase']] ?? $pilotState['phase'] }}</strong>
                @if ($pilotState['since']) depuis {{ \Carbon\Carbon::parse($pilotState['since'])->format('d/m H:i') }} @endif</div>
            @foreach ([
                'min_outside_temp' => ['T ext mini', '°C'], 'max_outside_temp' => ['T ext maxi', '°C'],
                'room_band_low' => ['Relance si ambiance <', 'K / consigne'], 'room_band_high' => ['Pause si ambiance >', 'K / consigne'],
                'min_block_minutes' => ['Bloc minimum', 'min'], 'max_pause_minutes' => ['Pause maximum', 'min'],
                'boost_k' => ['Relève de consigne pendant les blocs', 'K'],
            ] as $k => [$label, $unit])
                <div>
                    <label class="text-sm font-medium">{{ $label }}</label>
                    <x-filament::input.wrapper :suffix="$unit"><x-filament::input type="number" step="0.1" wire:model="pilot.{{ $k }}" /></x-filament::input.wrapper>
                </div>
            @endforeach
            <div>
                <label class="text-sm font-medium">Plage horaire</label>
                <div class="flex gap-2">
                    <x-filament::input.wrapper><x-filament::input type="time" wire:model="pilot.window_start" /></x-filament::input.wrapper>
                    <x-filament::input.wrapper><x-filament::input type="time" wire:model="pilot.window_end" /></x-filament::input.wrapper>
                </div>
            </div>
            <div class="md:col-span-4"><x-filament::button type="submit" icon="heroicon-o-check">Enregistrer</x-filament::button></div>
        </form>

        @if ($actions->isNotEmpty())
            <table class="mt-6 w-full text-sm">
                <thead class="text-left text-gray-500"><tr><th class="py-2">Date</th><th>Action</th><th>Raison</th><th>Mode</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($actions as $a)
                        <tr>
                            <td class="py-1.5">{{ $a->created_at->format('d/m H:i') }}</td>
                            <td>{{ ['block' => 'Bloc de chauffe', 'pause' => 'Pause', 'restore' => 'Restauration'][$a->action] ?? $a->action }}</td>
                            <td>{{ $a->reason }}</td>
                            <td><x-filament::badge size="sm" :color="$a->dry_run ? 'gray' : 'success'">{{ $a->dry_run ? 'simulation' : 'appliqué' }}</x-filament::badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
