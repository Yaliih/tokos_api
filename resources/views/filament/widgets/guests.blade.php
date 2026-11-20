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

    {{-- Styles en ligne + variables du panneau : s'adapte au thème clair comme sombre --}}
    <style>
        .gsv-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
        .gsv-tab { border: 0; background: transparent; color: inherit; opacity: .55; font: inherit; font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 8px; cursor: pointer; }
        .gsv-tab:hover { background: rgba(128,128,128,.14); opacity: 1; }
        .gsv-tab.on { background: rgba(128,128,128,.16); opacity: 1; color: var(--primary-600); }
        .gsv-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        .gsv-card { border-radius: 12px; padding: 14px; border: 1px solid rgba(128,128,128,.22); background: rgba(128,128,128,.06); }
        .gsv-label { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; opacity: .6; }
        .gsv-value { font-size: 26px; font-weight: 700; line-height: 1.1; margin: 4px 0 2px; }
        .gsv-note { font-size: 12px; opacity: .6; }
    </style>

    <div class="gsv-tabs" role="group" aria-label="Période">
        @foreach ($periodes as $k => $label)
            <button type="button" class="gsv-tab {{ $periode === $k ? 'on' : '' }}" wire:click="$set('periode', '{{ $k }}')">{{ $label }}</button>
        @endforeach
    </div>

    <div class="gsv-grid">
        @foreach ($cartes as $c)
            <div class="gsv-card">
                <div class="gsv-label">{{ $c['label'] }}</div>
                <div class="gsv-value">{{ $c['valeur'] }}</div>
                <div class="gsv-note">{{ $c['note'] }}</div>
            </div>
        @endforeach
    </div>
</x-filament::section>
