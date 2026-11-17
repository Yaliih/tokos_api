@extends('web.auth')
@section('content')
<h1>Content de te revoir</h1>
<p class="muted" style="margin-bottom:22px">Connecte-toi avec ton numéro de téléphone.</p>
<form method="post" action="/connexion">
    @csrf
    <input type="hidden" name="suite" value="{{ request('suite') }}">
    <span class="field" style="margin-bottom:6px"><span>Numéro de téléphone</span></span>
    <div style="margin-bottom:16px">@include('web.partials.phone-field', ['id' => 'login', 'placeholder' => '810 000 000'])</div>
    <label class="field"><span class="row" style="justify-content:space-between;width:100%">Mot de passe<a href="/mot-de-passe-oublie" style="color:var(--primary);font-weight:700;font-size:.85rem">Oublié ?</a></span><input class="input" type="password" name="password" autocomplete="current-password" required></label>
    <label class="switch" style="border:0;padding-top:0"><span><b>Rester connecté</b></span><input type="checkbox" name="remember" value="1" checked></label>
    <button class="btn btn-lg btn-block" style="margin-top:8px">@icon('log-in')Se connecter</button>
</form>
@feature('registration')<p style="margin-top:20px;text-align:center" class="muted">Pas encore de compte ? <a href="/inscription" style="color:var(--primary);font-weight:800">Créer un compte</a></p>@endfeature
@endsection
