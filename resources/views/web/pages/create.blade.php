@extends('web.layout')
@section('content')
@php
    $types = [
        'challenge' => ['Défi', 'challenge', 'Tu publies ta performance, chacun vient t\'affronter en duel.'],
        'duel' => ['Duel', 'vs', 'Face à face avec quelqu\'un, ou un versus entre deux propositions.'],
        'contest' => ['Concours', 'medal', 'Des candidats, le public vote, un classement final.'],
        'tournament' => ['Tournoi', 'bracket', 'Élimination directe, tour après tour jusqu\'à la finale.'],
    ];
    // Défi et duel se publient sur l'écran rapide (create-quick) : ici, concours et tournoi
    $types = array_filter($types, fn ($k) => in_array($k, ['contest', 'tournament'], true) && \App\Support\Features::type($k), ARRAY_FILTER_USE_KEY);
    $presetType = old('type', $preset);
    // Le type est déjà choisi (on arrive ici par « Concours » ou « Tournoi ») : pas d'écran « Que veux-tu lancer ? »,
    // on va droit au contenu. Les onglets du haut permettent de changer de type.
    $typeKnown = $presetType && array_key_exists($presetType, $types);
    $limits = config('competition.limits');
    $sizes = config('competition.tournament_sizes');
    $initialSpace = old('space', $space);
    $initialLimits = $limits[$initialSpace ? 'space' : 'user'];
    $presets = config('competition.duration_presets');
    $media = config('competition.media');
    // Les étapes affichées dépendent du format : un duel se lance en deux étapes
    // L'étape « Réglages » (genre de duel, format, publier en tant que) vient après le choix du type
    $settingsFor = 'challenge duel tournament'.($mySpaces->isNotEmpty() ? ' contest' : '');
    $dots = [
        ['Type', null],
        ['Réglages', $settingsFor],
        ['Le duel', 'duel'],
        ['L\'essentiel', 'challenge contest tournament'],
        ['Participants', 'challenge contest tournament'],
        ['Publication', 'challenge contest tournament'],
    ];
    $durationChips = function (string $name, int $default) use ($presets) {
        return view('web.partials.chips', ['name' => $name, 'options' => $presets, 'value' => (int) old($name, $default)])->render();
    };
