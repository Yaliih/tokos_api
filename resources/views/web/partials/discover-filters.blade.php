{{-- Découvrir : recherche et type, en haut de la page (une seule page pour tout trouver). Des mots, sans icônes. --}}
@php
    $types = ['' => ['Tout', 'sparkles'], 'challenge' => ['Défis', 'challenge'], 'duel' => ['Duels', 'vs'], 'contest' => ['Concours', 'medal'], 'tournament' => ['Tournois', 'bracket']];
    $qs = fn ($over) => '/decouvrir?'.http_build_query(array_filter(array_merge(['type' => $filters['type'], 'statut' => $filters['status'] === 'en-cours' ? null : $filters['status'], 'categorie' => $filters['categorie'], 'mode' => $filters['mode'], 'q' => $filters['q']], $over)));
    $any = $filters['type'] || $filters['q'] || $filters['categorie'] || $filters['mode'] || $filters['status'] !== 'en-cours';
@endphp
<form class="disc-search" method="get" action="/decouvrir" role="search">
    @if ($filters['type'])<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
    <span class="input-icon">@icon('search')<input class="input" name="q" value="{{ $filters['q'] }}" placeholder="Rechercher un défi, un concours…" aria-label="Rechercher"></span>
    <select class="input" name="categorie" aria-label="Catégorie" onchange="this.form.submit()">
        <option value="">Catégories</option>
        @foreach (config('competition.categories') as $v => $l)<option value="{{ $v }}" @selected($filters['categorie'] === $v)>{{ $l }}</option>@endforeach
    </select>
</form>
<nav class="seg" aria-label="Type">
    @foreach ($types as $v => [$label, $ico])
        <a href="{{ $v === '' && ! $filters['q'] && ! $filters['categorie'] ? '/decouvrir' : $qs(['type' => $v ?: null]) }}" @if(($filters['type'] ?? '') === $v && ($v !== '' || $any)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
    <a href="{{ $qs(['statut' => $filters['status'] === 'termines' ? null : 'termines']) }}" @if($filters['status'] === 'termines') aria-current="page" @endif>Terminés</a>
</nav>
