@extends('web.auth')
@section('content')
<h1>Mot de passe oublié</h1>
@if ($step === 1)
    <p class="muted" style="margin-bottom:22px">On t'envoie un code par WhatsApp ou par SMS.</p>
    <form method="post" action="/mot-de-passe-oublie">
        @csrf
        <span class="field" style="margin-bottom:6px"><span>Numéro de téléphone</span></span>
        <div style="margin-bottom:16px">@include('web.partials.phone-field', ['id' => 'fp', 'placeholder' => '810 000 000'])</div>
        <button class="btn btn-lg btn-block">@icon('comment', 'ico-sm')Recevoir un code</button>
    </form>
@else
    <p class="muted" style="margin-bottom:22px">Entre le code reçu au {{ $phone['code'] }} {{ $phone['number'] }}, puis ton nouveau mot de passe.</p>
    <form method="post" action="/mot-de-passe-oublie/nouveau">
        @csrf
        <label class="field"><span>Code</span><input class="input" name="verification_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" required style="font-size:1.4rem;letter-spacing:.4em;text-align:center;font-weight:800"></label>
        <label class="field"><span>Nouveau mot de passe</span><input class="input" type="password" name="password" minlength="6" autocomplete="new-password" required></label>
        <label class="field"><span>Confirme le mot de passe</span><input class="input" type="password" name="password_confirmation" autocomplete="new-password" required></label>
        <button class="btn btn-lg btn-block">@icon('lock', 'ico-sm')Changer le mot de passe</button>
    </form>
    <form method="post" action="/mot-de-passe-oublie/recommencer" style="margin-top:14px;text-align:center">@csrf<button class="link-btn">Changer de numéro ou renvoyer un code</button></form>
@endif
<p style="margin-top:20px;text-align:center" class="muted"><a href="/connexion" style="color:var(--primary);font-weight:800">Retour à la connexion</a></p>
@endsection
