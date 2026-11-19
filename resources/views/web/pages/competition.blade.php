@extends('web.layout')
@section('content')
@php
    // Valeurs par défaut : un visiteur sans compte n'a pas toutes les clés
    $v = array_merge(['can_manage' => false, 'can_enter' => false, 'can_propose' => false, 'invitation' => null, 'login_required' => false,
        'my_entry_ids' => [], 'is_juror' => false, 'votes_left' => null, 'enter_as' => [], 'my_entry_status' => null], $c['viewer'] ?? []);
    $verb = ($c['application'] ?? false) ? 'Candidater' : ['challenge' => 'Relever le défi', 'duel' => 'Accepter le duel', 'contest' => 'Participer', 'tournament' => 'S\'inscrire'][$c['type']];
    $typeIcon = \App\Support\Icons::TYPE[$c['type']] ?? 'trophy';
    $formatIcon = ['video' => 'video', 'audio' => 'mic', 'image' => 'image', 'text' => 'type'][$c['entry_format'] ?? ''] ?? null;
    $over = in_array($c['status'], ['completed', 'cancelled'], true);
    $deadline = $c['status'] === 'open' && $c['type'] !== 'challenge' && $c['registration_ends_at'] ? $c['registration_ends_at'] : $c['ends_at'];
    $room = empty($c['max_entries']) || $c['entries_count'] < $c['max_entries'];
    $entered = count($v['my_entry_ids']) > 0 && ! in_array($v['my_entry_status'], ['withdrawn', 'rejected'], true);
    $isOrg = $v['can_manage'];
    $big = in_array($c['type'], ['contest', 'tournament'], true);
    $geoEnter = !empty($c['geo_city']) && in_array($c['geo_scope'] ?? 'both', ['enter', 'both'], true) && \App\Support\Features::on('geo_limits');
    // La performance du créateur (défi, duel) ou la couverture : montrée en grand, c'est la publication
    $oe = (!empty($c['owner_entry']) && $c['format'] !== 'versus') ? $c['owner_entry'] : null;
    $heroMedia = $oe && count($oe['media'] ?? []) ? $oe['media'] : ($c['cover_url'] ? [['type' => ($c['cover_type'] ?? 'image') === 'video' ? 'video' : 'image', 'url' => $c['cover_url']]] : []);
    $listTitle = ['duels' => 'Duels', 'tableau' => 'Tableau', 'classement' => $c['status'] === 'completed' ? 'Classement' : 'Candidats', 'participants' => $c['type'] === 'challenge' ? 'Challengers' : 'Inscrits', 'propositions' => 'Propositions', 'gestion' => 'Gérer'][$tab] ?? '';
@endphp

{{-- La publication : qui, quoi, la performance en grand, trois actions. Le reste (règles, jury, dates) est dans « Infos ». --}}
<article class="card dpost">
    <header class="post-head">
        <a class="av" href="{{ $c['organizer']['url'] ?? '#' }}" tabindex="-1" aria-hidden="true">@include('web.partials.avatar', ['p' => $c['organizer']])</a>
        <div class="who-line">
            <div class="who-top"><a href="{{ $c['organizer']['url'] ?? '#' }}">{{ $c['organizer']['name'] ?? '' }}</a></div>
            <span class="dpost-kind">@icon($typeIcon, 'ico-sm'){{ $c['type_label'] }}@if($c['category_label'] ?? null) · {{ $c['category_label'] }}@endif
                @if (($c['time']['state'] ?? 'none') !== 'none') · @include('web.partials.when', ['t' => $c['time']])@endif
                @if (!empty($c['edited_at'])) · <span title="Modifié {{ \Illuminate\Support\Carbon::parse($c['edited_at'])->locale('fr')->diffForHumans() }}">modifié</span>@endif</span>
        </div>
        <button type="button" class="icon-btn" data-open="infos-sheet" aria-label="Infos et règles">@icon('info')</button>
        @if ($isOrg && !$over && empty($c['is_over']) && \App\Support\Features::on('editing'))<button type="button" class="icon-btn" data-open="edit-sheet" aria-label="Modifier la publication">@icon('edit')</button>@endif
        @if ($isOrg)<a class="icon-btn" href="?tab=gestion" aria-label="Gérer">@icon('settings')</a>@endif
    </header>

    <h1 class="dpost-title">{{ $c['title'] }}</h1>
    @php $oeText = $oe ? \App\Services\Competition\CompetitionEditor::body($oe['content'] ?? null, $c['title']) : null; @endphp
    @if ($oe && empty($oe['media']) && $oeText)
        <p class="dpost-text">{{ $oeText }}</p>
    @elseif (!$oe && $c['description'])
        <p class="dpost-text clamp">{!! \App\Support\Mentions::html($c['description'], $c['mentions'] ?? []) !!}</p>
    @endif
    @if (count($heroMedia))<div class="dpost-media">@include('web.partials.media', ['media' => $heroMedia, 'limit' => true])</div>@endif

    @php
        $facts = array_filter([
            !empty($c['geo_city']) && \App\Support\Features::on('geo_limits') ? ['map-pin', 'Réservé à '.$c['geo_city']] : null,
            $c['prize_coins_locked'] ? ['coin', number_format($c['prize_coins_locked'], 0, ',', ' ').' coins à gagner'] : ($c['prize'] ? ['gift', \Illuminate\Support\Str::limit($c['prize'], 40)] : null),
            ($c['vote_cost'] ?? 0) ? ['coin', $c['vote_cost'].' / vote'] : null,
            ['users', $c['entries_count'].($c['max_entries'] ? '/'.$c['max_entries'] : '').' '.($c['type'] === 'contest' ? 'candidats' : 'participants')],
            $formatIcon ? [$formatIcon, $c['entry_format_label'] ?? ''] : null,
        ]);
    @endphp
    <div class="dpost-facts">@foreach ($facts as [$ico, $t])<span>@icon($ico, 'ico-sm'){{ $t }}</span>@endforeach</div>

    @if ($c['winner'])
        <a class="dpost-winner" href="{{ $c['winner']['participant']['url'] ?? '#' }}">@icon('trophy')<span class="trunc"><strong>{{ $c['winner']['display_name'] }}</strong> a gagné</span></a>
    @endif

    <footer class="post-actions">
        @auth
            <form method="post" action="/c/{{ $c['slug'] }}/aimer" data-ajax data-like data-silent>@csrf
                <button class="act-btn {{ $liked ? 'on' : '' }}" aria-pressed="{{ $liked ? 'true' : 'false' }}" aria-label="J'aime">@icon('heart')<span data-like-count>{{ $c['likes_count'] }}</span></button></form>
        @else
            <a class="act-btn" href="/connexion?suite={{ urlencode($c['url']) }}" aria-label="J'aime">@icon('heart')<span>{{ $c['likes_count'] }}</span></a>
        @endauth
        @feature('comments')<button type="button" class="act-btn" data-comments="{{ $c['slug'] }}" aria-label="Commentaires">@icon('comment')<span data-comments-count="{{ $c['slug'] }}">{{ $c['comments_count'] }}</span></button>@endfeature
        <span class="push"></span>
        <button type="button" class="act-btn" data-share="{{ $c['url'] }}" data-title="{{ $c['title'] }}" data-text="Viens voter dans « {{ $c['title'] }} » sur Sosa" data-report="competition:{{ $c['id'] }}" aria-label="Partager">@icon('share')</button>
    </footer>
