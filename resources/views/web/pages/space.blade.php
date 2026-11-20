@extends('web.layout')
@section('content')
@php $p = $s['participant']; @endphp
<section class="card profile">
    <div class="profile-cover">@if($space->cover)@if(($space->cover_type ?? 'image') === 'video')<button type="button" class="cover-open" data-zoom-src="{{ $space->cover_url }}" data-zoom-type="video" aria-label="Voir la couverture en grand"><video src="{{ $space->cover_url }}#t=0.1" muted loop playsinline autoplay preload="metadata"></video></button>@else<img loading="lazy" decoding="async" src="{{ $space->cover_url }}" alt="" data-zoom>@endif @endif</div>
    <div class="profile-body">
        <div class="profile-top">
            @if (! empty($p['has_avatar']))<button type="button" class="avatar-open" data-zoom-src="{{ $p['avatar_url'] }}" aria-label="Voir la photo en grand">@include('web.partials.avatar', ['p' => $p, 'size' => 'lg'])</button>@else @include('web.partials.avatar', ['p' => $p, 'size' => 'lg'])@endif
            <div class="row" style="padding-bottom:6px">
                @auth
                    @if ($canEdit)<a class="btn btn-ghost" href="/espaces/{{ $space->slug }}/modifier">@icon('edit', 'ico-sm')<span class="hide-sm">Modifier</span></a>@endif
                    @if ($canManage)
                        <a class="btn btn-ghost" href="/espaces/{{ $space->slug }}/membres" aria-label="Membres">@icon('users', 'ico-sm')<span class="hide-sm">Membres</span>@if($pendingCount)<span class="count-dot">{{ $pendingCount }}</span>@endif</a>
                        @if ($canEdit && ! $space->is_verified && \App\Support\Features::on('certification'))<a class="btn btn-ghost icon-only" href="/parametres/certification?espace={{ $space->slug }}" aria-label="Certifier l'Espace" title="Certifier l'Espace">@icon('check-circle', 'ico-sm')</a>@endif
                    @elseif ($isInvited)
                        <form method="post" action="/espaces/{{ $space->slug }}/invitation">@csrf<input type="hidden" name="decision" value="accept"><button class="btn">@icon('check', 'ico-sm')Accepter l'invitation</button></form>
                        <form method="post" action="/espaces/{{ $space->slug }}/invitation">@csrf<input type="hidden" name="decision" value="refuse"><button class="btn btn-ghost" aria-label="Refuser l'invitation">@icon('x', 'ico-sm')<span class="hide-sm">Refuser</span></button></form>
                    @elseif (! $canPublish)
                        <form method="post" action="/espaces/{{ $space->slug }}/rejoindre">@csrf<button class="btn {{ $isMember || $isPending ? 'btn-ghost' : '' }}">@icon($isMember ? 'check' : ($isPending ? 'clock' : 'user-plus'), 'ico-sm'){{ $isMember ? 'Membre' : ($isPending ? 'Demande envoyée' : ($space->join_approval ? 'Demander à rejoindre' : 'Rejoindre')) }}</button></form>
                    @endif
                    @if ($canPublish)<a class="btn" href="/creer?espace={{ $space->slug }}" aria-label="Lancer une compétition">@icon('plus', 'ico-sm')<span class="hide-sm">Lancer</span></a>@endif
                    @unless ($canManage)@feature('gifts')<button type="button" class="btn btn-ghost icon-only" data-gift data-gift-type="space" data-gift-id="{{ $space->id }}" data-gift-name="{{ $space->name }}" aria-label="Offrir des coins à {{ $space->name }}" title="Offrir des coins">@icon('gift', 'ico-sm')</button>@endfeature@endunless
                    <a class="btn btn-coral" href="/creer?type=duel&adversaire={{ $space->slug }}&adversaire_type=space">@icon('vs', 'ico-sm')Défier</a>
                @endauth
            </div>
        </div>
        <h1>{{ $p['name'] }}@if($p['is_verified'])@icon('check-circle', 'verified')@endif</h1>
        @php $label = \App\Support\Rankings::spaceLabel($space); $site = \App\Http\Middleware\SpaceSite::urlFor($space); @endphp
        <div class="handle">{{ $label ?: 'Espace' }}@if($space->location) · {{ $space->location }}@endif</div>
        <div class="space-site-row">@feature('space_sites')
            {{-- Toujours l'adresse interne : les sous-domaines d'Espaces ne sont pas encore actifs --}}
            <a class="space-site" href="/espaces/{{ $space->slug }}/site" target="_blank" rel="noopener">@icon('globe', 'ico-sm')Voir le site</a>@endfeature
            {{-- Deux partages : le mini-site (son adresse) et la page de l'Espace sur Sosa --}}
            <button type="button" class="space-site icon" data-share="{{ url('/espaces/'.$space->slug.'/site') }}" data-title="{{ $space->name }}" data-text="Le site de {{ $space->name }} sur Sosa :" aria-label="Partager le mini-site">@icon('share', 'ico-sm')</button>
            <button type="button" class="space-site icon" data-share="{{ url('/espaces/'.$space->slug) }}" data-title="{{ $space->name }}" data-text="{{ $space->name }} sur Sosa :" aria-label="Partager l'Espace">@icon('link', 'ico-sm')</button>
        </div>
        @if ($space->bio)<p class="bio">{{ $space->bio }}</p>@endif
        <div class="counts"><span><b>{{ $space->members_count }}</b> membres</span><span><b>{{ $s['organized_count'] }}</b> compétitions</span>@if($s['rank'])<span><b>#{{ $s['rank'] }}</b> des Espaces</span>@endif</div>
    </div>
</section>

@include('web.partials.stats', ['s' => $s])
@if (count($s['badges']))
    <div class="badges" style="margin-bottom:8px">@foreach ($s['badges'] as $b)<span class="badge" title="{{ $b['description'] }}"><i>@icon(\App\Support\Icons::BADGE[$b['code']] ?? 'award')</i>{{ $b['name'] }}</span>@endforeach</div>
@endif

<div class="card-title" style="margin-top:20px"><h2>Compétitions</h2></div>
<div class="feed">
    @forelse ($competitions as $c) @include('web.partials.competition-card', ['c' => $c, 'withMatch' => true]) @empty
        <div class="card empty"><div class="bubble">@icon('trophy')</div><h3>Rien d'organisé pour l'instant</h3>@if($canManage)<a class="btn" href="/creer?espace={{ $space->slug }}">Lancer la première</a>@endif</div>
    @endforelse
</div>

<div class="card-title" style="margin-top:24px"><h2>Activité</h2></div>
<div class="feed">
    @forelse (\App\Http\Controllers\Web\PageController::feedBlocks($activities) as $b) @if ($b['type'] === 'post') @include('web.partials.activity', ['a' => $b['item']]) @else @include('web.partials.brief', ['items' => $b['items']]) @endif @empty <div class="card empty"><p>Pas encore d'activité.</p></div> @endforelse
</div>
{{-- Compétitions de l'Espace : un lien vers la page dédiée --}}
<a class="space-site" href="/espaces/{{ $space->slug }}/competitions">@icon('trophy', 'ico-sm')Voir les compétitions de l'Espace</a>

@endsection
