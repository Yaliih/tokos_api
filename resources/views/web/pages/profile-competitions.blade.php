@extends('web.layout')
@section('content')
@php
    // Page des compétitions d'un profil ou d'un Espace : le contrôleur fournit l'adresse
    $base = $pageUrl ?? ('/@'.$owner->username.'/competitions');
    $back = $backUrl ?? ('/@'.$owner->username);
    $showJoined = $showJoined ?? true;
    $qs = fn (array $o) => $base.'?'.http_build_query(array_filter(array_merge(['onglet' => $tab, 'tri' => $tri], $o)));
@endphp
<div class="back-line"><a href="{{ $back }}">@icon('arrow-left', 'ico-sm')<span>{{ $ownerName }}</span></a></div>

<div class="pcomp-page">
    <h1>@icon('trophy', 'ico-sm')Compétitions</h1>

    <div class="pcomp-tabs" role="tablist">
        <a class="pcomp-tab {{ $tab === 'creees' ? 'on' : '' }}" href="{{ $qs(['onglet' => 'creees']) }}" role="tab">
            Créées <b>{{ $madeCount }}</b>
        </a>
        @if ($showJoined)
            <a class="pcomp-tab {{ $tab === 'participe' ? 'on' : '' }}" href="{{ $qs(['onglet' => 'participe']) }}" role="tab">
                Participées <b>{{ $joinedCount }}</b>
            </a>
        @endif
    </div>

    <div class="pcomp-sorts">
        <span>Tri :</span>
        <a class="{{ $tri === 'recentes' ? 'on' : '' }}" href="{{ $qs(['tri' => 'recentes']) }}">Récentes</a>
        <a class="{{ $tri === 'bientot' ? 'on' : '' }}" href="{{ $qs(['tri' => 'bientot']) }}">Bientôt finies</a>
        <a class="{{ $tri === 'anciennes' ? 'on' : '' }}" href="{{ $qs(['tri' => 'anciennes']) }}">Anciennes</a>
    </div>

    @if ($comps->isEmpty())
        <p class="pcomps-empty">
            {{ $tab === 'participe' ? 'Aucune compétition rejointe pour l\'instant.' : 'Aucune compétition créée pour l\'instant.' }}
        </p>
    @else
        <div class="pcomps-list">
            @foreach ($comps as $c)
                @php $n = $c->entries()->count(); @endphp
                <a class="pcomp" href="/c/{{ $c->slug }}">
                    <span class="pcomp-t">{{ $c->title }}</span>
                    <span class="pcomp-m">
                        <span class="pcomp-tag">{{ ucfirst($c->type) }}</span>
                        <span class="pcomp-tag {{ $c->status === 'open' ? 'on' : '' }}">{{ $c->status === 'open' ? 'Ouverte' : ucfirst($c->status) }}</span>
                        <span class="pcomp-n">{{ $n }} inscription{{ $n > 1 ? 's' : '' }}</span>
                        @if ($tab === 'creees' && $c->status === 'open' && $n === 0)<span class="pcomp-n">· modifiable</span>@endif
                    </span>
                </a>
            @endforeach
        </div>
        <div class="pcomp-pager">{{ $comps->links() }}</div>
    @endif
</div>
@endsection