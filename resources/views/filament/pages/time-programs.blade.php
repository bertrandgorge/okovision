<x-filament-panels::page>
    @php $fmtDate = fn (?string $t) => $t ? \Carbon\Carbon::parse($t)->translatedFormat('D d M à H:i') : '—'; @endphp

    {{-- Programmes soirée et vacances (circuit de chauffage) --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Programme soirée" icon="heroicon-o-moon"
            description="Force le chauffage en confort jusqu'à l'heure choisie, puis la chaudière revient seule à son programme habituel.">
            @if ($specials === null)
                <p class="text-sm text-gray-500">Chaudière injoignable.</p>
            @else
                <div class="space-y-3">
                    <div class="flex items-center gap-2 text-sm">
                        @if ($specials['party']['active'])
                            <x-filament::badge color="success">En cours</x-filament::badge>
                            <span>jusqu'au {{ $fmtDate($specials['party']['end']) }}</span>
                        @else
                            <x-filament::badge color="gray">Inactif</x-filament::badge>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">Confort jusqu'au</span>
                            <x-filament::input.wrapper class="w-56">
                                <x-filament::input type="datetime-local" wire:model="partyEnd" />
                            </x-filament::input.wrapper>
                        </label>
                        <x-filament::button wire:click="startParty" wire:confirm="Activer le programme soirée ?" icon="heroicon-o-play">
                            {{ $specials['party']['active'] ? 'Modifier la fin' : 'Activer' }}
                        </x-filament::button>
                        @if ($specials['party']['active'])
                            <x-filament::button color="gray" wire:click="stopParty" wire:confirm="Arrêter le programme soirée ?" icon="heroicon-o-stop">Arrêter</x-filament::button>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        La soirée ne fait que forcer le confort : elle ne contourne ni la limite de chauffe (T extérieure trop douce),
                        ni l'hystérésis d'ambiance (pièce déjà à la consigne). Raccourci : bouton « Soirée ce soir » du tableau de bord, jusqu'à 23:59.
                    </p>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Programme vacances" icon="heroicon-o-paper-airplane"
            description="Entre deux dates, remplace le programme de chauffage par une température d'ambiance plus économique.">
            @if ($specials === null)
                <p class="text-sm text-gray-500">Chaudière injoignable.</p>
            @else
                @php $vac = $specials['vacation']; @endphp
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($vac['active'])
                            <x-filament::badge color="success">En cours</x-filament::badge>
                            <span>jusqu'au {{ $fmtDate($vac['end']) }}, à {{ number_format($vac['temp'] ?? 0, 1, ',', ' ') }} °C</span>
                        @elseif ($vac['on'] && $vac['end'] && \Carbon\Carbon::parse($vac['end'])->isFuture())
                            <x-filament::badge color="info">Programmé</x-filament::badge>
                            <span>du {{ $fmtDate($vac['start']) }} au {{ $fmtDate($vac['end']) }}, à {{ number_format($vac['temp'] ?? 0, 1, ',', ' ') }} °C</span>
                        @else
                            <x-filament::badge color="gray">Inactif</x-filament::badge>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">Départ</span>
                            <x-filament::input.wrapper class="w-56">
                                <x-filament::input type="datetime-local" wire:model="vacationStart" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">Fin du programme</span>
                            <x-filament::input.wrapper class="w-56">
                                <x-filament::input type="datetime-local" wire:model="vacationEnd" />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">Température</span>
                            <x-filament::input.wrapper class="w-28" suffix="°C">
                                <x-filament::input type="number" step="0.5" min="5" max="25" wire:model.live.debounce.500ms="vacationTemp" />
                            </x-filament::input.wrapper>
                        </label>
                    </div>
                    <x-param-advice :param="$vacationParam" :value="$vacationTemp" />
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button wire:click="startVacation" wire:confirm="Programmer les vacances sur la chaudière ?" icon="heroicon-o-play">
                            {{ $vac['on'] ? 'Modifier' : 'Programmer' }}
                        </x-filament::button>
                        @if ($vac['on'])
                            <x-filament::button color="gray" wire:click="stopVacation" wire:confirm="Arrêter le programme vacances ?" icon="heroicon-o-stop">Arrêter</x-filament::button>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Pour retrouver une maison chaude, faites finir le programme environ 24 h avant votre retour (conseil ÖkoFEN) :
                        une maison refroidie met longtemps à remonter. L'eau chaude n'est pas concernée.
                    </p>
                </div>
            @endif
        </x-filament::section>
    </div>

    {{-- Programme horaire du chauffage (l'eau chaude et le bouclage sont sur la page « Eau chaude ») --}}
    <livewire:time-program-editor circuit="hk" />
</x-filament-panels::page>
