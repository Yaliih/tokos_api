@extends('sites.layout')
@section('hero')
{{-- Affiche : le nom en très grand, la couverture encadrée à côté --}}
<section class="s-poster">
    <div class="s-poster-t">
        <p class="s-kicker">{{ $label ?: 'Espace' }}@if($space->location) · {{ $space->location }}@endif</p>
        <h1>{{ $space->name }}</h1>
        @if ($space->bio)<p class="s-bio">{{ $space->bio }}</p>@endif
        <div class="s-ctas">
            @if ($live->isNotEmpty())<a class="s-btn" href="#en-ce-moment">À l'affiche</a>@endif
            <a class="s-btn s-btn-ghost" href="{{ $mainUrl }}/espaces/{{ $space->slug }}">{{ $isMember ? 'Mon Espace' : 'Rejoindre' }}</a>
        </div>
    </div>
    <div class="s-poster-img">
        @if ($space->cover && ($space->cover_type ?? 'image') === 'video')<video src="{{ $space->cover_url }}#t=0.1" muted loop playsinline autoplay preload="metadata"></video>
        @elseif ($space->cover)<img src="{{ $space->cover_url }}" alt="{{ $space->name }}">
        @else<img class="s-poster-logo" src="{{ $space->avatar_url }}" alt="{{ $space->name }}">@endif
    </div>
</section>
@endsection
