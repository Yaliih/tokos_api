{{-- Tournoi, avant un duel du 2e tour ou plus : chacun envoie sa nouvelle prestation avant l'heure limite --}}
@php
    $p = $m['preparing'];
    $mine = $p['my_side'];
    $fmt = $m['competition']['entry_format'] ?? null;
    $kinds = in_array($fmt, ['video', 'audio', 'image'], true) ? [$fmt] : ['image', 'video', 'audio'];
    $what = ['video' => 'ta vidéo', 'audio' => 'ton audio', 'image' => 'ta photo', 'text' => 'ton texte'][$fmt] ?? 'ta prestation';
@endphp
<section class="card rp" id="prestation">
    <div class="rp-duo">
        @foreach (['a', 'b'] as $s)
            @php $e = $m['entry_'.$s]; $ok = $p[$s.'_ready']; @endphp
            <div class="rp-side {{ $ok ? 'ok' : '' }}">
                @include('web.partials.avatar', ['p' => $e['participant'] ?? null, 'size' => 'md'])
                <b class="trunc">{{ $e['display_name'] ?? '?' }}</b>
                <span class="rp-state">@icon($ok ? 'check' : 'clock', 'ico-sm'){{ $ok ? 'Prêt' : 'Pas encore envoyé' }}</span>
            </div>
            @if ($s === 'a')<span class="rp-vs">VS</span>@endif
        @endforeach
    </div>
    <p class="rp-time">@icon('clock', 'ico-sm')<span>Envoi jusqu'au {{ \Illuminate\Support\Carbon::parse($p['submit_by'])->locale('fr')->isoFormat('D MMM à HH:mm') }} · reste <span class="countdown" data-ends="{{ $p['submit_by'] }}"></span></span></p>
    <p class="rp-rule">Le duel commence dès que les deux ont envoyé. Sans envoi à temps, l'autre passe au tour suivant.</p>

    @if ($mine && ! $p[$mine.'_ready'])
        <form class="rp-form" method="post" action="/m/{{ $m['id'] }}/prestation" enctype="multipart/form-data">
            @csrf
            <h3>Envoie {{ $what }} pour ce tour</h3>
            @if ($fmt !== 'text')
                @include('web.partials.picker', ['name' => 'media[0][file]', 'meta' => 'media[0][metadata]', 'kinds' => $kinds, 'label' => 'Ta nouvelle prestation', 'hint' => 'Une nouvelle, pas celle du tour d\'avant.', 'compact' => true, 'required' => true])
            @endif
            <textarea class="input" name="content" rows="2" maxlength="5000" data-emoji placeholder="{{ $fmt === 'text' ? 'Ton texte pour ce tour' : 'Un mot (facultatif)' }}" @if($fmt === 'text') required @endif></textarea>
            <button class="btn btn-lg btn-block">@icon('send', 'ico-sm')Envoyer</button>
        </form>
    @elseif ($mine)
        <p class="rp-done">@icon('check', 'ico-sm')Ta prestation est envoyée. On attend ton adversaire.</p>
    @endif
</section>