@endphp
<div class="wizard">
    <nav class="type-tabs" aria-label="Que veux-tu lancer ?">
        @foreach (collect(['challenge' => ['Défi', 'challenge'], 'duel' => ['Duel', 'vs'], 'contest' => ['Concours', 'medal'], 'tournament' => ['Tournoi', 'bracket']])->filter(fn ($v, $k) => \App\Support\Features::type($k)) as $k => [$label, $ico])
            <a href="/creer?type={{ $k }}{{ $space ? '&espace='.$space : '' }}" @if($presetType === $k) aria-current="page" @endif>@icon($ico, 'ico-sm'){{ $label }}</a>
        @endforeach
    </nav>
    <div class="wizard-head">
        <a class="row muted small" href="{{ url()->previous() !== url()->current() ? url()->previous() : '/' }}" style="font-weight:700">@icon('arrow-left', 'ico-sm')Retour</a>
        <h1 style="margin-top:10px" data-type-title>Lancer
            <span data-for="challenge">un défi</span><span data-for="duel">un duel</span><span data-for="contest">un concours</span><span data-for="tournament">un tournoi</span></h1>
        <ol class="steps" aria-label="Étapes">
            @foreach ($dots as $i => [$s, $for])@continue($i === 0 && $typeKnown)<li class="{{ $i === ($typeKnown ? 1 : 0) ? 'current' : '' }}" data-step-dot @if($for) data-for="{{ $for }}" @endif><span class="bar"></span><span>{{ $s }}</span></li>@endforeach
        </ol>
    </div>

    <form id="create-form" class="card card-pad" method="post" action="/creer" enctype="multipart/form-data" data-wizard novalidate
          data-limits='@json($limits)' data-suggest-url="/recherche/participants">
        @csrf

        {{-- 1. Format --}}
        <section class="step {{ $typeKnown ? '' : 'active' }}" data-step data-type-step @if($typeKnown) hidden @endif>
            <h2>Que veux-tu lancer ?</h2>
            <p class="lead">Les duels, les votes et le classement se gèrent ensuite tout seuls.</p>
            <div class="options">
                @foreach ($types as $value => [$label, $ico, $hint])
                    <label class="option"><input type="radio" name="type" value="{{ $value }}" @checked($presetType === $value) required>
                        <span class="box">@icon($ico)<strong>{{ $label }}</strong><span>{{ $hint }}</span></span></label>
                @endforeach
            </div>
        </section>

        {{-- 2. Réglages : seulement une fois le type choisi --}}
        <section class="step" data-step data-for="{{ $settingsFor }}">
            <h2><span data-for="challenge">Ton défi</span><span data-for="duel">Ton duel</span><span data-for="contest">Ton concours</span><span data-for="tournament">Ton tournoi</span></h2>
            <p class="lead">Quelques réglages, puis on passe au contenu.</p>
            <div data-for="duel">
                <span class="step-label">Quel genre de duel ?</span>
                <div class="options">
                    <label class="option compact"><input type="radio" name="format" value="challenge" @checked(old('format', 'challenge') === 'challenge')><span class="box">@icon('user-plus')<strong>Je défie quelqu'un</strong></span></label>
                    @feature('versus')<label class="option compact"><input type="radio" name="format" value="versus" @checked(old('format') === 'versus')><span class="box">@icon('columns')<strong>Versus : deux propositions</strong></span></label>@endfeature
                </div>
            </div>
            <div data-for="challenge duel tournament" style="margin-top:18px">
                <span class="step-label">Format des performances</span>
                <p class="limit-hint" style="margin:-2px 0 10px">Tout le monde répond dans le même format que toi : vidéo contre vidéo, audio contre audio, texte contre texte.</p>
                <div class="options formats">
                    @foreach (['video' => ['video', 'Vidéo'], 'audio' => ['mic', 'Audio'], 'image' => ['image', 'Photo'], 'text' => ['type', 'Texte']] as $fv => [$fi, $fl])
                        <label class="option compact"><input type="radio" name="entry_format" value="{{ $fv }}" @checked(old('entry_format', 'video') === $fv)><span class="box">@icon($fi)<strong>{{ $fl }}</strong></span></label>
                    @endforeach
                </div>
            </div>
            @if ($mySpaces->isNotEmpty())
                <div style="margin-top:18px"><span class="step-label">Publier en tant que</span>
                <div class="options">
                    <label class="option compact"><input type="radio" name="space" value="" @checked(!$initialSpace)><span class="box">@include('web.partials.avatar', ['p' => \App\Support\Participant::present(auth()->user()), 'size' => 'sm'])<strong>Moi-même</strong></span></label>
                    @foreach ($mySpaces as $s)
                        <label class="option compact"><input type="radio" name="space" value="{{ $s->slug }}" @checked($initialSpace === $s->slug)><span class="box">@include('web.partials.avatar', ['p' => \App\Support\Participant::present($s), 'size' => 'sm'])<strong>{{ $s->name }}</strong></span></label>
                    @endforeach
                </div></div>
            @endif
        </section>

        {{-- Duel : tout sur une seule étape, on lance directement --}}
        <section class="step" data-step data-for="duel">
            <div data-for="duel:challenge">
                <h2>Qui veux-tu défier ?</h2>
                <p class="lead">Tape @ puis son nom. Il est prévenu tout de suite.</p>
                <div class="suggest" data-suggest>
                    <label class="field" style="margin-bottom:6px"><span>Adversaire</span>
                        <span class="input-icon">@icon('at')<input class="input" name="opponent" autocomplete="off" value="{{ old('opponent', $opponent) }}" placeholder="pseudo ou nom de l'Espace" data-suggest-input data-required-for="duel:challenge" data-unless-filled="opponent_phone"></span></label>
                    <input type="hidden" name="opponent_type" value="{{ $opponentType }}" data-suggest-type>
                    <ul class="suggest-list" data-suggest-list hidden role="listbox"></ul>
                </div>
                @feature('phone_invites')
                <button type="button" class="link-btn" data-toggle-phone>@icon('phone', 'ico-sm')Il n'a pas de compte ? Invite-le par son numéro</button>
                <div class="phone-fallback" data-phone-fallback hidden>
                    <p class="note">@icon('info')<span>On lui envoie le défi par SMS. Tu pourras aussi le lui envoyer sur WhatsApp. À son inscription avec ce numéro, le duel l'attend.</span></p>
                    @include('web.partials.phone-field', ['id' => 'opp', 'code' => 'opponent_phone_code', 'number' => 'opponent_phone', 'required' => false, 'placeholder' => 'Son numéro', 'autocomplete' => 'off'])
                </div>
                @endfeature
                <label class="field" style="margin-top:14px"><span>Message (facultatif)</span><input class="input" name="message" maxlength="280" data-emoji placeholder="Par défaut : « {{ auth()->user()->first_name }} te défie ! Montre ce que tu sais faire, le public décidera. »"></label>
                <span class="step-label">Ta performance</span>
                @include('web.partials.picker', ['name' => 'media[0][file]', 'meta' => 'media[0][metadata]', 'label' => 'Ta performance', 'hint' => 'Ou glisse un fichier ici.', 'follow' => true])
                <label class="field" style="margin-top:12px"><span data-text-label>Ton texte</span><textarea class="input" data-emoji name="content" maxlength="5000" data-content-for="duel:challenge" placeholder="Ta punchline, ta réponse…">{{ old('content') }}</textarea></label>
            </div>
            <div data-for="duel:versus">
                <h2>Les deux propositions</h2>
                <p class="lead">Les deux dans le même format : le public choisit son camp.</p>
                <div class="versus-form">
                    @foreach (['a' => 'Proposition 1', 'b' => 'Proposition 2'] as $k => $label)
                        <div class="versus-form-side">
                            <label class="field"><span>{{ $label }}</span><input class="input" name="side_{{ $k }}_label" maxlength="80" placeholder="{{ $k === 'a' ? 'Ex. Pondu' : 'Ex. Moambe' }}" data-required-for="duel:versus"></label>
                            @include('web.partials.picker', ['name' => 'side_'.$k.'_media[0][file]', 'meta' => 'side_'.$k.'_media[0][metadata]', 'label' => 'Média', 'size' => 'small', 'hint' => 'Ou glisse un fichier ici.', 'follow' => true])
                            <textarea class="input" data-emoji name="side_{{ $k }}_content" maxlength="2000" placeholder="Texte (facultatif)" data-versus-text style="margin-top:10px;min-height:72px"></textarea>
                        </div>
                    @endforeach
                </div>
            </div>
            <span class="step-label" style="margin-top:16px">Durée du duel</span>
            {!! $durationChips('duel_hours', 24) !!}
            <div style="margin-top:14px">@include('web.partials.geo-field')</div>
            @feature('paid_votes')<div style="margin-top:14px">@include('web.partials.paid-votes', ['hint' => 'Chacun peut voter plusieurs fois pour son camp. Les coins te reviennent, moins '.config('coins.commission').' % pour Sosa.'])</div>@endfeature
        </section>

        {{-- L'essentiel --}}
        <section class="step" data-step data-for="challenge contest tournament">
            <h2>L'essentiel</h2>
            <p class="lead">Un titre clair attire plus de monde.</p>
            <label class="field"><span>Titre</span><input class="input" name="title" minlength="3" maxlength="120" value="{{ old('title') }}" placeholder="Ex. Le meilleur freestyle de 30 secondes" data-required-for="challenge contest tournament"></label>
            <div class="field">@include('web.partials.category-field')</div>
            <label class="field"><span>Description<em class="req" data-for="contest"> obligatoire</em></span><small data-for="challenge" style="margin:-4px 0 8px">Explique le défi. Ta performance, tu l'ajoutes à l'étape suivante.</small><textarea class="input" data-emoji name="description" maxlength="5000" placeholder="Ce que tu attends des participants" data-required-for="contest">{{ old('description') }}</textarea></label>
            <div data-for="contest tournament">
            <span class="step-label">Couverture : une image ou une courte vidéo</span>
            @include('web.partials.picker', ['name' => 'cover', 'meta' => 'cover_duration', 'kinds' => ['image', 'video'], 'label' => 'Image ou vidéo de couverture', 'maxVideo' => $media['max_cover_video_seconds'], 'hint' => 'Elle s\'affiche en haut et dans le fil.', 'compact' => true, 'requiredFor' => 'contest tournament', 'requiredMessage' => 'Ajoute une couverture : une image ou une courte vidéo. Elle est obligatoire pour un concours ou un tournoi.'])
            </div>
        </section>

        {{-- Participants --}}
        <section class="step" data-step data-for="challenge contest tournament">
            <div data-for="challenge">
                <h2>Montre ce que tu sais faire</h2>
                <p class="lead">Ta performance ouvre le défi : chaque challenger viendra t'affronter.</p>
                @include('web.partials.picker', ['name' => 'media[0][file]', 'meta' => 'media[0][metadata]', 'label' => 'Ta performance', 'hint' => 'Ou glisse un fichier ici.', 'follow' => true])
                <label class="field" style="margin-top:12px"><span data-text-label>Ton texte</span><textarea class="input" data-emoji name="content" maxlength="5000" data-content-for="challenge" placeholder="Ta punchline, ta réponse…">{{ old('content') }}</textarea></label>
                <label class="field"><span>Challengers maximum</span><input class="input" type="number" name="max_entries" min="2" data-limit-key="challenge_max_entries" max="{{ $initialLimits['challenge_max_entries'] }}" value="{{ old('max_entries') }}" placeholder="Jusqu'à {{ $initialLimits['challenge_max_entries'] }}"></label>
                <label class="switch"><span><b>Montrer chaque duel dans le fil</b><small>Chaque challenger qui relève ton défi apparaît dans le fil d'actualité.</small></span><input type="hidden" name="show_duels_in_feed" value="0"><input type="checkbox" name="show_duels_in_feed" value="1" checked></label>
            </div>
            <div data-for="contest tournament">
                <h2>Les participants</h2>
                <p class="lead">Combien, qui, et comment ils s'inscrivent.</p>
                <div data-for="tournament">
                    <span class="step-label">Taille du tableau</span>
                    <div class="options sizes">
                        @foreach ($sizes as $n)
                            <label class="option pill" @if($n > $initialLimits['tournament_max_entries']) hidden @endif><input type="radio" name="max_entries" value="{{ $n }}" data-size @checked((int) old('max_entries', 8) === $n) @disabled($n > $initialLimits['tournament_max_entries'])><span class="box"><strong>{{ $n }}</strong></span></label>
                        @endforeach
                    </div>
                </div>
                <label class="field" data-for="contest"><span>Candidats maximum</span><input class="input" type="number" name="max_entries" min="2" data-limit-key="contest_max_entries" max="{{ $initialLimits['contest_max_entries'] }}" value="{{ old('max_entries') }}" placeholder="Jusqu'à {{ $initialLimits['contest_max_entries'] }}"></label>
                <p class="limit-hint" data-limit-hint>Jusqu'à <b data-limit-value>{{ $initialLimits['contest_max_entries'] }}</b> participants.</p>

                @feature('space_competitions')
                <span class="step-label">Qui peut s'inscrire</span>
                <div class="options">
                    <label class="option compact"><input type="radio" name="participation_mode" value="individual" checked><span class="box">@icon('user')<strong>Les personnes</strong></span></label>
                    <label class="option compact"><input type="radio" name="participation_mode" value="space"><span class="box">@icon('users')<strong>Les Espaces</strong></span></label>
                    <label class="option compact"><input type="radio" name="participation_mode" value="mixed"><span class="box">@icon('sparkles')<strong>Les deux</strong></span></label>
                </div>
                @endfeature
                @feature('applications')<label class="switch" style="margin-top:14px"><span><b>Je valide moi-même les candidatures</b><small>Chaque candidat envoie sa photo, une présentation et, s'il veut, une courte vidéo. Tu acceptes ou refuses.</small></span><input type="checkbox" name="requires_approval" value="1" checked></label>@endfeature
                @feature('prizes')
                {{-- Récompenses par place : 1er, 2e… ou une plage (3e à 20e). En texte et/ou en coins (bloqués à la publication). --}}
                @php $coinsOn = \App\Support\Features::on('coins'); $oldTiers = old('prize_tiers', [['from' => 1, 'to' => 1, 'text' => '', 'coins' => '']]); @endphp
                <div class="prize-tiers" data-prize-tiers data-balance="{{ (int) auth()->user()->coins }}">
                    <span class="step-label">Récompenses <small>(facultatif)</small></span>
                    <div class="pt-rows" data-pt-rows>
                        @foreach ($oldTiers as $i => $t)
                            <div class="pt-row" data-pt-row>
                                <span class="pt-place"><span>Places</span><input type="number" min="1" max="2048" name="prize_tiers[{{ $i }}][from]" value="{{ $t['from'] ?? 1 }}" aria-label="De la place" data-pt-from>
                                    <span>à</span><input type="number" min="1" max="2048" name="prize_tiers[{{ $i }}][to]" value="{{ $t['to'] ?? 1 }}" aria-label="À la place" data-pt-to></span>
                                <input class="pt-text" name="prize_tiers[{{ $i }}][text]" maxlength="120" value="{{ $t['text'] ?? '' }}" placeholder="Récompense : ex. 100 $ + un trophée" aria-label="Récompense">
                                @if ($coinsOn)<label class="pt-coins">@icon('coin', 'ico-sm')<input type="number" min="0" name="prize_tiers[{{ $i }}][coins]" value="{{ $t['coins'] ?? '' }}" placeholder="0" aria-label="Coins par gagnant" data-pt-coins></label>@endif
                                <button type="button" class="icon-btn pt-del" data-pt-del aria-label="Retirer cette ligne">@icon('x', 'ico-sm')</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="link-btn" data-pt-add>@icon('plus', 'ico-sm')Ajouter une plage</button>
                    @if ($coinsOn)
                        <p class="pt-total" data-pt-total hidden>@icon('lock', 'ico-sm')<span><b data-pt-sum>0</b> coins seront bloqués dès la publication, puis envoyés aux gagnants à la fin. Ton solde : <b>{{ number_format((int) auth()->user()->coins, 0, ',', ' ') }}</b>.</span></p>
                        <p class="pt-total pt-short" data-pt-short hidden>@icon('alert', 'ico-sm')<span>Il te manque des coins. <a href="/portefeuille?section=recharger">Recharger</a></span></p>
                    @endif
                </div>
                @endfeature
            </div>
        </section>

        {{-- Publication --}}
        <section class="step" data-step data-for="challenge contest tournament">
            <h2>Publication</h2>
            <p class="lead">Les scores sont visibles par tous pendant les votes.</p>

            <div data-for="challenge">
                <span class="step-label">Durée du défi</span>
                {!! $durationChips('challenge_hours', 72) !!}
                <p class="note" style="margin-top:10px">@icon('clock')<span>Tous les duels se terminent à la fin du défi. Le premier challenger a plus de temps pour récolter des votes que le dernier.</span></p>
            </div>
            <div data-for="tournament">
                <span class="step-label">Durée de chaque tour</span>
                {!! $durationChips('round_hours', 24) !!}
            </div>
            <div class="grid-2" style="margin-top:14px">
                <div class="field" data-for="contest tournament"><span>Fin des inscriptions</span>@include('web.partials.date', ['name' => 'registration_ends_at', 'presets' => [24 => 'Dans 24 h', 72 => '3 jours', 168 => '1 semaine']])<small>Lancement à cette date, ou dès que c'est complet.</small></div>
                <div class="field" data-for="contest"><span>Fin des votes</span>@include('web.partials.date', ['name' => 'ends_at', 'presets' => [168 => '1 semaine', 336 => '2 semaines', 720 => '1 mois']])</div>
            </div>

            <span class="step-label">Qui peut participer</span>
            <div class="options" style="margin-bottom:14px">
                <label class="option compact"><input type="radio" name="access" value="open" checked><span class="box">@icon('globe')<strong>Tout le monde</strong></span></label>
                <label class="option compact"><input type="radio" name="access" value="members" data-space-only @disabled(!$initialSpace)><span class="box">@icon('users')<strong>Membres de l'Espace</strong></span></label>
                <label class="option compact"><input type="radio" name="access" value="invited"><span class="box">@icon('lock')<strong>Sur invitation</strong></span></label>
            </div>
            <label class="field" data-invitees><span>Inviter <em class="req" data-invitees-required hidden>obligatoire</em></span>
                <input class="input" name="invitees_text" placeholder="@pseudo, #espace" data-required-when="access=invited"><small>@ pour une personne, # pour un Espace. Ils reçoivent une notification.</small></label>
            @feature('paid_votes')<div data-for="contest tournament" style="margin-top:6px">@include('web.partials.paid-votes', ['hint' => 'Concours : on vote plusieurs fois, pour un seul candidat. Tournoi : plusieurs votes par duel, pour un seul camp. Les coins te reviennent, moins '.config('coins.commission').' % pour Sosa.'])</div>@endfeature
            <div style="margin-top:6px">@include('web.partials.geo-field')</div>
            @feature('jury')
            <div class="jury-field" data-for="contest tournament" data-space-field @if(!$initialSpace) hidden @endif>
                <label class="field"><span>@icon('award', 'ico-sm')Jury <small class="faint">(facultatif)</small></span>
                    <input class="input" name="jurors_text" value="{{ old('jurors_text') }}" placeholder="@pseudo1, @pseudo2, @pseudo3">
                    <small><span data-for="contest">Chaque juré note les candidats de 0 à 10.</span><span data-for="tournament">Chaque juré vote dans chaque duel.</span></small></label>
                <span class="step-label">Poids du jury dans le résultat</span>
                @include('web.partials.chips', ['name' => 'jury_weight', 'options' => [30 => '30 %', 50 => '50 %', 70 => '70 %', 100 => 'Jury seul'], 'value' => (int) old('jury_weight', 50)])
                <small class="faint small" style="display:block;margin-top:6px">Le reste vient des votes du public. Sans juré, seul le public compte.</small>
            </div>
            @endfeature
        </section>

        <p class="step-error" data-step-error hidden>@icon('alert')<span></span></p>
        <div class="wizard-nav">
            <button type="button" class="btn btn-ghost" data-prev hidden>@icon('chevron-left', 'ico-sm')Précédent</button>
            <span></span>
            <button type="button" class="btn" data-next>Continuer @icon('chevron-right', 'ico-sm')</button>
            <button type="submit" class="btn btn-lg" data-submit hidden>@icon('send', 'ico-sm')
                <span data-for="challenge">Lancer le défi</span><span data-for="duel">Lancer le duel</span><span data-for="contest">Publier le concours</span><span data-for="tournament">Publier le tournoi</span></button>
        </div>
    </form>
</div>
@endsection
