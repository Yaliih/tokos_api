{{-- Catégorie d'Espace, puis sous-catégorie facultative (seules celles de la catégorie choisie s'affichent) --}}
@php $cats = config('spaces.categories'); $cat = $category ?? null; $sub = $subcategory ?? null; @endphp
<div class="grid-2" data-space-cat>
    <label class="field"><span>Catégorie</span>
        <select class="input" name="category" data-cat-select>
            <option value="">Choisir</option>
            @foreach ($cats as $k => $c)<option value="{{ $k }}" @selected($cat === $k)>{{ $c['label'] }}</option>@endforeach
        </select></label>
    <label class="field" data-sub-field @if(! $cat || empty($cats[$cat]['sub'])) hidden @endif><span>Précise <small class="faint">(facultatif)</small></span>
        <select class="input" name="subcategory" data-sub-select>
            <option value="">—</option>
            @foreach ($cats as $k => $c)
                @foreach ($c['sub'] as $sk => $sl)<option value="{{ $sk }}" data-cat="{{ $k }}" @selected($cat === $k && $sub === $sk) @if($cat !== $k) hidden disabled @endif>{{ $sl }}</option>@endforeach
            @endforeach
        </select></label>
</div>
