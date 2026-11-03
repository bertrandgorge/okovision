<x-filament-panels::page>
    @php
        $fmt = fn ($x, $d = 1) => $x === null ? '—' : number_format((float) $x, $d, ',', ' ');
        $controls = [
            'slope' => ['Pente', 0, 4, 0.1, '', "°C de départ en plus par °C extérieur en moins. Se règle quand il fait froid (T ext < 5 °C)."],
            'foot' => ['Pied de courbe', 20, 45, 0.5, '°C', 'Départ à 15 °C extérieur. Décale toute la courbe. Se règle à mi-saison (5 à 15 °C).'],
            'room_comfort' => ['Consigne confort', 15, 24, 0.5, '°C', 'Chaque °C de consigne ajoute (1 + pente) °C au départ.'],
            'room_reduced' => ['Consigne réduit', 12, 22, 0.5, '°C', 'Nuit / absence.'],
            'influence' => ['Influence sonde d\'ambiance', 0, 10, 1, '', '°C de départ ajoutés par °C manquant en ambiance.'],
            'flow_min' => ['Départ mini', 10, 60, 1, '°C', null],
            'flow_max' => ['Départ maxi', 40, 80, 1, '°C', null],
            'limit_comfort' => ['Limite de chauffe confort', 10, 22, 0.5, '°C', 'Arrêt du chauffage au-dessus de cette T ext moyennée.'],
            'limit_reduced' => ['Limite de chauffe réduit', 5, 22, 0.5, '°C', null],
        ];
    @endphp

    @if ($error)
        <div class="rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-200 dark:ring-warning-800">
            Chaudière injoignable ({{ $error }}). Valeurs affichées : dernier profil connu. L'application des réglages est désactivée.
        </div>
    @endif

    <div class="grid gap-6 2xl:grid-cols-3">
        <x-filament::section class="2xl:col-span-2" heading="Courbe" description="Glissez les poignées orange (à −5 °C et +15 °C) pour régler la pente et le pied de courbe, ou utilisez les curseurs. Les points colorés sont les consignes réellement calculées par la chaudière : bleu = pièce trop froide, rouge = trop chaude.">
            <x-slot name="afterHeader">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="days">
                        <option value="14">14 derniers jours</option>
                        <option value="30">30 derniers jours</option>
                        <option value="60">60 derniers jours</option>
                        <option value="120">120 derniers jours</option>
                        <option value="365">12 derniers mois</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-slot>
            <div x-on:oko-curve-drag="$wire.dragCurve($event.detail.points)">
                <x-echart id="curve-chart" :option="$option" height="520px" :curve-editor="['points' => $handles, 'seriesIndex' => 0]" />
            </div>
        </x-filament::section>

        <x-filament::section heading="Réglages" :description="$hasChanges ? 'Modifications non appliquées' : 'Identique à la chaudière'">
            <x-slot name="afterHeader">
                @if ($hasChanges)
                    <x-filament::button size="sm" color="gray" wire:click="resetEdit" icon="heroicon-o-arrow-uturn-left">Annuler</x-filament::button>
                @endif
            </x-slot>
            <div class="space-y-4">
                @foreach ($controls as $key => [$label, $min, $max, $step, $unit, $help])
                    @php $changed = abs((float) ($edit[$key] ?? 0) - (float) ($current[$key] ?? 0)) > 1e-6; @endphp
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <label class="text-sm font-medium">{{ $label }}</label>
                            <div class="flex items-center gap-1">
                                <x-filament::icon-button size="sm" color="gray" icon="heroicon-m-minus" wire:click="step('{{ $key }}', -{{ $step }})" label="Diminuer" />
                                <span @class(['oko-kpi-value w-16 text-center text-sm font-semibold', 'text-primary-600' => $changed])>{{ $fmt($edit[$key] ?? null, $step < 1 ? 1 : 0) }} {{ $unit }}</span>
                                <x-filament::icon-button size="sm" color="gray" icon="heroicon-m-plus" wire:click="step('{{ $key }}', {{ $step }})" label="Augmenter" />
                            </div>
                        </div>
                        <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" wire:model.live.debounce.150ms="edit.{{ $key }}" class="mt-1 w-full accent-primary-600" />
                        @if ($changed)
                            <p class="text-xs text-primary-600">actuel : {{ $fmt($current[$key] ?? null, $step < 1 ? 1 : 0) }} {{ $unit }}</p>
                        @elseif ($help)
                            <p class="text-xs text-gray-500">{{ $help }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section heading="Températures de départ calculées" description="Consigne ambiance confort, sans correction de la sonde d'ambiance.">
            <table class="w-full text-sm [&_td]:px-1.5 [&_th]:px-1.5">
                <thead class="text-left text-gray-500">
                    <tr><th class="py-2">T extérieure</th><th class="text-end">Actuelle</th><th class="text-end">Nouvelle</th><th class="text-end">Chaudière demandée</th>
                        <th class="text-end">Moyenne mesurée</th><th class="text-end">Écart ambiance</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($table as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['t'] }} °C</td>
                            <td class="text-end">{{ $row['current'] === null ? 'arrêt' : $fmt($row['current']).' °C' }}</td>
                            <td @class(['text-end font-medium', 'text-primary-600' => $row['edited'] !== $row['current']])>{{ $row['edited'] === null ? 'arrêt' : $fmt($row['edited']).' °C' }}</td>
                            <td class="text-end">{{ $row['boiler'] === null ? '—' : $fmt($row['boiler']).' °C' }}</td>
                            <td class="text-end">{{ $row['measured'] === null ? '—' : $fmt($row['measured']).' °C' }}</td>
                            <td @class(['text-end', 'text-info-600' => ($row['deviation'] ?? 0) < -0.3, 'text-danger-600' => ($row['deviation'] ?? 0) > 0.3])>{{ $row['deviation'] === null ? '—' : sprintf('%+.1f K', $row['deviation']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-500">
                Formule du régulateur (identifiée sur l'historique) : départ = pied + (consigne − 20) + pente × (consigne − 5 − T ext) + influence × (consigne − T ambiante), borné entre départ mini et maxi.
            </p>
        </x-filament::section>

        <x-filament::section heading="Courbe apprise" icon="heroicon-o-academic-cap">
            @if ($learned['suggestion'])
                <p class="text-sm">
                    Sur {{ number_format($learned['points'], 0, ',', ' ') }} mesures ({{ \Carbon\Carbon::parse($from)->format('d/m') }} → {{ \Carbon\Carbon::parse($to)->format('d/m') }}),
                    la courbe qui reproduit les consignes réellement nécessaires (après correction par la sonde d'ambiance) est :
                </p>
                <p class="my-3 text-lg font-semibold">pente {{ $fmt($learned['suggestion']['slope']) }} · pied {{ $fmt($learned['suggestion']['foot']) }} °C</p>
                <x-filament::button size="sm" wire:click="applySuggestion({{ $learned['suggestion']['slope'] }}, {{ $learned['suggestion']['foot'] }})" icon="heroicon-o-arrow-down-tray">Reprendre dans l'éditeur</x-filament::button>
            @else
                <p class="text-sm text-gray-500">Pas assez de mesures en chauffe sur la période pour apprendre la courbe.</p>
            @endif

            <div class="prose prose-sm mt-6 max-w-none dark:prose-invert">
                <h4>Méthode de réglage</h4>
                <ul>
                    <li><strong>Une modification par jour au maximum</strong>, par petits pas : la maison réagit lentement.</li>
                    <li>Entre +5 et +15 °C extérieur : trop chaud → pied −2 à −5 °C ; trop froid → pied +2 à +5 °C.</li>
                    <li>Entre −20 et +5 °C : trop chaud → pente −0,1 à −0,2 ; trop froid → pente +0,1 à +0,2.</li>
                    <li>Avec une sonde d'ambiance, réglez la courbe pour que l'écart reste proche de 0 : la sonde ne fait alors qu'affiner. Une courbe trop haute fait travailler la vanne mélangeuse en permanence et augmente les pertes.</li>
                    <li>Convecteurs à ailettes : faible inertie et puissance très dépendante de la température d'eau ; une pente de 1,2 à 1,8 est usuelle. Les vannes thermostatiques doivent rester des organes de réglage fin, pas le réglage principal.</li>
                    <li>Visez la température de départ la plus basse qui assure le confort : moins de pertes de distribution et une demande chaudière plus régulière.</li>
                </ul>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
