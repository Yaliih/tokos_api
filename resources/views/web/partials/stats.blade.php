{{-- Bilan d'un profil ou d'un Espace : un anneau pour le taux de victoire, une barre victoires / défaites,
     puis quelques repères en pastilles. $s : statistiques (SocialController::stats) --}}
@php
    $played = (int) $s['wins'] + (int) $s['losses'] + (int) ($s['draws'] ?? 0);
    $rate = $played ? (int) round($s['wins'] / $played * 100) : 0;
    $r = 34; $circ = 2 * M_PI * $r;
    $w = $played ? $s['wins'] / $played * 100 : 0;
    $d = $played ? ($s['draws'] ?? 0) / $played * 100 : 0;
@endphp
<section class="record card">
    <div class="record-main">
        <div class="ring" role="img" aria-label="{{ $played ? $rate.' % de victoires' : 'Aucun duel joué' }}">
            <svg viewBox="0 0 80 80" aria-hidden="true">
                <circle cx="40" cy="40" r="{{ $r }}" class="ring-bg"/>
                @if ($played)<circle cx="40" cy="40" r="{{ $r }}" class="ring-fg" stroke-dasharray="{{ round($circ * $rate / 100, 2) }} {{ round($circ, 2) }}"/>@endif
            </svg>
            <span><b>{{ $played ? $rate.'%' : '–' }}</b><small>victoires</small></span>
        </div>
        <div class="record-bar">
            <div class="record-title">
                <strong>{{ $played }} duel{{ $played > 1 ? 's' : '' }}</strong>
                @if (($s['current_streak'] ?? 0) > 1 && \App\Support\Features::on('badges'))<span class="chip chip-coral">@icon('flame'){{ $s['current_streak'] }} d'affilée</span>@endif
            </div>
            <div class="split" aria-hidden="true">
                <i class="w" style="width: {{ $w }}%"></i><i class="d" style="width: {{ $d }}%"></i>
            </div>
            <div class="split-legend">
                <span><i class="w"></i><b>{{ $s['wins'] }}</b> victoire{{ $s['wins'] > 1 ? 's' : '' }}</span>
                <span><i class="l"></i><b>{{ $s['losses'] }}</b> défaite{{ $s['losses'] > 1 ? 's' : '' }}</span>
                @if (($s['draws'] ?? 0) > 0)<span><i class="d"></i><b>{{ $s['draws'] }}</b> nul{{ $s['draws'] > 1 ? 's' : '' }}</span>@endif
            </div>
        </div>
    </div>
    <div class="facts">
        <span class="fact" title="{{ $s['season']['label'] ?? 'Saison' }}">@icon('crown')<b>{{ $s['season']['points'] ?? 0 }}</b><small>points</small></span>
        <span class="fact" title="Meilleure série">@icon('flame')<b>{{ $s['best_streak'] }}</b><small>meilleure série</small></span>
        <span class="fact" title="Trophées">@icon('trophy')<b>{{ $s['trophies'] }}</b><small>trophée{{ $s['trophies'] > 1 ? 's' : '' }}</small></span>
    </div>
</section>
