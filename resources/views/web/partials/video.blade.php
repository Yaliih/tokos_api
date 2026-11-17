{{-- Vidéo du site : lecture automatique sans le son quand elle est à l'écran, et des commandes simples :
     toucher la vidéo = lecture / pause, son, agrandir, barre de progression.
     $src, $poster (facultatif), $meta (largeur / hauteur connues), $natural (garder le format de la vidéo).
     Le cadre a déjà le bon format avant la lecture : rien ne bouge quand on appuie sur lecture. --}}
@php
    $natural = $natural ?? false;
    $w = (int) ($meta['width'] ?? 0);
    $h = (int) ($meta['height'] ?? 0);
    // Format téléphone prioritaire : une vidéo en hauteur garde son vrai format et prend presque toute la hauteur de l'écran
    $tall = $w > 0 && $h > 0 && $h / $w > 1.25;
    $ar = $w > 0 && $h > 0 ? $w.' / '.$h : null;
    // « #t=3 » : sans image d'aperçu, le navigateur affiche l'image vers 3 secondes au lieu d'un écran noir
    // (la lecture repart du début, voir tokos.js). Avec une image d'aperçu, rien n'est téléchargé avant la lecture.
    $frameSrc = ! empty($poster) || str_contains($src, '#') ? $src : $src.'#t=3';
@endphp
<div class="vplayer is-paused {{ $natural ? 'natural' : '' }} {{ $natural && $tall ? 'tall' : '' }}" data-vplayer @if($natural && $ar) style="--ar: {{ $ar }}; --arn: {{ round($w / $h, 4) }}" data-ar-known @endif>
    {{-- Format inconnu : on charge juste l'en-tête de la vidéo pour connaître sa taille avant la lecture --}}
    <video src="{{ $frameSrc }}" playsinline muted loop preload="{{ empty($poster) || ($natural && ! $ar) ? 'metadata' : 'none' }}" data-autoplay @if(!empty($poster)) poster="{{ $poster }}" @endif></video>
    <button type="button" class="vbig" data-vtoggle aria-label="Lire ou mettre en pause">@icon('play', 'play')@icon('pause', 'pause')</button>
    <div class="vctl">
        <button type="button" class="vbtn" data-vmute aria-label="Activer le son">@icon('volume-x', 'off')@icon('volume', 'on')</button>
        <button type="button" class="vbtn" data-zoom-src="{{ $src }}" data-zoom-type="video" aria-label="Agrandir la vidéo">@icon('maximize', 'ico-sm')</button>
    </div>
    <span class="vprog" role="slider" tabindex="0" aria-label="Aller à un moment de la vidéo" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-vseek><i></i><b class="vtime" hidden></b></span>
</div>
