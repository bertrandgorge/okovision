@props(['param' => null, 'value' => null])

{{-- Valeur conseillée d'un réglage (bloc « advice » du catalogue), avec son explication --}}
@php $a = $param ? app(\App\Okofen\Catalog::class)->advice($param, $value) : null; @endphp

@if ($a)
    <div {{ $attributes->class(['mt-1 text-xs']) }}>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @if ($a['text'] !== null)
                <span class="font-medium text-gray-700 dark:text-gray-200">Conseillé : {{ $a['text'] }}</span>
            @endif
            @if ($a['factory'] !== null)
                <span class="text-gray-500 dark:text-gray-400">{{ $a['text'] !== null ? '· usine' : 'Usine :' }} {{ $a['factory'] }}</span>
            @endif
            @if ($a['outside'])
                <x-filament::badge size="sm" color="warning" icon="heroicon-m-exclamation-triangle">hors conseil</x-filament::badge>
            @endif
        </div>
        @if ($a['why'])
            <p class="mt-0.5 text-gray-500 dark:text-gray-400">{{ $a['why'] }}</p>
        @endif
    </div>
@endif
