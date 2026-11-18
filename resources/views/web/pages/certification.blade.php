@extends('web.layout')
@section('content')
{{-- Demande de certification : ce qu'il faut envoyer, le code à tenir sur le selfie, l'état de la demande --}}
@php
    $me = auth()->user();
    $kind = $space ? 'space' : 'user';
    $cats = config('certification.categories.'.$kind);
    $docs = config('certification.documents');
    $pending = $last && $last->status === 'pending';
    $rejected = $last && $last->status === 'rejected';
@endphp
<div class="back-line"><a href="{{ $space ? '/espaces/'.$space->slug : '/@'.$me->username }}">@icon('arrow-left', 'ico-sm')<span>Certification</span></a></div>

<section class="card cert-hero">
    <span class="cert-badge">@icon('check-circle')</span>
    <div>
        <h1>{{ $verified ? ($space ? $space->name.' est certifié' : 'Ton profil est certifié') : 'Obtenir le badge certifié' }}</h1>
        <p>{{ $verified ? 'Le badge apparaît à côté du nom, partout sur Sosa.' : 'Le badge montre que '.($space ? 'cet Espace est bien l\'organisation officielle' : 'c\'est bien toi').'. On vérifie à la main, en quelques jours.' }}</p>
    </div>
</section>

@if ($spaces->isNotEmpty())
    <nav class="seg" aria-label="Pour qui">
        <a href="/parametres/certification" @if(!$space) aria-current="page" @endif>@icon('user', 'ico-sm')Moi</a>
        @foreach ($spaces as $sp)
            <a href="?espace={{ $sp->slug }}" @if($space && $space->id === $sp->id) aria-current="page" @endif>@icon('users', 'ico-sm'){{ $sp->name }}</a>
        @endforeach
    </nav>
@endif

@if ($pending)
    <div class="card cert-state">@icon('clock')<div><strong>Demande en cours d'examen</strong><span>Envoyée {{ \App\Support\Presenter::ago($last->created_at) }}. Tu seras prévenu par notification.</span></div></div>
@elseif (! $verified)
    @if ($rejected)
        <div class="card cert-state no">@icon('info')<div><strong>Demande précédente refusée</strong><span>{{ $last->admin_note }}</span></div></div>
    @endif

    @if (config('certification.require_avatar') && ! $hasAvatar)
        <div class="card cert-state">@icon('image')<div><strong>Ajoute d'abord une photo de {{ $space ? 'l\'Espace' : 'profil' }}</strong><span>On vérifie qu'elle correspond.</span></div>
            <a class="btn btn-sm" href="{{ $space ? '/espaces/'.$space->slug.'/modifier' : '/parametres/profil' }}">Ajouter</a></div>
    @else
    <form method="post" action="/parametres/certification" enctype="multipart/form-data" class="card cert-form">
        @csrf
        @if ($space)<input type="hidden" name="space" value="{{ $space->slug }}">@endif

        <span class="step-label">{{ $space ? 'Type d\'organisation' : 'Tu es' }}</span>
        <div class="ops" style="margin-bottom:16px">
            @foreach ($cats as $k => $label)
                <label class="op"><input type="radio" name="category" value="{{ $k }}" @checked(old('category', array_key_first($cats)) === $k) required data-cert-cat><span>{{ $label }}</span></label>
            @endforeach
        </div>

        <label class="field"><span>{{ $space ? 'Nom officiel de l\'organisation' : 'Nom complet, comme sur ta pièce' }}</span>
            <input class="input" name="full_name" value="{{ old('full_name', $space ? $space->name : trim($me->first_name.' '.$me->last_name)) }}" required maxlength="120"></label>

        <ol class="cert-steps">
            <li>
                <strong>{{ $space ? 'Pièce d\'identité du responsable' : 'Ta pièce d\'identité' }}</strong>
                <span>Photo nette du recto, tous les coins visibles.</span>
                <select class="input" name="document_type" required>
                    @foreach (['voter_card', 'passport', 'driving_license', 'student_card'] as $d)<option value="{{ $d }}" @selected(old('document_type') === $d)>{{ $docs[$d] }}</option>@endforeach
                </select>
                <input class="input" type="file" name="document" accept="image/*,application/pdf" required>
            </li>
            <li>
                <strong>Un selfie avec ta pièce et ce code</strong>
                <span>Écris <b class="cert-code">{{ $code }}</b> sur une feuille, tiens-la avec ta pièce à côté de ton visage.</span>
                <input class="input" type="file" name="selfie" accept="image/*" capture="user" required>
            </li>
            <li data-cert-proof>
                <strong>{{ $space ? 'Un document officiel' : 'Un justificatif' }} <small data-cert-optional>{{ $space ? '' : '(facultatif)' }}</small></strong>
                <span>{{ $space ? 'RCCM, Id. Nat., statuts, ou lettre officielle de l\'établissement signée.' : 'Étudiant : carte d\'étudiant ou attestation d\'inscription. Sinon : article de presse, affiche, contrat…' }}</span>
                <input class="input" type="file" name="proof" accept="image/*,application/pdf" @if($space) required @endif>
            </li>
        </ol>

        <label class="field"><span>Liens publics (facultatif)</span>
            <input class="input" name="links" value="{{ old('links') }}" placeholder="Facebook, Instagram, TikTok, YouTube, site officiel…"></label>
        <label class="field"><span>Un mot pour l'équipe (facultatif)</span>
            <textarea class="input" name="note" maxlength="600" rows="2">{{ old('note') }}</textarea></label>

        <p class="faint small" style="margin:4px 0 14px">Tes documents restent privés : seule l'équipe Sosa les voit, et ils sont supprimés {{ config('certification.keep_documents_days') }} jours après la décision.</p>
        <button class="btn btn-lg btn-block">@icon('send', 'ico-sm')Envoyer</button>
    </form>
    @endif
@endif
@endsection
