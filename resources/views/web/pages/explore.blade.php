@extends('web.layout')
@section('content')

<div class="page-head"><h1>Découvrir</h1></div>
@include('web.partials.discover-filters', ['filters' => $filters])
<div class="feed" data-infinite-list>
    @forelse ($items as $c)
        @include('web.partials.competition-card', ['c' => $c, 'withMatch' => true])
    @empty
        <div class="card empty"><div class="bubble">@icon('search')</div><h3>Rien ne correspond</h3><p>Essaie d'autres filtres, ou lance ta propre compétition.</p><a class="btn" href="/creer">@icon('plus', 'ico-sm')Lancer</a></div>
    @endforelse
</div>
<div class="pagination" data-infinite>
    @if ($page->previousPageUrl())<a class="btn btn-ghost" href="{{ $page->previousPageUrl() }}">@icon('chevron-left', 'ico-sm')Précédent</a>@endif
    @if ($page->nextPageUrl())<a class="btn btn-ghost" href="{{ $page->nextPageUrl() }}">Suivant @icon('chevron-right', 'ico-sm')</a>@endif
</div>
@endsection
