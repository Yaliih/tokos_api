@extends('sites.layout')
@section('hero')
{{-- Classique : la couverture en grand, le nom posé dessus --}}
<section class="s-hero {{ $space->cover ? 'has-cover' : '' }}">
    @if ($space->cover)
        @if (($space->cover_type ?? 'image') === 'video')<video src="{{ $space->cover_url }}#t=0.1" muted loop playsinline autoplay preload="metadata"></video>
        @else<img src="{{ $space->cover_url }}" alt="{{ $space->name }}">@endif
    @endif
    <div class="s-hero-in">
        <img class="s-logo" src="{{ $space->avatar_url }}" alt="{{ $space->name }}">
        <p class="s-kicker">{{ $label ?: 'Espace' }}@if($space->location) · {{ $space->location }}@endif</p>
        <h1>{{ $space->name }}</h1>
        @if ($space->bio)<p class="s-bio">{{ $space->bio }}</p>@endif
        <div class="s-ctas">
            @if ($live->isNotEmpty())<a class="s-btn" href="#en-ce-moment">Voir les compétitions</a>@endif
            <a class="s-btn s-btn-ghost" href="{{ $mainUrl }}/espaces/{{ $space->slug }}">{{ $isMember ? 'Mon Espace sur Sosa' : 'Rejoindre sur Sosa' }}</a>
        </div>
    </div>
</section>
@endsection
