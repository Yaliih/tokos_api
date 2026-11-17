{{-- Votes payants (concours, duel, tournoi) : un prix en coins par vote ; on peut voter plusieurs fois pour un seul camp --}}
@php $presets = config('coins.vote_cost_presets'); @endphp
<div class="paid-votes" data-paid-votes>
    <label class="switch"><span><b>Votes payants en coins</b><small>{{ $hint ?? 'On peut voter plusieurs fois, toujours pour le même. Les coins te reviennent, moins '.config('coins.commission').' % pour Sosa.' }}</small></span><input type="checkbox" data-paid-toggle @checked(old('vote_cost') > 0)></label>
    <div class="paid-box" data-paid-box @if(!(old('vote_cost') > 0)) hidden @endif>
        <span class="step-label">Prix d'un vote</span>
        <div class="chips-choice">
            @foreach ($presets as $p)<button type="button" class="chip-opt {{ (int) old('vote_cost', $presets[0]) === $p ? 'on' : '' }}" data-cost-preset="{{ $p }}">@icon('coin', 'ico-sm'){{ $p }}</button>@endforeach
            <input class="input cost-input" type="number" name="vote_cost" min="0" max="{{ config('coins.max_vote_cost') }}" value="{{ old('vote_cost', 0) }}" aria-label="Coins par vote" data-cost-input>
        </div>
    </div>
</div>
