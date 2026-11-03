<x-filament-panels::page>
    @php $fmt = fn ($x, $d = 0) => $x === null ? '—' : number_format((float) $x, $d, ',', ' '); @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-kpi label="Mesures minute en base" :value="$fmt($range->n)" :hint="$range->a ? 'du '.\Carbon\Carbon::parse($range->a)->format('d/m/Y').' au '.\Carbon\Carbon::parse($range->b)->format('d/m/Y') : null" />
        <x-kpi label="Synthèses journalières" :value="$fmt($stats->n)" :hint="$stats->a ? 'depuis le '.\Carbon\Carbon::parse($stats->a)->format('d/m/Y') : null" />
        <x-kpi label="Mois archivés" :value="$archives->count()" :hint="$fmt($archives->sum('bytes') / 1048576, 1).' Mo de fichiers'" />
        <x-kpi label="Conservation en base" :value="$keep" unit="mois" :hint="count($eligible).' mois à archiver'" />
    </div>

    <x-filament::section heading="Stratégie d'archivage" icon="heroicon-o-information-circle">
        <div class="prose prose-sm max-w-none dark:prose-invert">
            <ul>
                <li>Les <strong>mesures minute</strong> (~1 440 lignes par jour) représentent l'essentiel du volume. Au-delà de {{ $keep }} mois, elles sont exportées chaque mois dans un fichier CSV compressé (≈ 400 Ko/mois) dans <code>storage/app/archives</code>, contrôlées, puis supprimées de la base.</li>
                <li>Les <strong>synthèses journalières</strong> et les <strong>cycles brûleur</strong> restent en base indéfiniment (quelques Mo pour des décennies) : historique, signature énergétique et comparaisons de saisons continuent de fonctionner.</li>
                <li>La page <em>Journée</em> relit automatiquement le fichier archivé si la journée n'est plus en base. Un mois peut aussi être restauré en base (bouton ci-dessous) ou téléchargé.</li>
                <li>Pensez à inclure le dossier <code>storage/app/archives</code> dans vos sauvegardes.</li>
            </ul>
        </div>
    </x-filament::section>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section heading="Archives">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500"><tr><th class="py-2">Mois</th><th class="text-end">Lignes</th><th class="text-end">Taille</th><th>État</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($archives as $a)
                        <tr>
                            <td class="py-1.5 font-medium">{{ $a->month }}</td>
                            <td class="text-end">{{ $fmt($a->rows) }}</td>
                            <td class="text-end">{{ $fmt($a->bytes / 1024) }} Ko</td>
                            <td>{{ $a->purged ? 'hors base' : 'aussi en base' }}</td>
                            <td class="space-x-2 text-end">
                                <x-filament::link tag="button" wire:click="download('{{ $a->month }}')" size="sm">Télécharger</x-filament::link>
                                @if ($a->purged)<x-filament::link tag="button" wire:click="restore('{{ $a->month }}')" wire:confirm="Réinjecter ce mois dans la base ?" size="sm">Restaurer</x-filament::link>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-gray-500">Aucune archive pour l'instant.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Tables les plus volumineuses">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500"><tr><th class="py-2">Table</th><th class="text-end">Lignes (estimation)</th><th class="text-end">Taille</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($tables as $t)
                        <tr><td class="py-1.5">{{ $t->name }}</td><td class="text-end">{{ $fmt($t->rows_estimate) }}</td><td class="text-end">{{ $fmt($t->bytes / 1048576, 1) }} Mo</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
