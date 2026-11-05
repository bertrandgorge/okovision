@php
    $record = $getRecord();
    $attached = $record->channels->pluck('id')->all();
@endphp
<div class="px-3" style="display: flex; flex-wrap: nowrap; align-items: center; gap: 0.25rem;">
    @forelse ($allChannels as $channel)
        @php
            $on = in_array($channel->id, $attached, true);
            $tooltip = $channel->name.' ('.$channel->type->getLabel().') : '
                .($on ? 'notifié — cliquer pour retirer' : 'non notifié — cliquer pour ajouter')
                .($channel->enabled ? '' : ' · canal désactivé');
        @endphp
        <x-filament::icon-button
            :icon="$channel->type->getIcon()"
            :color="$on ? 'primary' : 'gray'"
            :tooltip="$tooltip"
            :label="$tooltip"
            wire:click="toggleChannel({{ $record->getKey() }}, {{ $channel->id }})"
            :style="$on ? '' : 'opacity: 0.35;'"
        />
    @empty
        <span class="text-sm text-gray-400">aucun canal</span>
    @endforelse
</div>
