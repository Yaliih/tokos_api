{{-- Choix rapide sous forme de pastilles (durées…). $name, $options [valeur => libellé], $value --}}
<div class="chips-choice" role="radiogroup">
    @foreach ($options as $v => $l)
        <label class="chip-opt"><input type="radio" name="{{ $name }}" value="{{ $v }}" @checked((string) $value === (string) $v)><span>{{ $l }}</span></label>
    @endforeach
</div>
