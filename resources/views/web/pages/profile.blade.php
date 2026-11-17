@extends('web.layout')
@section('content')
@php $p = $s['participant']; $cover = $owner->getRawOriginal('cover') ? $owner->cover : null; @endphp
<section class="card profile">
    {{-- La couverture et la photo de profil s'ouvrent en grand (la vidéo avec le son) --}}
    <div class="profile-cover">@if($cover)@if(($owner->cover_type ?? 'image') === 'video')<button type="button" class="cover-open" data-zoom-src="{{ $cover }}" data-zoom-type="video" aria-label="Voir la couverture en grand"><video src="{{ $cover }}#t=0.1" muted loop playsinline autoplay preload="metadata"></video></button>@else<img loading="lazy" decoding="async" src="{{ $cover }}" alt="" data-zoom>@endif @endif</div>
    <div class="profile-body">
        <div class="profile-top">
            @if (! empty($p['has_avatar']))<button type="button" class="avatar-open" data-zoom-src="{{ $p['avatar_url'] }}" aria-label="Voir la photo de profil en grand">@include('web.partials.avatar', ['p' => $p, 'size' => 'lg'])</button>@else @include('web.partials.avatar', ['p' => $p, 'size' => 'lg'])@endif
            <div class="row" style="padding-bottom:6px">
                @auth
                    @if ($isMe)
                        <a class="btn btn-ghost" href="/parametres/profil">@icon('edit', 'ico-sm')Modifier</a>
                        <button type="button" class="btn btn-ghost icon-only" data-open="parametres" aria-label="Paramètres">@icon('settings', 'ico-sm')</button>
                    @else
                        @feature('gifts')<button type="button" class="btn btn-ghost icon-only" data-gift data-gift-type="user" data-gift-id="{{ $p['id'] }}" data-gift-name="{{ $p['name'] }}" aria-label="Offrir des coins à {{ $p['name'] }}" title="Offrir des coins">@icon('gift', 'ico-sm')</button>@endfeature
                        <form method="post" action="/suivre/{{ $p['handle'] }}">@csrf<button class="btn {{ $following ? 'btn-ghost' : '' }}">@icon($following ? 'check' : 'user-plus', 'ico-sm'){{ $following ? 'Abonné' : 'Suivre' }}</button></form>
                        <a class="btn btn-coral" href="/creer?type=duel&adversaire={{ $p['handle'] }}">@icon('vs', 'ico-sm')Défier</a>
                    @endif
                @endauth
            </div>
        </div>
        <h1>{{ $p['name'] }}@if($p['is_verified'])@icon('check-circle', 'verified')@endif</h1>
        <div class="handle">{{ '@'.$p['handle'] }}@if($s['rank']) <span class="chip chip-primary" style="margin-left:6px">@icon('crown')#{{ $s['rank'] }} cette saison</span>@endif</div>
        @if ($owner->bio)<p class="bio">{{ $owner->bio }}</p>@endif
        <div class="counts"><a href="/{{ '@'.$p['handle'] }}/abonnes"><b>{{ $followers }}</b> abonné{{ $followers > 1 ? 's' : '' }}</a><a href="/{{ '@'.$p['handle'] }}/abonnements"><b>{{ $followingCount }}</b> abonnement{{ $followingCount > 1 ? 's' : '' }}</a><span><b>{{ $s['organized_count'] }}</b> compétitions organisées</span></div>
    </div>
</section>

@if ($isMe && \App\Support\Features::on('coins'))
    {{-- Le portefeuille n'est visible que par soi, ici sur son profil --}}
    <a class="card wallet-strip" href="/portefeuille">
        <span class="wallet-coin sm">@icon('coin')</span>
        <span class="grow"><b>{{ number_format($owner->coins, 0, ',', ' ') }} coins</b><small>Mon portefeuille</small></span>
        <span class="ws-acts"><span>Recharger</span>@feature('withdrawals')<span>Retirer</span>@endfeature</span>
        @icon('chevron-right', 'ico-sm faint')
    </a>
@endif

@include('web.partials.stats', ['s' => $s])

@if (count($s['badges']) && \App\Support\Features::on('badges'))
    <div class="card-title" style="margin-top:20px"><h2>Badges</h2></div>
    <div class="badges">@foreach ($s['badges'] as $b)<span class="badge" title="{{ $b['description'] }}"><i>@icon(\App\Support\Icons::BADGE[$b['code']] ?? 'award')</i>{{ $b['name'] }}</span>@endforeach</div>
@endif

@if (count($s['active_competitions']))
    <div class="card-title" style="margin-top:24px"><h2>En ce moment</h2></div>
    <div class="feed">@foreach ($s['active_competitions'] as $c) @include('web.partials.competition-card', ['c' => $c]) @endforeach</div>
@endif

@if (count($s['recent_matches']))
    <div class="card-title" style="margin-top:24px"><h2>Derniers duels</h2></div>
    <div class="feed">@foreach (collect($s['recent_matches'])->take(4) as $m) @include('web.partials.versus', ['m' => $m, 'compact' => true]) @endforeach</div>
@endif

<div class="card-title" style="margin-top:24px"><h2>Activité</h2></div>
<div class="feed">
    @forelse (\App\Http\Controllers\Web\PageController::feedBlocks($activities) as $b) @if ($b['type'] === 'post') @include('web.partials.activity', ['a' => $b['item']]) @else @include('web.partials.brief', ['items' => $b['items']]) @endif @empty <div class="card empty"><div class="bubble">@icon('sparkles')</div><p>Pas encore d'activité.</p></div> @endforelse
</div>
@if ($isMe)
<dialog id="parametres" class="sheet settings">
    <div class="sheet-head"><h2>Paramètres</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <a class="setting-row" href="/parametres/profil">@icon('edit')<span class="grow">Modifier le profil<small>Photo, couverture, nom, pseudo, bio</small></span>@icon('chevron-right', 'ico-sm')</a>
    <a class="setting-row" href="/parametres/mot-de-passe">@icon('lock')<span class="grow">Mot de passe<small>Le changer avec un code reçu par SMS</small></span>@icon('chevron-right', 'ico-sm')</a>
    @feature('push')<button type="button" class="setting-row" data-push hidden aria-pressed="false">@icon('bell')<span class="grow">Notifications sur ce téléphone<small data-push-label>Reçois une alerte quand on te défie ou vote</small></span><span class="switch-ui" aria-hidden="true"><i></i></span></button>@endfeature
    <button type="button" class="setting-row" data-install data-install-go hidden>@icon('download')<span class="grow">Installer Sosa<small>Sur l'écran d'accueil, comme une application</small></span>@icon('chevron-right', 'ico-sm')</button>
    <button type="button" class="setting-row theme-btn" data-theme-toggle>@icon('moon', 'moon')@icon('sun', 'sun')<span class="grow">Thème clair ou sombre</span><span class="switch-ui" aria-hidden="true"><i></i></span></button>
    <button type="button" class="setting-row" data-datasaver-toggle aria-pressed="false">@icon('radio')<span class="grow">Économie de données<small>Les vidéos ne se lancent pas toutes seules</small></span><span class="switch-ui" aria-hidden="true"><i></i></span></button>
    @feature('coins')<a class="setting-row" href="/portefeuille">@icon('wallet')<span class="grow">Mon portefeuille<small>Recharger, voir tes gains{{ \App\Support\Features::on('withdrawals') ? ', retirer' : '' }}</small></span>@icon('chevron-right', 'ico-sm')</a>@endfeature
    @feature('certification')<a class="setting-row" href="/parametres/certification">@icon('check-circle')<span class="grow">Certification<small>{{ $owner->verified_at ? 'Ton profil est certifié' : 'Obtenir le badge certifié' }}</small></span>@icon('chevron-right', 'ico-sm')</a>@endfeature
    <a class="setting-row" href="/defis-recus">@icon('vs')<span class="grow">Défis reçus</span>@icon('chevron-right', 'ico-sm')</a>
    <a class="setting-row" href="/notifications">@icon('bell')<span class="grow">Notifications</span>@icon('chevron-right', 'ico-sm')</a>
    <form method="post" action="/deconnexion">@csrf<button class="setting-row danger">@icon('log-out')<span class="grow">Se déconnecter</span></button></form>
</dialog>
@endif
@endsection
