@extends('web.auth')
@section('content')
<h1>Créer un compte</h1>
<ol class="steps" aria-label="Étapes" style="margin-bottom:22px">
    @foreach (['Nom', 'Âge', 'Mot de passe', 'Goûts', 'Numéro', 'Code'] as $i => $l)
        <li @if($i === 0) class="current" @endif data-step-dot="{{ $i }}"><span class="bar"></span><span>{{ $l }}</span></li>
    @endforeach
</ol>
{{-- Le numéro et le code passent en dernier : le SMS ne part qu'une fois tout le reste rempli.
     Ainsi personne ne perd un crédit SMS en abandonnant, et le code ne risque pas d'expirer
     pendant qu'on remplit son nom, sa date de naissance ou ses goûts. --}}
<form method="post" action="/inscription" data-wizard novalidate>
    <input type="hidden" name="suite" value="{{ request('suite') }}">
    @csrf
    <section class="step active" data-step="0">
        <h2>Comment tu t'appelles ?</h2>
        <p class="lead">C'est le nom que les autres verront.</p>
        <label class="field"><span>Prénom</span><input class="input" name="first_name" value="{{ old('first_name') }}" autocomplete="given-name" required></label>
        <label class="field"><span>Nom</span><input class="input" name="last_name" value="{{ old('last_name') }}" autocomplete="family-name" required></label>
    </section>
    <section class="step" data-step="1">
        <h2>Ta date de naissance</h2>
        <p class="lead">Elle ne s'affiche pas sur ton profil.</p>
        <label class="field"><span class="sr-only">Date de naissance</span><input class="input" type="date" name="birth_date" value="{{ old('birth_date') }}" autocomplete="bday" required></label>
        <div class="field"><span>Tu es</span>
            <div class="gender-choice" role="radiogroup" aria-label="Sexe">
                @foreach (['female' => 'Une femme', 'male' => 'Un homme'] as $k => $l)
                    <label class="chip-opt"><input type="radio" name="gender" value="{{ $k }}" required @checked(old('gender') === $k)><span>{{ $l }}</span></label>
                @endforeach
            </div></div>
    </section>
    <section class="step" data-step="2">
        <h2>Choisis un mot de passe</h2>
        <p class="lead">Au moins 6 caractères.</p>
        <label class="field"><span>Mot de passe</span><input class="input" type="password" name="password" minlength="6" autocomplete="new-password" required></label>
        <label class="field"><span>Encore une fois</span><input class="input" type="password" name="password_confirmation" autocomplete="new-password" required></label>
    </section>
    <section class="step" data-step="3">
        <h2>Qu'est-ce qui te plaît ?</h2>
        <p class="lead">Touche ce que tu aimes : on te montre d'abord ces défis. Tu peux passer.</p>
        @include('web.partials.interest-chips', ['selected' => old('interests', [])])
    </section>
    <section class="step" data-step="4">
        <h2>Ton numéro</h2>
        <p class="lead">On t'envoie un code par SMS tout de suite après, pour vérifier que c'est bien toi.</p>
        <div style="margin-bottom:16px">@include('web.partials.phone-field', ['id' => 'reg', 'placeholder' => '810 000 000 (sans le 0)'])</div>
    </section>
    <section class="step" data-step="5">
        <h2>Le code reçu</h2>
        <p class="lead">Entre les 6 chiffres envoyés par SMS. <button type="button" class="btn btn-ghost btn-sm" id="send-code">@icon('refresh', 'ico-sm')Renvoyer le code</button></p>
        <label class="field"><span class="sr-only">Code</span><input class="input" name="verification_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" required style="font-size:1.5rem;letter-spacing:.4em;text-align:center;font-weight:800"></label>
        <p class="legal-note" style="margin-top:18px">En créant ton compte, tu acceptes les <a href="/conditions" target="_blank">conditions</a> et la <a href="/confidentialite" target="_blank">confidentialité</a>.</p>
    </section>
    <p class="step-error" data-step-error hidden>@icon('alert')<span></span></p>
    <div class="wizard-nav" style="border:0">
        <button type="button" class="btn btn-ghost" data-prev hidden>@icon('chevron-left', 'ico-sm')Retour</button>
        <span></span>
        <button type="button" class="btn btn-lg" data-next data-send-code-on="4">Continuer @icon('chevron-right', 'ico-sm')</button>
        <button type="submit" class="btn btn-lg" data-submit hidden>@icon('check', 'ico-sm')Créer mon compte</button>
    </div>
</form>
<p style="margin-top:20px;text-align:center" class="muted">Déjà inscrit ? <a href="/connexion" style="color:var(--primary);font-weight:800">Se connecter</a></p>
@endsection