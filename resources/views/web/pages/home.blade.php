@extends('web.layout')
@section('content')
@guest
    <section class="card welcome">
        <h1>Défie, affronte, fais voter.</h1>
        <p>Publie ta meilleure performance, laisse les autres venir te défier, et que le public tranche. Seul, ou avec ton Espace contre un autre.</p>
        <div class="row">
            @feature('registration')<a class="btn btn-lg" href="/inscription">@icon('user-plus', 'ico-sm')Créer un compte</a>@endfeature
            <a class="btn btn-outline btn-lg hide-sm" href="/?vue=direct">@icon('vs', 'ico-sm')Voir les duels</a>
        </div>
        <div class="welcome-steps hide-sm">
            <div><b>@icon('challenge')Lance</b>Un défi, un duel, un concours ou un tournoi.</div>
            <div><b>@icon('vs')Affronte</b>Chaque challenger est un nouveau duel.</div>
            <div><b>@icon('arrow-up')Fais voter</b>Le public soutient son camp, les scores sont visibles.</div>
        </div>
    </section>
@endguest

@if (auth()->check() || \App\Models\CompetitionMatch::where('status', 'voting')->where('ends_at', '>', now())->exists())
<div class="card" style="margin-bottom:14px">
    @include('web.partials.stories')
</div>
@endif

@auth
    {{-- Proposé seulement quand le téléphone sait installer l'application --}}
    <div class="card install-banner" data-install hidden>
        <img loading="lazy" decoding="async" src="/icons/icon-192.png" alt="" width="40" height="40">
        <span class="grow"><b>Installe Sosa</b><small>Plus rapide, et moins de données consommées.</small></span>
        <button type="button" class="btn btn-sm" data-install-go>@icon('download', 'ico-sm')Installer</button>
        <button type="button" class="icon-btn" data-install-dismiss aria-label="Plus tard">@icon('x', 'ico-sm')</button>
    </div>
    <section class="card composer">
        <div class="composer-top">
            @include('web.partials.avatar', ['p' => \App\Support\Participant::present(auth()->user())])
            <a class="composer-input" href="/creer"><span class="hide-sm">Lance un défi, un duel ou un concours…</span><span class="show-sm">Lance un défi…</span></a>
        </div>
        <div class="composer-actions">
            <a href="/creer?type=challenge">@icon('challenge')<span>Défi</span></a>
            <a href="/creer?type=duel">@icon('vs')<span>Duel</span></a>
            <a href="/creer?type=contest">@icon('medal')<span>Concours</span></a>
            <a href="/creer?type=tournament">@icon('bracket')<span>Tournoi</span></a>
        </div>
    </section>
    @if ($invitations > 0)
        <a class="flash flash-info" href="/defis-recus">@icon('vs')<span>Tu as {{ $invitations }} défi{{ $invitations > 1 ? 's' : '' }} en attente de réponse</span><span class="push">@icon('chevron-right', 'ico-sm')</span></a>
    @endif
@endauth

<nav class="tabs feed-tabs" aria-label="Fil">
    <a href="/" @if($view === 'fil') aria-current="page" @endif>Pour toi</a>
    <a href="/?vue=direct" @if($view === 'direct') aria-current="page" @endif><span class="tab-lbl">Duels @if($liveCount)<span class="n">{{ $liveCount }}</span>@endif</span></a>
    <a href="/?vue=ouvertes" @if($view === 'ouvertes') aria-current="page" @endif>À rejoindre</a>
</nav>

<div class="feed" @if($view === 'fil') data-feed @endif>
    @if ($view === 'direct')
        @if ($matches->count() && \App\Support\Features::on('swipe'))<a class="swipe-cta" href="/voter"><span class="swipe-cta-ico">@icon('arrow-left')@icon('arrow-right')</span><span class="grow"><b>Voter en glissant</b><small>Un duel à la fois, plein écran</small></span>@icon('chevron-right')</a>@endif
        @forelse ($matches as $m)
            @include('web.partials.versus', ['m' => $m, 'compact' => true])
        @empty
            <div class="card empty"><div class="bubble">@icon('vs')</div><h3>Aucun duel en cours</h3><p>Lance un défi : chaque challenger ouvre un nouveau duel.</p><a class="btn" href="/creer">@icon('challenge', 'ico-sm')Lancer un défi</a></div>
        @endforelse
    @elseif ($view === 'ouvertes')
        @forelse ($open as $c)
            @include('web.partials.competition-card', ['c' => $c])
        @empty
            <div class="card empty"><div class="bubble">@icon('trophy')</div><h3>Rien d'ouvert pour l'instant</h3><p>Lance le premier concours ou tournoi.</p><a class="btn" href="/creer?type=contest">@icon('medal', 'ico-sm')Lancer un concours</a></div>
        @endforelse
    @else
        @if ($askInterests && count($blocks) < 2)@include('web.partials.ask-interests')@endif
        @if ($askCity && count($blocks) < 2)@include('web.partials.ask-city')@endif
        @forelse ($blocks as $bi => $b)
            {{-- Au fil du défilement : des personnes à suivre, puis des Espaces à rejoindre --}}
            @if ($bi === 1 && $askInterests)@include('web.partials.ask-interests')@endif
            @if ($bi === 1 && $askCity)@include('web.partials.ask-city')@endif
            @if ($bi === 2)@include('web.partials.suggest-strip', ['kind' => 'people', 'items' => \App\Support\Suggestions::people(auth()->user())])@endif
            @if ($bi === 6 && \App\Support\Features::on('spaces'))@include('web.partials.suggest-strip', ['kind' => 'spaces', 'items' => \App\Support\Suggestions::spaces(auth()->user())])@endif
            @if ($b['type'] === 'post')
                @include('web.partials.activity', ['a' => $b['item']])
            @else
                @include('web.partials.brief', ['items' => $b['items']])
            @endif
        @empty
            <div class="card empty">
                <div class="bubble">@icon('sparkles')</div>
                <h3>Ton fil est encore calme</h3>
                <p>Suis des créateurs, rejoins un Espace ou lance le premier défi.</p>
                <div class="row" style="justify-content:center;margin-top:12px"><a class="btn" href="/creer">@icon('challenge', 'ico-sm')Lancer un défi</a><a class="btn btn-ghost" href="/espaces">@icon('users', 'ico-sm')Trouver un Espace</a></div>
            </div>
        @endforelse
    @endif
</div>
@if ($next)
    {{-- Défilement infini (le lien reste utilisable sans JavaScript) --}}
    <div class="feed-more" data-feed-more data-next="{{ $next }}"><span class="spinner" aria-hidden="true"></span><a class="btn btn-ghost" href="/?avant={{ $next }}">@icon('chevron-down', 'ico-sm')Voir plus</a></div>
@endif
@endsection
