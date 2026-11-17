{{-- Lecteur audio avec ses ondes. $m : média audio présenté (url, metadata) --}}
@php $peaks = \App\Support\Wave::peaks($m, $bars ?? 48); $dur = \App\Support\Wave::duration($m); @endphp
<div class="wave {{ $class ?? '' }}" data-wave>
    <button type="button" class="wave-play" aria-label="Lire l'audio">@icon('play', 'play')@icon('pause', 'pause')</button>
    <div class="wave-bars" role="slider" aria-label="Position de lecture" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0">
        @foreach ($peaks as $p)<i style="height: {{ max(12, (int) round($p * 100)) }}%"></i>@endforeach
    </div>
    <span class="wave-time" data-wave-time>{{ $dur ?? '0:00' }}</span>
    <audio src="{{ $m['url'] }}" preload="none"></audio>
</div>
