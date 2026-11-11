@props([
    'option' => [],
    'height' => '360px',
    'group' => null,
    'hover' => null,
    'id' => null,
])

@php
    use Filament\Support\Facades\FilamentAsset;
    use Illuminate\Support\Facades\Vite;
    use Illuminate\Support\Js;

    $renderer = Vite::asset('resources/js/filament/echart.js');
    $configuration = array_filter([
        'option' => $option,
        'height' => $height,
        'group' => $group,
        'hover' => $hover,
    ], fn ($v) => $v !== null);
    $key = $id ?? 'echart-'.md5(json_encode(array_keys($option)).$height);
@endphp

{{-- Graphique ECharts : même mécanique que les widgets JS de Filament (rendu par resources/js/filament/echart.js) --}}
<div
    wire:key="{{ $key }}.renderer"
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('js-widget', 'filament/widgets') }}"
    x-data="jsWidgetComponent({
                renderer: {{ (string) Js::from($renderer) }},
                rendererConfiguration: JSON.parse($el.querySelector('[data-renderer-configuration]').dataset.rendererConfiguration),
            })"
    {{ $attributes->class(['oko-echart w-full']) }}
>
    <span
        hidden
        wire:key="{{ $key }}.configuration.{{ md5(json_encode($configuration)) }}"
        data-renderer-configuration="{{ json_encode($configuration) }}"
        x-init="updateRendererConfiguration(JSON.parse($el.dataset.rendererConfiguration))"
    ></span>
    <div wire:ignore x-ref="host" style="min-height: {{ $height }}"></div>
    <p x-cloak x-show="hasError" role="alert" class="text-sm text-danger-600">Le graphique n'a pas pu être chargé.</p>
</div>