</article>

@isset($phoneInvite)
    {{-- Défi envoyé à un numéro sans compte : relance en un toucher sur WhatsApp --}}
    <div class="card invite-wa">
        <span class="invite-wa-txt">@icon('whatsapp')<span><b>{{ $phoneInvite->fullPhone() }}</b> n'a pas encore de compte : envoie-lui le défi sur WhatsApp.</span></span>
        <a class="btn btn-sm" href="{{ $phoneInvite->whatsappUrl() }}" target="_blank" rel="noopener">@icon('whatsapp', 'ico-sm')Envoyer sur WhatsApp</a>
    </div>
@endisset
{{-- Une seule action principale, toujours visible (collée en bas sur téléphone) --}}
@php
    $cta = null; // [type, label, icon, attr]
    if ($v['invitation']) $cta = ['open', 'Répondre au défi', 'vs', 'repondre'];
    elseif ($v['can_enter'] && $geoEnter && !empty($v['geo_locate'])) $cta = ['locate', 'Vérifier ma position pour '.mb_strtolower($verb), 'map-pin', null];
    elseif ($v['can_enter']) $cta = ['open', $verb, $c['type'] === 'challenge' ? 'vs' : (($c['application'] ?? false) ? 'user' : 'zap'), 'participer'];
    elseif ($isOrg && $big && $c['status'] === 'open') $cta = ['link', 'Gérer les inscriptions', 'settings', '?tab=gestion'];
    $note = null;
    // Terminé (ou temps écoulé) : plus moyen de participer, on le dit clairement
    $ended = $over || $c['is_over'];
    $closed = ! $ended && $c['status'] === 'open' && ! $c['accepts_entries'] && $room && ! $entered;
    if ($ended) { $cta = null; $note = ['flag', $c['status'] === 'cancelled' ? 'Ce '.mb_strtolower($c['type_label']).' a été annulé.' : 'Ce '.mb_strtolower($c['type_label']).' est déjà terminé : on ne peut plus participer.']; }
    elseif ($closed && ! $isOrg) { $cta = null; $note = ['lock', 'Les inscriptions sont closes'.(! empty($c['registration_ends_at']) ? ' depuis le '.\Illuminate\Support\Carbon::parse($c['registration_ends_at'])->locale('fr')->isoFormat('D MMM à HH:mm') : '').'.']; }
    elseif ($isOrg && in_array($c['type'], ['challenge', 'duel'], true)) $note = [$typeIcon, $c['type'] === 'challenge' ? 'Ton défi : partage-le pour attirer des challengers.' : 'Ton duel.'];
    elseif ($entered) $note = ['check', $v['my_entry_status'] === 'pending' ? 'Candidature envoyée : l\'organisateur doit valider.' : ($c['type'] === 'challenge' ? 'Tu as relevé ce défi.' : 'Tu participes.')];
    elseif ($isOrg && $big) $note = ['settings', 'Tu organises : tu valides et tu lances, sans participer.'];
    elseif ($v['is_juror']) $note = ['scale', 'Tu fais partie du jury.'];
    elseif (!empty($v['geo_wrong_city']) && $geoEnter) $note = ['map-pin', 'Réservé à '.$c['geo_city'].' : ta position est en dehors.'];
    elseif (!$cta && $c['status'] === 'open' && !$v['login_required'] && $big && !$room) $note = ['users', 'Complet : toutes les places sont prises.'];
