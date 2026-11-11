@props([
    'key',
    'param' => null,
    'label' => null,
    'value' => null,
    'changed' => false,
    'current' => null,
    'factory' => null,
    'help' => null,
    'disabled' => false,
    'wide' => false,
    'below' => null,
])

{{--
    Ligne de réglage : libellé, contrôle (slot) et boutons « Appliquer » / « Usine » à gauche, explications à droite
    (ou dessous si la ligne est étroite).
    La page Livewire doit exposer applyOne(key), factoryOne(key) et resetOne(key) (trait AppliesSettings).
--}}
@php
    $catalog = app(\App\Okofen\Catalog::class);
    $label ??= $param ? $catalog->label($param) : $key;
    $isFactory = $factory !== null && $value !== null && $value !== '' && abs((float) $value - (float) $factory) < 1e-6;
    $show = fn ($v) => $v === null || $v === '' ? '—'
        : ($param['options'][(int) $v] ?? rtrim(rtrim(number_format((float) $v, 1, ',', ' '), '0'), ',').(isset($param['unit']) ? ' '.$param['unit'] : ''));
@endphp

{{-- Requête de conteneur : explications à droite dès que la ligne a la place (~670 px), dessous sinon --}}
<div {{ $attributes->class([
    '@container transition',
    'rounded-lg bg-primary-50/60 px-3 dark:bg-primary-950/20 -mx-3' => $changed,
]) }}>
<div class="grid gap-x-6 gap-y-2 py-3 @2xl:grid-cols-12">
    <div class="space-y-1.5 @2xl:col-span-5">
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-sm font-medium">{{ $label }}</span>
            @if ($changed)
                <span class="text-xs text-primary-600">actuel : {{ $show($current) }}</span>
            @endif
        </div>

        {{-- champ et boutons sur une même ligne --}}
        <div class="flex flex-wrap items-center gap-2">
            <div @class(['w-44 shrink-0' => ! $wide, 'w-full max-w-xs' => $wide])>{{ $slot }}</div>
            <x-filament::button size="xs" icon="heroicon-m-paper-airplane" :disabled="$disabled || ! $changed"
                wire:click="applyOne('{{ $key }}')"
                wire:confirm="Appliquer « {{ $label }} » : {{ $show($current) }} → {{ $show($value) }} ?">
                Appliquer
            </x-filament::button>
            @if ($changed)
                <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-uturn-left" wire:click="resetOne('{{ $key }}')">Annuler</x-filament::button>
            @endif
            <x-filament::button size="xs" color="gray" icon="heroicon-m-building-office" :disabled="$disabled || $factory === null || $isFactory"
                wire:click="factoryOne('{{ $key }}')"
                wire:confirm="Remettre « {{ $label }} » à sa valeur d'usine ({{ $show($factory) }}) et l'appliquer sur la chaudière ?"
                :tooltip="$factory === null ? 'Valeur d\'usine inconnue' : 'Remettre la valeur d\'usine ('.$show($factory).') et l\'appliquer'">
                Usine{{ $factory !== null ? ' ('.$show($factory).')' : '' }}
            </x-filament::button>
        </div>

        @if ($below)
            {{ $below }}
        @endif
    </div>

    <div class="text-sm text-gray-600 dark:text-gray-300 @2xl:col-span-7">
        @if ($text = $help ?? ($param['description'] ?? null))
            <p>{{ $text }}</p>
        @endif
        <x-param-advice :param="$param" :value="$value" class="mt-2" />
    </div>
</div>
</div>
