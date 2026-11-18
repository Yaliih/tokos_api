@extends('web.layout')
@section('content')
{{-- Modifier un profil ou un Espace : la photo tout en haut, puis des blocs qu'on ouvre un par un
     (Identité, Préférences, Bio). Un seul formulaire : « Enregistrer » en bas enregistre tout. --}}
@php
    // Un bloc s'ouvre d'office s'il contient une erreur de validation (sinon on ne verrait pas le champ fautif)
    $openIf = fn (array $fields) => collect($fields)->contains(fn ($f) => $errors->has($f));
    $isUser = $kind === 'user';
@endphp
<div class="back-line"><a href="{{ $back }}">@icon('arrow-left', 'ico-sm')<span>{{ $isUser ? 'Modifier le profil' : 'Modifier l\'Espace' }}</span></a></div>
<form class="edit-form card" method="post" action="{{ $action }}" enctype="multipart/form-data">
    @csrf
    <div class="edit-cover" data-preview>
        <div class="edit-cover-media" data-preview-box>
            @if ($cover)
                @if ($coverType === 'video')<video src="{{ $cover }}#t=0.1" muted playsinline loop preload="metadata"></video>@else<img loading="lazy" decoding="async" src="{{ $cover }}" alt="">@endif
            @else
                <span class="edit-cover-empty">@icon('image')Ajoute une photo ou une courte vidéo</span>
            @endif
        </div>
        <input type="file" name="cover" accept="image/*,video/*" hidden data-preview-input data-max-mb="{{ (int) config('competition.media.max_upload_mb', 50) }}" data-max-video="{{ (int) config('competition.media.max_cover_video_seconds', 30) }}">
        <div class="edit-cover-btns">
            <button type="button" class="vbtn wide" data-preview-pick>@icon('camera', 'ico-sm')<span>Couverture</span></button>
            @if ($cover)<label class="vbtn" title="Retirer la couverture"><input type="checkbox" name="remove_cover" value="1" hidden data-remove-cover>@icon('x', 'ico-sm')</label>@endif
        </div>
    </div>

    <div class="edit-avatar" data-preview>
        <span class="edit-avatar-img" data-preview-box><img loading="lazy" decoding="async" src="{{ $avatar }}" alt=""></span>
        <input type="file" name="avatar" accept="image/*" hidden data-preview-input data-max-mb="8">
        <button type="button" class="edit-avatar-btn" data-preview-pick aria-label="Changer la photo de profil">@icon('camera', 'ico-sm')</button>
    </div>

    <div class="edit-forms">
        {{-- 1. Identité --}}
        <details class="pedit" @if(! $errors->any() || $openIf($isUser ? ['first_name', 'last_name', 'username'] : ['name', 'category'])) open @endif>
            <summary><span class="pedit-t">@icon('user', 'ico-sm')Identité</span><span class="pedit-hint">{{ $isUser ? 'Prénom, nom, pseudo' : 'Nom, catégorie' }}</span></summary>
            <div class="pedit-body">
                @if ($isUser)
                    <div class="grid-2">
                        <label class="field"><span>Prénom</span><input class="input" name="first_name" required minlength="2" maxlength="60" value="{{ old('first_name', $values['first_name']) }}"></label>
                        <label class="field"><span>Nom</span><input class="input" name="last_name" maxlength="60" value="{{ old('last_name', $values['last_name']) }}"></label>
                    </div>
                    {{-- Le pseudo ne se change qu'une seule fois (le premier est créé à l'inscription) --}}
                    @php $canRename = auth()->user()?->canChangeUsername() ?? false; @endphp
                    @if ($canRename)
                        <label class="field"><span>Pseudo</span><span class="input-icon">@icon('at')<input class="input" name="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_.]+" value="{{ old('username', $values['username']) }}" data-username-once="{{ $values['username'] }}"></span>
                            <small>Ton adresse : tokos/@<b data-handle-preview>{{ $values['username'] }}</b>. Tu ne peux le changer <b>qu'une seule fois</b> : choisis bien. L'ancien mènera à ton profil.</small></label>
                    @else
                        <div class="field"><span>Pseudo</span><span class="input-icon is-locked">@icon('at')<input class="input" value="{{ $values['username'] }}" readonly aria-readonly="true" tabindex="-1">@icon('lock', 'ico-sm lock')</span>
                            <small>Tu as déjà changé ton pseudo une fois : il ne peut plus être modifié.</small></div>
                    @endif
                @else
                    <label class="field"><span>Nom de l'Espace</span><input class="input" name="name" required minlength="2" maxlength="80" value="{{ old('name', $values['name']) }}"></label>
                    @include('web.partials.space-category', ['category' => old('category', $values['category']), 'subcategory' => old('subcategory', $values['subcategory'] ?? null)])
                @endif
            </div>
        </details>

        {{-- 2. Préférences --}}
        <details class="pedit" @if($openIf(['city', 'school_space_id', 'interests', 'location', 'website', 'site_template'])) open @endif>
            <summary><span class="pedit-t">@icon('settings', 'ico-sm')Préférences</span><span class="pedit-hint">{{ $isUser ? ' Ville, institution, goûts' : 'Ville, site, réglages' }}</span></summary>
            <div class="pedit-body">
                @if ($isUser)
                    {{-- Ville et institution : pour trier les classements --}}
                    <div class="grid-2">
                        <label class="field"><span>@icon('map-pin', 'ico-sm')Ville @if(!empty($values['located']))<small class="ok-tag">@icon('check', 'ico-sm')vérifiée</small>@endif</span>
                            <span class="input-btn"><input class="input" name="city" list="cities" maxlength="60" value="{{ old('city', $values['city'] ?? '') }}" placeholder="Kinshasa" data-my-city>
                                <button type="button" class="btn btn-soft btn-sm" data-locate aria-label="Me localiser">@icon('map-pin', 'ico-sm')<span class="hide-sm">Me localiser</span></button></span>
                            <datalist id="cities">@foreach (config('places.cities') as $c)<option value="{{ $c }}">@endforeach</datalist>
                            <small>Certaines compétitions sont réservées à une ville : ta position la confirme.</small></label>
                        <label class="field"><span>@icon('school', 'ico-sm')Institution</span>
                            <select class="input" name="school_space_id">
                                <option value="">Aucune</option>
                                @foreach (\App\Models\Space::where('kind', 'school')->where('is_active', true)->orderByDesc('is_verified')->orderBy('name')->get(['id', 'name', 'is_verified']) as $sc)
                                    <option value="{{ $sc->id }}" @selected((string) old('school_space_id', $values['school_space_id'] ?? '') === (string) $sc->id)>{{ $sc->name }}</option>
                                @endforeach
                            </select>
                            <small>Les Espaces de la catégorie Éducation. Le tien n'y est pas ? Il peut créer son Espace.</small></label>
                    </div>
                    <div class="field"><span>@icon('sparkles', 'ico-sm')Ce qui te plaît</span>
                        @include('web.partials.interest-chips', ['selected' => old('interests', $values['interests'] ?? [])])
                        <input type="hidden" name="interests_sent" value="1">
                        <small>On te montre d'abord les compétitions de ces disciplines.</small></div>
                @else
                    <label class="field"><span>@icon('map-pin', 'ico-sm')Ville</span>
                        <input class="input" name="location" list="cities" maxlength="80" value="{{ old('location', $values['location'] ?? '') }}" placeholder="Kinshasa">
                        <datalist id="cities">@foreach (config('places.cities') as $c)<option value="{{ $c }}">@endforeach</datalist></label>
                    <label class="field"><span>@icon('link', 'ico-sm')Site ou page <small class="faint">(facultatif)</small></span>
                        <input class="input" name="website" maxlength="120" value="{{ old('website', $values['website'] ?? '') }}" placeholder="facebook.com/votre-page"></label>
                    <label class="switch"><span><b>Valider les nouveaux membres</b><small>Chaque demande attend l'accord d'un administrateur ou d'un modérateur. Sinon, tout le monde peut rejoindre.</small></span><input type="hidden" name="join_approval_sent" value="1"><input type="checkbox" name="join_approval" value="1" @checked(old('join_approval', $values['join_approval'] ?? false))></label>
                    {{-- Le petit site de l'Espace : choix du modèle --}}
                    @feature('space_sites')<div class="field" style="margin-top:14px"><span>@icon('globe', 'ico-sm')Modèle du site</span>
                        <div class="tpl-pick">
                            @foreach (config('sites.templates') as $k => $t)
                                <label class="tpl"><input type="radio" name="site_template" value="{{ $k }}" @checked(old('site_template', $values['site_template'] ?? config('sites.default')) === $k)>
                                    <span class="tpl-box"><span class="tpl-mini {{ $k }}" aria-hidden="true"><i></i><i></i><i></i></span><b>{{ $t['label'] }}</b><small>{{ $t['help'] }}</small>
                                        <a href="/espaces/{{ $values['slug'] }}/site?modele={{ $k }}" target="_blank" rel="noopener">Aperçu</a></span></label>
                            @endforeach
                        </div></div>@endfeature
                @endif
            </div>
        </details>

        {{-- 3. Bio --}}
        <details class="pedit" @if($openIf(['bio'])) open @endif>
            <summary><span class="pedit-t">@icon('type', 'ico-sm')Bio</span><span class="pedit-hint">Quelques mots sur toi</span></summary>
            <div class="pedit-body">
                <label class="field"><span>Bio</span><textarea class="input" name="bio" maxlength="{{ $isUser ? 300 : 500 }}" rows="3" placeholder="{{ $isUser ? 'Qui es-tu ? Ce que tu sais faire, ta ville…' : 'Ce que fait votre Espace, où vous trouver…' }}" data-count>{{ old('bio', $values['bio']) }}</textarea><small data-count-out></small></label>
            </div>
        </details>
    </div>

    <div class="edit-save"><a class="btn btn-ghost" href="{{ $back }}">@icon('x', 'ico-sm')Annuler</a><button class="btn">@icon('check', 'ico-sm')Enregistrer</button></div>
</form>
@endsection