@endphp
@if ($cta || $note)
<div class="cta-zone {{ $cta ? 'has-cta' : '' }}">
    @if ($note)<p class="cta-note">@icon($note[0], 'ico-sm')<span>{{ $note[1] }}</span>@if($big && !$over)<button type="button" class="link-btn" data-open="infos-sheet">Comment ça marche ?</button>@endif</p>@endif
    @if ($cta)
        <div class="cta-bar">
            @if ($cta[0] === 'open')<button type="button" class="btn btn-lg btn-block" data-open="{{ $cta[3] }}">@icon($cta[2], 'ico-sm'){{ $cta[1] }}</button>
            @elseif ($cta[0] === 'locate')<button type="button" class="btn btn-lg btn-block" data-locate="reload">@icon($cta[2], 'ico-sm'){{ $cta[1] }}</button>
            @else<a class="btn btn-lg btn-block" href="{{ $cta[3] }}">@icon($cta[2], 'ico-sm'){{ $cta[1] }}</a>@endif
            @if ($big && !$note)<button type="button" class="btn btn-lg btn-ghost cta-how" data-open="infos-sheet" aria-label="Comment ça marche">@icon('info', 'ico-sm')</button>@endif
        </div>
    @endif
</div>
@endif

{{-- La remise du prix : la vidéo du gagnant qui reçoit son prix, preuve que les gains sont réels --}}
@if (!empty($c['prize_proof']))
    <section class="card prize-proof" id="remise">
        <h2 class="pp-title">@icon('gift', 'ico-sm')Remise du prix</h2>
        @if ($c['prize_proof']['text'])<p class="pp-text">{!! \App\Support\Mentions::html($c['prize_proof']['text'], $c['prize_proof']['mentions'] ?? []) !!}</p>@endif
        <div class="pp-media">@include('web.partials.media', ['media' => $c['prize_proof']['media']])</div>
        @if (!empty($c['can_publish_prize']))
            <details class="pp-again"><summary>Remplacer</summary>@include('web.partials.prize-proof-form', ['c' => $c])</details>
        @endif
    </section>
@elseif (!empty($c['can_publish_prize']))
    <section class="card prize-proof prize-ask" id="remise">
        <h2 class="pp-title">@icon('gift', 'ico-sm')Dernière étape : la remise du prix</h2>
        <p class="pp-text">Filme le gagnant quand il reçoit son prix chez le sponsor (ou demande au commerçant de filmer avec son téléphone), puis publie la vidéo ici.</p>
        <ul class="pp-why">
            <li>@icon('check-circle', 'ico-sm')Tout le monde voit que les gains sont réels.</li>
            <li>@icon('check-circle', 'ico-sm')Le sponsor a sa publicité, avec un client content.</li>
            <li>@icon('check-circle', 'ico-sm')Les autres ont envie de jouer la prochaine fois.</li>
        </ul>
        @include('web.partials.prize-proof-form', ['c' => $c])
    </section>
@endif

@if (!empty($phoneInvite))
    <div class="card invite-card">
        <span class="tile">@icon('phone')</span>
        <div class="grow"><strong>Défi envoyé au {{ $phoneInvite->fullPhone() }}</strong>
            <div class="muted small">Envoie-le aussi sur WhatsApp : dès son inscription, le duel l'attend.</div></div>
        <a class="btn btn-whatsapp" href="{{ $phoneInvite->whatsappUrl() }}" target="_blank" rel="noopener">@icon('whatsapp')WhatsApp</a>
        <button type="button" class="btn btn-ghost" data-share="{{ $phoneInvite->url() }}" data-title="{{ $c['title'] }}" aria-label="Copier le lien">@icon('share')</button>
    </div>
@endif

{{-- Le contenu : directement la liste (duels, candidats…). Tournoi : Duels ou Tableau. --}}
<div class="list-head">
    @if (in_array($tab, ['propositions', 'gestion'], true) || ($tab === 'participants' && $c['type'] !== 'tournament'))
        <a class="list-back" href="{{ $c['url'] }}">@icon('arrow-left', 'ico-sm')</a>
    @endif
    @php $tourSeg = $c['type'] === 'tournament' && in_array($tab, ['duels', 'tableau', 'participants'], true); @endphp
    @unless ($tourSeg)<h2>{{ $listTitle }}</h2>@endunless
    @if ($tourSeg)
        <nav class="seg seg-tour" aria-label="Affichage">
            <a href="?tab=participants" @if($tab === 'participants') aria-current="page" @endif>Inscrits</a>
            <a href="?tab=duels" @if($tab === 'duels') aria-current="page" @endif>Duels</a>
            <a href="?tab=tableau" @if($tab === 'tableau') aria-current="page" @endif>Tableau</a>
        </nav>
    @endif
</div>

