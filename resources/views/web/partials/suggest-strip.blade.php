{{-- Bande de suggestions dans le fil (téléphone et tablette) : personnes à suivre ou Espaces à rejoindre --}}
@php $isPeople = $kind === 'people'; @endphp
@if (count($items))
<section class="suggest-strip" aria-label="{{ $isPeople ? 'Personnes à suivre' : 'Espaces à rejoindre' }}">
    <div class="ss-head">
        <h2>@icon($isPeople ? 'user-plus' : 'users', 'ico-sm'){{ $isPeople ? 'À suivre' : 'Espaces à rejoindre' }}</h2>
        <a href="{{ $isPeople ? '/a-suivre' : '/espaces' }}">Tout voir</a>
    </div>
    <div class="ss-row">
        @foreach ($items as $p)
            <div class="ss-card">
                <a href="{{ $p['url'] }}" class="ss-who">
                    @include('web.partials.avatar', ['p' => $p, 'size' => 'md'])
                    <strong>{{ $p['name'] }}@if($p['is_verified'] ?? false)@icon('check-circle', 'verified')@endif</strong>
                    <small>{{ $p['sub'] }}</small>
                </a>
                @auth
                    @if ($isPeople)
                        <form method="post" action="/suivre/{{ $p['handle'] }}" data-ajax data-toggle-text="Abonné">@csrf<button class="btn btn-sm">@icon('user-plus', 'ico-sm')Suivre</button></form>
                    @else
                        <form method="post" action="/espaces/{{ $p['slug'] }}/rejoindre" data-ajax data-toggle-text="Membre">@csrf<button class="btn btn-sm">@icon('users', 'ico-sm')Rejoindre</button></form>
                    @endif
                @else
                    <a class="btn btn-sm" href="/connexion?suite={{ urlencode($p['url']) }}">@icon($isPeople ? 'user-plus' : 'users', 'ico-sm'){{ $isPeople ? 'Suivre' : 'Rejoindre' }}</a>
                @endauth
            </div>
        @endforeach
    </div>
</section>
@endif
