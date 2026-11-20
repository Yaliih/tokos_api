@php
    $periodes = ['24h' => '24 h', '7j' => '7 jours', '30j' => '30 jours', 'tout' => 'Tout'];
    $cartes = [
        ['label' => 'Visiteurs', 'valeur' => number_format($guests, 0, ',', ' '), 'note' => number_format($guestPeople, 0, ',', ' ').' personne'.($guestPeople > 1 ? 's' : '').' différente'.($guestPeople > 1 ? 's' : '')],
        ['label' => 'Part des vues', 'valeur' => $part.' %', 'note' => 'visiteurs / membres'],
        ['label' => 'Membres connectés', 'valeur' => number_format($members, 0, ',', ' '), 'note' => 'vues avec un compte'],
        ['label' => 'Total des vues', 'valeur' => number_format($total, 0, ',', ' '), 'note' => $periodes[$periode]],
    ];
@endphp

<x-filament::section>
    <x-slot name="heading">Visiteurs sans compte</x-slot>
    <x-slot name="description">Les personnes qui viennent sans s'inscrire : combien, et quelle part des vues.</x-slot>

    <div class="mb-4 flex flex-wrap items-center gap-1" role="group" aria-label="Période">
        @foreach ($periodes as $k => $label)
            <button type="button" wire:click="$set('periode', '{{ $k }}')"
                @class([
                    'rounded-lg px-2.5 py-1 text-xs font-semibold transition',
                    'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400' => $periode === $k,
                    'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5' => $periode !== $k,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($cartes as $c)
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $c['label'] }}</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $c['valeur'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $c['note'] }}</div>
            </div>
        @endforeach
    </div>
</x-filament::section>
