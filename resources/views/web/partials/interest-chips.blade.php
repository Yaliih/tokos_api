{{-- Centres d'intérêt : les disciplines des compétitions, plusieurs choix possibles. $selected : clés déjà choisies --}}
@php $selected = (array) ($selected ?? []); @endphp
<div class="chips-choice interests" role="group" aria-label="Centres d'intérêt">
    @foreach (config('competition.categories') as $k => $label)
        <label class="chip-opt"><input type="checkbox" name="interests[]" value="{{ $k }}" @checked(in_array($k, $selected, true))><span>{{ $label }}</span></label>
    @endforeach
</div>
