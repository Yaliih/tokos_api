{{-- Classement d'un concours dans le fil : le podium, et parfois une tranche plus loin (20e, 21e, 22e…) --}}
@php $final = $s['final'] ?? false; @endphp
<div class="fs">
    <ol class="fs-list">
        @foreach ($s['rows'] as $r)
            @if (! empty($r['gap']))
                <li class="fs-gap" aria-hidden="true"><span></span><span></span><span></span></li>
            @else
                <li class="fs-row {{ $r['rank'] <= 3 ? 'top r'.$r['rank'] : '' }}">
                    <span class="fs-rank">@if($r['rank'] === 1)@icon('crown', 'ico-sm')@else{{ $r['rank'] }}@endif</span>
                    @if ($r['avatar'])<img class="fs-av" src="{{ $r['avatar'] }}" alt="" loading="lazy">@else<span class="fs-av fs-ini">{{ mb_strtoupper(mb_substr($r['name'], 0, 1)) }}</span>@endif
                    <span class="fs-name">{{ $r['name'] }}</span>
                    <span class="fs-votes"><b>{{ number_format($r['votes'], 0, ',', ' ') }}</b> vote{{ $r['votes'] > 1 ? 's' : '' }}</span>
                </li>
            @endif
        @endforeach
    </ol>
    <div class="fs-foot">
        <span>{{ $final ? 'Classement final' : 'Classement provisoire' }} · {{ $s['total'] }} candidat{{ $s['total'] > 1 ? 's' : '' }}</span>
        <a class="btn btn-sm {{ $final ? 'btn-ghost' : '' }}" href="{{ $comp['url'] ?? '#' }}">@icon($final ? 'trophy' : 'arrow-up', 'ico-sm'){{ $final ? 'Voir tout' : 'Voter' }}</a>
    </div>
</div>
