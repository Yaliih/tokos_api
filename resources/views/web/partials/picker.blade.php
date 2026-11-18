{{--
    Sélecteur de média : photo, vidéo ou audio, avec aperçu (image, vidéo, ondes de l'audio)
    et contrôle de la durée avant l'envoi.
    Paramètres : name (champ du fichier), meta (champ des infos : durée, ondes), kinds (types acceptés), label, hint,
    maxVideo / maxAudio (secondes), required, size ('', 'small'),
    textName (facultatif : ajoute « Texte », une zone d'écriture qui remplace le média ; ex. versus)
--}}
@php
    $kinds = $kinds ?? ['image', 'video', 'audio'];
    $maxVideo = $maxVideo ?? (int) config('competition.media.max_video_seconds');
    $maxAudio = $maxAudio ?? (int) config('competition.media.max_audio_seconds');
    $labels = ['image' => ['image', 'Photo'], 'video' => ['video', 'Vidéo'], 'audio' => ['mic', 'Audio']];
    $accept = collect(['image' => 'image/*', 'video' => 'video/*', 'audio' => 'audio/*'])->only($kinds)->implode(',');
    $limits = collect([
        in_array('video', $kinds) ? 'vidéo '.\App\Support\MediaDuration::label($maxVideo) : null,
        in_array('audio', $kinds) ? 'audio '.\App\Support\MediaDuration::label($maxAudio) : null,
    ])->filter()->implode(', ');
@endphp
<div class="picker {{ $size ?? '' }}" data-picker @if($follow ?? false) data-follow-format @endif data-max-video="{{ $maxVideo }}" data-max-audio="{{ $maxAudio }}" data-max-mb="{{ (int) config('competition.media.max_upload_mb', 50) }}">
    <input type="file" name="{{ $name }}" accept="{{ $accept }}" data-picker-input hidden
        @if($required ?? false) data-required-file @endif
        @if(!empty($requiredFor)) data-required-for="{{ $requiredFor }}" @endif
        @if(!empty($requiredMessage)) data-required-message="{{ $requiredMessage }}" @endif>
    @if (!empty($meta))<input type="hidden" name="{{ $meta }}" data-picker-meta>@endif
    @if (!empty($textName))
        {{-- Texte : une zone pour écrire remplace le média --}}
        <div class="picker-text" data-picker-text hidden>
            <textarea class="input" name="{{ $textName }}" rows="3" maxlength="2000" placeholder="{{ $textPlaceholder ?? 'Ton texte' }}" disabled></textarea>
            <button type="button" class="picker-back" data-kind-back>@icon('refresh', 'ico-sm')Changer</button>
        </div>
    @endif
    <div class="picker-empty">
        <span class="picker-label">{{ $label ?? 'Ajouter un média' }}@if(($required ?? false) || !empty($requiredFor)) <em @if(!empty($requiredFor)) data-for="{{ $requiredFor }}" @endif>obligatoire</em>@endif</span>
        {{-- D'abord le format (Vidéo, Audio, Photo, et Texte s'il est proposé), puis comment : filmer / enregistrer / prendre, ou importer --}}
        <div class="picker-btns picker-kinds" data-kind-btns>
            @foreach (array_values(array_filter(['video', 'audio', 'image'], fn ($k) => in_array($k, $kinds, true))) as $t)
                <button type="button" class="picker-btn" data-kind="{{ $t }}">@icon($labels[$t][0]){{ $labels[$t][1] }}</button>
            @endforeach
            @if (!empty($textName))<button type="button" class="picker-btn" data-kind="text">@icon('type')Texte</button>@endif
        </div>
        <div class="picker-btns picker-how" data-kind-how hidden>
            <div class="picker-how-group" data-how="video" hidden>
                @feature('recording')<button type="button" class="picker-btn rec" data-record="video">@icon('camera')Filmer</button>@endfeature
                <button type="button" class="picker-btn" data-pick="video" aria-label="Importer une vidéo">@icon('upload')Importer</button>
            </div>
            <div class="picker-how-group" data-how="audio" hidden>
                @feature('recording')<button type="button" class="picker-btn rec" data-record="audio">@icon('mic')Enregistrer</button>@endfeature
                <button type="button" class="picker-btn" data-pick="audio" aria-label="Importer un audio">@icon('upload')Importer</button>
            </div>
            <div class="picker-how-group" data-how="image" hidden>
                <button type="button" class="picker-btn" data-pick="image" data-capture="environment" aria-label="Prendre une photo">@icon('camera')Prendre</button>
                <button type="button" class="picker-btn" data-pick="image" aria-label="Importer une photo">@icon('upload')Importer</button>
            </div>
            <button type="button" class="picker-back" data-kind-back>@icon('refresh', 'ico-sm')Changer</button>
        </div>
        <small>{{ $hint ?? 'Ou glisse un fichier ici.' }} 50 Mo maximum{{ $limits ? ', '.$limits : '' }}.</small>
    </div>
    <div class="picker-preview" hidden>
        <div class="picker-media" data-picker-media></div>
        <div class="picker-info">
            <span class="grow"><strong data-picker-name></strong><small data-picker-details></small></span>
            <button type="button" class="btn btn-ghost btn-sm" data-picker-change>@icon('refresh', 'ico-sm')Changer</button>
            <button type="button" class="icon-btn" data-picker-clear aria-label="Retirer">@icon('x')</button>
        </div>
    </div>
</div>
