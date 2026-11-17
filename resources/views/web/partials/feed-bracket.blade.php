{{-- Tableau d'un tournoi dans le fil : le tour en cours (ronds = participants, sans nom) et la suite jusqu'à la finale --}}
@php
    $pairs = $b['pairs'] ?? [];
    $M = count($pairs);                       // duels de ce tour
    $W = 340;
    $id = 'bk'.substr(md5(($comp['slug'] ?? '').($b['round'] ?? '')), 0, 8);
    $players = $M * 2;
    $roundName = $b['round_name'] ?? null;
    $nodes = []; $lines = [];
    if ($M >= 2) {
        // Deux moitiés qui se rejoignent au centre (la finale)
        $perSide = $M;                         // participants par moitié
        $K = (int) round(log($perSide, 2)) + 1;  // colonnes par moitié, du tour actuel au finaliste
        $H = max(130, min(420, $perSide * 22));
        $mid = 26;                             // demi-largeur du centre
        $step = $K > 1 ? ($W / 2 - 14 - $mid) / ($K - 1) : 0;
        $r0 = max(3.5, min(13, $H / $perSide * 0.4));
        $flat = [];
        foreach ($pairs as $p) { $flat[] = $p[0]; $flat[] = $p[1]; }
        foreach ([0, 1] as $side) {
            $avatars = array_slice($flat, $side * $perSide, $perSide);
            for ($c = 0; $c < $K; $c++) {
                $n = intdiv($perSide, 2 ** $c);
                $x = $side === 0 ? 14 + $c * $step : $W - 14 - $c * $step;
                for ($i = 0; $i < $n; $i++) {
                    $y = $H * ($i + 0.5) / $n;
                    $nodes[] = ['x' => $x, 'y' => $y, 'r' => $c === 0 ? $r0 : max(3, min(6, $r0 * 0.6)), 'av' => $c === 0 ? ($avatars[$i] ?? null) : null, 'first' => $c === 0, 'bye' => $c === 0 && ! array_key_exists($i, $avatars)];
                    if ($c + 1 < $K) {
                        $px = $side === 0 ? 14 + ($c + 1) * $step : $W - 14 - ($c + 1) * $step;
                        $py = $H * (intdiv($i, 2) + 0.5) / intdiv($n, 2);
                        $mx = ($x + $px) / 2;
                        $lines[] = "M{$x},{$y} H{$mx} V{$py} H{$px}";
                    } else {
                        $lines[] = "M{$x},{$y} H".($W / 2);
                    }
                }
            }
        }
    }
@endphp
<div class="fb">
    <div class="fb-head"><b>{{ $roundName ? \Illuminate\Support\Str::ucfirst($roundName) : 'Tour '.($b['round'] ?? 1) }}</b><span>{{ $players }} en lice</span></div>
    @if ($M >= 2)
        <svg class="fb-svg" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Tableau du tournoi : {{ $players }} participants">
            <defs><clipPath id="{{ $id }}" clipPathUnits="objectBoundingBox"><circle cx=".5" cy=".5" r=".5"/></clipPath></defs>
            @foreach ($lines as $d)<path class="fb-line" d="{{ $d }}"/>@endforeach
            <circle class="fb-final" cx="{{ $W / 2 }}" cy="{{ $H / 2 }}" r="15"/>
            {!! str_replace('<svg ', '<svg x="'.($W / 2 - 8).'" y="'.($H / 2 - 8).'" width="16" height="16" ', \App\Support\Icons::svg('trophy', 'fb-trophy')) !!}
            @foreach ($nodes as $n)
                @if ($n['av'])
                    <image href="{{ $n['av'] }}" x="{{ $n['x'] - $n['r'] }}" y="{{ $n['y'] - $n['r'] }}" width="{{ $n['r'] * 2 }}" height="{{ $n['r'] * 2 }}" clip-path="url(#{{ $id }})" preserveAspectRatio="xMidYMid slice"/>
                    <circle class="fb-ring" cx="{{ $n['x'] }}" cy="{{ $n['y'] }}" r="{{ $n['r'] }}"/>
                @else
                    <circle class="{{ $n['first'] ? 'fb-dot' : 'fb-next' }}" cx="{{ $n['x'] }}" cy="{{ $n['y'] }}" r="{{ $n['r'] }}"/>
                @endif
            @endforeach
        </svg>
    @elseif ($M === 1)
        {{-- La finale : les deux face à face --}}
        <div class="fb-final-duo">
            @foreach ($pairs[0] as $k => $av)
                @if ($k === 1)<span class="fb-vs">@icon('trophy')</span>@endif
                @if ($av)<img src="{{ $av }}" alt="">@else<span class="fb-empty"></span>@endif
            @endforeach
        </div>
    @endif
    <div class="fs-foot">
        <span>{{ $comp['title'] ?? '' }}</span>
        <a class="btn btn-sm" href="{{ ($comp['url'] ?? '#') }}?tab=duels">@icon('vs', 'ico-sm')Voir les duels</a>
    </div>
</div>
