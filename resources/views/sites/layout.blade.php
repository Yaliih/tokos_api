{{-- Petit site d'un Espace (lions.sosa.social) : page autonome, sans la navigation de l'app --}}
@php $s = $space; $u = fn ($path) => $mainUrl.$path; @endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $s->name }}</title>
    <meta name="description" content="{{ $s->bio ?: $s->name.' : défis, concours et tournois sur Sosa.' }}">
    <meta property="og:title" content="{{ $s->name }}">
    <meta property="og:description" content="{{ $s->bio ?: ($label ?: 'Espace').' sur Sosa' }}">
    <meta property="og:image" content="{{ $s->cover && ($s->cover_type ?? 'image') === 'image' ? $s->cover_url : $s->avatar_url }}">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="{{ $template === 'affiche' ? '#0E0E13' : '#FFFFFF' }}">
    <link rel="icon" href="{{ $s->avatar_url }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ url('/a/site.'.filemtime(public_path('css/site.css')).'.css') }}">
</head>
<body class="site t-{{ $template }}">
<header class="s-bar">
    <a class="s-brand" href="#top"><img src="{{ $s->avatar_url }}" alt="">{{ $s->name }}@if($s->is_verified)@icon('check-circle', 's-ok')@endif</a>
    <nav class="s-nav" aria-label="Sections">
        @if ($live->isNotEmpty())<a href="#en-ce-moment">En ce moment</a>@endif
        @if ($past->isNotEmpty())<a href="#palmares">Palmarès</a>@endif
        @if ($talents->isNotEmpty())<a href="#talents">Talents</a>@endif
        <a href="#contact">Contact</a>
    </nav>
    <a class="s-btn s-btn-sm" href="{{ $u('/espaces/'.$s->slug) }}">{{ $isMember ? 'Mon Espace' : 'Rejoindre' }}</a>
</header>
<main id="top">
    @yield('hero')

    <section class="s-stats" aria-label="En chiffres">
        <div><b>{{ number_format($counts['members'], 0, ',', ' ') }}</b><span>membre{{ $counts['members'] > 1 ? 's' : '' }}</span></div>
        <div><b>{{ $counts['competitions'] }}</b><span>compétition{{ $counts['competitions'] > 1 ? 's' : '' }}</span></div>
        <div><b>{{ number_format($counts['votes'], 0, ',', ' ') }}</b><span>votes reçus</span></div>
    </section>

    @if ($live->isNotEmpty())
    <section class="s-sec" id="en-ce-moment">
        <h2>En ce moment</h2>
        <div class="s-grid">@foreach ($live as $c)@include('sites.partials.comp', ['c' => $c])@endforeach</div>
    </section>
    @endif

    @if ($past->isNotEmpty())
    <section class="s-sec" id="palmares">
        <h2>Palmarès</h2>
        <ol class="s-palm">
            @foreach ($past as $c)
                @php $w = $c['winner']['participant'] ?? null; @endphp
                <li><a href="{{ $u($c['url']) }}">
                    <span class="s-palm-who">@if($w)<img src="{{ $w['avatar_url'] }}" alt="">@endif<span class="s-crown">@icon('crown')</span></span>
                    <span class="s-palm-t"><b>{{ $c['winner']['display_name'] ?? ($w['name'] ?? '') }}</b><small>{{ $c['title'] }}@if($c['category_label']) · {{ $c['category_label'] }}@endif</small></span>
                    <span class="s-palm-d">{{ $c['ends_at'] ? \Illuminate\Support\Carbon::parse($c['ends_at'])->locale('fr')->isoFormat('MMM YYYY') : '' }}</span>
                </a></li>
            @endforeach
        </ol>
    </section>
    @endif

    @if ($talents->isNotEmpty())
    <section class="s-sec" id="talents">
        <h2>Nos talents</h2>
        <p class="s-lead">Les membres qui ont le plus de points cette saison.</p>
        <div class="s-talents">
            @foreach ($talents as $t)
                <a href="{{ $u($t['participant']['url']) }}"><img src="{{ $t['participant']['avatar_url'] }}" alt=""><b>{{ $t['participant']['name'] }}</b><small>{{ $t['points'] }} pt{{ $t['points'] > 1 ? 's' : '' }} · {{ $t['wins'] }} victoire{{ $t['wins'] > 1 ? 's' : '' }}</small></a>
            @endforeach
        </div>
    </section>
    @endif

    @if ($live->isEmpty() && $past->isEmpty())
    <section class="s-sec s-empty"><h2>Bientôt</h2><p class="s-lead">{{ $s->name }} prépare ses premières compétitions. Rejoins l'Espace pour être prévenu.</p></section>
    @endif

    <section class="s-sec" id="contact">
        <h2>Contact</h2>
        <div class="s-contact">
            <ul>
                @if ($label)<li>@icon('users')<span>{{ $label }}</span></li>@endif
                @if ($s->location)<li>@icon('map-pin')<span>{{ $s->location }}</span></li>@endif
                @if ($s->website)<li>@icon('globe')<a href="{{ \Illuminate\Support\Str::startsWith($s->website, 'http') ? $s->website : 'https://'.$s->website }}" rel="noopener" target="_blank">{{ preg_replace('#^https?://#', '', $s->website) }}</a></li>@endif
                <li>@icon('link')<a href="{{ $u('/espaces/'.$s->slug) }}">L'Espace sur Sosa</a></li>
            </ul>
            @if ($managers->isNotEmpty())
                <div class="s-mgr"><small>Responsables</small>
                    @foreach ($managers as $m)<a href="{{ $u($m['url']) }}"><img src="{{ $m['avatar_url'] }}" alt="">{{ $m['name'] }}</a>@endforeach</div>
            @endif
        </div>
    </section>
</main>
<footer class="s-foot">
    <span>{{ $s->name }}</span>
    <a href="{{ $mainUrl }}/">Propulsé par <b class="s-sosa"><i>s</i>o<i>s</i>a</b></a>
</footer>
</body>
</html>
