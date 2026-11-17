{{-- Fenêtre de participation : identité, média (photo, vidéo, audio avec aperçu) et texte.
     En mode candidature : photo obligatoire, présentation, courte vidéo facultative. --}}
@php
    $types = $types ?? ['text', 'image', 'video', 'audio'];
    $mediaTypes = array_values(array_intersect(['image', 'video', 'audio'], $types));
    $identities = $identities ?? [];
@endphp
<dialog class="sheet" id="{{ $id }}" aria-labelledby="{{ $id }}-title">
    <form method="post" action="{{ $action }}" enctype="multipart/form-data">
        @csrf
        <div class="sheet-head">
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button>
        </div>
        <div class="sheet-body">
            @if (!empty($hint))<p class="muted">{{ $hint }}</p>@endif
            @if (count($identities) > 1)
                <span class="step-label">Participer en tant que</span>
                <div class="options" style="margin-bottom:16px">
                    @foreach ($identities as $i => $p)
                        <label class="option compact">
                            <input type="radio" name="{{ $identityField ?? 'as_space' }}" value="{{ ($identityValue ?? 'space') === 'space' ? ($p['type'] === 'space' ? $p['handle'] : '') : $p['handle'] }}" @checked($i === 0)>
                            <span class="box">@include('web.partials.avatar', ['p' => $p, 'size' => 'sm'])<strong>{{ $p['name'] }}</strong></span>
                        </label>
                    @endforeach
                </div>
            @elseif (count($identities) === 1 && ($identities[0]['type'] ?? null) === 'space')
                <input type="hidden" name="{{ $identityField ?? 'as_space' }}" value="{{ $identities[0]['handle'] }}">
                <p class="chip chip-primary" style="margin-bottom:14px">@icon('users')Au nom de {{ $identities[0]['name'] }}</p>
            @endif

            @if ($application ?? false)
                <div class="note">@icon('info')<span>Ta candidature est envoyée à l'organisateur. Tu es prévenu dès qu'elle est acceptée.</span></div>
                @include('web.partials.picker', ['name' => 'photo', 'kinds' => ['image'], 'label' => 'Ta photo', 'required' => true, 'size' => 'small', 'hint' => 'Une photo de toi, bien visible.'])
                <label class="field" style="margin-top:12px"><span>Présente-toi</span>
                    <textarea class="input" data-emoji name="content" required minlength="10" maxlength="1000" placeholder="Qui es-tu, que vas-tu proposer ?"></textarea></label>
                @include('web.partials.picker', ['name' => 'video', 'kinds' => ['video'], 'label' => 'Courte vidéo de présentation (facultatif)', 'size' => 'small', 'maxVideo' => (int) config('competition.media.max_intro_video_seconds'), 'hint' => 'Présente-toi en quelques secondes.'])
                <div style="height:14px"></div>
            @else
                @if ($mediaTypes)
                    @include('web.partials.picker', ['name' => 'media[0][file]', 'meta' => 'media[0][metadata]', 'kinds' => $mediaTypes, 'label' => 'Ta performance', 'hint' => in_array('text', $types) ? 'Facultatif si tu écris un texte.' : null])
                @endif
                @if (in_array('text', $types))
                    <label class="field" style="margin-top:14px"><span>{{ $mediaTypes ? 'Ton texte (facultatif)' : 'Ton texte' }}</span>
                        <textarea class="input" data-emoji name="content" maxlength="5000" placeholder="Ta punchline, ta réponse, ta présentation…"></textarea></label>
                @endif
            @endif
            {{ $slot ?? '' }}
            <div class="row" style="margin-top:6px">
                @foreach (($buttons ?? [['label' => $submit, 'name' => null, 'value' => null, 'class' => '']]) as $b)
                    <button class="btn btn-lg {{ $b['class'] }}" @if($b['name']) name="{{ $b['name'] }}" value="{{ $b['value'] }}" @endif style="flex:1">{{ $b['label'] }}</button>
                @endforeach
            </div>
        </div>
    </form>
</dialog>
