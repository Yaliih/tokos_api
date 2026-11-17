{{-- Réactions en émojis sur un duel : un toucher pour réagir, un second pour retirer --}}
@php $r = $m['reactions'] ?? null; @endphp
@if ($r)
<div class="reactions" aria-label="Réactions">
    @foreach ($r['available'] as $emoji)
        @php $n = $r['counts'][$emoji] ?? 0; $mine = $r['mine'] === $emoji; @endphp
        @auth
            <form method="post" action="/m/{{ $m['id'] }}/reagir" data-ajax data-reaction data-silent>@csrf
                <input type="hidden" name="emoji" value="{{ $emoji }}">
                <button class="react {{ $mine ? 'on' : '' }}" aria-pressed="{{ $mine ? 'true' : 'false' }}" aria-label="Réagir {{ $emoji }}"><span class="e">{{ $emoji }}</span><span class="n" data-react-count>{{ $n ?: '' }}</span></button>
            </form>
        @else
            <a class="react" href="/connexion?suite={{ urlencode($m['url']) }}" aria-label="Connecte-toi pour réagir"><span class="e">{{ $emoji }}</span><span class="n">{{ $n ?: '' }}</span></a>
        @endauth
    @endforeach
</div>
@endif
