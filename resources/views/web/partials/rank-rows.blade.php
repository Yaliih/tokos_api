{{-- Lignes de classement : rang, personne, victoires, points. Ma ligne est mise en avant. --}}
<ol class="rank-list rank-compact">
    @foreach ($rows as $r)
        @php $isMe = auth()->check() && $r['participant']['type'] === 'user' && (int) $r['participant']['id'] === (int) auth()->id(); @endphp
        <li class="{{ $isMe ? 'me' : '' }}">
            <a href="{{ $r['participant']['url'] }}">
                <span class="rank-pos">@if($r['rank'] === 1)@icon('crown', 'ico-sm gold-ico')@else{{ $r['rank'] ?? '–' }}@endif</span>
                @include('web.partials.avatar', ['p' => $r['participant']])
                <span class="rank-who"><strong>{{ $isMe ? 'Toi' : $r['participant']['name'] }}</strong>
                    <span class="rank-sub">{{ $r['wins'] }} V · {{ $r['losses'] }} D
                        @if ($r['streak'] > 1)<span class="streak">@icon('flame'){{ $r['streak'] }}</span>@endif</span></span>
                <span class="rank-val"><b>{{ $r['points'] }}</b><small>pt{{ $r['points'] > 1 ? 's' : '' }}</small></span>
            </a>
        </li>
    @endforeach
</ol>
