@if (!empty($p))
<a class="who" href="{{ $p['url'] }}">
    @include('web.partials.avatar', ['p' => $p, 'size' => $size ?? 'sm'])
    <span style="min-width:0">
        <strong>{{ $p['name'] }}</strong>@if($p['is_verified'])@icon('check-circle', 'verified')@endif
        @if (!empty($sub))<span class="sub">{{ $sub }}</span>@elseif(($p['type'] ?? '') === 'space')<span class="sub">Espace</span>@endif
    </span>
</a>
@endif
