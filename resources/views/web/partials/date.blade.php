{{-- Date et heure : raccourcis + champ stylé. $name, $presets [heures => libellé] --}}
<div class="date-field" data-date-field>
    <div class="chips-choice">
        @foreach ($presets as $h => $l)
            <button type="button" class="chip-opt-btn" data-date-preset="{{ $h }}">{{ $l }}</button>
        @endforeach
    </div>
    <span class="input-icon">@icon('calendar')<input class="input" type="datetime-local" name="{{ $name }}" value="{{ old($name) }}"></span>
</div>
