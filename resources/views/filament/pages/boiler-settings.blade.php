<x-filament-panels::page>
    @if ($error)
        <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-200 dark:ring-danger-800">
            Lecture impossible : {{ $error }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::tabs>
            <x-filament::tabs.item :active="$level === 'basic'" wire:click="$set('level', 'basic')" icon="heroicon-o-star">Essentiels</x-filament::tabs.item>
            <x-filament::tabs.item :active="$level === 'expert'" wire:click="$set('level', 'expert')" icon="heroicon-o-wrench-screwdriver">Avancés (technicien)</x-filament::tabs.item>
        </x-filament::tabs>
        <x-filament::input.wrapper class="w-56">
            <x-filament::input.select wire:model.live="group">
                <option value="all">Toutes les catégories</option>
                @foreach ($groups as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <x-filament::input.wrapper class="w-64" prefix-icon="heroicon-o-magnifying-glass">
            <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher un réglage…" />
        </x-filament::input.wrapper>
        @if ($changes)
            <x-filament::badge color="warning">{{ count($changes) }} modification(s) non appliquée(s)</x-filament::badge>
            <x-filament::link tag="button" wire:click="resetEdit" size="sm">Annuler</x-filament::link>
        @endif
        <div wire:loading><x-filament::loading-indicator class="h-5 w-5" /></div>
    </div>

    @if ($level === 'expert')
        <div class="rounded-xl bg-warning-50 p-3 text-sm text-warning-800 ring-1 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-200 dark:ring-warning-800">
            Réglages de combustion et de sécurité normalement réservés au technicien Okofen. Une valeur inadaptée peut dégrader la combustion ou mettre la chaudière en défaut.
            Sauvegardez un profil avant toute modification.
        </div>
    @endif

    @forelse ($params as $group => $items)
        <x-filament::section :heading="$catalog->groups()[$group] ?? $group" collapsible>
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($items as $p)
                    @php
                        $k = \App\Filament\Pages\BoilerSettings::key($p['name']);
                        $m = $meta[$k] ?? [];
                        $changed = array_key_exists($p['name'], $changes);
                        $options = $m['options'] ?? ($p['options'] ?? []);
                        $unit = $m['unit'] ?? ($p['unit'] ?? null);
                        $min = $m['min'] ?? ($p['min'] ?? null);
                        $max = $m['max'] ?? ($p['max'] ?? null);
                        $step = isset($p['divisor']) && $p['divisor'] >= 10 ? 1 / $p['divisor'] : 1;
                        $writable = $p['writable'] ?? false;
                    @endphp
                    <div @class(['grid gap-2 py-3 md:grid-cols-12 md:items-center', 'bg-primary-50/50 dark:bg-primary-950/20 -mx-2 px-2 rounded-lg' => $changed])>
                        <div class="md:col-span-5">
                            <div class="text-sm font-medium">{{ $catalog->label($p) }}</div>
                            <div class="text-xs text-gray-500">
                                @if (isset($p['short_fr']) && $p['short_fr'] !== $catalog->label($p)) Écran chaudière : « {{ $p['short_fr'] }} » · @endif
                                <code class="text-[11px]">{{ str_replace('CAPPL:', '', $p['name']) }}</code>
                            </div>
                        </div>
                        <div class="md:col-span-3">
                            @if (! ($m['ok'] ?? true))
                                <span class="text-sm text-gray-400">illisible</span>
                            @elseif ($options)
                                <x-filament::input.wrapper :disabled="! $writable">
                                    <x-filament::input.select wire:model.live="edit.{{ $k }}" :disabled="! $writable">
                                        @foreach ($options as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            @else
                                <x-filament::input.wrapper :suffix="$unit" :disabled="! $writable">
                                    <x-filament::input type="number" wire:model.live.debounce.500ms="edit.{{ $k }}" :step="$step" :min="$min" :max="$max" :disabled="! $writable" />
                                </x-filament::input.wrapper>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 md:col-span-4">
                            @if ($changed)
                                <span class="font-medium text-primary-600">actuel : {{ isset($options[$values[$k]]) ? $options[$values[$k]] : $values[$k].' '.$unit }}</span><br>
                            @endif
                            @if ($min !== null && $max !== null && ! $options) plage {{ $min }} – {{ $max }} {{ $unit }}<br> @endif
                            {{ $p['description'] ?? '' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <x-filament::section><p class="text-sm text-gray-500">Aucun réglage ne correspond.</p></x-filament::section>
    @endforelse

    <x-filament::section heading="Journal des modifications" icon="heroicon-o-clipboard-document-list" collapsible collapsed>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr><th class="py-2">Date</th><th>Réglage</th><th>Avant</th><th>Après</th><th>Origine</th><th>Statut</th><th>Par</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($history as $h)
                    @php $p = $catalog->parameter($h->name); @endphp
                    <tr>
                        <td class="py-1.5">{{ $h->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $p ? $catalog->label($p) : (\App\Okofen\TimeProgram::labelFor($h->name) ?? $h->name) }}</td>
                        <td>{{ $h->old_value }}</td>
                        <td class="font-medium">{{ $h->new_value }}</td>
                        <td>{{ ['manual' => 'manuel', 'curve' => 'loi d\'eau', 'optimizer' => 'pilotage', 'advisor' => 'recommandation', 'profile' => 'profil'][$h->source] ?? $h->source }}</td>
                        <td>
                            <x-filament::badge size="sm" :color="['applied' => 'success', 'failed' => 'danger', 'dry_run' => 'gray'][$h->status] ?? 'gray'">
                                {{ ['applied' => 'appliqué', 'failed' => 'échec', 'dry_run' => 'simulation'][$h->status] ?? $h->status }}
                            </x-filament::badge>
                            @if ($h->message)<span class="text-xs text-gray-500">{{ $h->message }}</span>@endif
                        </td>
                        <td>{{ $h->user?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-3 text-gray-500">Aucune modification enregistrée.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
