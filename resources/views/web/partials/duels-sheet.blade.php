{{-- Fenêtre d'un défi depuis le fil : 1. ses duels  2. le relever --}}
@php
    $v = $c['viewer'];
    $canEnter = ($v['can_enter'] ?? false) && ! ($v['can_manage'] ?? false);
    $mine = $v['can_manage'] ?? false;
    $inIt = count($v['my_entry_ids'] ?? []) > 0;
    $isOpen = $c['status'] === 'open';
    // Fini (ou annulé, ou temps écoulé) : on le dit tout de suite, quel que soit le type
    $ended = in_array($c['status'], ['completed', 'cancelled'], true) || (! empty($c['ends_at']) && \Illuminate\Support\Carbon::parse($c['ends_at'])->isPast());
    $kindWord = mb_strtolower($c['type_label'] ?? 'défi');
    $fmt = ['video' => 'une vidéo', 'audio' => 'un audio', 'image' => 'une photo', 'text' => 'un texte'][$c['entry_format'] ?? ''] ?? null;
    $first = explode(' ', $c['organizer']['name'])[0];
@endphp
<nav class="seg ds-seg" role="tablist" aria-label="Défi">
    <button type="button" role="tab" aria-pressed="true" data-ds-tab="duels">Duels{{ count($matches) ? ' ('.count($matches).')' : '' }}</button>
    <button type="button" role="tab" aria-pressed="false" data-ds-tab="join">{{ $c['type'] === 'challenge' ? 'Relever le défi' : 'Participer' }}@if($ended) · fini @endif</button>
</nav>

<div class="ds-panel" data-ds-panel="duels">
    @forelse ($matches as $m)
        @php
            $a = $m['entry_a']; $b = $m['entry_b'];
            $pa = $m['score_a']; $pb = $m['score_b'];
            $won = fn ($e) => $e && (int) $m['winner_entry_id'] === (int) $e['id'];
        @endphp
        <a class="ds-row {{ $m['status'] === 'voting' ? 'live' : '' }}" href="{{ $m['url'] }}">
            <span class="ds-side">
                <img class="avatar sm" src="{{ $a['avatar_url'] ?? $a['participant']['avatar_url'] ?? '' }}" alt="Photo de profil">
                <span class="ds-name {{ $won($a) ? 'won' : '' }}">{{ $a['display_name'] ?? '?' }}</span>
            </span>
            <span class="ds-mid">
                @if ($m['status'] === 'voting')
                    <span class="ds-live">En cours</span>
                @elseif ($m['is_draw'])
                    <span class="ds-end">Égalité</span>
                @else
                    <span class="ds-end">Fini</span>
                @endif
                @if ($pa !== null && $m['total_votes'] > 0)<span class="ds-score">{{ round($pa) }} – {{ round($pb) }} %</span>@else<span class="ds-score">{{ $m['total_votes'] }} vote{{ $m['total_votes'] > 1 ? 's' : '' }}</span>@endif
            </span>
            <span class="ds-side right">
                <span class="ds-name {{ $won($b) ? 'won' : '' }}">{{ $b['display_name'] ?? 'En attente' }}</span>
                @if ($b)<img class="avatar sm" src="{{ $b['avatar_url'] ?? $b['participant']['avatar_url'] ?? '' }}" alt="Photo de profil">@endif
            </span>
        </a>
    @empty
        <p class="ds-empty">Pas encore de duel. Le premier qui relève le défi lance le premier duel.</p>
    @endforelse
    @if (count($matches))<a class="link-btn ds-all" href="{{ $c['url'] }}?tab=duels">Voir la page du défi @icon('chevron-right', 'ico-sm')</a>@endif
</div>

<div class="ds-panel" data-ds-panel="join" hidden>
    <div class="ds-join">
        @include('web.partials.avatar', ['p' => $c['organizer'], 'size' => 'md'])
        <p class="ds-join-t">
            @if ($c['type'] === 'challenge')
                Réponds à <b>{{ $c['organizer']['name'] }}</b>{{ $fmt ? ' avec '.$fmt : '' }}. Ta réponse devient un duel face à {{ $first }}, et le public vote.
            @else
                Rejoins « {{ $c['title'] }} ».
            @endif
        </p>
        @if ($ended)
            <p class="ds-ended">@icon('flag', 'ico-sm'){{ $c['status'] === 'cancelled' ? 'Ce '.$kindWord.' a été annulé.' : 'Ce '.$kindWord.' est déjà terminé.' }}</p>
            <p class="ds-note">On ne peut plus le relever. Regarde les duels, ou lance le tien.</p>
            <a class="btn btn-lg btn-block" href="/creer?type={{ $c['type'] === 'challenge' ? 'challenge' : $c['type'] }}">@icon('challenge', 'ico-sm')Lancer mon défi</a>
        @elseif ($mine)
            <p class="ds-note">@icon('info', 'ico-sm')C'est ta publication : partage-la pour qu'on te défie.</p>
            <button type="button" class="btn btn-lg btn-block btn-ghost" data-share="{{ $c['url'] }}" data-title="{{ $c['title'] }}">@icon('share', 'ico-sm')Partager</button>
        @elseif ($inIt)
            <p class="ds-note">@icon('check', 'ico-sm')Tu as déjà relevé ce défi.</p>
            <a class="btn btn-lg btn-block btn-ghost" href="{{ $c['url'] }}?tab=duels">Voir mon duel</a>
        @elseif (! $isOpen)
            <p class="ds-note">@icon('clock', 'ico-sm')Ce défi n'accepte plus de réponses.</p>
        @elseif (auth()->guest())
            <a class="btn btn-lg btn-block" href="/connexion?suite={{ urlencode($c['url'].'#participer') }}">@icon('vs', 'ico-sm')Relever le défi</a>
        @elseif ($canEnter)
            <a class="btn btn-lg btn-block" href="{{ $c['url'] }}#participer">@icon('vs', 'ico-sm')Relever le défi</a>
        @else
            <p class="ds-note">@icon('info', 'ico-sm'){{ $v['enter_reason'] ?? 'Tu ne peux pas relever ce défi pour le moment.' }}</p>
            <a class="btn btn-lg btn-block btn-ghost" href="{{ $c['url'] }}">Voir le défi</a>
        @endif
    </div>
</div>
