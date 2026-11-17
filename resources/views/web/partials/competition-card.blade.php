@php
    $inline = $inline ?? false;
    $typeIcon = \App\Support\Icons::TYPE[$c['type']] ?? 'trophy';
    $formatIcon = ['video' => 'video', 'audio' => 'mic', 'image' => 'image', 'text' => 'type'][$c['entry_format'] ?? ''] ?? null;
    $state = ['completed' => 'Terminé', 'cancelled' => 'Annulé'][$c['status']] ?? null;
@endphp
<article class="comp {{ $inline ? 'inline' : '' }}">
    @php
        // Dans le fil, le texte déjà affiché (titre en haut, description sous le titre) n'est pas répété dans la vitrine
        $oe = $c['owner_entry'] ?? null;
        $oeText = trim((string) ($oe['content'] ?? ''));
        $oeSkipText = $inline && $oe && ($oeText === '' || ! empty($c['blurb']) || mb_strtolower($oeText) === mb_strtolower(trim($c['title'])));
        $oeShow = $oe && (! empty($oe['media']) || ! $oeSkipText);
    @endphp
    @if (!$c['cover_url'] && $oeShow)
        {{-- Défi : la performance du créateur sert de vitrine --}}
        @include('web.partials.showcase', ['e' => $oe, 'hideText' => $oeSkipText])
    @elseif ($c['cover_url'])
        @if (($c['cover_type'] ?? 'image') === 'video')
            <div class="comp-cover is-video">@include('web.partials.video', ['src' => $c['cover_url'], 'natural' => true])</div>
        @else
            <a href="{{ $c['url'] }}" class="comp-cover" tabindex="-1" aria-hidden="true"><img src="{{ $c['cover_url'] }}" alt="" loading="lazy"></a>
        @endif
    @endif
    @php $hasVisual = $c['cover_url'] || $oeShow || ($inline && $oe); @endphp
    <div class="comp-body {{ $inline && ($hasVisual || ($titleShown ?? false)) ? 'slim' : '' }}">
        {{-- Dans le fil, le titre est déjà en haut de la publication --}}
        {{-- Dans le fil, le titre est déjà dans la publication : pas de répétition ici --}}
        @unless ($inline && (($titleShown ?? false) || $hasVisual))
        <div class="comp-head">
            @unless ($c['cover_url'] || !empty($c['owner_entry']))<a class="tile" data-type="{{ $c['type'] }}" href="{{ $c['url'] }}" tabindex="-1" aria-hidden="true">@icon($typeIcon)</a>@endunless
            <div class="grow">
                <a href="{{ $c['url'] }}" class="comp-title">{{ $c['title'] }}</a>
                @unless ($inline)
                <div class="comp-org">
                    <span class="comp-kind" title="{{ $c['type_label'] }}{{ !empty($c['entry_format_label']) ? ' en '.mb_strtolower($c['entry_format_label']) : '' }}">@icon($typeIcon, 'ico-sm')@if($formatIcon)@icon($formatIcon, 'ico-sm')@endif</span>
                    <span class="trunc">{{ $c['type_label'] }} de <b>{{ $c['organizer']['name'] ?? '' }}</b></span>
                    @if ($state)<span class="chip">{{ $state }}</span>@endif
                </div>
                @endunless
            </div>
        </div>
        @endunless
        {{-- Chiffres essentiels, en icônes (lisibles sur petit écran) --}}
        @unless ($inline)
        <div class="comp-stats">
            <span title="Participants">@icon('users'){{ $c['entries_count'] }}{{ $c['max_entries'] ? '/'.$c['max_entries'] : '' }}</span>
            <span title="Votes">@icon('arrow-up'){{ $c['votes_count'] }}</span>
            @include('web.partials.when', ['t' => $c['time'] ?? null])
            @if (!empty($c['prize_delivered']))<a class="prize-done" href="{{ $c['url'] }}#remise" title="Voir la remise du prix">@icon('gift')Prix remis</a>
            @elseif ($c['prize'])<span title="{{ $c['prize'] }}">@icon('gift')</span>@endif
        </div>
        @endunless
        @php
            $myIds = $c['viewer']['my_entry_ids'] ?? [];
            $typeWord = ['challenge' => 'au défi', 'duel' => 'au duel', 'tournament' => 'au tournoi', 'contest' => 'au concours'][$c['type']] ?? '';
            $typeThis = ['challenge' => 'ce défi', 'duel' => 'ce duel', 'tournament' => 'ce tournoi', 'contest' => 'ce concours'][$c['type']] ?? '';
        @endphp
        @if ($inline)
        @elseif (($c['viewer']['can_manage'] ?? false) && in_array($c['type'], ['challenge', 'duel'], true))
            <p class="mini-note">@icon('vs', 'ico-sm')<span>Ton {{ mb_strtolower($c['type_label']) }}</span></p>
        @elseif (count($myIds))
            <p class="mini-note">@icon('vs', 'ico-sm')<span class="trunc">Tu participes {{ $typeWord }} de <b>{{ $c['organizer']['name'] ?? '' }}</b></span></p>
        @endif
        @if (!empty($c['winner']))
            <div class="comp-winner">@icon('trophy')<span class="trunc"><strong>{{ $c['winner']['display_name'] }}</strong> a gagné</span></div>
        @endif
        @php
            $room = empty($c['max_entries']) || $c['entries_count'] < $c['max_entries'];
            $canEnter = ($c['viewer']['can_enter'] ?? false) && ! ($c['viewer']['can_manage'] ?? false);
            $cta = $c['status'] !== 'open' || ! $canEnter ? null : match ($c['type']) {
                'challenge' => ['vs', 'Défier'],
                'contest', 'tournament' => $room ? [($c['application'] ?? false) ? 'user' : 'zap', ($c['application'] ?? false) ? 'Candidater' : ($c['type'] === 'tournament' ? 'S\'inscrire' : 'Participer')] : null,
                default => auth()->check() ? ['check', 'Accepter'] : null, // un duel s'adresse à une personne précise
            };
        @endphp
        @if (($withCta ?? true) && $cta)
        <div class="comp-foot">
            <a class="btn btn-sm" href="{{ auth()->guest() ? '/connexion?suite='.urlencode($c['url']) : $c['url'].(($c['viewer']['can_enter'] ?? false) ? '#participer' : '') }}">@icon($cta[0], 'ico-sm'){{ $cta[1] }}</a>
        </div>
        @endif
    </div>
    @if (($withMatch ?? false) && !empty($c['featured_match']) && $c['featured_match']['status'] === 'voting')
        <div style="padding:0 16px 16px">@include('web.partials.versus', ['m' => $c['featured_match'], 'compact' => true, 'frame' => 'inset', 'showTop' => false])</div>
    @endif
</article>
