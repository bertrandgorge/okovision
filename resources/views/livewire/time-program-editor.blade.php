<div class="space-y-6">
    @php
        $explain = [
            'hk' => [
                'Pendant une plage, le chauffage vise la consigne <strong>confort</strong> ; en dehors, la consigne <strong>réduit</strong> (ou s\'arrête si la T extérieure dépasse la limite de chauffe en réduit).',
                'Inutile d\'avancer le début d\'une plage : la chaudière démarre d\'elle-même en avance (durée d\'anticipation) pour être à la consigne à l\'heure indiquée.',
                'Évitez de hacher la journée : une coupure courte bascule le circuit en réduit ; il s\'arrête, puis peut ne pas repartir au retour du confort tant que la pièce n\'a pas refroidi (hystérésis d\'ambiance). ÖkoFEN conseille des plages longues et continues.',
            ],
            'ww' => [
                'Pendant une plage, le ballon est maintenu à la <strong>consigne d\'eau chaude</strong> : chaque puisage qui le fait passer sous consigne − hystérésis relance une charge. En dehors, il n\'est rechargé que s\'il descend sous la <strong>T mini</strong> moins l\'hystérésis (manuel ÖkoFEN p. 26 et 59).',
                '<strong>Début de plage : avant les douches.</strong> Contrairement au chauffage, il n\'y a pas d\'anticipation pour l\'eau chaude : la charge démarre à l\'heure de début de la plage, et le brûleur doit d\'abord s\'allumer et chauffer la chaudière.'
                    .($chargeDelay ? ' Sur vos '.$chargeDelay['count'].' dernières charges en début de plage, l\'eau était chaude <strong>'.$chargeDelay['median'].' min</strong> après le début (entre '.$chargeDelay['min'].' et '.$chargeDelay['max'].' min : moins quand la chaudière est déjà chaude, plus à froid) : faites commencer la plage au moins '.(int) (ceil($chargeDelay['median'] / 15) * 15).' min avant la première douche.' : ' Comptez environ 45 min si la chaudière est froide.'),
                '<strong>Fin de plage : juste après la dernière douche.</strong> Plus tard, le ballon refroidi par les douches est rechargé aussitôt, ce qui coûte une charge et un démarrage du brûleur de plus, même si personne ne s\'en sert avant la plage suivante. Gardez une plage plus longue seulement si vous voulez de l\'eau bien chaude toute la soirée.',
                'Peu de plages, courtes et calées sur les gros puisages : une charge par plage, donc peu de démarrages. Si l\'eau est trop tiède hors plage (vaisselle, lavabo), remontez plutôt la T mini (30 → 40 °C) que d\'allonger les plages.',
                'Le jour du cycle anti-légionelles, le ballon monte à 65 °C pendant les plages.',
            ],
            'zirkp' => [
                'Pendant une plage, la pompe de bouclage peut faire circuler l\'eau chaude jusqu\'aux robinets ; en dehors, elle reste arrêtée.',
                'Limitez les plages aux heures de puisage : le bouclage refroidit le ballon et fait démarrer la chaudière plus souvent.',
            ],
        ][$circuit] ?? [];
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::tabs>
            @foreach ([0, 1] as $p)
                <x-filament::tabs.item :active="$program === $p" wire:click="$set('program', {{ $p }})">
                    Programme {{ $p + 1 }}
                    @if ($active === $p)
                        <x-slot name="badge">actif</x-slot>
                    @endif
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div wire:loading><x-filament::loading-indicator class="h-5 w-5" /></div>
        <div class="ms-auto flex flex-wrap items-center gap-2">
            @if ($error === null && $active !== null && $active !== $program)
                <x-filament::button color="gray" icon="heroicon-o-check-circle" wire:click="activate"
                    wire:confirm="Utiliser le programme {{ $program + 1 }} sur la chaudière ?">Activer le programme {{ $program + 1 }}</x-filament::button>
            @endif
            @if ($changed)
                <x-filament::badge color="warning">Modifications non appliquées</x-filament::badge>
                <x-filament::button color="gray" icon="heroicon-o-arrow-uturn-left" wire:click="resetEdit">Annuler</x-filament::button>
            @endif
            <x-filament::button icon="heroicon-o-paper-airplane" wire:click="apply" :disabled="! $changed || $invalid !== null"
                wire:confirm="Écrire ce programme sur la chaudière ?{{ $newSummary ? ' Actuel : '.$summary.'. Nouveau : '.$newSummary.'.' : '' }}">Appliquer</x-filament::button>
            <x-filament::icon-button icon="heroicon-o-arrow-path" color="gray" label="Relire la chaudière" tooltip="Relire la chaudière" wire:click="load" />
        </div>
    </div>

    @if ($error)
        <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-200 dark:ring-danger-800">
            Lecture impossible : {{ $error }}
        </div>
    @else
        <div class="space-y-1.5 rounded-xl bg-gray-50 p-4 text-sm text-gray-700 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
            @if ($active !== null && $active !== $program)
                <p class="font-medium text-warning-700 dark:text-warning-400">
                    Ce programme n'est pas utilisé actuellement : la chaudière suit le programme {{ $active + 1 }}. Bouton « Activer le programme {{ $program + 1 }} » pour basculer.
                </p>
            @endif
            @foreach ($explain as $line)
                <p class="flex gap-2"><span class="text-gray-400">•</span><span>{!! $line !!}</span></p>
            @endforeach
            <p class="flex gap-2"><span class="text-gray-400">•</span><span>Jusqu'à 3 plages par jour. Deux programmes par circuit : préparez par exemple un programme « télétravail » et un programme « bureau », et basculez de l'un à l'autre.</span></p>
        </div>

        {{-- Vue de la semaine --}}
        <x-filament::section heading="Semaine" description="Aperçu des plages, y compris les modifications non appliquées.">
            @php $preview = is_array($parsed) ? $parsed : $current; @endphp
            <div class="space-y-1.5">
                <div class="ml-24 flex justify-between text-[11px] text-gray-400">
                    @foreach ([0, 3, 6, 9, 12, 15, 18, 21, 24] as $h)
                        <span>{{ sprintf('%02d', $h) }}h</span>
                    @endforeach
                </div>
                @foreach ($days as $d => $label)
                    <div class="flex items-center gap-3">
                        <div class="w-21 shrink-0 text-sm text-gray-600 dark:text-gray-300">{{ $label }}</div>
                        <div class="relative h-6 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/5">
                            @foreach ([3, 6, 9, 12, 15, 18, 21] as $h)
                                <div class="absolute inset-y-0 border-l border-white/70 dark:border-gray-900/60" style="left: {{ $h / 24 * 100 }}%"></div>
                            @endforeach
                            @foreach ($preview[$d] ?? [] as [$a, $b])
                                <div class="absolute inset-y-0.5 rounded bg-primary-500/80" style="left: {{ $a / 1440 * 100 }}%; width: {{ max(0.4, ($b - $a) / 1440 * 100) }}%"
                                     title="{{ \App\Okofen\TimeProgram::time($a) }} – {{ \App\Okofen\TimeProgram::time($b) }}"></div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($invalid)
            <div class="rounded-xl bg-danger-50 p-3 text-sm text-danger-800 ring-1 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-200 dark:ring-danger-800">{{ $invalid }}</div>
        @endif

        {{-- Édition --}}
        <x-filament::section heading="Plages horaires"
            :description="($circuit === 'hk' ? 'Heures de confort' : 'Heures de fonctionnement').($perDay ? ', jour par jour.' : ', identiques tous les jours.').($circuit === 'hk' ? ' En dehors des plages : réduit.' : '')">
            <x-slot name="afterHeader">
                {{-- Interrupteur « Réglage par jour » --}}
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <span class="text-gray-600 dark:text-gray-300">Réglage par jour</span>
                    <button type="button" role="switch" aria-checked="{{ $perDay ? 'true' : 'false' }}" wire:click="$toggle('perDay')"
                        @class(['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition', 'bg-primary-600' => $perDay, 'bg-gray-300 dark:bg-gray-600' => ! $perDay])>
                        <span @class(['inline-block h-5 w-5 rounded-full bg-white shadow transition', 'translate-x-5.5' => $perDay, 'translate-x-0.5' => ! $perDay])></span>
                    </button>
                    <span class="w-6 font-medium">{{ $perDay ? 'on' : 'off' }}</span>
                </label>
            </x-slot>
            @if ($merged)
                <p class="mb-2 flex items-start gap-1.5 rounded-lg bg-warning-50 p-2.5 text-xs text-warning-800 ring-1 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-200 dark:ring-warning-800">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-px h-4 w-4 shrink-0" />
                    Les jours avaient des plages différentes : celles du lundi s'appliqueront désormais à toute la semaine. Rien n'est écrit sur la chaudière avant « Appliquer » ; « Annuler » rétablit le programme actuel.
                </p>
            @endif
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($perDay ? $days : [\App\Livewire\TimeProgramEditor::ALL_DAYS_SOURCE => 'Tous les jours'] as $d => $label)
                    @php
                        $filled = collect($edit[$d] ?? [])->filter(fn ($s) => filled($s['start'] ?? null) || filled($s['end'] ?? null));
                    @endphp
                    <div class="py-3">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <div class="w-24 text-sm font-medium">{{ $label }}</div>

                            @forelse ($filled as $i => $slot)
                                <div class="flex items-center gap-1 rounded-lg bg-gray-50 py-1 pe-1 ps-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                    <span class="me-1 text-xs text-gray-500">de</span>
                                    <x-filament::input.wrapper class="w-28">
                                        <x-filament::input type="time" wire:model.live.debounce.400ms="edit.{{ $d }}.{{ $i }}.start" />
                                    </x-filament::input.wrapper>
                                    <span class="mx-1 text-xs text-gray-500">à</span>
                                    <x-filament::input.wrapper class="w-28">
                                        <x-filament::input type="time" wire:model.live.debounce.400ms="edit.{{ $d }}.{{ $i }}.end" />
                                    </x-filament::input.wrapper>
                                    <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" label="Supprimer cette plage" tooltip="Supprimer cette plage"
                                        wire:click="removeSlot({{ $d }}, {{ $i }})" />
                                </div>
                            @empty
                                <span class="text-sm text-gray-500">{{ $circuit === 'hk' ? 'Aucune plage : réduit toute la journée' : 'Aucune plage ce jour' }}</span>
                            @endforelse

                            @if ($filled->count() < \App\Okofen\TimeProgram::SLOTS)
                                <x-filament::link tag="button" icon="heroicon-m-plus" size="sm" wire:click="addSlot({{ $d }})">Ajouter une plage</x-filament::link>
                            @endif

                            <div class="ml-auto flex items-center gap-1">
                                @if ($perDay)
                                <x-filament::dropdown placement="bottom-end">
                                    <x-slot name="trigger">
                                        <x-filament::button color="gray" size="xs" icon="heroicon-m-document-duplicate">Recopier</x-filament::button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        <x-filament::dropdown.list.item wire:click="copyDay({{ $d }}, 'weekdays')">Sur lundi – vendredi</x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item wire:click="copyDay({{ $d }}, 'weekend')">Sur samedi – dimanche</x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item wire:click="copyDay({{ $d }}, 'all')">Sur toute la semaine</x-filament::dropdown.list.item>
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                                @endif
                                @if ($filled->isNotEmpty())
                                    <x-filament::icon-button icon="heroicon-o-trash" color="gray" label="Vider la journée" tooltip="Supprimer toutes les plages" wire:click="clearDay({{ $d }})" />
                                @endif
                            </div>
                        </div>
                        @foreach (array_unique($hints[$d] ?? []) as $hint)
                            <p class="mt-1.5 flex items-start gap-1.5 text-xs text-warning-700 dark:text-warning-400 md:ps-28">
                                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-px h-4 w-4 shrink-0" />{{ $hint }}
                            </p>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <p class="text-xs text-gray-500">Sur la chaudière actuellement : {{ $summary }}</p>
    @endif
</div>
