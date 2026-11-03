@props(['label', 'value' => null, 'unit' => null, 'hint' => null, 'icon' => null, 'color' => 'gray'])

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

<div {{ $attributes->class(['rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10']) }}>
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
</div>
