{{--
    Choisir une discipline : un champ qui ouvre une fenêtre (en bas sur téléphone) avec une recherche.
    Tes goûts d'abord, puis toutes les disciplines (réglées dans l'admin). $value : discipline choisie.
--}}
@php
    $cats = config('competition.categories');
    $value = (string) ($value ?? old('category', ''));
    $mine = array_values(array_intersect((array) (auth()->user()->interests ?? []), array_keys($cats)));
    $others = array_values(array_diff(array_keys($cats), $mine));
    $label = $cats[$value] ?? null;
@endphp
<div class="cat-field" data-cat-field>
    {{-- Discipline désactivée depuis (ancienne publication) : rien n'est envoyé tant qu'on ne choisit pas, elle reste en place --}}
    <input type="hidden" @if($label || $value === '') name="category" @endif value="{{ $label ? $value : '' }}" data-cat-input>
    <button type="button" class="cat-btn {{ $label ? 'is-set' : '' }}" data-cat-open aria-haspopup="dialog">
        <span class="cat-ico">@icon('target', 'ico-sm')</span>
        <span class="cat-btn-t"><small>Discipline</small><b data-cat-label>{{ $label ?? 'Choisir (facultatif)' }}</b></span>
        @icon('chevron-right', 'ico-sm cat-caret')
    </button>
    <dialog class="sheet cat-sheet" data-cat-sheet aria-label="Choisir une discipline">
        <div class="sheet-head"><h2>Discipline</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
        <label class="cat-search">@icon('search', 'ico-sm')<input type="search" placeholder="Rechercher une discipline" autocomplete="off" enterkeyhint="search" data-cat-q aria-label="Rechercher une discipline"></label>
        <ul class="cat-list" role="listbox" data-cat-list>
            <li><button type="button" role="option" class="cat-opt" data-cat-opt="" aria-selected="{{ $label ? 'false' : 'true' }}"><span>Aucune</span>@icon('check', 'ico-sm cat-on')</button></li>
            @foreach (['Tes goûts' => $mine, ($mine ? 'Toutes les disciplines' : '') => $others] as $group => $keys)
                @if (count($keys))
                    @if ($group)<li class="cat-group" data-cat-group>{{ $group }}</li>@endif
                    @foreach ($keys as $k)
                        <li><button type="button" role="option" class="cat-opt" data-cat-opt="{{ $k }}" aria-selected="{{ $value === $k ? 'true' : 'false' }}"><span>{{ $cats[$k] }}</span>@icon('check', 'ico-sm cat-on')</button></li>
                    @endforeach
                @endif
            @endforeach
        </ul>
        <p class="cat-empty" data-cat-empty hidden>Aucune discipline ne correspond.</p>
    </dialog>
</div>
