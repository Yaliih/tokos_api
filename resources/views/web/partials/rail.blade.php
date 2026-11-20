@php
    $railHot = \App\Models\CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
        ->where('status', 'voting')->where('is_bye', false)->orderByDesc('hot_score')->limit(5)->get();
    $me = auth()->user();
    $suggest = collect();
    if ($me) {
        $following = $me->following()->pluck('users.id')->push($me->id);
        $suggest = \App\Models\User::whereNotIn('id', $following)->where('is_active', true)
            ->withCount('followers')->orderByDesc('followers_count')->limit(4)->get();
    }
@endphp
<aside class="rail" aria-label="En ce moment">
    <section class="card card-pad">
        <div class="card-title"><h2>Duels chauds</h2><a href="/voter" aria-label="Voter en glissant">@icon('chevron-right')</a></div>
        @forelse ($railHot as $hm)
            @php
                $hp = \App\Support\Presenter::match($hm, $me); $ea = $hp['entry_a'] ?? []; $eb = $hp['entry_b'] ?? [];
                $pa = $ea['participant'] ?? null; $pb = $eb['participant'] ?? null; $sa = $hp['score_a'] ?? 50;
                // Deux personnes différentes : leurs photos. Une seule personne (versus, deux choix) : le contenu de chaque choix.
                $solo = ($hm->competition?->format === 'versus') || ! $pa || ! $pb || (($pa['id'] ?? null) === ($pb['id'] ?? null) && ($pa['type'] ?? null) === ($pb['type'] ?? null));
                $snip = function (array $e): string {
                    $t = trim((string) ($e['label'] ?? ''));
                    if ($t === '') { $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($e['content'] ?? '')))); }
                    return $t !== '' ? \Illuminate\Support\Str::limit($t, 28) : '';
                };
                $choice = function (array $e, string $fallback) use ($snip) {
                    $m = collect($e['media'] ?? [])->first(fn ($x) => in_array($x['type'] ?? '', ['image', 'video', 'audio'], true));
                    $text = $snip($e);
                    return ['m' => $m, 'text' => $text !== '' ? $text : $fallback,
                        'initial' => $text !== '' ? mb_strtoupper(mb_substr($text, 0, 2)) : mb_substr($fallback, -1)];
                };
                $ca = $solo ? $choice($ea, 'Choix A') : null; $cb = $solo ? $choice($eb, 'Choix B') : null;
            @endphp
            <a class="hot{{ $solo ? ' hot-solo' : '' }}" href="{{ $hp['url'] }}">
                <span class="pair">
                    @if ($solo)
                        @foreach ([$ca, $cb] as $ch)
                            @php $m = $ch['m']; @endphp
                            @if ($m && $m['type'] === 'image')
                                <img loading="lazy" decoding="async" src="{{ $m['thumbnail_url'] ?? $m['url'] }}" alt="Photo de profil">
                            @elseif ($m && $m['type'] === 'video')
                                <span class="thumb is-video">
                                    @if (! empty($m['thumbnail_url']))<img loading="lazy" decoding="async" src="{{ $m['thumbnail_url'] }}" alt="Photo de profil">
                                    @else<video src="{{ $m['url'] }}#t=0.5" preload="metadata" muted playsinline tabindex="-1" aria-hidden="true"></video>@endif
                                    <i>@icon('play')</i>
                                </span>
                            @elseif ($m && $m['type'] === 'audio')
                                <span class="thumb is-icon">@icon('mic')</span>
                            @else
                                <span class="thumb is-text">{{ $ch['initial'] }}</span>
                            @endif
                        @endforeach
                    @else
                        @if ($pa)<img loading="lazy" decoding="async" src="{{ $pa['avatar_url'] }}" alt="Photo de profil">@else<span class="dot"></span>@endif
                        @if ($pb)<img loading="lazy" decoding="async" src="{{ $pb['avatar_url'] }}" alt="Photo de profil">@else<span class="dot"></span>@endif
                    @endif
                </span>
                @php
                    $ta = $solo ? $ca['text'] : ($ea['display_name'] ?? '');
                    $tb = $solo ? $cb['text'] : ($eb['display_name'] ?? '');
                    $hide = ($hp['score_a'] ?? null) === null;
                    $pctA = $hide ? null : (int) round($sa); $pctB = $hide ? null : 100 - $pctA;
                @endphp
                <span class="names" aria-label="{{ $ta }} contre {{ $tb }}{{ $hide ? '' : ', '.$pctA.' % contre '.$pctB.' %' }}">
                    <span class="sides"><span class="sa">{{ $ta }}</span><span class="sb">{{ $tb }}</span></span>
                    <span class="score">
                        @unless ($hide)<em>{{ $pctA }} %</em>@endunless
                        <span class="bar"><i style="width: {{ $hide ? 50 : $pctA }}%"></i><b></b></span>
                        @unless ($hide)<em>{{ $pctB }} %</em>@endunless
                    </span>
                </span>
            </a>
        @empty
            <p class="muted small">Aucun duel en cours. <a href="/creer" style="color:var(--primary);font-weight:700">Lance le premier</a></p>
        @endforelse
    </section>

    @if ($suggest->isNotEmpty())
    <section class="card card-pad">
        <div class="card-title"><h2>Suggestions</h2></div>
        <ul class="mini">
            @foreach ($suggest as $su)
                @php $sp = \App\Support\Participant::present($su); @endphp
                <li>@include('web.partials.who', ['p' => $sp, 'sub' => $su->followers_count.' abonné'.($su->followers_count > 1 ? 's' : '')])
                    <form method="post" action="/suivre/{{ $sp['handle'] }}" data-ajax data-toggle-text="Abonné" style="margin-left:auto">@csrf<button class="btn btn-soft btn-sm">@icon('user-plus', 'ico-sm')Suivre</button></form></li>
            @endforeach
        </ul>
    </section>
    @endif

    <p class="rail-foot">Sosa, le réseau des défis. Joue fair-play : les votes truqués sont détectés.<br><a href="/conditions">Conditions</a> · <a href="/confidentialite">Confidentialité</a></p>
</aside>
