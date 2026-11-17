@extends('web.layout')
@section('content')
{{-- Mode swipe : un duel à la fois. Sur téléphone, l'un sur l'autre : glisse vers le haut ou le bas.
     Sur grand écran, côte à côte : glisse vers la gauche ou la droite. --}}
<div class="swipe" data-swipe>
    <div class="swipe-top">
        <a class="icon-btn" href="/" aria-label="Retour">@icon('arrow-left')</a>
        <strong>Voter</strong>
        <span class="swipe-count" data-swipe-count>{{ count($matches) }}</span>
    </div>
    <div class="swipe-deck" data-swipe-deck>
        @foreach ($matches as $i => $m)
            @php $o = $m['display_order']; @endphp
            <div class="swipe-card" data-swipe-card data-match="{{ $m['id'] }}" data-left="{{ $m['entry_'.$o[0]]['id'] }}" data-right="{{ $m['entry_'.$o[1]]['id'] }}" @if($i > 0) hidden @endif>
                <a class="swipe-title" href="{{ $m['url'] }}">{{ $m['competition']['title'] }}</a>
                @include('web.partials.versus', ['m' => $m, 'compact' => true, 'frame' => 'none', 'showTop' => false, 'defy' => false, 'showTime' => true])
                <span class="swipe-stamp left" aria-hidden="true"><span class="h">@icon('arrow-left')</span><span class="v">@icon('arrow-up')</span></span>
                <span class="swipe-stamp right" aria-hidden="true"><span class="h">@icon('arrow-right')</span><span class="v">@icon('arrow-down')</span></span>
            </div>
        @endforeach
        <div class="swipe-end card" data-swipe-end @if(count($matches)) hidden @endif>
            <div class="bubble">@icon('check-circle')</div>
            <h3>Tu as tout voté</h3>
            <p class="muted">Reviens plus tard, ou lance ton propre défi.</p>
            <div class="row" style="justify-content:center;margin-top:12px"><a class="btn" href="/creer?type=challenge">@icon('challenge', 'ico-sm')Lancer un défi</a><a class="btn btn-ghost" href="/">@icon('home', 'ico-sm')Accueil</a></div>
        </div>
    </div>
    <div class="swipe-actions" data-swipe-actions @if(!count($matches)) hidden @endif>
        <button type="button" class="swipe-btn" data-swipe-vote="left" aria-label="Voter pour le premier"><span class="h">@icon('arrow-left')</span><span class="v">@icon('arrow-up')</span></button>
        <button type="button" class="swipe-btn skip" data-swipe-skip aria-label="Passer ce duel">Passer</button>
        <button type="button" class="swipe-btn" data-swipe-vote="right" aria-label="Voter pour le second"><span class="h">@icon('arrow-right')</span><span class="v">@icon('arrow-down')</span></button>
    </div>
    <p class="swipe-hint"><span class="h">Glisse vers ton préféré</span><span class="v">Glisse vers le haut ou le bas pour ton préféré</span></p>
</div>
@endsection
