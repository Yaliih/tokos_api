<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Visiteurs sans compte</x-slot>
        <x-slot name="description">Les personnes qui viennent sans s'inscrire : combien, et quelle part des vues.</x-slot>

        @php
            $n = fn (int $v) => number_format($v, 0, ',', ' ');
            $s = fn (int $v) => $v > 1 ? 's' : '';
            $vide = $vues === 0;
            $rien = 'aucune vue sur la période';

            $cartes = [
                [
                    'label' => 'Visiteurs',
                    'valeur' => $vide ? '—' : $n($personnesVisiteurs),
                    'sous' => $vide ? '' : 'personne'.$s($personnesVisiteurs).' différente'.$s($personnesVisiteurs),
                    'note' => $vide ? $rien : $n($vuesVisiteurs).' vue'.$s($vuesVisiteurs).' sans compte',
                ],
                [
                    'label' => 'Part des vues',
                    'valeur' => $vide ? '—' : $part.' %',
                    'sous' => $vide ? '' : 'des vues viennent de visiteurs',
                    'note' => $vide ? $rien : $n($vuesVisiteurs).' vue'.$s($vuesVisiteurs).' sur '.$n($vues),
                    'part' => $vide ? 0 : $part,
                ],
                [
                    'label' => 'Membres connectés',
                    'valeur' => $vide ? '—' : $n($personnesMembres),
                    'sous' => $vide ? '' : 'personne'.$s($personnesMembres).' avec un compte',
                    'note' => $vide ? $rien : $n($vuesMembres).' vue'.$s($vuesMembres),
                ],
                [
                    'label' => 'Total des vues',
                    'valeur' => $n($vues),
                    'sous' => 'vues enregistrées',
                    'note' => $plage,
                ],
            ];
        @endphp

        {{-- Styles en ligne : le bloc ne dépend pas de Tailwind et suit le thème clair comme sombre --}}
        <style>
            .gsv-block { --gsv-line: rgba(128,128,128,.26); --gsv-fill: rgba(128,128,128,.06); }

            .gsv-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
            .gsv-tab { border: 0; background: transparent; color: inherit; opacity: .55; font: inherit; font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 8px; cursor: pointer; }
            .gsv-tab:hover { background: rgba(128,128,128,.14); opacity: 1; }
            .gsv-tab.on { background: rgba(128,128,128,.16); opacity: 1; color: var(--primary-600); }

            /* Les 4 cartes tiennent sur une ligne ; 2 puis 1 quand l'écran rétrécit */
            .gsv-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; align-items: stretch; transition: opacity .15s ease; }
            .gsv-grid.gsv-busy { opacity: .55; }

            .gsv-card { display: flex; flex-direction: column; min-width: 0; border-radius: 12px; padding: 14px 16px; border: 1px solid var(--gsv-line); background: var(--gsv-fill); }
            .gsv-label { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; opacity: .6; line-height: 1.25; }
            .gsv-value { font-size: clamp(21px, 1.4vw + 14px, 28px); font-weight: 700; line-height: 1.15; margin: 4px 0 2px; font-variant-numeric: tabular-nums; white-space: nowrap; }
            .gsv-sous { font-size: 12.5px; font-weight: 600; opacity: .78; line-height: 1.3; }
            .gsv-note { margin-top: auto; padding-top: 8px; font-size: 12px; opacity: .58; line-height: 1.35; }
            .gsv-bar { margin-top: 8px; height: 5px; border-radius: 99px; background: rgba(128,128,128,.2); overflow: hidden; }
            .gsv-bar > span { display: block; height: 100%; border-radius: 99px; background: var(--primary-600); }

            .gsv-foot { margin: 14px 0 0; font-size: 11.5px; line-height: 1.45; opacity: .55; }

            @media (max-width: 1100px) {
                .gsv-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 520px) {
                .gsv-grid { grid-template-columns: 1fr; gap: 10px; }
                .gsv-card { padding: 12px 14px; }
                .gsv-value { font-size: 24px; }
                .gsv-tabs { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 2px; }
                .gsv-tab { flex: 0 0 auto; }
            }
        </style>

        <div class="gsv-block">
            <div class="gsv-tabs" role="group" aria-label="Période">
                @foreach ($periodes as $cle => $libelle)
                    <button
                        type="button"
                        wire:click="choisirPeriode('{{ $cle }}')"
                        class="gsv-tab {{ $periode === $cle ? 'on' : '' }}"
                        aria-pressed="{{ $periode === $cle ? 'true' : 'false' }}"
                    >{{ $libelle }}</button>
                @endforeach
            </div>

            <div class="gsv-grid" wire:loading.class="gsv-busy" aria-live="polite">
                @foreach ($cartes as $carte)
                    <div class="gsv-card">
                        <div class="gsv-label">{{ $carte['label'] }}</div>
                        <div class="gsv-value">{{ $carte['valeur'] }}</div>
                        @if ($carte['sous'] !== '')
                            <div class="gsv-sous">{{ $carte['sous'] }}</div>
                        @endif
                        <div class="gsv-note">{{ $carte['note'] }}</div>

                        @isset($carte['part'])
                            <div class="gsv-bar" role="img" aria-label="{{ $carte['part'] }} % des vues viennent de visiteurs sans compte">
                                <span style="width: {{ $carte['part'] }}%"></span>
                            </div>
                        @endisset
                    </div>
                @endforeach
            </div>

            <p class="gsv-foot">
                Une vue = une personne sur un contenu (compétition ou duel) ; une même personne ne compte qu'une fois par contenu.
                Les chiffres viennent de la table <code>content_views</code> et ne bougent pas d'un affichage à l'autre.
            </p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
