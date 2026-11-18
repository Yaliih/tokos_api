@php
    $icon = \App\Support\Icons::VERB[$a['verb']] ?? 'sparkles';
    $tone = in_array($a['verb'], ['match.completed', 'competition.completed', 'badge.earned', 'rank.top10', 'streak', 'contest.leader'], true) ? 'gold'
        : (in_array($a['verb'], ['duel.invited', 'challenge.accepted', 'match.milestone', 'match.tight', 'competition.last_call', 'contest.surge', 'prize.delivered'], true) ? 'coral' : '');
    $actor = $a['actors'][0] ?? $a['actor'];
    $shareTitle = strip_tags($a['text']);
    $pk = $a['preview']['kind'] ?? null;
    $pc = $pk === 'competition' ? $a['preview']['competition'] : null;
    $pm = $pk === 'match' ? $a['preview']['match']
        : (($pc && in_array($pc['type'], ['duel', 'challenge'], true) && ($pc['featured_match']['status'] ?? null) === 'voting') ? $pc['featured_match'] : null);
    $comp = $pc ?? ($pm['competition'] ?? (in_array($pk, ['standings', 'bracket'], true) ? ($a['preview']['competition'] ?? null) : null));
    // Un mot pour l'action, l'icône sur l'avatar fait le reste
    $verbWord = [
        'competition.created' => 'publie',
        'duel.invited' => 'lance un duel', 'challenge.accepted' => 'relève le défi', 'entry.submitted' => 'participe',
        'match.completed' => 'gagne son duel', 'match.milestone' => 'cartonne', 'competition.completed' => 'remporte',
        'tournament.started' => 'tournoi lancé', 'tournament.round' => 'tour suivant', 'contest.voting' => 'votes ouverts',
        'contest.standings' => 'classement',
    ][$a['verb']] ?? null;
    $compact = $comp && $verbWord; // en-tête court : nom et temps, puis une petite ligne (type, discipline)
    // Le titre est toujours dans la publication (pas sous le nom), suivi de la description
    $titleInBody = $compact && filled($comp['title'] ?? null);
    // Petite ligne sous le nom : l'action si elle dit quelque chose (« relève le défi »), sinon le type, puis la discipline
    $metaLine = $compact ? collect([
        $a['verb'] === 'competition.created' ? ($comp['type_label'] ?? null) : \Illuminate\Support\Str::ucfirst($verbWord),
        $comp['category_label'] ?? null,
    ])->filter()->implode(' · ') : null;
    // Le type de publication, en haut de chaque carte : Défi, Duel, Concours, Tournoi
    $typeIco = ['challenge' => 'challenge', 'duel' => 'vs', 'contest' => 'medal', 'tournament' => 'bracket'][$comp['type'] ?? ''] ?? null;
    // Le temps qui reste (ou « Terminé il y a… ») plutôt que la date de publication.
    // Défi : c'est le temps du défi qui compte, même si ce duel-là est fini.
    $time = (($comp['type'] ?? '') === 'challenge' && ! empty($pc['time'])) ? $pc['time'] : ($pm['time'] ?? ($pc['time'] ?? ($a['time'] ?? ($comp['time'] ?? null))));
    $hasTime = ($time['state'] ?? 'none') !== 'none';
