@extends('web.layout')
@section('content')
{{-- Membres d'un Espace : demandes à valider, ajout, rôles (administrateurs et modérateurs) --}}
@php $slug = $space['slug']; @endphp
<div class="back-line"><a href="/espaces/{{ $slug }}">@icon('arrow-left', 'ico-sm')<span>{{ $space['name'] }}</span></a></div>
<div class="page-head"><div><h1>Membres</h1><p>{{ $count }} {{ $count > 1 ? 'membres' : 'membre' }} · Adhésion {{ $space['join_approval'] ? 'sur validation' : 'ouverte à tous' }}@if($can_edit) · <a href="/espaces/{{ $slug }}/modifier">changer</a>@endif</p></div></div>

@if (count($pending))
<section class="card team-block">
    <h2 class="team-h">Demandes à valider <span class="count-dot">{{ count($pending) }}</span></h2>
    @foreach ($pending as $u)
        <div class="notif team-row">
            @include('web.partials.who', ['p' => $u, 'size' => '', 'sub' => '@'.$u['username'].' · '.\App\Support\Presenter::ago($u['at'])])
            <div class="team-actions">
                <form method="post" action="/espaces/{{ $slug }}/membres/{{ $u['id'] }}/decision">@csrf<input type="hidden" name="decision" value="accept"><button class="btn btn-sm">@icon('check', 'ico-sm')Accepter</button></form>
                <form method="post" action="/espaces/{{ $slug }}/membres/{{ $u['id'] }}/decision">@csrf<input type="hidden" name="decision" value="refuse"><button class="btn btn-ghost btn-sm">Refuser</button></form>
            </div>
        </div>
    @endforeach
</section>
@endif

<section class="card team-block">
    <h2 class="team-h">Inviter quelqu'un</h2>
    <form class="team-add" method="post" action="/espaces/{{ $slug }}/membres" data-suggest-url="/recherche/participants?type=user">@csrf
        <div class="suggest team-suggest" data-suggest data-suggest-empty="Personne de ce nom sur Sosa.">
            <input class="input" name="username" required maxlength="60" placeholder="Nom ou @pseudo" autocomplete="off" aria-label="Nom ou pseudo de la personne" data-suggest-input>
            <ul class="suggest-list" data-suggest-list hidden role="listbox"></ul>
        </div>
        <button class="btn">@icon('user-plus', 'ico-sm')Inviter</button>
    </form>
    <small class="faint">La personne reçoit une invitation et rejoint l'Espace si elle accepte.</small>
    @if (count($invited))
        <h3 class="team-sub">Invitations en attente</h3>
        @foreach ($invited as $u)
            <div class="notif team-row">
                @include('web.partials.who', ['p' => $u, 'size' => '', 'sub' => '@'.$u['username'].' · invité '.\App\Support\Presenter::ago($u['at'])])
                <div class="team-actions"><form method="post" action="/espaces/{{ $slug }}/membres/{{ $u['id'] }}/retirer">@csrf<button class="btn btn-ghost btn-sm">Annuler</button></form></div>
            </div>
        @endforeach
    @endif
</section>

<section class="card team-block">
    <h2 class="team-h">Rôles</h2>
    <ul class="team-roles">
        @foreach ($roles as $r)<li><b>{{ $r['label'] }}</b> {{ $r['help'] }}</li>@endforeach
    </ul>
    @unless ($can_edit)<small class="faint">Seul un administrateur donne les rôles.</small>@endunless
</section>

<section class="card team-block">
    <h2 class="team-h">Tous les membres</h2>
    @foreach ($members as $u)
        <div class="notif team-row">
            @include('web.partials.who', ['p' => $u, 'size' => '', 'sub' => '@'.$u['username']])
            <div class="team-actions">
                @if ($u['role'] === 'owner' || ! $can_edit)
                    @if ($u['role'] !== 'member')<span class="type-tag">{{ $u['role_label'] }}</span>@endif
                @else
                    <form method="post" action="/espaces/{{ $slug }}/membres/{{ $u['id'] }}/role">@csrf
                        <select class="input team-role" name="role" aria-label="Rôle de {{ $u['name'] }}" onchange="this.form.submit()">
                            <option value="member" @selected($u['role'] === 'member')>Membre</option>
                            @foreach ($roles as $r)<option value="{{ $r['value'] }}" @selected($u['role'] === $r['value'])>{{ $r['label'] }}</option>@endforeach
                        </select>
                    </form>
                @endif
                @if ($u['role'] !== 'owner' && ($can_edit || $u['role'] === 'member') && $u['id'] !== auth()->id())
                    <form method="post" action="/espaces/{{ $slug }}/membres/{{ $u['id'] }}/retirer" data-confirm="Retirer {{ $u['name'] }} de l'Espace ?">@csrf<button class="icon-btn" aria-label="Retirer {{ $u['name'] }}" title="Retirer de l'Espace">@icon('x', 'ico-sm')</button></form>
                @endif
            </div>
        </div>
    @endforeach
</section>
@endsection
