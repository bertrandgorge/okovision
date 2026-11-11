<x-filament-panels::page>
    @php
        $catalog = app(\App\Okofen\Catalog::class);
        $fmt = fn ($x, $d = 1) => $x === null ? '—' : number_format((float) $x, $d, ',', ' ');
        // clé => [libellé, min, max, pas, unité, aide propre à la page]
        $controls = [
            'slope' => ['Pente', 0, 4, 0.1, '', '°C de départ en plus par °C extérieur en moins : agit surtout par grand froid.'],
            'foot' => ['Pied de courbe', 20, 45, 0.5, '°C', 'Départ à 15 °C extérieur : décale toute la courbe, agit surtout à mi-saison.'],
            'influence' => ['Influence de la sonde d\'ambiance', 0, 5, 1, '', 'Coefficient multiplicateur de la correction de la sonde d\'ambiance. Plus ce chiffre est élevé, plus la sonde d\'ambiance a d\'impact sur le réglage de la température de départ.'],
            'flow_min' => ['Départ mini', 10, 60, 1, '°C', 'Plancher de la courbe : le départ ne descend jamais en dessous.'],
            'flow_max' => ['Départ maxi', 40, 80, 1, '°C', 'Plafond de la courbe : protège les émetteurs, limite le départ par grand froid.'],
            'raise' => ['Surélévation', 0, 20, 0.5, 'K', 'Ajoutée au départ calculé pour la consigne chaudière (réserve pour la vanne mélangeuse).'],
            'room_comfort' => ['Consigne confort', 15, 24, 0.5, '°C', 'Pendant les plages du programme. Décale aussi la loi d\'eau : chaque °C ajoute (1 + pente) °C au départ.'],
            'room_reduced' => ['Consigne réduit', 12, 22, 0.5, '°C', 'En dehors des plages (nuit, absence).'],
            'hyst' => ['Hystérésis d\'ambiance', 0, 10, 0.5, 'K', 'Le circuit s\'arrête quand la pièce dépasse consigne + hystérésis et repart 1 °C sous ce seuil.'],
            'lead' => ['Durée d\'anticipation', 0, 300, 15, 'min', 'La chauffe démarre avant la plage confort pour être à la consigne à l\'heure dite.'],
            'limit_comfort' => ['Limite de chauffe confort', 10, 22, 0.5, '°C', 'Pendant une plage confort, arrêt si la T ext moyennée dépasse cette valeur ; relance 2 °C en dessous.'],
            'limit_reduced' => ['Limite de chauffe réduit', -10, 22, 0.5, '°C', 'Pareil pendant le réduit : décide si l\'on chauffe la nuit.'],
            'average' => ['Durée de moyenne de la T ext', 0, 180, 15, 'min', 'La T extérieure est lissée sur cette durée avant d\'être comparée aux limites et utilisée par la loi d\'eau.'],
        ];
        $row = fn (string $key) => [
            'label' => $controls[$key][0], 'min' => $controls[$key][1], 'max' => $controls[$key][2], 'step' => $controls[$key][3],
            'unit' => $controls[$key][4], 'help' => $controls[$key][5],
            'param' => $catalog->parameter(\App\Filament\Pages\HeatingCurvePage::PARAMS[$key]),
        ];
        $groups = [
            'room' => [
                'Consignes d\'ambiance et thermostat', 'heroicon-o-home',
                'Pendant les plages du programme horaire, la chaudière vise la consigne confort, sinon la consigne réduit (ligne verte). Avec la sonde d\'ambiance, le circuit s\'arrête quand la pièce dépasse consigne + hystérésis et ne repart qu\'1 °C sous ce seuil : c\'est le tunnel gris du graphique. Ces consignes entrent aussi dans la loi d\'eau : monter la consigne relève toute la courbe.',
                ['room_comfort', 'room_reduced', 'hyst', 'lead'],
            ],
            'limits' => [
                'Limites de chauffe', 'heroicon-o-sun',
                'Indépendamment de la pièce, le circuit s\'arrête quand la T extérieure moyennée dépasse la limite de la plage en cours (confort ou réduit) et ne repart que 2 °C en dessous : lignes rouges du graphique. La moyenne évite de couper au moindre rayon de soleil sur la sonde. Les deux conditions s\'ajoutent : le chauffage ne tourne que si la T ext est sous la limite et la pièce sous son seuil de relance.',
                ['limit_comfort', 'limit_reduced', 'average'],
            ],
        ];
        $hlClass = 'ring-2 ring-warning-400 bg-warning-50/80 dark:bg-warning-950/30 rounded-lg px-3 -mx-3';
    @endphp

    {{-- hl : réglages mis en exergue au survol des graphiques (évènement oko-setting-hover) --}}
    <div class="space-y-6" x-data="{ hl: [] }" x-on:oko-setting-hover.window="hl = $event.detail.keys">
        @if ($error)
            <div class="rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-200 dark:ring-warning-800">
                Chaudière injoignable ({{ $error }}). Valeurs affichées : dernier profil connu. L'application des réglages est désactivée.
            </div>
        @endif

        {{-- Journée : T extérieure face aux limites, T ambiante face aux consignes et à l'hystérésis --}}
        <x-filament::section heading="Journée de chauffage" icon="heroicon-o-clock"
            description="En haut de la légende, la pièce : le thermostat arrête le chauffage quand la pièce dépasse la ligne « arrêt » et ne le relance que sous « relance » (tunnel gris). En dessous, l'extérieur : chauffage coupé quand la T ext moyennée dépasse la « limite », relancé sous « relance limite ». Cliquez sur une légende pour masquer la courbe ; survolez une courbe pour voir les réglages en jeu.">
            <x-slot name="afterHeader">
                <div class="flex items-center gap-2">
                    <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" label="Jour précédent" tooltip="Jour précédent" color="gray" />
                    <x-filament::input.wrapper class="w-44">
                        <x-filament::input type="date" wire:model.live="date" :max="now()->format('Y-m-d')" />
                    </x-filament::input.wrapper>
                    <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" label="Jour suivant" tooltip="Jour suivant" color="gray"
                        :disabled="$date >= now()->format('Y-m-d')" />
                    <div wire:loading wire:target="previous,next,date"><x-filament::loading-indicator class="h-5 w-5" /></div>
                </div>
            </x-slot>
            @if ($dayChart['empty'])
                <p class="text-sm text-gray-500">Aucune mesure pour cette journée.</p>
            @else
                <x-echart id="heating-day-{{ $date }}" :option="$dayChart['option']" height="420px" />
            @endif
        </x-filament::section>

        {{-- Réglages groupés --}}
        @foreach ($groups as $g => [$title, $icon, $intro, $keys])
            <x-filament::section :heading="$title" :icon="$icon">
                <p class="mb-2 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">{{ $intro }}</p>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($keys as $key)
                        @php $r = $row($key); @endphp
                        <x-setting-row :key="$key" :label="$r['label']" :param="$r['param']" :value="$edit[$key] ?? null" :current="$current[$key] ?? null"
                            :changed="$this->isSettingChanged($key)" :factory="$this->factoryValue($key)" :help="$r['help']" :disabled="$error !== null"
                            x-bind:class="hl.includes('{{ $key }}') && '{{ $hlClass }}'">
                            <x-filament::input.wrapper :valid="! $this->isSettingChanged($key)" :suffix="$r['unit'] ?: null">
                                <x-filament::input type="number" wire:model.live.debounce.500ms="edit.{{ $key }}" :step="$r['step']" :min="$r['min']" :max="$r['max']" />
                            </x-filament::input.wrapper>
                        </x-setting-row>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach

        {{-- Loi d'eau : graphique (carré) à gauche, réglages à droite --}}
        <x-filament::section heading="Loi d'eau : température de départ" icon="heroicon-o-arrow-trending-up"
            :description="$hasChanges ? 'Modifications non appliquées : « Appliquer » sur chaque ligne, ou « Tout appliquer » en haut (conseillé pour pente + pied).' : 'Survolez la courbe : les réglages qui la façonnent à cette température extérieure s\'illuminent.'">
            <x-slot name="afterHeader">
                <div class="flex items-center gap-2">
                    @if ($hasChanges)
                        <x-filament::button size="sm" color="gray" wire:click="resetEdit" icon="heroicon-o-arrow-uturn-left">Tout annuler</x-filament::button>
                    @endif
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="days">
                            <option value="14">Mesures : 14 jours</option>
                            <option value="30">Mesures : 30 jours</option>
                            <option value="60">Mesures : 60 jours</option>
                            <option value="120">Mesures : 120 jours</option>
                            <option value="365">Mesures : 12 mois</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </x-slot>
            <div class="grid gap-8 lg:grid-cols-2">
                <div>
                    <x-echart id="curve-chart" :option="$option" :hover="['points' => $curveHover]" height="600px" />
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Points colorés : consignes de départ réellement calculées par la chaudière (correction de la sonde comprise) ; bleu = pièce trop froide, rouge = trop chaude.
                        En orange, la courbe avec les modifications en cours.
                    </p>
                </div>
                <div>
                    <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                        Départ = pied + (consigne − 20) + pente × (consigne − 5 − T ext) + influence × (consigne − T ambiante), borné entre départ mini et maxi.
                        La pente agit surtout par grand froid, le pied à mi-saison ; l'influence de la sonde corrige en continu l'écart mesuré dans la pièce.
                        La consigne chaudière vaut ce départ plus la surélévation.
                    </p>
                    <div class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach (['slope', 'foot', 'influence', 'flow_min', 'flow_max', 'raise'] as $key)
                            @php $r = $row($key); @endphp
                            <x-setting-row :key="$key" :label="$r['label']" :param="$r['param']" :value="$edit[$key] ?? null" :current="$current[$key] ?? null"
                                :changed="$this->isSettingChanged($key)" :factory="$this->factoryValue($key)" :help="$r['help']" :disabled="$error !== null"
                                :wide="$key === 'influence'" x-bind:class="hl.includes('{{ $key }}') && '{{ $hlClass }}'">
                                @if ($key === 'influence')
                                    {{-- liste déroulante 0–5 : chaque valeur avec son effet --}}
                                    @php
                                        $levels = [
                                            0 => 'sonde d\'ambiance désactivée',
                                            1 => 'correction douce, si la loi d\'eau est déjà juste (usine)',
                                            2 => 'correction franche, utile avec une sonde bien placée',
                                            3 => 'correction franche, utile avec une sonde bien placée',
                                            4 => 'risque d\'oscillations, la pièce réagissant avec plusieurs heures de retard',
                                            5 => 'risque d\'oscillations, la pièce réagissant avec plusieurs heures de retard',
                                        ];
                                        $inf = (int) round((float) ($edit['influence'] ?? 0));
                                        if (! isset($levels[$inf])) {
                                            $levels[$inf] = 'valeur actuelle, hors de la plage habituelle';
                                        }
                                    @endphp
                                    <x-filament::input.wrapper :valid="! $this->isSettingChanged('influence')">
                                        <x-filament::input.select wire:model.live="edit.influence">
                                            @foreach ($levels as $v => $text)
                                                <option value="{{ $v }}">{{ $v }} : {{ $text }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                @else
                                    <x-filament::input.wrapper :valid="! $this->isSettingChanged($key)" :suffix="$r['unit'] ?: null">
                                        <x-filament::input type="number" wire:model.live.debounce.500ms="edit.{{ $key }}" :step="$r['step']" :min="$r['min']" :max="$r['max']" />
                                    </x-filament::input.wrapper>
                                @endif
                            </x-setting-row>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Méthode de réglage --}}
        <x-filament::section heading="Régler la loi d'eau" icon="heroicon-o-academic-cap" collapsible>
            <div class="prose prose-sm max-w-none dark:prose-invert">
                <p>
                    La loi d'eau fait l'essentiel du travail : elle donne la température de départ selon la T extérieure. La sonde d'ambiance ne devrait
                    qu'affiner, et le thermostat (hystérésis) ne couper qu'exceptionnellement. Une courbe juste se reconnaît à une pièce qui reste à la
                    consigne avec une correction de la sonde proche de zéro : points verts sur la courbe, peu d'arrêts du circulateur dans la journée.
                </p>
                <h4>Neutraliser la sonde d'ambiance pendant le réglage</h4>
                <p>
                    Pour régler la loi d'eau « à l'ancienne », en observant la pièce, mettez <strong>l'influence de la sonde à 0</strong> le temps de l'essai :
                    c'est elle qui masque les défauts de la courbe, en ajoutant ou retirant en permanence quelques degrés au départ. À 0, la température de départ
                    ne dépend plus que de la courbe, et la température à laquelle la pièce se stabilise dit directement si la courbe est trop haute ou trop basse.
                </p>
                <p>
                    L'hystérésis d'ambiance n'est qu'un complément. Elle ne joue que si la courbe est trop haute : la pièce dépasse alors consigne + hystérésis
                    et le thermostat coupe le circuit, ce qui empêche de voir où elle se serait stabilisée. Relevez-la à 2–3 °C pendant l'essai si vous voyez
                    le circulateur s'arrêter ; sinon, inutile d'y toucher. Une fois la pièce stable à la consigne, remettez l'influence à 2–3 (et l'hystérésis à 1–1,5 °C).
                </p>
                <p>
                    Avec Okovision, ce n'est pas indispensable : la <strong>courbe apprise</strong> (en bas de page) se calcule à partir des consignes que la
                    chaudière a réellement utilisées, correction de la sonde comprise. Elle donne directement la pente et le pied qui rendraient cette correction
                    nulle, sans dégrader le confort pendant des jours d'essai.
                </p>
                <h4>Méthode</h4>
                <ul>
                    <li><strong>Fixez d'abord la consigne confort</strong> : elle décale toute la courbe (chaque °C ajoute 1 + pente °C au départ).</li>
                    <li><strong>Un seul réglage par jour</strong>, par petits pas, par temps stable : la maison réagit en plusieurs heures.</li>
                    <li>Entre +5 et +15 °C extérieur, on agit sur le <strong>pied</strong> : trop chaud → −1 à −2 °C ; trop froid → +1 à +2 °C.</li>
                    <li>Entre −20 et +5 °C, on agit sur la <strong>pente</strong> : trop chaud → −0,1 à −0,2 ; trop froid → +0,1 à +0,2.</li>
                    <li>Pièce de la sonde représentative : robinets thermostatiques ouverts en grand, pas de soleil direct ni de poêle.</li>
                    <li>Visez le départ le plus bas qui assure le confort : moins de pertes et une demande chaudière plus régulière. Convecteurs à ailettes : pente usuelle 1,2 à 1,8.</li>
                </ul>
                <h4>Lire le graphique de la journée</h4>
                <ul>
                    <li>Circulateur haché, pièce qui touche le haut du tunnel gris : courbe trop haute (ou consigne trop basse).</li>
                    <li>Pièce sous la consigne alors que le circulateur tourne en continu : courbe trop basse, ou départ maxi qui limite.</li>
                    <li>Chauffage arrêté alors que la pièce est fraîche, T ext moyennée au-dessus de la ligne rouge : limite de chauffe trop basse.</li>
                </ul>
            </div>
        </x-filament::section>

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
        </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