@switch($tab)
    @case('duels')
        <div class="feed">
            @forelse ($matches as $m)
                @include('web.partials.versus', ['m' => $m, 'compact' => true, 'showTop' => false, 'defy' => false, 'note' => false])
            @empty
                <div class="card empty"><div class="bubble">@icon('vs')</div>
                    <h3>{{ $c['type'] === 'challenge' ? 'Personne n\'a encore relevé ce défi' : ($c['type'] === 'duel' ? 'En attente de l\'adversaire' : 'Le tournoi n\'a pas commencé') }}</h3>
                    <p>{{ $c['type'] === 'challenge' ? 'Partage-le pour attirer des challengers.' : ($c['type'] === 'duel' ? 'Il a 72 h pour accepter.' : 'Les duels commencent au lancement.') }}</p>
                    <button type="button" class="btn btn-soft" data-share="{{ $c['url'] }}" data-title="{{ $c['title'] }}">@icon('share', 'ico-sm')Partager</button>
                </div>
            @endforelse
        </div>
        @break

    @case('tableau')
        @if (!empty($bracket) && count($bracket))
            <div class="bracket">
                @foreach ($bracket as $r)
                    <div class="bracket-round">
                        <h3>{{ ucfirst($r['name']) }}</h3>
                        <ol>
                            @foreach ($r['matches'] as $m)
                                <li>
                                    @if ($m)
                                        <a class="slot {{ $m['status'] === 'voting' ? 'live' : '' }}" href="{{ $m['is_bye'] ? '#' : $m['url'] }}">
                                            @foreach (['a', 'b'] as $s)
                                                @php $e = $m['entry_'.$s]; $won = $m['winner_entry_id'] && $e && $m['winner_entry_id'] === $e['id']; @endphp
                                                <span class="line {{ $s }} {{ $won ? 'won' : '' }} {{ $m['status'] === 'completed' && !$won && $e ? 'lost' : '' }}">
                                                    <span>{{ $e['display_name'] ?? 'Exempt' }}</span>
                                                    <span>{{ $m['is_bye'] ? '' : ($m['votes_'.$s] ?? '?') }}</span>
                                                </span>
                                            @endforeach
                                        </a>
                                    @else
                                        <span class="slot tbd"><span class="line"><span>À venir</span></span><span class="line b"><span>À venir</span></span></span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        @else
            <div class="card empty"><div class="bubble">@icon('bracket')</div><h3>Tableau à venir</h3><p>Il sera tiré au lancement, d'après le classement des participants.</p></div>
        @endif
        @break

    @case('classement')
    @case('participants')
        @if ($tab === 'classement' && $c['status'] === 'completed' && count($entries) >= 3)
            <div class="podium">
                @foreach ([1, 0, 2] as $idx)
                    @php $pe = $entries[$idx]; @endphp
                    <div class="place p{{ $idx + 1 }}">
                        @if ($idx === 0)@icon('crown', 'crown')@else<span class="medal-num">{{ $idx + 1 }}</span>@endif
                        @include('web.partials.avatar', ['p' => $pe['participant'], 'size' => $idx === 0 ? 'md' : ''])
                        <strong>{{ $pe['display_name'] }}</strong>
                        <b>{{ $pe['votes_count'] }}</b><span class="muted">votes</span>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="entries">
            @forelse ($entries as $i => $e)
                @php $rank = $e['rank'] ?? $i + 1; @endphp
                <article class="entry {{ $tab === 'classement' && $rank <= 3 ? 'p'.$rank : '' }}">
                    <span class="pos">{{ $tab === 'classement' ? $rank : '' }}@if($tab !== 'classement')@icon('user', 'ico-sm')@endif</span>
                    <div style="min-width:0">
                        @if ($e['participant']) @include('web.partials.who', ['p' => $e['participant']]) @else <strong>{{ $e['display_name'] }}</strong> @endif
                        @if ($e['status'] === 'eliminated')<span class="chip">Éliminé</span>@endif
                        @if ($e['status'] === 'winner')<span class="chip chip-success">@icon('award')Lauréat</span>@endif
                        @if (($jokerId ?? null) && $e['id'] === $jokerId)<span class="chip chip-gold" title="Le plus soutenu">@icon('star')Joker</span>@endif
                        @if ($c['type'] === 'contest')
                            @if ($e['content'])<p class="entry-text">{!! \App\Support\Mentions::html(\Illuminate\Support\Str::limit($e['content'], 200), $e['mentions'] ?? []) !!}</p>@endif
                            @if (count($e['media']))<div class="media-strip">@include('web.partials.media', ['media' => $e['media']])</div>@endif
                        @endif
                        @if (in_array($e['id'], $v['my_entry_ids']) && $c['status'] === 'open')
                            <form method="post" action="/c/{{ $c['slug'] }}/retirer/{{ $e['id'] }}" style="margin-top:10px">@csrf<button class="btn btn-sm btn-danger">@icon('log-out', 'ico-sm')Retirer ma participation</button></form>
                        @endif
                    </div>
                    <div class="votes">
                        @if (! empty($e['match_url']))
                            {{-- Défi : la réponse mène à son duel --}}
                            <a class="btn btn-sm btn-soft" href="{{ $e['match_url'] }}">@icon('vs', 'ico-sm')Voir le duel</a>
                        @endif
                        @if ($c['type'] === 'contest')
                            {{-- Un seul bouton : la flèche et le nombre de votes ; toucher pour voter ou retirer --}}
                            @php
                                $paid = (int) ($c['vote_cost'] ?? 0);
                                $votedOther = $paid && collect($entries)->contains(fn ($x) => ($x['voted_by_me'] ?? false) && $x['id'] !== $e['id']);
                            @endphp
                            @if ($c['status'] === 'live' && auth()->check() && !in_array($e['id'], $v['my_entry_ids']))
                                <form method="post" action="/c/{{ $c['slug'] }}/voter/{{ $e['id'] }}" @if($paid) data-paid-vote data-cost="{{ $paid }}" data-name="{{ $e['display_name'] }}" @endif>@csrf
                                    <button class="vote-chip {{ ($e['voted_by_me'] ?? false) ? 'on' : '' }}" @disabled($votedOther) aria-pressed="{{ ($e['voted_by_me'] ?? false) ? 'true' : 'false' }}"
                                        title="{{ $votedOther ? 'Tes votes vont à un autre candidat' : ($paid ? $paid.' coin'.($paid > 1 ? 's' : '').' par vote' : '') }}"
                                        aria-label="{{ $paid ? 'Voter avec des coins' : (($e['voted_by_me'] ?? false) ? 'Retirer mon vote' : 'Voter') }} pour {{ $e['display_name'] }}">@icon('arrow-up'){{ $e['votes_count'] ?? 0 }}@if($paid && ($e['my_votes'] ?? 0))<small>dont {{ $e['my_votes'] }}</small>@endif</button></form>
                            @else
                                <span class="vote-chip static">@icon('arrow-up'){{ $e['votes_count'] ?? 0 }}</span>
                            @endif
                            @if (!empty($e['participant']) && auth()->check() && !in_array($e['id'], $v['my_entry_ids']))
                                @feature('gifts')<button type="button" class="gift-btn" data-gift data-gift-type="{{ $e['participant']['type'] }}" data-gift-id="{{ $e['participant']['id'] }}" data-gift-name="{{ $e['display_name'] }}" data-gift-comp="{{ $c['id'] }}" aria-label="Offrir des coins à {{ $e['display_name'] }}" title="Offrir des coins">@icon('gift', 'ico-sm')</button>@endfeature
                            @endif
                            @if ($v['is_juror'] && in_array($c['status'], ['open', 'live']))
                                <form method="post" action="/c/{{ $c['slug'] }}/jury/{{ $e['id'] }}" class="row" style="margin-top:8px;justify-content:flex-end;flex-wrap:nowrap">@csrf
                                    <label class="sr-only" for="score-{{ $e['id'] }}">Note du jury</label>
                                    <input class="input" id="score-{{ $e['id'] }}" type="number" name="score" min="0" max="10" style="width:72px;padding:8px" placeholder="/10">
                                    <button class="btn btn-sm">@icon('star', 'ico-sm')Noter</button></form>
                            @endif
                        @else
                            @if ($c['type'] === 'challenge')
                                <b class="vote-count">@icon('arrow-up', 'ico-sm'){{ $e['votes_count'] ?? 0 }}</b>
                            @else
                                <b>{{ $e['wins'] }}–{{ $e['losses'] }}</b>
                            @endif
                        @endif
                    </div>
                </article>
            @empty
                <div class="card empty"><div class="bubble">@icon('users')</div><h3>Aucun participant</h3><p>Sois le premier à te lancer.</p></div>
            @endforelse
        </div>
        @if ($c['type'] === 'contest' && $v['votes_left'] !== null && $c['status'] === 'live')
            <p class="mini-note" style="margin-top:10px">@icon('arrow-up', 'ico-sm')Encore {{ $v['votes_left'] }} vote{{ $v['votes_left'] > 1 ? 's' : '' }}</p>
        @endif
        @break

    @case('propositions')
        <section class="card card-pad">
            <h2>Représenter ton Espace</h2>
            <p class="muted">Propose ta participation : les gestionnaires de l'Espace choisiront qui l'envoie.</p>
            @if ($v['can_propose'] && $mySpaces->isNotEmpty())
                <button class="btn" data-open="proposer">@icon('sparkles', 'ico-sm')Faire une proposition</button>
            @else
                <a class="btn btn-soft" href="/espaces">@icon('users', 'ico-sm')Rejoindre un Espace</a>
            @endif
        </section>
        <div class="entries" style="margin-top:14px">
            @foreach ($proposals ?? [] as $p)
                <article class="entry">
                    <span class="pos">@icon('sparkles', 'ico-sm')</span>
                    <div style="min-width:0">
                        @include('web.partials.who', ['p' => \App\Support\Participant::present($p->user), 'sub' => 'pour '.$p->space->name])
                        @if ($p->content)<p class="muted" style="margin-top:8px">{{ $p->content }}</p>@endif
                        @if (count($p->mediaUrls()))<div class="media-strip">@include('web.partials.media', ['media' => $p->mediaUrls(), 'limit' => true])</div>@endif
                    </div>
                    <div class="votes">
                        @if ($p->status === 'pending' && $p->space->canManage(auth()->user()))
                            <form method="post" action="/c/{{ $c['slug'] }}/propositions/{{ $p->id }}">@csrf<button class="btn btn-sm">@icon('check', 'ico-sm')Choisir</button></form>
                        @else
                            <span class="chip {{ $p->status === 'selected' ? 'chip-success' : '' }}">{{ ['pending' => 'En attente', 'selected' => 'Choisie', 'declined' => 'Non retenue'][$p->status] }}</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        @break

    @case('gestion')
        <div class="stack">
            @if (\App\Support\Features::on('jury') && in_array($c['type'], ['contest', 'tournament']) && ($c['organizer']['type'] ?? '') === 'space' && $c['status'] !== 'completed')
                <section class="card card-pad">
                    <h2>@icon('scale', 'ico-sm') Jury</h2>
                    <p class="muted">{{ $c['type'] === 'tournament' ? 'Chaque juré vote dans chaque duel.' : 'Chaque juré note les candidats de 0 à 10.' }} Son poids s'ajoute aux votes du public.</p>
                    <form method="post" action="/c/{{ $c['slug'] }}/jury">@csrf
                        <label class="field"><span>Membres du jury</span><input class="input" name="jurors_text" value="{{ collect($c['jurors'])->map(fn ($j) => '@'.($j['handle'] ?? ''))->implode(', ') }}" placeholder="@pseudo1, @pseudo2"></label>
                        <span class="step-label">Poids du jury</span>
                        @include('web.partials.chips', ['name' => 'jury_weight', 'options' => [0 => 'Aucun', 30 => '30 %', 50 => '50 %', 70 => '70 %', 100 => 'Jury seul'], 'value' => $c['vote_audience'] === 'jury' ? 100 : (int) $c['jury_weight']])
                        <button class="btn" style="margin-top:12px">@icon('check', 'ico-sm')Enregistrer le jury</button>
                    </form>
                </section>
            @endif
            @if ($c['status'] === 'open' && in_array($c['type'], ['contest', 'tournament']))
                <section class="card card-pad">
                    <h2>Lancer maintenant</h2>
                    <p class="muted">{{ $c['type'] === 'tournament' ? 'Le tableau est tiré selon le classement des participants, puis le premier tour démarre.' : 'Les inscriptions ferment et les votes s\'ouvrent.' }}</p>
                    <form method="post" action="/c/{{ $c['slug'] }}/lancer">@csrf<button class="btn">@icon('play', 'ico-sm')Lancer avec {{ $c['entries_count'] }} participants</button></form>
                </section>
            @endif
            @if (!empty($pending) && count($pending))
                <section class="card card-pad">
                    <h2>Candidatures à valider</h2>
                    @foreach ($pending as $e)
                        <div class="spread" style="padding:12px 0;border-bottom:1px solid var(--border)">
                            <div style="min-width:0">@if($e['participant']) @include('web.partials.who', ['p' => $e['participant']]) @endif
                                @if ($e['content'])<p class="small muted" style="margin:6px 0 0">{{ \Illuminate\Support\Str::limit($e['content'], 300) }}</p>@endif
                                @if (count($e['media']))<div class="media-strip" style="display:flex;gap:8px;margin-top:8px;overflow-x:auto">@foreach ($e['media'] as $md)@if ($md['type'] === 'image')<img loading="lazy" decoding="async" src="{{ $md['url'] }}" alt="" data-zoom style="height:120px;width:auto;border-radius:10px">@elseif ($md['type'] === 'video')<video src="{{ $md['url'] }}" controls preload="metadata" style="height:120px;width:auto;border-radius:10px"></video>@endif @endforeach</div>@endif</div>
                            <form method="post" action="/c/{{ $c['slug'] }}/candidatures/{{ $e['id'] }}" class="row" style="flex-wrap:nowrap">@csrf
                                <button class="btn btn-sm" name="decision" value="approve">@icon('check', 'ico-sm')Accepter</button>
                                <button class="btn btn-sm btn-ghost" name="decision" value="reject">@icon('x', 'ico-sm')Refuser</button></form>
                        </div>
                    @endforeach
                </section>
            @endif
            @if ($c['status'] === 'open')
                <section class="card card-pad">
                    <h2>Inviter</h2>
                    <form method="post" action="/c/{{ $c['slug'] }}/inviter" style="margin-top:12px">@csrf
                        <label class="field"><span>Pseudos et Espaces</span><input class="input" name="handles" placeholder="@pseudo, #mon-espace"><small>@ pour une personne, # pour un Espace.</small></label>
                        <label class="field"><span>Message</span><input class="input" name="message" maxlength="280" placeholder="On vous attend !"></label>
                        <button class="btn">@icon('send', 'ico-sm')Envoyer</button></form>
                </section>
                @if (in_array($c['type'], ['contest', 'tournament']))
                    <section class="card card-pad">
                        <h2>Ajouter un candidat</h2>
                        <form method="post" action="/c/{{ $c['slug'] }}/candidats" enctype="multipart/form-data" style="margin-top:12px">@csrf
                            <div class="grid-2">
                                <label class="field"><span>Nom affiché</span><input class="input" name="label" required maxlength="80"></label>
                                <label class="field"><span>Pseudo (facultatif)</span><input class="input" name="username" placeholder="si la personne a un compte"></label>
                            </div>
                            <label class="field"><span>Présentation</span><textarea class="input" name="content"></textarea></label>
                            @include('web.partials.picker', ['name' => 'media[0][file]', 'meta' => 'media[0][metadata]', 'label' => 'Média du candidat', 'size' => 'small'])
                            <button class="btn" style="margin-top:14px">@icon('user-plus', 'ico-sm')Ajouter</button></form>
                    </section>
                @endif
            @endif
            @if (in_array($c['status'], ['open', 'live']))
                <section class="card card-pad">
                    <h2>Annuler la compétition</h2>
                    <p class="muted">Les duels en cours s'arrêtent et les participants sont prévenus. Impossible de revenir en arrière.</p>
                    <form method="post" action="/c/{{ $c['slug'] }}/annuler">@csrf
                        <label class="field"><span>Raison (envoyée aux participants)</span><input class="input" name="reason" maxlength="200"></label>
                        <button class="btn btn-danger">@icon('x', 'ico-sm')Annuler définitivement</button></form>
                </section>
            @endif
        </div>
        @break
