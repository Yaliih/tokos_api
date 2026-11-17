@extends('web.layout')
@section('content')
{{--
    Publier un défi ou un duel, comme une publication, en deux temps courts (rien ne dépasse l'écran) :
    1. ta performance (vidéo, audio, photo ou texte) et un mot ;  2. discipline, durée, ta ville, votes payants, puis « Publier ».
    Le format des réponses suit ton média, le titre vient de ton texte.
--}}
@php
    $me = auth()->user();
    $meP = \App\Support\Participant::present($me);
    $isDuel = $preset === 'duel';
    $versus = $isDuel && \App\Support\Features::on('versus') && old('format', request('format')) === 'versus';
    $tabs = collect(['challenge' => ['Défi', 'challenge'], 'duel' => ['Duel', 'vs'], 'contest' => ['Concours', 'medal'], 'tournament' => ['Tournoi', 'bracket']])
        ->filter(fn ($v, $k) => \App\Support\Features::type($k));
    $presets = config('competition.duration_presets');
    $initialSpace = old('space', $space);
    $cats = config('competition.categories');
    $myCats = array_values(array_intersect((array) ($me->interests ?? []), array_keys($cats)));
    // Ses disciplines d'abord
    $cats = collect($cats)->sortBy(fn ($l, $k) => in_array($k, $myCats, true) ? 0 : 1)->all();
@endphp
<div class="composer-page">
    <nav class="type-tabs" aria-label="Que veux-tu lancer ?">
        @foreach ($tabs as $k => [$label, $ico])
            <a href="/creer?type={{ $k }}{{ $initialSpace ? '&espace='.$initialSpace : '' }}" @if($preset === $k) aria-current="page" @endif>@icon($ico, 'ico-sm'){{ $label }}</a>
        @endforeach
    </nav>

    <form id="quick-form" class="qc" method="post" action="/creer" enctype="multipart/form-data" data-quick novalidate data-suggest-url="/recherche/participants">
        @csrf
        <input type="hidden" name="type" value="{{ $preset }}">
        <input type="hidden" name="format" value="{{ $isDuel ? ($versus ? 'versus' : 'challenge') : '' }}" data-qc-format>

        <section class="qc-step" data-qc-step="1">
        {{-- Qui publie --}}
        {{-- Publier en tant que : toucher son nom ouvre la liste (moi, mes Espaces) juste en dessous --}}
        @php $as = $initialSpace ? $mySpaces->firstWhere('slug', $initialSpace) : null; $asP = $as ? \App\Support\Participant::present($as) : $meP; @endphp
        <div class="qc-who" data-as>
            <input type="hidden" name="space" value="{{ $as ? $as->slug : '' }}" data-as-input>
            <button type="button" class="qc-as" data-as-btn aria-haspopup="listbox" aria-expanded="false">
                <img class="avatar" src="{{ $asP['avatar_url'] }}" alt="" data-as-img>
                <span class="qc-as-t"><small>Publier en tant que</small><b data-as-name>{{ $asP['name'] }}</b></span>
                @icon('chevron-down', 'ico-sm qc-as-caret')</button>
            <div class="qc-pop" data-as-pop hidden role="listbox" aria-label="Publier en tant que"></div>
        </div>

        @if ($isDuel)
            {{-- Duel : contre quelqu'un, ou entre deux choix proposés par moi (le public choisit) --}}
            @feature('versus')
            <div class="seg qc-modes" role="group" aria-label="Type de duel">
                <button type="button" data-qc-set="challenge" aria-pressed="{{ $versus ? 'false' : 'true' }}">@icon('user-plus', 'ico-sm')Défier quelqu'un</button>
                <button type="button" data-qc-set="versus" aria-pressed="{{ $versus ? 'true' : 'false' }}">@icon('columns', 'ico-sm')Deux choix</button>
            </div>
            @endfeature
            <div class="qc-versus-off" @if($versus) hidden @endif data-qc-mode="challenge">
                <div class="suggest qc-to" data-suggest>
                    <label class="qc-to-in">@icon('vs', 'ico-sm')<span class="sr-only">Adversaire</span>
                        <input name="opponent" autocomplete="off" value="{{ old('opponent', $opponent) }}" placeholder="Qui veux-tu défier ? @pseudo" data-suggest-input></label>
                    <input type="hidden" name="opponent_type" value="{{ $opponentType }}" data-suggest-type>
                    <ul class="suggest-list" data-suggest-list hidden role="listbox"></ul>
                </div>
                {{-- Plusieurs adversaires : chacun reçoit son propre duel --}}
                <div class="opp-chips" data-opp-chips hidden></div>
                <small class="qc-small opp-hint">Tu peux en défier plusieurs (10 au plus) : chacun reçoit son propre duel.</small>
                @feature('phone_invites')
                <button type="button" class="link-btn qc-small" data-toggle-phone>@icon('phone', 'ico-sm')Pas sur Sosa ? Invite-le par son numéro</button>
                <div class="phone-fallback" data-phone-fallback hidden>
                    @include('web.partials.phone-field', ['id' => 'opp', 'code' => 'opponent_phone_code', 'number' => 'opponent_phone', 'required' => false, 'placeholder' => 'Son numéro', 'autocomplete' => 'off'])
                    <button type="button" class="link-btn qc-small" data-add-phone>@icon('plus', 'ico-sm')Ajouter ce numéro</button>
                </div>
                @endfeature
            </div>
        @endif

        {{-- Le titre (en grand) et la description : communs au défi, au duel et aux deux choix --}}
        <div class="qc-body">
            {{-- Titre : la première ligne, en grand. Plein (80 caractères) ou Entrée : on passe au texte, en taille normale. --}}
            <input class="qc-head" name="title" maxlength="80" value="{{ old('title') }}" placeholder="{{ $isDuel ? 'Titre de ton duel (facultatif)' : 'Ton défi, en une phrase' }}" data-qc-title aria-label="Titre" enterkeyhint="next" autofocus>
            <textarea class="qc-text" name="content" maxlength="1000" rows="3" data-emoji data-autogrow data-qc-content
                placeholder="{{ $isDuel ? 'Un mot pour ton adversaire… (facultatif)' : 'Ajoute des détails… (facultatif)' }}">{{ old('content') }}</textarea>
            <small class="qc-count" data-qc-count hidden></small>
            <div class="qc-perf" data-qc-mode="{{ $isDuel ? 'challenge' : 'post' }}" @if($versus) hidden @endif>
                @include('web.partials.picker', ['name' => 'media[0][file]', 'meta' => 'media[0][metadata]', 'label' => 'Ta performance', 'hint' => 'Vidéo, audio ou photo.', 'size' => 'qc', 'compact' => true])
                <p class="qc-hint">@icon('info', 'ico-sm')<span>{{ $isDuel ? 'Ton adversaire répond dans le même format : vidéo contre vidéo, texte contre texte.' : 'Les challengers répondent dans le même format que toi. Chaque réponse devient un duel contre toi.' }}</span></p>
            </div>
        </div>

        @if ($isDuel)
            {{-- Deux choix face à face, proposés par moi : le public vote pour l'un des deux --}}
            <div class="qc-versus" data-qc-mode="versus" @unless($versus) hidden @endunless>
                <div class="versus-form">
                    @foreach (['a' => ['Choix 1', 'Ex. Pondu'], 'b' => ['Choix 2', 'Ex. Moambe']] as $k => [$lb, $ph])
                        <div class="versus-form-side">
                            <span class="vf-label">{{ $lb }}</span>
                            <input class="input" name="side_{{ $k }}_label" maxlength="80" value="{{ old('side_'.$k.'_label') }}" placeholder="{{ $ph }}" aria-label="{{ $lb }}">
                            @include('web.partials.picker', ['name' => 'side_'.$k.'_media[0][file]', 'meta' => 'side_'.$k.'_media[0][metadata]', 'label' => $k === 'a' ? 'Format des deux choix' : 'Même format que le choix 1', 'size' => 'small qc', 'hint' => '', 'textName' => 'side_'.$k.'_content', 'textPlaceholder' => 'Le texte de ce choix'])
                        </div>
                        @if ($k === 'a')<span class="vf-vs" aria-hidden="true">VS</span>@endif
                    @endforeach
                </div>
                <p class="qc-hint">@icon('info', 'ico-sm')<span>Le public vote pour l'un des deux. Les deux choix ont toujours le même format : texte contre texte, photo contre photo, vidéo contre vidéo, audio contre audio.</span></p>
            </div>
        @endif

        </section>

        {{-- 2. Les réglages : chacun en une ligne, avec un choix par défaut --}}
        <section class="qc-step" data-qc-step="2" hidden>
            @include('web.partials.category-field')
            <div class="qc-row">
                <span class="qc-label">Durée</span>
                <div class="qc-chips">@include('web.partials.chips', ['name' => $isDuel ? 'duel_hours' : 'challenge_hours', 'options' => $presets, 'value' => (int) old($isDuel ? 'duel_hours' : 'challenge_hours', $isDuel ? 24 : 72)])</div>
            </div>
            @unless ($isDuel)
                {{-- Le maximum dépend de qui publie : un compte personnel ou un Espace --}}
                @php $caps = ['user' => config('competition.limits.user.challenge_max_entries'), 'space' => config('competition.limits.space.challenge_max_entries')]; $cap = $caps[$as ? 'space' : 'user']; @endphp
                <div class="qc-row" data-max-row data-caps='@json($caps)'>
                    <span class="qc-label">Challengers maximum</span>
                    <div class="qc-chips"><div class="chips-choice" role="radiogroup">
                        <label class="chip-opt"><input type="radio" name="max_entries" value="" @checked(old('max_entries', '') === '')><span>Le maximum</span></label>
                        @foreach ([10, 20, 50, 100, 500] as $n)
                            <label class="chip-opt" data-cap="{{ $n }}" @if($n >= $cap) hidden @endif><input type="radio" name="max_entries" value="{{ $n }}" @checked((int) old('max_entries') === $n) @disabled($n >= $cap)><span>{{ $n }}</span></label>
                        @endforeach
                    </div></div>
                    <small class="qc-hint" data-max-hint>{{ $as ? "Depuis un Espace : jusqu'à {$cap} challengers." : "En ton nom : jusqu'à {$cap} challengers. Publie depuis un de tes Espaces pour aller jusqu'à {$caps['space']}." }}</small>
                </div>
            @endunless
            @include('web.partials.geo-field')
            @feature('paid_votes')@include('web.partials.paid-votes', ['hint' => 'Chacun peut voter plusieurs fois pour son camp. Les coins te reviennent, moins '.config('coins.commission').' % pour Sosa.'])@endfeature
        </section>

        <p class="step-error" data-qc-error hidden>@icon('alert')<span></span></p>
        <div class="qc-send">
            <button type="button" class="btn btn-ghost btn-lg" data-qc-back hidden aria-label="Retour">@icon('arrow-left', 'ico-sm')</button>
            <button type="button" class="btn btn-lg btn-block" data-qc-next>Suivant @icon('arrow-right', 'ico-sm')</button>
            <button class="btn btn-lg btn-block" data-qc-submit hidden>@icon('send', 'ico-sm')<span>Publier</span></button>
        </div>
    </form>
</div>
@endsection
