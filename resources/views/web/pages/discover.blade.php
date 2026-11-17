@extends('web.layout')
@section('content')
<div class="page-head"><h1>Découvrir</h1></div>
@include('web.partials.discover-filters', ['filters' => ['type' => null, 'status' => 'en-cours', 'categorie' => null, 'mode' => null, 'q' => null]])
@feature('swipe')<a class="swipe-cta" href="/voter" style="margin-bottom:16px"><span class="swipe-cta-ico">@icon('arrow-left')@icon('arrow-right')</span><span class="grow"><b>Voter en glissant</b><small>Un duel à la fois, plein écran</small></span>@icon('chevron-right')</a>@endfeature

@if (count($d['hot_matches']))
    <div class="card-title" style="margin-top:8px"><h2>Duels chauds</h2></div>
    <div class="feed" style="margin-bottom:28px">
        @foreach ($d['hot_matches'] as $m)
            @include('web.partials.versus', ['m' => $m, 'compact' => true])
        @endforeach
    </div>
@endif

@if (count($d['ending_soon']))
    <div class="card-title"><h2>Derniers instants pour voter</h2></div>
    <div class="feed" style="margin-bottom:28px">
        @foreach ($d['ending_soon'] as $m)
            @include('web.partials.versus', ['m' => $m, 'compact' => true])
        @endforeach
    </div>
@endif

<div class="card-title"><h2>Défis ouverts</h2><a href="/decouvrir?type=challenge" aria-label="Tous les défis">@icon('chevron-right')</a></div>
<div class="feed" style="margin-bottom:28px">
    @forelse ($d['open_challenges'] as $c)
        @include('web.partials.competition-card', ['c' => $c])
    @empty
        <div class="card empty"><div class="bubble">@icon('challenge')</div><h3>Aucun défi ouvert</h3><p>Lance le premier et invite tes amis.</p><a class="btn" href="/creer">@icon('challenge', 'ico-sm')Lancer un défi</a></div>
    @endforelse
</div>

@if (count($d['space_battles']))
    <div class="card-title"><h2>Batailles d'Espaces</h2><a href="/decouvrir?mode=espaces" aria-label="Toutes les batailles">@icon('chevron-right')</a></div>
    <div class="feed" style="margin-bottom:28px">
        @foreach ($d['space_battles'] as $c)
            @include('web.partials.competition-card', ['c' => $c, 'withMatch' => true])
        @endforeach
    </div>
@endif

@if (count($d['open_registrations']))
    <div class="card-title"><h2>Inscriptions ouvertes</h2></div>
    <div class="feed" style="margin-bottom:28px">
        @foreach ($d['open_registrations'] as $c)
            @include('web.partials.competition-card', ['c' => $c])
        @endforeach
    </div>
@endif
@endsection