@endswitch



@if ($v['can_enter'] && ! $v['invitation'])
    @include('web.partials.entry-form', [
        'id' => 'participer', 'title' => $verb, 'action' => '/c/'.$c['slug'].'/participer',
        'types' => $c['media_types'], 'identities' => $v['enter_as'], 'submit' => ($c['application'] ?? false) ? 'Envoyer ma candidature' : $verb,
        'application' => $c['application'] ?? false,
        'hint' => trim(($c['type'] === 'challenge' ? 'Ton duel contre '.($c['organizer']['name'] ?? 'l\'organisateur').' commence dès l\'envoi. ' : '')
            .($c['type'] === 'contest' && !($c['application'] ?? false) ? 'Envoie ta meilleure performance : c\'est elle que le public verra et votera. ' : '')
            .($c['type'] === 'tournament' ? 'Ta performance te représente dans chacun de tes duels. Les adversaires sont tirés au sort à la clôture. ' : '')
            .(!empty($c['entry_format_label']) && !($c['application'] ?? false) ? 'Réponds en '.mb_strtolower($c['entry_format_label']).', comme le créateur.' : '')) ?: null,
    ])
@endif
@if ($v['invitation'])
    @include('web.partials.entry-form', [
        'id' => 'repondre', 'title' => 'Tu as été défié', 'action' => '/invitations/'.$v['invitation']['id'],
        'types' => $c['media_types'], 'hint' => $v['invitation']['message'] ? '« '.$v['invitation']['message'].' »' : 'Envoie ta réponse : le duel démarre aussitôt.',
        'buttons' => [['label' => 'Accepter le duel', 'name' => 'decision', 'value' => 'accept', 'class' => ''], ['label' => 'Refuser', 'name' => 'decision', 'value' => 'decline', 'class' => 'btn-ghost']],
    ])
