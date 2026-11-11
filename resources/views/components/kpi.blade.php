@props(['label', 'value' => null, 'unit' => null, 'hint' => null, 'icon' => null, 'color' => 'gray', 'href' => null])

@php
    $colors = [
        'gray' => 'text-gray-500 dark:text-gray-400',
        'primary' => 'text-primary-600 dark:text-primary-400',
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'info' => 'text-info-600 dark:text-info-400',
    ];
@endphp

{{-- Avec href, la tuile entière devient un lien vers la page détaillée --}}
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class([
    'block rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
    'transition hover:bg-gray-50 hover:ring-primary-500/50 focus-visible:outline-2 focus-visible:outline-primary-500 dark:hover:bg-white/5' => $href,
]) }}>
    <div class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400">
        @if ($icon)
            <x-filament::icon :icon="$icon" @class(['h-5 w-5', $colors[$color] ?? $colors['gray']]) />
        @endif
        <span>{{ $label }}</span>
    </div>
    <div class="oko-kpi-value mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
        {{ $value ?? '—' }}@if ($unit !== null && $value !== null)<span class="ms-1 text-base font-normal text-gray-500">{{ $unit }}</span>@endif
    </div>
    @if ($hint)
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</div>
    @endif
    {{ $slot }}
</{{ $href ? 'a' : 'div' }}>
