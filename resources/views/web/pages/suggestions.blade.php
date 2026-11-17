@extends('web.layout')
@section('content')
{{-- « À suivre » : les personnes suggérées, même ville ou institution d'abord, puis les plus suivies --}}
<div class="follows-head">
    <a class="icon-btn" href="/" aria-label="Retour">@icon('arrow-left')</a>
    <div class="grow"><h1>À suivre</h1><small>Des gens de ta ville, de ton institution, et les plus suivis</small></div>
</div>
<form class="follows-search" method="get" role="search">
    @icon('search', 'ico-sm')<input name="q" value="{{ $q }}" placeholder="Chercher une personne (nom ou @pseudo)" aria-label="Chercher une personne" autocomplete="off" enterkeyhint="search">
</form>
@if (count($items))
    <ul class="follows-list">
        @foreach ($items as $p)
            <li>
                <a class="follows-who" href="{{ $p['url'] }}">
                    @include('web.partials.avatar', ['p' => $p])
                    <span class="grow"><strong>{{ $p['name'] }}@if($p['is_verified'] ?? false)@icon('check-circle', 'verified')@endif</strong>
                        <small>{{ '@'.$p['handle'] }} · {{ $p['sub'] }}</small></span>
                </a>
                @auth
                    @php $on = $p['following'] ?? false; @endphp
                    <form method="post" action="/suivre/{{ $p['handle'] }}" data-ajax data-silent data-toggle-text="{{ $on ? 'Suivre' : 'Abonné' }}">@csrf<button class="btn btn-sm {{ $on ? 'btn-ghost' : 'btn-soft' }}">{{ $on ? 'Abonné' : 'Suivre' }}</button></form>
                @else
                    <a class="btn btn-sm btn-soft" href="/connexion?suite={{ urlencode($p['url']) }}">Suivre</a>
                @endauth
            </li>
        @endforeach
    </ul>
    @if ($more)<a class="btn btn-ghost btn-block" href="/a-suivre?page={{ $page + 1 }}{{ $q !== '' ? '&q='.urlencode($q) : '' }}" style="margin-top:12px">Voir plus</a>@endif
@elseif ($q !== '')
    <div class="card empty"><div class="bubble">@icon('search')</div><h3>Personne ne s'appelle ainsi ici</h3><p>Vérifie l'orthographe ou essaie avec le @pseudo.</p></div>
@else
    <div class="card empty"><div class="bubble">@icon('users')</div><h3>Tu suis déjà tout le monde</h3><p>Reviens plus tard : de nouvelles personnes arrivent chaque jour.</p></div>
@endif
@endsection
