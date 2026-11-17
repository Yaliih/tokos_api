{{-- Numéro de téléphone avec la liste de tous les pays (intl-tel-input : drapeaux, indicatifs, recherche, noms en français).
     Le champ indicatif reste un vrai champ : sans JavaScript, on peut l'écrire à la main.
     $code, $number : noms des champs ; $id ; $placeholder ; $required --}}
@php
    $code = $code ?? 'phone_code'; $number = $number ?? 'phone_number'; $id = $id ?? 'phone';
    $required = $required ?? true;
@endphp
@once
    <link rel="stylesheet" href="{{ asset('vendor/intl-tel-input/css/intlTelInput.min.css') }}">
    <script src="{{ asset('vendor/intl-tel-input/js/intlTelInput.min.js') }}" defer></script>
    <script src="{{ asset('vendor/intl-tel-input/js/fr.js') }}" defer></script>
@endonce
<div class="phone tel-field" data-tel data-utils="{{ asset('vendor/intl-tel-input/js/utils.js') }}">
    <label class="sr-only" for="{{ $id }}-cc">Indicatif</label>
    <input class="input tel-code" id="{{ $id }}-cc" name="{{ $code }}" value="{{ old($code, $codeValue ?? '+243') }}" @if($required) required @endif maxlength="5" data-tel-code>
    <label class="sr-only" for="{{ $id }}-n">Numéro</label>
    <input class="input" type="tel" id="{{ $id }}-n" name="{{ $number }}" value="{{ old($number, $numberValue ?? '') }}" inputmode="tel" autocomplete="{{ $autocomplete ?? 'tel-national' }}" placeholder="{{ $placeholder ?? '' }}" @if($required) required @endif data-tel-number>
</div>
