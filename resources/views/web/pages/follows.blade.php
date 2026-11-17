@extends('web.layout')
@section('content')
@php $h = '@'.$owner->username; $isMe = auth()->id() === $owner->id; @endphp
<div class="follows-head">
    <a class="icon-btn" href="/{{ $h }}" aria-label="Retour au profil">@icon('arrow-left')</a>
    <div class="grow"><h1>{{ \App\Support\Participant::name($owner) }}</h1><small>{{ $h }}</small></div>
</div>
<nav class="seg follows-seg" aria-label="Abonnés et abonnements">
    <a href="/{{ $h }}/abonnes" @if($list === 'abonnes') aria-current="page" @endif>{{ $followersCount }} abonné{{ $followersCount > 1 ? 's' : '' }}</a>
    <a href="/{{ $h }}/abonnements" @if($list === 'abonnements') aria-current="page" @endif>{{ $followingCount }} abonnement{{ $followingCount > 1 ? 's' : '' }}</a>
</nav>

@if ($people->total() > 12 || $q)
    <form class="follows-search" method="get" role="search">
        @icon('search', 'ico-sm')<input name="q" value="{{ $q }}" placeholder="Rechercher" aria-label="Rechercher une personne" autocomplete="off">
    </form>
@endif

@if ($people->count())
    <ul class="follows-list">
        @foreach ($people as $u)
            @php $p = \App\Support\Participant::present($u); $isFollowing = isset($mineIds[$u->id]); @endphp
            <li>
                <a class="follows-who" href="{{ $p['url'] }}">
                    @include('web.partials.avatar', ['p' => $p])
                    <span class="grow"><strong>{{ $p['name'] }}@if($p['is_verified'])@icon('check-circle', 'verified')@endif</strong>
                        <small>{{ '@'.$p['handle'] }}@if(isset($followsMe[$u->id]) && auth()->id() !== $u->id) · te suit @endif</small></span>
                </a>
                @auth
                    @if (auth()->id() !== $u->id)
                        <form method="post" action="/suivre/{{ $p['handle'] }}" data-ajax data-silent data-toggle-text="{{ $isFollowing ? 'Suivre' : 'Abonné' }}">@csrf
                            <button class="btn btn-sm {{ $isFollowing ? 'btn-ghost' : 'btn-soft' }}">{{ $isFollowing ? 'Abonné' : (isset($followsMe[$u->id]) ? 'Suivre en retour' : 'Suivre') }}</button></form>
                    @endif
                @endauth
            </li>
        @endforeach
    </ul>
    @if ($people->hasMorePages())<a class="btn btn-ghost btn-block" href="{{ $people->nextPageUrl() }}" style="margin-top:12px">Voir plus</a>@endif
@else
    <div class="card empty"><div class="bubble">@icon('users')</div>
        @if ($q)<h3>Personne ne s'appelle ainsi ici</h3>
        @elseif ($list === 'abonnes')<h3>{{ $isMe ? 'Personne ne te suit encore' : 'Pas encore d\'abonnés' }}</h3><p>{{ $isMe ? 'Lance un défi : ceux qui votent pour toi te suivront.' : '' }}</p>
        @else<h3>{{ $isMe ? 'Tu ne suis personne' : 'Ne suit personne pour le moment' }}</h3>@if($isMe)<p>Suis des gens pour voir leurs défis en premier dans ton fil.</p><a class="btn" href="/decouvrir">@icon('search', 'ico-sm')Trouver des gens</a>@endif
        @endif
    </div>
@endif
@endsection