@endphp
<article class="post {{ $a['importance'] >= 3 ? 'highlight' : '' }}">
    <header class="post-head">
        <a class="av" href="{{ $actor['url'] ?? '#' }}" tabindex="-1" aria-hidden="true">
            @include('web.partials.avatar', ['p' => $actor])
            @if (! $compact || $tone === 'gold')<span class="kind {{ $tone }}">@icon($icon)</span>@endif
        </a>
        <div class="who-line">
            @if ($compact)
                {{-- Le nom et le temps sur la même ligne ; dessous, en petit : le type et la discipline --}}
                <div class="who-top"><a href="{{ $actor['url'] ?? '#' }}">{{ $actor['name'] ?? '' }}</a>
                    @if ($hasTime)<span class="post-when">· @include('web.partials.when', ['t' => $time])</span>@endif
                </div>
                @if ($metaLine)<div class="who-meta">{{ $metaLine }}</div>@endif
            @else
                @foreach ($a['segments'] as $s)
                    @if ($s['type'] === 'actor')<a href="{{ $s['url'] }}">{{ $s['text'] }}</a>@elseif ($s['type'] === 'link')<a class="ctx" href="{{ $s['url'] }}">{{ $s['text'] }}</a>@elseif ($s['type'] === 'strong')<strong>{{ $s['text'] }}</strong>@else{{ $s['text'] }}@endif
                @endforeach
                @if ($hasTime)<div class="who-meta post-when">@include('web.partials.when', ['t' => $time])</div>@endif
            @endif
        </div>
    </header>

    @if ($titleInBody)
        <a class="post-title" href="{{ $pm['url'] ?? $comp['url'] }}">{{ $comp['title'] }}</a>
    @endif
    @if ($compact && ! empty($comp['blurb']))
        {{-- La description, sous le titre : trois lignes, puis « Lire plus » --}}
        <div class="post-desc" data-readmore><p>{!! \App\Support\Mentions::html($comp['blurb'], $comp['mentions'] ?? []) !!}</p><button type="button" class="more-btn" data-readmore-btn hidden>Voir plus</button></div>
    @endif

    @if ($pm)
        {{-- Un duel se montre tel quel : votes, j'aime et commentaires dans sa barre --}}
        <div class="post-body flush">@include('web.partials.versus', ['m' => $pm, 'compact' => true, 'frame' => 'none', 'showTop' => false, 'social' => true, 'showTime' => false])</div>
        @if (($comp['type'] ?? '') === 'challenge')
            {{-- Défi : ce duel n'est qu'un parmi d'autres. Voir tous les duels, ou relever le défi --}}
            @php $dCount = $pc['matches_count'] ?? null; $dEnter = $pc ? (($pc['viewer']['can_enter'] ?? false) && ! ($pc['viewer']['can_manage'] ?? false) && $pc['status'] === 'open') : false; @endphp
            <div class="post-more">
                <a class="pm-link" href="{{ $comp['url'] }}?tab=duels" data-duels="{{ $comp['slug'] }}">@icon('vs', 'ico-sm'){{ $dCount ? $dCount.' duel'.($dCount > 1 ? 's' : '') : 'Tous les duels' }}@icon('chevron-right', 'ico-sm')</a>
                @if ($dEnter)<a class="btn btn-sm defy-btn" href="{{ $comp['url'] }}#participer" data-duels="{{ $comp['slug'] }}" data-duels-tab="join">@icon('vs', 'ico-sm')Défier</a>@endif
            </div>
        @endif
    @elseif ($a['preview'])
        @switch($pk)
            @case('competition')
                <div class="post-body flush">@include('web.partials.competition-card', ['c' => $pc, 'inline' => true, 'withCta' => false, 'titleShown' => $titleInBody])</div>
                @break
            @case('standings')
                <div class="post-body flush">@include('web.partials.feed-standings', ['s' => $a['preview']['standings'], 'comp' => $comp])</div>
                @break
            @case('bracket')
                <div class="post-body flush">@include('web.partials.feed-bracket', ['b' => $a['preview']['bracket'], 'comp' => $comp])</div>
                @break
            @case('competition_summary')
                <div class="post-body"><a class="chip chip-primary" href="{{ $a['preview']['competition']['url'] }}">{{ $a['preview']['competition']['type_label'] }} : {{ $a['preview']['competition']['title'] }}</a></div>
                @break
            @case('badge')
                <div class="post-body">
                    <div class="mini-badge">
                        <span class="medal">@icon(\App\Support\Icons::BADGE[$a['preview']['badge']['code']] ?? 'award')</span>
                        <div><strong>{{ $a['preview']['badge']['name'] }}</strong><div class="muted small">{{ $a['preview']['badge']['description'] }}</div></div>
                    </div>
                </div>
                @break
            @case('space')
                <div class="post-body">
                    <a class="space-preview" href="{{ $a['preview']['space']['url'] }}">
                        @include('web.partials.avatar', ['p' => $a['preview']['space'], 'size' => 'md'])
                        <div class="grow"><strong>{{ $a['preview']['space']['name'] }}</strong>
                            @isset($a['preview']['space']['members_count'])<div class="muted small">{{ $a['preview']['space']['members_count'] }} membres</div>@endisset</div>
                        @icon('chevron-right', 'ico-sm faint')
                    </a>
                </div>
                @break
            @case('post')
                <div class="post-body">
                    @if (filled($a['preview']['post']['content']))
                        {{-- Publication longue : le texte est replié, « Lire plus » ouvre la suite (même mécanique que la description d'un concours) --}}
                        <div class="post-text rm" data-rm="4"><p class="rm-txt">{!! \App\Support\Mentions::html($a['preview']['post']['content']) !!}</p><button type="button" class="rm-btn" data-rm-btn hidden>Voir plus</button></div>
                    @endif
                    @if (!empty($a['preview']['post']['media']))
                        <div class="post-media">@include('web.partials.media', ['media' => $a['preview']['post']['media'], 'limit' => true])</div>
                    @endif
                </div>
                @break
        @endswitch
    @endif

    @php
        // Bouton « Défier » : relever le défi en un clic depuis le fil
        // Toujours « Défier » sur un défi ouvert ; « Participer » sur un concours ou un tournoi qui a encore de la place
        $open = $pc && $pc['status'] === 'open';
        $room = $pc && (empty($pc['max_entries']) || $pc['entries_count'] < $pc['max_entries']);
        // Seulement si on peut vraiment entrer : ouvert, pas fini, de la place, pas organisateur, pas déjà dedans
        $canEnter = $pc && ($pc['viewer']['can_enter'] ?? false) && ! ($pc['viewer']['can_manage'] ?? false);
        $cta = ! $open || ! $canEnter ? null : ($pc['type'] === 'challenge' ? ['vs', 'Défier'] : (in_array($pc['type'], ['contest', 'tournament'], true) && $room ? [($pc['application'] ?? false) ? 'user' : 'zap', ($pc['application'] ?? false) ? 'Candidater' : ($pc['type'] === 'tournament' ? 'S\'inscrire' : 'Participer')] : null));
        $ctaHref = ! $cta ? null : (auth()->guest() ? '/connexion?suite='.urlencode($pc['url']) : $pc['url'].(($pc['viewer']['can_enter'] ?? false) ? '#participer' : ''));
    @endphp
    @if ($a['url'] && ! $pm)
    <footer class="post-actions">
        @if ($comp)
            @auth
                <form method="post" action="/c/{{ $comp['slug'] }}/aimer" data-ajax data-like data-silent>@csrf
                    <button class="act-btn {{ ($comp['liked'] ?? false) ? 'on' : '' }}" aria-pressed="{{ ($comp['liked'] ?? false) ? 'true' : 'false' }}" aria-label="J'aime">@icon('heart')<span data-like-count>{{ $comp['likes_count'] ?? 0 }}</span></button></form>
            @else
                <a class="act-btn" href="/connexion?suite={{ urlencode($a['url']) }}" aria-label="J'aime">@icon('heart')<span>{{ $comp['likes_count'] ?? 0 }}</span></a>
            @endauth
            @feature('comments')<a class="act-btn" href="{{ $comp['url'] }}?tab=commentaires" aria-label="Commentaires">@icon('comment')<span>{{ $comp['comments_count'] ?? 0 }}</span></a>@endfeature
            @if (($comp['type'] ?? '') === 'challenge')
                {{-- Les duels du défi, dans une fenêtre --}}
                <a class="act-btn" href="{{ $comp['url'] }}?tab=duels" data-duels="{{ $comp['slug'] }}" aria-label="Voir les duels">@icon('vs')<span>{{ $comp['matches_count'] ?? 0 }}</span></a>
            @else
                @isset($comp['entries_count'])<span class="act-meta" title="Participants">@icon('users'){{ $comp['entries_count'] }}</span>@endisset
            @endif
            @isset($comp['votes_count'])<span class="act-meta" title="Votes">@icon('arrow-up'){{ $comp['votes_count'] }}</span>@endisset
        @endif
        <span class="push"></span>
        @if ($cta)
            <a class="btn btn-sm defy-btn" href="{{ $ctaHref }}" @if($pc['type'] === 'challenge') data-duels="{{ $pc['slug'] }}" data-duels-tab="join" @endif>@icon($cta[0], 'ico-sm'){{ $cta[1] }}</a>
        @endif
        <button type="button" class="act-btn" data-share="{{ $a['url'] }}" data-title="{{ $shareTitle }}" @if($comp) data-report="competition:{{ $comp['id'] }}" @endif aria-label="Partager">@icon('share')</button>
    </footer>
    @endif
</article>
