{{-- Petites nouvelles regroupées dans une seule carte --}}
<section class="card brief" aria-label="En bref">
    <div class="brief-head">@icon('sparkles', 'ico-sm')En bref</div>
    @foreach ($items as $a)
        @php
            $icon = \App\Support\Icons::VERB[$a['verb']] ?? 'sparkles';
            $tone = in_array($a['verb'], ['match.completed', 'competition.completed', 'badge.earned', 'rank.top10', 'streak'], true) ? 'gold'
                : (in_array($a['verb'], ['duel.invited', 'challenge.accepted', 'match.milestone'], true) ? 'coral' : '');
            $actor = $a['actors'][0] ?? $a['actor'];
        @endphp
        <div class="brief-item">
            <a class="av" href="{{ $actor['url'] ?? '#' }}" tabindex="-1" aria-hidden="true">
                @include('web.partials.avatar', ['p' => $actor, 'size' => 'sm'])
                <span class="kind {{ $tone }}">@icon($icon)</span>
            </a>
            <div class="txt">
                <p>@foreach ($a['segments'] as $s)@if ($s['type'] === 'actor')<a class="who" href="{{ $s['url'] }}">{{ $s['text'] }}</a>@elseif ($s['type'] === 'link')<a class="ctx" href="{{ $s['url'] }}">{{ $s['text'] }}</a>@elseif ($s['type'] === 'strong')<strong>{{ $s['text'] }}</strong>@else{{ $s['text'] }}@endif @endforeach</p>
                @if (! empty($a['created_at']))<small>{{ \App\Support\Presenter::ago(\Illuminate\Support\Carbon::parse($a['created_at'])) }}</small>@endif
            </div>
            @if (! empty($a['subject']['url']))
                {{-- La personne suivie, l'Espace rejoint --}}
                <a class="brief-subject" href="{{ $a['subject']['url'] }}" aria-label="{{ $a['subject']['name'] ?? '' }}">@include('web.partials.avatar', ['p' => $a['subject'], 'size' => 'sm'])</a>
            @endif
        </div>
    @endforeach
</section>
