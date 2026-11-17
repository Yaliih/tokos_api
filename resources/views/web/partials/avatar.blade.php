@if (!empty($p))
<img class="avatar {{ $size ?? '' }} {{ ($p['type'] ?? '') === 'space' ? 'space' : '' }}" src="{{ $p['avatar_url'] }}" alt="" loading="lazy">
@else
<span class="avatar {{ $size ?? '' }}"></span>
@endif
