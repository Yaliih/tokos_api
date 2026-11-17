{{-- Médias d'une publication : vidéo lue seulement quand elle est à l'écran, photo agrandie au clic --}}
@foreach (($media ?? []) as $m)
    @if ($m['type'] === 'video')
        <div class="media-video">@include('web.partials.video', ['src' => $m['url'], 'poster' => $m['thumbnail_url'] ?? null, 'meta' => $m['metadata'] ?? null, 'natural' => true])</div>
    @elseif ($m['type'] === 'audio')
        @include('web.partials.wave', ['m' => $m])
    @else
        <img src="{{ $m['url'] }}" alt="" loading="lazy" data-zoom>
    @endif
    @break($limit ?? false)
@endforeach