@endif
@if ($tab === 'propositions' && $v['can_propose'] && $mySpaces->isNotEmpty())
    @include('web.partials.entry-form', [
        'id' => 'proposer', 'title' => 'Proposer pour mon Espace', 'action' => '/c/'.$c['slug'].'/proposer',
        'types' => $c['media_types'], 'submit' => 'Envoyer ma proposition',
        'identities' => $mySpaces->map(fn ($s) => \App\Support\Participant::present($s))->all(),
        'identityField' => 'space', 'identityValue' => 'slug',
    ])
@endif

{{-- Modifier : seulement ce qui est encore permis (voir CompetitionEditor). La vidéo publiée ne change pas. --}}
@if ($isOrg && !$over && \App\Support\Features::on('editing'))
@php $ed = $edit ?? ['can' => false, 'fields' => [], 'note' => null, 'text' => null]; $has = fn ($f) => in_array($f, $ed['fields'], true); @endphp
<dialog class="sheet" id="edit-sheet" aria-labelledby="edit-title">
    <div class="sheet-head"><h2 id="edit-title">Modifier</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <div class="sheet-body">
        @if (! $ed['can'])
            <p class="edit-locked">@icon('lock', 'ico-sm')<span>{{ $ed['note'] }}</span></p>
            @if ($c['status'] === 'open' || $c['status'] === 'live')<p class="muted small">Pour changer quand même, annule la publication et publie-la à nouveau.</p>@endif
        @else
            <form class="edit-form" method="post" action="/c/{{ $c['slug'] }}/modifier">@csrf
                @if ($has('title'))
                    <textarea class="edit-title" name="title" rows="1" maxlength="120" placeholder="Titre" aria-label="Titre" data-autogrow data-edit-title @if($big) required minlength="3" @endif>{{ old('title', $c['title']) }}</textarea>
                @else
                    <p class="edit-title is-fixed">{{ $c['title'] }}</p>
                @endif
                @if ($has('text'))
                    <textarea class="edit-text" name="text" rows="3" maxlength="{{ $big ? 5000 : 1000 }}" data-emoji data-autogrow aria-label="{{ $big ? 'Description' : 'Texte' }}" placeholder="{{ $big ? 'Description' : 'Ton texte' }}">{{ old('text', $ed['text']) }}</textarea>
                @endif
                @if ($has('category'))
                    <div class="edit-row">@include('web.partials.category-field', ['value' => old('category', $c['category'] ?? '')])</div>
                @endif
                @if ($has('prize'))
                    <label class="edit-row"><span class="qc-label">Récompense</span><input class="input" name="prize" maxlength="255" value="{{ old('prize', $c['prize']) }}" placeholder="Ex. 100 $ et un trophée"></label>
                @endif
                @if ($has('rules'))
                    <label class="edit-row"><span class="qc-label">Règlement</span><textarea class="input" name="rules" rows="3" maxlength="5000" data-autogrow>{{ old('rules', $c['rules']) }}</textarea></label>
                @endif
                @if ($ed['note'])<p class="edit-note">@icon('info', 'ico-sm')<span>{{ $ed['note'] }}</span></p>@endif
                @if (count($heroMedia) && !$big)<p class="edit-note">@icon('video', 'ico-sm')<span>La vidéo, la photo ou l'audio publiés ne peuvent pas être changés.</span></p>@endif
                <button class="btn btn-lg btn-block">@icon('check', 'ico-sm')Enregistrer</button>
            </form>
        @endif
    </div>
