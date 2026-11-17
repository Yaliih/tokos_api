@extends('web.layout')
@section('content')
{{-- « Reçus » : ce qui attend ta réponse. Les notifications, elles, t'informent seulement. --}}
<div class="page-head"><div><h1>Reçus</h1><p>Ce qui attend ta réponse : défis, revanches, invitations et candidatures à valider.</p></div></div>
@if (count($items))
    <ul class="inbox">
        @foreach ($items as $it)
            <li class="inbox-item" id="recu-{{ $it['key'] }}">
                <a class="inbox-av" href="{{ $it['from']['url'] ?? '#' }}">@include('web.partials.avatar', ['p' => $it['from']])</a>
                <div class="inbox-main">
                    <div class="inbox-line">
                        <span class="type-tag">{{ $it['label'] }}</span>
                        <a class="inbox-who" href="{{ $it['from']['url'] ?? '#' }}">{{ $it['from']['name'] ?? 'Quelqu\'un' }}</a> {{ $it['text'] }}
                        <a class="inbox-title" href="{{ $it['url'] }}">{{ $it['title'] }}</a>
                    </div>
                    @if ($it['message'])<p class="inbox-msg">« {{ $it['message'] }} »</p>@endif
                    <small class="inbox-at">{{ \App\Support\Presenter::ago($it['at']) }}</small>
                    <div class="inbox-actions">
                        @if ($it['kind'] === 'space_invite')
                            <form method="post" action="/espaces/{{ $it['space_slug'] }}/invitation" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="accept"><button class="btn btn-sm">@icon('check', 'ico-sm')Rejoindre</button></form>
                            <form method="post" action="/espaces/{{ $it['space_slug'] }}/invitation" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="refuse"><button class="btn btn-ghost btn-sm">Refuser</button></form>
                        @elseif ($it['kind'] === 'member')
                            <form method="post" action="/espaces/{{ $it['space_slug'] }}/membres/{{ $it['id'] }}/decision" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="accept"><button class="btn btn-sm">@icon('check', 'ico-sm')Accepter</button></form>
                            <form method="post" action="/espaces/{{ $it['space_slug'] }}/membres/{{ $it['id'] }}/decision" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="refuse"><button class="btn btn-ghost btn-sm">Refuser</button></form>
                        @elseif ($it['kind'] === 'candidate')
                            <form method="post" action="/c/{{ $it['competition_slug'] }}/candidatures/{{ $it['id'] }}" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="approve"><button class="btn btn-sm">@icon('check', 'ico-sm')Accepter</button></form>
                            <form method="post" action="/c/{{ $it['competition_slug'] }}/candidatures/{{ $it['id'] }}" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="reject"><button class="btn btn-ghost btn-sm">Refuser</button></form>
                        @else
                            <a class="btn btn-sm" href="{{ $it['url'] }}#participer">@icon($it['kind'] === 'challenge' ? 'vs' : 'zap', 'ico-sm'){{ $it['kind'] === 'challenge' ? 'Relever' : 'Participer' }}</a>
                            <form method="post" action="/invitations/{{ $it['id'] }}" data-ajax data-inbox-done>@csrf<input type="hidden" name="decision" value="decline"><button class="btn btn-ghost btn-sm">Refuser</button></form>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
@else
    <div class="card empty"><div class="bubble">@icon('vs')</div><h3>Rien ne t'attend pour le moment</h3><p>Les défis qu'on te lance et les invitations arrivent ici. Et si c'était toi qui défiais quelqu'un ?</p><a class="btn" href="/creer?type=duel">@icon('vs', 'ico-sm')Défier quelqu'un</a></div>
@endif
@endsection
