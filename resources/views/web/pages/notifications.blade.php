@extends('web.layout')
@section('content')
<div class="page-head"><h1>Notifications</h1></div>
{{-- Proposé tant que ce téléphone n'est pas abonné --}}
@feature('push')
<button type="button" class="card push-card" data-push hidden aria-pressed="false">
    <span class="tile">@icon('bell')</span>
    <span class="grow"><b>Active les notifications</b><small data-push-label>Reçois une alerte quand on te défie ou vote</small></span>
    <span class="btn btn-sm">Activer</span>
</button>
@endfeature
@if (count($items))
    <div class="card" style="overflow:hidden" data-infinite-list>
        @foreach ($items as $n)
            @php $ico = \App\Support\Icons::NOTIF[$n['type']] ?? 'bell';
                $tone = in_array($n['type'], ['match.won', 'badge.earned', 'competition.completed'], true) ? 'gold' : (in_array($n['type'], ['challenge.received', 'match.ending_soon', 'rematch.requested'], true) ? 'coral' : ''); @endphp
            <a class="notif {{ $n['read'] ? '' : 'unread' }}" href="{{ $n['url'] ?: '#' }}">
                <span class="av">
                    @if ($n['actor'])@include('web.partials.avatar', ['p' => $n['actor']])<span class="kind {{ $tone }}">@icon($ico)</span>
                    @else<span class="lone">@icon($ico)</span>@endif
                </span>
                <span class="grow"><strong>{{ $n['title'] }}</strong><br><span class="muted">{{ $n['body'] }}</span><br><span class="faint small">{{ $n['time_ago'] }}</span></span>
                @unless ($n['read'])<span class="unread-dot" aria-label="Non lue"></span>@endunless
            </a>
        @endforeach
    </div>
    @if ($page->nextPageUrl())<div class="pagination" data-infinite><a class="btn btn-ghost" href="{{ $page->nextPageUrl() }}">Plus anciennes @icon('chevron-down', 'ico-sm')</a></div>@endif
@else
    <div class="card empty"><div class="bubble">@icon('bell')</div><h3>Rien de neuf</h3><p>Participe à un défi pour que ça bouge ici.</p><a class="btn" href="/decouvrir">@icon('compass', 'ico-sm')Découvrir</a></div>
@endif
@endsection
