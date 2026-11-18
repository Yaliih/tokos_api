@php
    $live = \App\Models\CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
        ->where('status', 'voting')->where('is_bye', false)->whereNotNull('ends_at')->where('ends_at', '>', now())
        ->orderBy('ends_at')->limit(12)->get();

    // L'image d'un duel : celle du défi s'il en a une (photo, ou vignette d'une vidéo) ;
    // sinon on montre les photos des deux personnes côte à côte (voir plus bas).
    $shotOf = function ($match) {
        foreach ([$match->entryA, $match->entryB] as $entry) {
            foreach ($entry ? $entry->mediaUrls() : [] as $m) {
                $type = $m['type'] ?? 'image';
                if ($type === 'image' && ! empty($m['url'])) {
                    return $m['url'];
                }
                if ($type === 'video' && ! empty($m['thumbnail_url'])) {
                    return $m['thumbnail_url'];
                }
            }
        }

        return null;
    };
@endphp
<div class="stories" aria-label="Se termine bientôt">
    @auth
    <a class="story add" href="/creer">
        <span class="story-ring"><span class="inner">@icon('plus', 'ico-lg')</span></span>
        <small>Lancer</small>
    </a>
    @endauth
    @foreach ($live as $lm)
        @php
            $pa = $lm->entryA?->participant;
            $pb = $lm->entryB?->participant;
            $shot = $shotOf($lm);
        @endphp
        <a class="story" href="/m/{{ $lm->id }}" title="{{ $lm->entryA?->displayName() }} contre {{ $lm->entryB?->displayName() }}">
            <span class="story-ring"><span class="inner">
                @if ($shot)
                    {{-- Le défi a une image : c'est elle qu'on voit dans le cercle --}}
                    <img class="shot" loading="lazy" decoding="async" src="{{ $shot }}" alt="">
                @else
                    {{-- Sinon, un duel entre deux personnes : leurs deux photos --}}
                    <span class="pair">
                        @if ($pa)<img loading="lazy" decoding="async" src="{{ \App\Support\Participant::present($pa)['avatar_url'] }}" alt="">@else<span class="a">{{ mb_strtoupper(mb_substr($lm->entryA?->displayName() ?? '?', 0, 1)) }}</span>@endif
                        @if ($pb)<img loading="lazy" decoding="async" src="{{ \App\Support\Participant::present($pb)['avatar_url'] }}" alt="">@else<span class="b">{{ mb_strtoupper(mb_substr($lm->entryB?->displayName() ?? '?', 0, 1)) }}</span>@endif
                    </span>
                @endif
                <span class="vs">VS</span>
            </span></span>
            <small class="story-time"><span class="countdown" data-ends="{{ $lm->ends_at->toIso8601String() }}"></span></small>
        </a>
    @endforeach
</div>