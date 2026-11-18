@extends('web.layout')
@section('content')
{{-- Un duel : les deux performances, le vote, trois actions. Rien d'autre à comprendre. --}}
@php
    $order = collect($m['display_order'] ?? ['a', 'b'])->map(fn ($s) => $m['entry_'.$s])->filter();
    $captions = $order->filter(fn ($e) => ! empty($e['content']) && ! empty($e['media']));
    $giftable = $order->filter(fn ($e) => ! empty($e['participant']) && ! (auth()->check() && ($e['participant']['type'] ?? '') === 'user' && (int) $e['participant']['id'] === auth()->id()));
@endphp
<div class="back-line"><a href="{{ $m['competition']['url'] }}">@icon('arrow-left', 'ico-sm')<span class="trunc">{{ $m['competition']['title'] }}</span></a>
    @if ($m['status'] === 'voting' && $m['ends_at'])<span class="post-time"><span class="countdown" data-prefix="reste " data-ends="{{ $m['ends_at'] }}"></span></span>
    @elseif ($m['status'] === 'completed')<span class="post-time over">@icon('flag', 'ico-sm')Terminé</span>@endif</div>
@if (!empty($m['viewer']['is_juror']))
    <p class="mini-note">@icon('scale', 'ico-sm')Tu es juré : ton vote compte pour le jury ({{ $m['jury']['weight'] ?? 0 }} %).</p>
@endif
@if ($m['preparing'])
    @include('web.partials.round-prepare', ['m' => $m])
@else
@include('web.partials.versus', ['m' => $m, 'showTop' => false, 'social' => true, 'showTime' => false])
@endif
@if ($m['forfeit'])
    <p class="mini-note">@icon('clock', 'ico-sm'){{ $m['forfeit'] === 'both' ? 'Aucune prestation envoyée à temps : la meilleure tête de série passe.' : 'Prestation non envoyée à temps : qualifié d\'office.' }}</p>
@endif
@if (!empty($m['jury']) && ($m['jury']['a'] + $m['jury']['b']) > 0)
    <p class="jury-line">@icon('scale', 'ico-sm')Jury : {{ $m['jury']['a'] }} – {{ $m['jury']['b'] }}<span>({{ $m['jury']['weight'] }} % du résultat)</span></p>
@endif
@unless ($m['preparing'])
@include('web.partials.reactions', ['m' => $m])
@endunless

@if ($captions->isNotEmpty() && ! $m['preparing'])
    <div class="match-captions">
        @foreach ($captions as $e)
            <p>@include('web.partials.avatar', ['p' => $e['participant'] ?? null, 'size' => 'sm'])<span><strong>{{ $e['display_name'] }}</strong>{{ $e['content'] }}</span></p>
        @endforeach
    </div>
@endif

@if ($giftable->isNotEmpty() || $m['viewer']['can_rematch'])
    <div class="match-more">
        @if ($giftable->isNotEmpty())
            @feature('gifts')
                @if ($giftable->count() === 1)
                    @php $e = $giftable->first(); @endphp
                    <button type="button" class="btn btn-soft btn-sm" data-gift data-gift-type="{{ $e['participant']['type'] }}" data-gift-id="{{ $e['participant']['id'] }}" data-gift-name="{{ $e['display_name'] }}" data-gift-comp="{{ $m['competition']['id'] }}">@icon('gift', 'ico-sm')Offrir un cadeau</button>
                @else
                    <button type="button" class="btn btn-soft btn-sm" data-open="gift-pick">@icon('gift', 'ico-sm')Offrir un cadeau</button>
                    <dialog class="sheet" id="gift-pick" aria-labelledby="gift-pick-t">
                        <div class="sheet-head"><h2 id="gift-pick-t">Offrir à qui ?</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
                        <div class="sheet-body gift-pick">
                            @foreach ($giftable as $e)
                                <button type="button" class="gift-who" data-close data-gift data-gift-type="{{ $e['participant']['type'] }}" data-gift-id="{{ $e['participant']['id'] }}" data-gift-name="{{ $e['display_name'] }}" data-gift-comp="{{ $m['competition']['id'] }}">
                                    @include('web.partials.avatar', ['p' => $e['participant']])<b>{{ $e['display_name'] }}</b>@icon('gift', 'ico-sm')</button>
                            @endforeach
                        </div>
                    </dialog>
                @endif
            @endfeature
        @endif
        @if ($m['viewer']['can_rematch'])
            <form method="post" action="/m/{{ $m['id'] }}/revanche">@csrf<button class="btn btn-soft btn-sm">@icon('rotate', 'ico-sm')Revanche</button></form>
        @endif
    </div>
@endif
@endsection
