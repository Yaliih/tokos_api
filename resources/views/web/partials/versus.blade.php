{{-- Tournoi : duel pas encore commencé (chacun envoie sa prestation) → petite carte d'attente --}}
@if (! empty($m['preparing']))
    <a class="rp-mini" href="{{ $m['url'] }}#prestation">
        @foreach (['a', 'b'] as $s)
            @php $e = $m['entry_'.$s]; @endphp
            <span class="rp-mini-side {{ $m['preparing'][$s.'_ready'] ? 'ok' : '' }}">@include('web.partials.avatar', ['p' => $e['participant'] ?? null])<b class="trunc">{{ $e['display_name'] ?? '?' }}</b>@icon($m['preparing'][$s.'_ready'] ? 'check' : 'clock', 'ico-sm')</span>
            @if ($s === 'a')<span class="rp-vs">VS</span>@endif
        @endforeach
        <small>{{ ($m['preparing']['my_side'] && ! $m['preparing'][$m['preparing']['my_side'].'_ready']) ? 'Envoie ta prestation' : 'Prestations en cours' }} · <span class="countdown" data-ends="{{ $m['preparing']['submit_by'] }}"></span></small>
    </a>
    @php return; @endphp
@endif
@php
    /** @var array $m  duel présenté par App\Support\Presenter::match */
    $compact = $compact ?? false;
    $frame = $frame ?? 'boxed'; // boxed | inset | none
    $showTop = $showTop ?? true;
    $social = $social ?? false;   // dans le fil : j'aime, commentaires et partage dans la même barre
    $showTime = $showTime ?? true; // le fil affiche déjà le temps restant en haut de la publication
    $defy = $defy ?? true;
    $note = $note ?? false; // « Tu participes… » : affiché en dehors du duel, par la page qui l'inclut
    $order = $m['display_order'] ?? ['a', 'b'];
    $completed = $m['status'] === 'completed';
    $hide = $m['score_a'] === null;
    $mine = $m['viewer']['my_vote_entry_id'] ?? null;
    $canVote = $m['viewer']['can_vote'] ?? false;
    $guest = ($m['viewer']['reason'] ?? null) === 'Connecte-toi pour voter';
    $comp = $m['competition'];
    $involvement = $m['viewer']['involvement'] ?? null;
    $typeWord = ['challenge' => 'au défi', 'duel' => 'au duel', 'tournament' => 'au tournoi', 'contest' => 'au concours'][$comp['type']] ?? 'à la compétition';
    $typeThis = ['challenge' => 'ce défi', 'duel' => 'ce duel', 'tournament' => 'ce tournoi', 'contest' => 'ce concours'][$comp['type']] ?? 'cette compétition';
    // Photo, vidéo, audio : face à face (gauche contre droite), même sur téléphone. Texte : l'un sous l'autre.
    $isVisual = fn ($e) => $e && collect($e['media'] ?? [])->contains(fn ($x) => in_array($x['type'], ['image', 'video']));
    $isAudio = fn ($e) => $e && ! $isVisual($e) && collect($e['media'] ?? [])->contains(fn ($x) => $x['type'] === 'audio');
    $row = ($isVisual($m['entry_a'] ?? null) && $isVisual($m['entry_b'] ?? null)) || ($isAudio($m['entry_a'] ?? null) && $isAudio($m['entry_b'] ?? null));
    $pctOf = fn ($s) => $hide ? null : (float) ($s === 'a' ? $m['score_a'] : $m['score_b']);
    // Champs cachés pour que le duel revienne à l'identique après un vote
    $keep = function () use ($compact, $frame, $showTop, $social, $showTime, $defy, $note) {
        return csrf_field()
            .($compact ? '<input type="hidden" name="compact" value="1">' : '')
            .'<input type="hidden" name="frame" value="'.e($frame).'">'
            .'<input type="hidden" name="top" value="'.($showTop ? 1 : 0).'">'
            .'<input type="hidden" name="social" value="'.($social ? 1 : 0).'">'
            .'<input type="hidden" name="time" value="'.($showTime ? 1 : 0).'">'
            .'<input type="hidden" name="defy" value="'.($defy ? 1 : 0).'">'
            .'<input type="hidden" name="note" value="'.($note ? 1 : 0).'">';
    };
@endphp
<article class="versus {{ $frame }} {{ $compact ? 'compact' : '' }} {{ $row ? 'is-row' : 'is-stack' }}" @if(!$compact && $m['status'] === 'voting') data-live-match="/m/{{ $m['id'] }}/fragment" @endif>
    @if ($showTop)
    <header class="versus-top">
        <a href="{{ $m['url'] }}">{{ $comp['title'] }}@if ($m['round_name']) <span class="faint">· {{ ucfirst($m['round_name']) }}</span>@endif</a>
        @if ($completed)<span class="chip">Terminé</span>@endif
    </header>
    @endif
    @if ($involvement && $note)
        <p class="versus-note">@icon('vs', 'ico-sm')
            @if ($involvement === 'organizer')<span>Tu as lancé {{ $typeThis }}</span>
            @else<span>Tu participes {{ $typeWord }} de <a href="{{ $comp['organizer']['url'] ?? $comp['url'] }}">{{ $comp['organizer']['name'] ?? '' }}</a></span>@endif
        </p>
    @endif

    <div class="versus-ring">
        @foreach ($order as $i => $s)
            @php
                $e = $m['entry_'.$s];
                $cls = $i === 0 ? 'a' : 'b';
                $pct = $pctOf($s);
                $isWinner = $completed && $e && $m['winner_entry_id'] === $e['id'];
                $isLoser = $completed && $e && $m['winner_entry_id'] && ! $isWinner;
                $isMine = $e && $mine === $e['id'];
                $visual = collect($e['media'] ?? [])->first(fn ($x) => in_array($x['type'], ['image', 'video']));
                $audio = collect($e['media'] ?? [])->first(fn ($x) => $x['type'] === 'audio');
                // Duel à deux choix : un choix sans média ni texte s'affiche en grand, par son nom
                $choice = $e && ! $visual && ! $audio && empty($e['content']) && empty($e['participant']) && filled($e['display_name'] ?? null);
                $isText = ! $visual && ! $audio && (! empty($e['content']) || $choice);
                $face = $e['participant']['avatar_url'] ?? null;
                $initial = mb_strtoupper(mb_substr($e['display_name'] ?? '?', 0, 1));
                $href = $e['participant']['url'] ?? $m['url'];
                $kind = $visual ? 'is-visual' : ($audio ? 'is-audio' : ($isText ? 'is-text' : 'is-empty'));
            @endphp
            @if ($i === 1)<div class="vs-sep" aria-hidden="true"><span>VS</span></div>@endif
            <section class="side {{ $cls }} {{ $kind }} {{ $isWinner ? 'is-winner' : '' }} {{ $isLoser ? 'is-loser' : '' }} {{ $isMine ? 'is-mine' : '' }}" aria-label="{{ $e['display_name'] ?? 'Exempté' }}{{ $pct !== null ? ', '.round($pct).' %' : '' }}">
                @if (! $e)
                    <div class="side-visual"><span class="faint small">Exempté</span></div>
                @elseif ($audio && ! $visual)
                    {{-- Audio : la photo de profil en cercle ; l'anneau se remplit pendant l'écoute --}}
                    @php $dur = \App\Support\Wave::duration($audio); @endphp
                    <div class="side-visual">
                        <div class="ring-player" data-ring-player>
                            <svg class="rp-ring" viewBox="0 0 100 100" aria-hidden="true"><circle class="rp-bg" cx="50" cy="50" r="47"/><circle class="rp-fg" cx="50" cy="50" r="47" pathLength="100"/></svg>
                            <span class="rp-face">@if ($face)<img loading="lazy" decoding="async" src="{{ $face }}" alt="Photo de profil">@else<span class="initial">{{ $initial }}</span>@endif</span>
                            <button type="button" class="rp-btn" data-ring-toggle aria-label="Écouter {{ $e['display_name'] }}">@icon('play', 'play')@icon('pause', 'pause')</button>
                            <audio src="{{ $audio['url'] }}" preload="metadata"></audio>
                        </div>
                        <a class="rp-name" href="{{ $href }}">{{ $e['display_name'] }}</a>
                        <span class="rp-time" data-ring-time data-total="{{ $dur }}">{{ $dur ?? '0:00' }}</span>
                        @if ($pct !== null)<span class="pct">{{ round($pct) }}%</span>@endif
                    </div>
                @else
                    <div class="side-visual">
                        @if ($visual && $visual['type'] === 'video')
                            @include('web.partials.video', ['src' => $visual['url'], 'poster' => $visual['thumbnail_url'] ?? null, 'meta' => $visual['metadata'] ?? null])
                        @elseif ($visual)
                            <img src="{{ $visual['url'] }}" alt="Proposition de {{ $e['display_name'] }}" loading="lazy" data-zoom>
                        @elseif ($isText)
                            {{-- Duel en texte : le texte se replie (même mécanique que les publications), « Voir plus » déplie par paliers --}}
                            <div class="quote"><div class="rm" data-rm="4"><p class="rm-txt">{!! $choice ? e($e['display_name']) : \App\Support\Mentions::html($e['content'], $e['mentions'] ?? []) !!}</p><button type="button" class="rm-btn" data-rm-btn hidden>Voir plus</button></div></div>
                        @else
                            <span class="face">@if ($face)<img loading="lazy" decoding="async" src="{{ $face }}" alt="Photo de profil">@else<span class="initial">{{ $initial }}</span>@endif</span>
                        @endif
                        {{-- Sur la carte, discrètement : qui, et combien --}}
                        @unless ($choice)<a class="tag" href="{{ $href }}">@if ($face)<img loading="lazy" decoding="async" src="{{ $face }}" alt="Photo de profil">@endif<span>{{ $e['display_name'] }}</span></a>@endunless
                        @if ($pct !== null)<span class="pct">{{ round($pct) }}%</span>@endif
                    </div>
                @endif
                @if ($isWinner)<span class="win-badge" title="Gagnant">@icon('trophy', 'ico-sm')</span>@endif
            </section>
        @endforeach
    </div>

    {{-- Une seule barre : flèche haut pour celui du haut, flèche bas pour celui du bas (gauche / droite sur grand écran) --}}
    <footer class="vbar">
        @php $cost = (int) ($comp['vote_cost'] ?? 0); $myVotes = (int) ($m['viewer']['my_votes'] ?? 0); @endphp
        <div class="vote-pill {{ $cost ? 'paid' : '' }}" role="group" aria-label="Voter">
            @foreach ($order as $i => $s)
                @php
                    $e = $m['entry_'.$s]; $isMine = $e && $mine === $e['id']; $dir = $i === 0 ? 'up' : 'down';
                    // Payant : on ajoute des votes à son camp (pas de retrait), l'autre camp est fermé une fois qu'on a choisi
                    $locked = $cost && $mine && ! $isMine;
                @endphp
                @if ($i === 1)<span class="vote-total" title="Votes">{{ $m['total_votes'] ?: '' }}</span>@endif
                @if ($e && ($canVote || $isMine))
                    <form method="post" action="/m/{{ $m['id'] }}/vote" data-ajax data-replace=".versus" data-silent @if($cost) data-paid-vote data-cost="{{ $cost }}" data-name="{{ $e['display_name'] }}" @endif>{!! $keep() !!}
                        <input type="hidden" name="entry_id" value="{{ $e['id'] }}">
                        <button class="va {{ $i === 0 ? 'first' : 'second' }} {{ $isMine ? 'on' : '' }}" @disabled(! $canVote || $locked) aria-pressed="{{ $isMine ? 'true' : 'false' }}"
                                aria-label="{{ $cost ? 'Voter avec des coins pour' : ($isMine ? 'Retirer mon vote pour' : 'Voter pour') }} {{ $e['display_name'] }}"
                                title="{{ $locked ? 'Tes votes vont à l\'autre camp' : ($cost ? 'Voter pour '.$e['display_name'].' ('.$cost.' coin'.($cost > 1 ? 's' : '').' par vote)' : ($isMine ? 'Retirer mon vote' : 'Voter pour '.$e['display_name'])) }}">@icon('arrow-'.$dir)</button>
                    </form>
                @elseif ($e && $guest)
                    <a class="va {{ $i === 0 ? 'first' : 'second' }}" href="/connexion?suite={{ urlencode($m['url']) }}" aria-label="Connecte-toi pour voter" title="Connecte-toi pour voter">@icon('arrow-'.$dir)</a>
                @else
                    <span class="va {{ $i === 0 ? 'first' : 'second' }} off" aria-hidden="true">@icon('arrow-'.$dir)</span>
                @endif
            @endforeach
        </div>
        @if ($cost)<span class="act-meta coin-price" title="{{ $cost }} coin{{ $cost > 1 ? 's' : '' }} par vote{{ $myVotes ? ' · tu as donné '.$myVotes.' vote'.($myVotes > 1 ? 's' : '') : '' }}">@icon('coin'){{ $myVotes ? '×'.$myVotes : $cost }}</span>@endif
        @if ($social)
            @auth
                <form method="post" action="/c/{{ $comp['slug'] }}/aimer" data-ajax data-like data-silent>@csrf
                    <button class="act-btn {{ ($comp['liked'] ?? false) ? 'on' : '' }}" aria-pressed="{{ ($comp['liked'] ?? false) ? 'true' : 'false' }}" aria-label="J'aime">@icon('heart')<span data-like-count>{{ ($comp['likes_count'] ?? 0) ?: '' }}</span></button></form>
            @else
                <a class="act-btn" href="/connexion?suite={{ urlencode($m['url']) }}" aria-label="J'aime">@icon('heart')<span>{{ ($comp['likes_count'] ?? 0) ?: '' }}</span></a>
            @endauth
            @feature('comments')<a class="act-btn" href="{{ $comp['url'] }}?tab=commentaires&duel={{ $m['id'] }}" data-duel="{{ $m['id'] }}" aria-label="Commentaires">@icon('comment')<span>{{ ($comp['comments_count'] ?? 0) ?: '' }}</span></a>@endfeature
        @endif
        @if ($showTime)
            @if ($m['status'] === 'voting' && $m['ends_at'])<span class="act-meta" title="Temps restant"><span class="countdown" data-prefix="reste " data-ends="{{ $m['ends_at'] }}"></span></span>
            @elseif ($completed)<span class="act-meta done">@icon($m['is_draw'] ? 'equal' : 'flag', 'ico-sm'){{ $m['is_draw'] ? 'Match nul' : 'Fini' }}{{ ! empty($m['ended_at']) ? ' '.\App\Support\Presenter::ago($m['ended_at']) : '' }}</span>@endif
        @endif
        <span class="push"></span>
        {{-- « Défier » seulement si le défi accepte encore des challengers et qu'on n'y est pas déjà --}}
        @if ($defy && $comp['type'] === 'challenge' && ($m['viewer']['can_defy'] ?? false))
            <a class="btn btn-sm defy-btn" href="{{ auth()->guest() ? '/connexion?suite='.urlencode($comp['url']) : $comp['url'].(($m['viewer']['can_defy'] ?? false) ? '#participer' : '') }}">@icon('vs', 'ico-sm')Défier</a>
        @endif
        @if ($social)
            <button type="button" class="act-btn" data-share="{{ $m['url'] }}" data-title="{{ $comp['title'] }}" data-report="match:{{ $m['id'] }}" aria-label="Partager">@icon('share')</button>
        @endif
    </footer>
</article>
