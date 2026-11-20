@php
    $live = \App\Models\CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
        ->where('status', 'voting')->where('is_bye', false)->whereNotNull('ends_at')->where('ends_at', '>', now())
        ->orderBy('ends_at')->limit(12)->get();

    // L'image d'un côté du cercle : celle de la performance d'abord (photo, ou vignette d'une vidéo).
    $visualOf = function ($entry) {
        foreach ($entry ? $entry->mediaUrls() : [] as $m) {
            $type = $m['type'] ?? 'image';
            if ($type === 'image' && ! empty($m['url'])) {
                return $m['url'];
            }
            if ($type === 'video' && ! empty($m['thumbnail_url'])) {
                return $m['thumbnail_url'];
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
            $ppa = $pa ? \App\Support\Participant::present($pa) : null;
            $ppb = $pb ? \App\Support\Participant::present($pb) : null;
            // Côté gauche / côté droit : l'image du duel, sinon la vraie photo de profil, sinon les initiales.
            $ia = $visualOf($lm->entryA) ?: (($ppa['has_avatar'] ?? false) ? $ppa['avatar_url'] : null);
            $ib = $visualOf($lm->entryB) ?: (($ppb['has_avatar'] ?? false) ? $ppb['avatar_url'] : null);
        @endphp
        <a class="story" href="/m/{{ $lm->id }}" title="{{ $lm->entryA?->displayName() }} contre {{ $lm->entryB?->displayName() }}">
            <span class="story-ring"><span class="inner">
                {{-- Les deux côtés l'un contre l'autre : le mince écart entre les deux est la ligne du milieu --}}
                <span class="pair">
                    @if ($ia)<img loading="lazy" decoding="async" src="{{ $ia }}" alt="Photo de profil">@else<span class="a">{{ mb_strtoupper(mb_substr($lm->entryA?->displayName() ?? '?', 0, 1)) }}</span>@endif
                    @if ($ib)<img loading="lazy" decoding="async" src="{{ $ib }}" alt="Photo de profil">@else<span class="b">{{ mb_strtoupper(mb_substr($lm->entryB?->displayName() ?? '?', 0, 1)) }}</span>@endif
                </span>
                <span class="vs">VS</span>
            </span></span>
            <small class="story-time"><span class="countdown" data-ends="{{ $lm->ends_at->toIso8601String() }}"></span></small>
        </a>
    @endforeach
</div>