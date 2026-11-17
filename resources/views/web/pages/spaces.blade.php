@extends('web.layout')
@section('content')
<div class="page-head">
    <div><h1>Espaces</h1><p>Une école, un quartier, un crew, un fan-club : réunissez-vous et affrontez les autres Espaces.</p></div>
    @auth<button class="btn" data-open="nouvel-espace">@icon('plus', 'ico-sm')Créer un Espace</button>@endauth
</div>
<form class="search" method="get" style="max-width:none;margin-bottom:16px">@icon('search')<label class="sr-only" for="sq">Rechercher</label><input id="sq" name="q" value="{{ $q }}" placeholder="Rechercher un Espace"></form>
<div class="card" data-infinite-list>
    @forelse ($spaces as $sp)
        @php $spp = \App\Support\Participant::present($sp); @endphp
        <div class="notif" style="align-items:center">
            @include('web.partials.who', ['p' => $spp, 'size' => '', 'sub' => $sp->members_count.' membres'])
            @auth
                <form method="post" action="/espaces/{{ $sp->slug }}/rejoindre" data-ajax data-toggle-text="{{ in_array($sp->id, $mine) ? 'Rejoindre' : 'Membre' }}" style="margin-left:auto">@csrf<button class="btn btn-sm {{ in_array($sp->id, $mine) ? 'btn-ghost' : '' }}">{{ in_array($sp->id, $mine) ? 'Membre' : 'Rejoindre' }}</button></form>
            @endauth
        </div>
    @empty
        <div class="empty"><div class="bubble">@icon('users')</div><h3>Aucun Espace trouvé</h3></div>
    @endforelse
</div>
<div class="pagination" data-infinite>
    @if ($spaces->previousPageUrl())<a class="btn btn-ghost" href="{{ $spaces->previousPageUrl() }}">Précédent</a>@endif
    @if ($spaces->nextPageUrl())<a class="btn btn-ghost" href="{{ $spaces->nextPageUrl() }}">Suivant</a>@endif
</div>
@auth
<dialog class="sheet" id="nouvel-espace" aria-labelledby="ne-title">
    <form method="post" action="/espaces">@csrf
        <div class="sheet-head"><h2 id="ne-title">Créer un Espace</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
        <div class="sheet-body">
            <label class="field"><span>Nom</span><input class="input" name="name" required minlength="3" maxlength="100" placeholder="Ex. Lycée Boboto, Crew Matonge"></label>
            @include('web.partials.space-category')
            <label class="field"><span>Présentation</span><textarea class="input" name="bio" maxlength="500" placeholder="Qui êtes-vous ? Qu'est-ce qui vous rassemble ?"></textarea></label>
            <button class="btn btn-lg btn-block">@icon('plus', 'ico-sm')Créer l'Espace</button>
        </div>
    </form>
</dialog>
@endauth
@endsection
