<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::tabs>
            @foreach ($circuits as $key => $label)
                <x-filament::tabs.item :active="$circuit === $key" wire:click="$set('circuit', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
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
        @if ($changed)
            <x-filament::badge color="warning">Modifications non appliquées</x-filament::badge>
            <x-filament::link tag="button" wire:click="resetEdit" size="sm">Annuler</x-filament::link>
        @endif
        <div wire:loading><x-filament::loading-indicator class="h-5 w-5" /></div>
    </div>

    @if ($error)
        <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-200 dark:ring-danger-800">
            Lecture impossible : {{ $error }}
        </div>
    @else
        <div class="text-sm text-gray-600 dark:text-gray-400">
            @if ($active !== null && $active !== $program)
                Ce programme n'est <strong>pas utilisé</strong> actuellement (la chaudière suit le programme {{ $active + 1 }}).
            @endif
            @if ($circuit === 'hk')
                Pendant les plages, le circuit vise la consigne <em>confort</em> ; en dehors, la consigne <em>réduit</em>.
            @elseif ($circuit === 'ww')
                Pendant les plages, le ballon est maintenu à la consigne d'eau chaude ; en dehors, seulement au-dessus de la T min ECS.
            @else
                Pendant les plages, la pompe de circulation d'eau chaude peut fonctionner.
            @endif
            Les jours aux plages identiques sont regroupés automatiquement, comme sur l'écran de la chaudière.
        </div>

        {{-- Vue de la semaine --}}
        <x-filament::section>
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
        <x-filament::section heading="Plages horaires" description="Jusqu'à 3 plages par jour. Laissez une plage vide pour la supprimer.">
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($days as $d => $label)
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                        <div class="w-24 text-sm font-medium">{{ $label }}</div>
                        @for ($i = 0; $i < \App\Okofen\TimeProgram::SLOTS; $i++)
                            <div class="flex items-center gap-1.5">
                                <x-filament::input.wrapper class="w-28">
                                    <x-filament::input type="time" wire:model.live.debounce.400ms="edit.{{ $d }}.{{ $i }}.start" />
                                </x-filament::input.wrapper>
                                <span class="text-gray-400">–</span>
                                <x-filament::input.wrapper class="w-28">
                                    <x-filament::input type="time" wire:model.live.debounce.400ms="edit.{{ $d }}.{{ $i }}.end" />
                                </x-filament::input.wrapper>
                            </div>
                        @endfor
                        <div class="ml-auto flex items-center gap-1">
                            <x-filament::dropdown placement="bottom-end">
                                <x-slot name="trigger">
                                    <x-filament::icon-button icon="heroicon-o-document-duplicate" color="gray" label="Recopier" tooltip="Recopier ce jour" />
                                </x-slot>
                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item wire:click="copyDay({{ $d }}, 'weekdays')">Sur lundi – vendredi</x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item wire:click="copyDay({{ $d }}, 'weekend')">Sur samedi – dimanche</x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item wire:click="copyDay({{ $d }}, 'all')">Sur toute la semaine</x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                            <x-filament::icon-button icon="heroicon-o-trash" color="gray" label="Vider" tooltip="Aucune plage ce jour" wire:click="clearDay({{ $d }})" />
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <p class="text-xs text-gray-500">Sur la chaudière actuellement : {{ $summary }}</p>
    @endif
</x-filament-panels::page>
