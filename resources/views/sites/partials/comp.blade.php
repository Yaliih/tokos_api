{{-- Carte d'une compétition sur le site d'un Espace --}}
@php $ico = \App\Support\Icons::TYPE[$c['type']] ?? 'trophy'; @endphp
<a class="s-card" href="{{ $mainUrl.$c['url'] }}">
    <span class="s-card-img">
        @if ($c['cover_url'] && $c['cover_type'] !== 'video')<img src="{{ $c['cover_url'] }}" alt="" loading="lazy">@else<span class="s-card-ph">@icon($ico)</span>@endif
        <span class="s-tag">@icon($ico){{ $c['type_label'] }}</span>
    </span>
    <span class="s-card-b">
        <b>{{ $c['title'] }}</b>
        <small>{{ $c['status_label'] }}@if($c['entries_count']) · {{ $c['entries_count'] }} participant{{ $c['entries_count'] > 1 ? 's' : '' }}@endif</small>
        @if ($c['prize'])<small class="s-prize">@icon('gift'){{ $c['prize'] }}</small>@endif
        <span class="s-go">{{ ($c['is_over'] ?? false) ? ($c['status'] === 'cancelled' ? 'Voir' : 'Voir le résultat') : ($c['status'] === 'live' ? 'Voter' : (($c['accepts_entries'] ?? false) ? ($c['application'] ? 'Candidater' : 'Participer') : 'Voir')) }} @icon('arrow-right')</span>
    </span>
</a>
