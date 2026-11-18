{{-- Performance d'un participant mise en avant (défi, duel) : vidéo, audio avec visage et ondes, photo ou texte. $e : participation présentée --}}
@php $sm = $e['media'][0] ?? null; $face = $e['participant']['avatar_url'] ?? null; @endphp
<div class="showcase {{ $sm ? 'is-'.$sm['type'] : 'is-text' }}">
    @if ($sm && $sm['type'] === 'video')
        <div class="media-video">@include('web.partials.video', ['src' => $sm['url'], 'poster' => $sm['thumbnail_url'] ?? null, 'meta' => $sm['metadata'] ?? null, 'natural' => true])</div>
    @elseif ($sm && $sm['type'] === 'image')
        <img src="{{ $sm['url'] }}" alt="" loading="lazy" data-zoom>
    @elseif ($sm && $sm['type'] === 'audio')
        <div class="showcase-audio">
            <span class="face">@if ($face)<img loading="lazy" decoding="async" src="{{ $face }}" alt="">@else<span class="initial">{{ mb_strtoupper(mb_substr($e['display_name'] ?? '?', 0, 1)) }}</span>@endif</span>
            @include('web.partials.wave', ['m' => $sm])
        </div>
    @endif
    @if (!empty($e['content']) && ! ($hideText ?? false))
        {{-- Le texte de la performance se replie : « Lire plus » ouvre la suite (le texte entier reste dans la page) --}}
        <div class="showcase-more rm" data-rm="4"><p class="showcase-text rm-txt">{!! \App\Support\Mentions::html($e['content'], $e['mentions'] ?? []) !!}</p><button type="button" class="rm-btn" data-rm-btn hidden>Voir plus</button></div>
    @endif
</div>
