@extends('web.layout')
@section('content')
@php
    $rows = $d['rows'];
    $val = fn ($r) => $r['points'].' pts';
    $isSpaces = $d['kind'] === 'space';
@endphp
<div class="page-head rank-head">
    <h1>Classement</h1>
    {{-- Cette semaine (tout le monde repart de zéro le lundi) ou toute la saison --}}
    <nav class="rank-period" aria-label="Période">
        @php $keep = request()->except(['periode', 'page']); @endphp
        <a href="?{{ http_build_query($keep + ['periode' => 'semaine']) }}" @if($period === 'semaine') aria-current="page" @endif>Semaine</a>
        <a href="?{{ http_build_query(\Illuminate\Support\Arr::except($keep, ['saison']) + ['periode' => 'saison']) }}" @if($period === 'saison') aria-current="page" @endif>Saison</a>
    </nav>
</div>
{{-- Saison en cours ou saisons passées (classement final) --}}
@if ($period === 'saison')
<form class="season-pick" method="get" action="">
    @foreach (request()->except(['saison', 'page']) as $k => $v)@if(is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    @if (count($seasons) > 1)
        <label class="season-select">@icon('calendar', 'ico-sm')<select name="saison" aria-label="Choisir une saison" onchange="this.form.submit()">
            @foreach ($seasons as $s)<option value="{{ $s['value'] }}" @selected($s['value'] === $season)>{{ $s['label'] }}{{ $s['current'] ? ' (en cours)' : '' }}</option>@endforeach
        </select></label>
    @else
        <span class="season-select">@icon('calendar', 'ico-sm'){{ $d['season_label'] }}</span>
    @endif
    @if ($past)<span class="season-past">Saison terminée · classement final</span>@endif
</form>
@endif
{{-- Qui est classé --}}
<nav class="seg" aria-label="Classements">
    <a href="?kind=user&periode={{ $period }}{{ $sq }}" @if($d['kind'] === 'user') aria-current="page" @endif>@icon('user', 'ico-sm')Personnes</a>
    @feature('spaces')<a href="?kind=space&periode={{ $period }}{{ $sq }}" @if($isSpaces) aria-current="page" @endif>@icon('users', 'ico-sm')Espaces</a>@endfeature
    <a href="?kind=city&periode={{ $period }}{{ $sq }}" @if($d['kind'] === 'city') aria-current="page" @endif>@icon('map-pin', 'ico-sm')Villes</a>
</nav>

@if ($d['kind'] === 'user' && $full)
    {{-- Trier : par discipline, par ville, par institution --}}
    <form class="rank-filters" method="get">
        <input type="hidden" name="kind" value="user"><input type="hidden" name="vue" value="tout"><input type="hidden" name="periode" value="{{ $period }}">@if($past)<input type="hidden" name="saison" value="{{ $season }}">@endif
        <label class="rank-filter">@icon('sparkles', 'ico-sm')<select name="discipline" aria-label="Discipline" onchange="this.form.submit()"><option value="">Toutes disciplines</option>@foreach ($categories as $k => $label)<option value="{{ $k }}" @selected($category === $k)>{{ $label }}</option>@endforeach</select></label>
        <label class="rank-filter">@icon('map-pin', 'ico-sm')<select name="ville" aria-label="Ville" onchange="this.form.submit()"><option value="">Toutes les villes</option>@foreach ($cities as $c)<option @selected($city === $c)>{{ $c }}</option>@endforeach</select></label>
        @feature('spaces')
        <label class="rank-filter">@icon('school', 'ico-sm')<select name="espace" aria-label="Institution" onchange="this.form.submit()"><option value="">Toutes les institutions</option>
            @if ($space && $space->kind !== 'school')<option value="{{ $space->slug }}" selected>{{ $space->name }}</option>@endif
            @foreach ($institutions as $sc)<option value="{{ $sc->slug }}" @selected($space && $space->id === $sc->id)>{{ $sc->name }}</option>@endforeach</select></label>
        @endfeature
        <noscript><button class="btn btn-sm">Afficher</button></noscript>
    </form>
@elseif ($isSpaces)
    <nav class="chips rank-types" aria-label="Type d'Espace">
        <a class="chip {{ ! $type ? 'on' : '' }}" href="?kind=space&periode={{ $period }}{{ $sq }}" @if(!$type) aria-current="true" @endif>Tous</a>
        @foreach ($spaceTypes as $k => $t)
            <a class="chip {{ $type === $k ? 'on' : '' }}" href="?kind=space&periode={{ $period }}{{ $sq }}&type={{ $k }}" @if($type === $k) aria-current="true" @endif>@icon($t['icon'], 'ico-sm'){{ $t['label'] }}</a>
        @endforeach
    </nav>
@endif

@if ($d['kind'] !== 'user')
    <p class="rank-note">@icon('info', 'ico-sm'){{ $isSpaces ? 'Les points de l\'Espace et ceux de ses membres, additionnés.' : 'Les points des personnes de chaque ville, additionnés.' }} Touche une ligne pour voir ses meilleurs.</p>
    @if (count($groups))
        <ol class="rank-list">
            @foreach ($groups as $g)
                <li>
                    <a href="{{ $g['url'] }}">
                        <span class="rank-pos">@if($g['rank'] === 1)@icon('crown', 'ico-sm')@else{{ $g['rank'] }}@endif</span>
                        @if ($g['participant'])@include('web.partials.avatar', ['p' => $g['participant']])@else<span class="avatar city-dot">@icon('map-pin', 'ico-sm')</span>@endif
                        <span class="rank-who"><strong>{{ $g['name'] }}</strong>
                            <span class="rank-sub one">{{ !empty($g['label']) ? $g['label'].', ' : '' }}{{ $g['members'] }} classé{{ $g['members'] > 1 ? 's' : '' }}, {{ $g['wins'] }} victoire{{ $g['wins'] > 1 ? 's' : '' }}</span></span>
                        <span class="rank-val"><b>{{ $g['points'] }}</b><small>pts</small></span>
                    </a>
                </li>
            @endforeach
        </ol>
    @else
        <div class="card empty"><div class="bubble">@icon($isSpaces ? 'users' : 'map-pin')</div>
            <h3>{{ $isSpaces ? 'Aucun Espace classé' : 'Aucune ville classée' }}</h3>
            <p>{{ $isSpaces ? 'Rejoins ton Espace ou choisis ton institution sur ton profil, puis gagne des duels.' : 'Indique ta ville sur ton profil, puis gagne des duels.' }}</p>
            @auth<a class="btn" href="/parametres/profil">@icon('edit', 'ico-sm')Compléter mon profil</a>@endauth</div>
    @endif
@elseif (! $full)
    {{-- Ce qui compte pour moi : ma place et mes voisins, mes abonnements, ma ville, ma discipline, puis le top 10 --}}
    @auth
        @if ($d['me'])
            <section class="rank-me">
                <p class="rank-me-line"><b>{{ $d['me']['rank'] }}<sup>{{ $d['me']['rank'] === 1 ? 'er' : 'e' }}</sup></b><span>sur {{ $d['me']['total'] }} · {{ $d['me']['points'] }} pt{{ $d['me']['points'] > 1 ? 's' : '' }} {{ $period === 'semaine' ? 'cette semaine' : ($past ? 'sur cette saison' : 'cette saison') }}</span></p>
                @if (count($d['me']['rows']))
                    @include('web.partials.rank-rows', ['rows' => $d['me']['rows']])
                @endif
            </section>
        @else
            <div class="rank-me rank-me-empty">
                <p><b>{{ $past ? 'Pas de points sur cette saison' : 'Pas encore de points '.($period === 'semaine' ? 'cette semaine' : 'cette saison') }}.</b> Gagne un duel pour entrer dans le classement{{ $period === 'semaine' ? ' : chaque lundi, tout le monde repart de zéro' : '' }}.</p>
                <a class="btn btn-sm" href="/creer">@icon('challenge', 'ico-sm')Lancer un défi</a>
            </div>
        @endif
    @endauth
    @forelse ($boards as $b)
        <section class="rank-board">
            <h2>@icon($b['icon'], 'ico-sm'){{ $b['title'] }}@if($b['more'])<a href="{{ $b['more'] }}">Voir tout</a>@endif</h2>
            @include('web.partials.rank-rows', ['rows' => $b['rows']])
        </section>
    @empty
        <div class="card empty"><div class="bubble">@icon('crown')</div><h3>Personne n'est encore classé {{ $period === 'semaine' ? 'cette semaine' : '' }}</h3><p>Le premier duel gagné t'y place.</p><a class="btn" href="/creer">@icon('challenge', 'ico-sm')Lancer un défi</a></div>
    @endforelse
@else
@if ($city || $space || $category !== 'all')
    <p class="rank-scope">@icon($space ? 'school' : ($city ? 'map-pin' : 'sparkles'), 'ico-sm')Les meilleurs{{ $category !== 'all' ? ' en '.mb_strtolower($categories[$category]) : '' }}{{ $space ? ' de '.$space->name : '' }}{{ $city ? ' à '.$city : '' }}<a href="?kind=user&vue=tout&periode={{ $period }}{{ $sq }}" aria-label="Enlever ce filtre">@icon('x', 'ico-sm')</a></p>
@endif
@if (count($rows) >= 3)
    <div class="podium">
        @foreach ([1, 0, 2] as $idx)
            @php $r = $rows[$idx]; @endphp
            <a class="place p{{ $idx + 1 }}" href="{{ $r['participant']['url'] }}">
                @if ($idx === 0)@icon('crown', 'crown')@else<span class="medal-num">{{ $idx + 1 }}</span>@endif
                @include('web.partials.avatar', ['p' => $r['participant'], 'size' => $idx === 0 ? 'md' : ''])
                <strong>{{ $r['participant']['name'] }}</strong>
                <b>{{ $val($r) }}</b><span class="muted">{{ $r['wins'] }} V, {{ $r['losses'] }} D</span>
            </a>
        @endforeach
    </div>
@endif
@if (count($rows))
<ol class="rank-list">
    @foreach ($rows as $r)
        @php $played = $r['wins'] + $r['losses']; $isMe = auth()->check() && $r['participant']['type'] === 'user' && $r['participant']['id'] === auth()->id(); @endphp
        <li class="{{ $isMe ? 'me' : '' }}">
            <a href="{{ $r['participant']['url'] }}">
                <span class="rank-pos">{{ $r['rank'] }}</span>
                @include('web.partials.avatar', ['p' => $r['participant']])
                <span class="rank-who">
                    <strong>{{ $r['participant']['name'] }}</strong>
                    <span class="rank-sub">
                        <span class="mini-split" aria-hidden="true"><i style="width: {{ $played ? round($r['wins'] / $played * 100) : 0 }}%"></i></span>
                        {{ $r['wins'] }} V · {{ $r['losses'] }} D
                        @if ($r['streak'] > 1)<span class="streak">@icon('flame'){{ $r['streak'] }}</span>@endif
                        @if ($r['trophies'])<span class="streak gold">@icon('trophy'){{ $r['trophies'] }}</span>@endif
                    </span>
                </span>
                <span class="rank-val"><b>{{ $sort === 'points' ? $r['points'] : $r['rating'] }}</b><small>{{ $sort === 'points' ? 'pts' : 'Elo' }}</small></span>
            </a>
        </li>
    @endforeach
</ol>
@else
    <div class="card empty"><div class="bubble">@icon('crown')</div><h3>Personne n'est encore classé ici</h3><p>Le premier duel gagné t'y place.</p><a class="btn" href="/creer">@icon('challenge', 'ico-sm')Lancer un défi</a></div>
@endif
@endif
@endsection
