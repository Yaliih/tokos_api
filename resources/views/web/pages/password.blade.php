@extends('web.layout')
@section('content')
{{-- Changer son mot de passe : un code par SMS, puis le nouveau mot de passe --}}
<div class="back-line"><a href="/@{{ auth()->user()->username }}">@icon('arrow-left', 'ico-sm')<span>Mon profil</span></a></div>
<div class="page-head"><div><h1>Mot de passe</h1><p>Pour le changer, on t'envoie d'abord un code par SMS au {{ $phone }}.</p></div></div>

<section class="card pw-card">
    <form method="post" action="/parametres/mot-de-passe/code">@csrf
        <button class="btn btn-ghost btn-block">@icon('phone', 'ico-sm'){{ $sent ? 'Renvoyer le code' : 'Recevoir le code par SMS' }}</button>
    </form>

    @if ($sent)
    <form method="post" action="/parametres/mot-de-passe" class="pw-form">@csrf
        <label class="field"><span>Code reçu</span><input class="input" name="verification_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required placeholder="6 chiffres"></label>
        <label class="field"><span>Nouveau mot de passe</span><input class="input" type="password" name="password" minlength="6" autocomplete="new-password" required></label>
        <label class="field"><span>Encore une fois</span><input class="input" type="password" name="password_confirmation" minlength="6" autocomplete="new-password" required></label>
        <button class="btn btn-lg btn-block">@icon('lock', 'ico-sm')Changer le mot de passe</button>
        <p class="faint small" style="text-align:center;margin:8px 0 0">Tes autres appareils seront déconnectés.</p>
    </form>
    @endif
</section>
@endsection