</dialog>
@endif

{{-- Infos : comment participer, règles, jury, dates. Ouvert par ⓘ ou « Comment ça marche ? » --}}
<dialog class="sheet" id="infos-sheet" aria-labelledby="infos-title">
    <div class="sheet-head"><h2 id="infos-title">Infos</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <div class="sheet-body">
        @if ($big && $c['status'] === 'open' && !$isOrg && !$v['invitation'])
            @include('web.partials.join-guide', ['c' => $c, 'v' => $v, 'inSheet' => true])
        @endif
        <section class="infos-tab">
            @if ($c['description'])<p class="infos-desc">{!! \App\Support\Mentions::html($c['description'], $c['mentions'] ?? []) !!}</p>@endif
            <ul class="info-list">
                <li>@icon($typeIcon)<span><b>{{ $c['type_label'] }}@if(!empty($c['entry_format_label'])) en {{ mb_strtolower($c['entry_format_label']) }}@endif</b>@if($c['category_label'] ?? null){{ $c['category_label'] }}@endif</span></li>
                @if ($c['vote_cost'] ?? 0)<li class="gift">@icon('coin')<span><b>Votes payants : {{ $c['vote_cost'] }} coin{{ $c['vote_cost'] > 1 ? 's' : '' }} par vote</b>{{ $c['type'] === 'contest' ? 'Vote autant de fois que tu veux, pour un seul candidat.' : 'Vote autant de fois que tu veux dans chaque duel, pour un seul camp.' }} Les coins vont à l'organisateur.</span></li>@endif
                @if (count($c['prize_tiers']))
                    <li class="gift">@icon('gift')<span><b>Récompenses</b>
                        <span class="prize-list">@foreach ($c['prize_tiers'] as $t)<span><em>{{ $t['place'] }}</em>{{ implode(' + ', array_filter([$t['text'], $t['coins'] ? number_format($t['coins'], 0, ',', ' ').' coins'.($t['to'] > $t['from'] ? ' chacun' : '') : null])) }}</span>@endforeach</span>
                        @if ($c['prize_coins_locked'])<small class="muted">Les {{ number_format($c['prize_coins_locked'], 0, ',', ' ') }} coins sont déjà bloqués : ils seront envoyés aux gagnants à la fin.</small>@endif
                    </span></li>
                @elseif ($c['prize'])<li class="gift">@icon('gift')<span><b>Récompense</b>{{ $c['prize'] }}</span></li>@endif
                <li>@icon('users')<span><b>{{ $c['entries_count'] }}{{ $c['max_entries'] ? ' / '.$c['max_entries'] : '' }} participants</b>{{ $c['participation_label'] ?? '' }}</span></li>
                <li>@icon('arrow-up')<span><b>{{ $c['votes_count'] }} votes</b>{{ $c['views_count'] }} personnes l'ont vu</span></li>
                @if ($c['ends_at'])<li>@icon('clock')<span><b>Fin</b>{{ \Illuminate\Support\Carbon::parse($c['ends_at'])->locale('fr')->isoFormat('dddd D MMMM, HH:mm') }}</span></li>@endif
                @if (count($c['jurors']))<li>@icon('scale')<span><b>Jury{{ ($c['jury_weight'] ?? 0) ? ' : '.$c['jury_weight'].' % du résultat' : '' }}</b>@foreach ($c['jurors'] as $j){{ $j['name'] }}@if(!$loop->last), @endif @endforeach</span></li>@endif
            </ul>
            <h3 class="infos-h">Comment ça marche</h3>
            <ul class="rules">@foreach ($c['rules_summary'] as $line)<li>@icon('check-circle'){{ $line }}</li>@endforeach</ul>
            @if ($c['rules'])<h3 class="infos-h">Règlement</h3><p style="white-space:pre-line">{{ $c['rules'] }}</p>@endif
            <button type="button" class="link-act faint" data-report-open="competition:{{ $c['id'] }}" style="margin-top:18px">@icon('flag', 'ico-sm')Signaler</button>
        </section>
        @if ($c['participation_mode'] !== 'individual' && auth()->check())
            <a class="btn btn-soft btn-block" href="?tab=propositions" style="margin-top:14px">@icon('sparkles', 'ico-sm')Représenter</a>
        @endif
    </div>
</dialog>
@if (!empty($openSheet))
    <script>document.addEventListener('DOMContentLoaded', () => { {!! $openSheet === 'infos' ? "document.getElementById('infos-sheet')?.showModal();" : '' !!} });</script>
@endif
@endsection
