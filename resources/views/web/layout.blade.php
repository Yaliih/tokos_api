@php
    $me = auth()->user();
    $unread = $me ? $me->unreadNotifications()->count() : 0;
    $pendingInvites = $me ? \App\Support\Inbox::count($me) : 0; // « Reçus » : tout ce qui attend ma réponse
    $mySpaces = $me ? \App\Models\Space::whereIn('id', $me->allSpaceIds())->limit(6)->get() : collect();
    $current = $current ?? '';
    $meP = $me ? \App\Support\Participant::present($me) : null;
    $nav = [
        ['home', '/', 'home', 'Accueil'],
        ['discover', '/decouvrir', 'compass', 'Découvrir'],
        ['swipe', '/voter', 'vs', 'Voter'],
        ['leaderboards', '/classements', 'chart', 'Classements'],
        ['spaces', '/espaces', 'users', 'Espaces'],
    ];
    $navFeature = ['swipe' => 'swipe', 'leaderboards' => 'leaderboard', 'spaces' => 'spaces'];
    // Pages « racine » (onglets du bas) : pas de bouton retour ; ailleurs, sur téléphone, retour + titre
    $path = trim(request()->path(), '/');
    $isRoot = in_array($path, ['', 'decouvrir', 'classements', 'espaces', 'voter'], true) || ($me && $path === '@'.$me->username);
    $backTo = $backTo ?? (str_starts_with($path, 'm/') || str_starts_with($path, 'c/') ? '/' : ($me ? '/@'.$me->username : '/'));
    $nav = array_values(array_filter($nav, fn ($n) => \App\Support\Features::on($navFeature[$n[0]] ?? 'x')));
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (request()->attributes->get('siteBase'))<base href="{{ request()->attributes->get('siteBase') }}">@endif
    <title>{{ isset($title) ? $title.' · Sosa' : 'Sosa : défis, duels et concours' }}</title>
    @php
        // Connexion, inscription, mot de passe : jamais dans Google (ce n'est pas du contenu)
        $authPage = request()->is('connexion*', 'inscription*', 'mot-de-passe*', 'verification*', 'code*');
    @endphp
    @if (! empty($noIndex) || $authPage)<meta name="robots" content="noindex, follow">@endif
    <link rel="canonical" href="{{ url()->current() }}">
    {{-- Ce que Google lit pour comprendre le site et afficher des liens sous le résultat --}}
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => url('/').'#organisation',
                    'name' => 'Sosa',
                    'url' => url('/'),
                    'logo' => url('/img/logo.png'),
                    'sameAs' => ['https://sosa.social'],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => url('/').'#site',
                    'url' => url('/'),
                    'name' => 'Sosa',
                    'description' => 'Défis, duels, concours et tournois : mesure-toi aux autres et gagne tes classements.',
                    'inLanguage' => 'fr',
                    'publisher' => ['@id' => url('/').'#organisation'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    <meta name="description" content="{{ $description ?? 'Lance des défis, affronte les autres en duel, vote pour tes favoris. Seul ou avec ton Espace.' }}">
    <meta property="og:site_name" content="Sosa">
    <meta property="og:title" content="{{ $title ?? 'Sosa' }}">
    <meta property="og:description" content="{{ $description ?? 'Défis, duels, concours et tournois.' }}">
    @isset($ogImage)<meta property="og:image" content="{{ $ogImage }}"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">@endisset
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    {{-- La barre du navigateur suit le thème du téléphone --}}
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#16161C" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Sosa">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <script>
        // Thème choisi avant l'affichage, pour éviter un flash de couleur
        document.documentElement.classList.add('js');
        try { var t = localStorage.getItem('tokos-theme'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}
        // Écran d'ouverture : seulement à la première ouverture de la session, jamais en naviguant
        try { if (!sessionStorage.getItem('tk-open') && !/TokosApp/.test(navigator.userAgent)) { document.documentElement.classList.add('splashing'); sessionStorage.setItem('tk-open', '1'); } } catch (e) {}
    </script>
    <style>
        .splash { display: none; }
        .splashing .splash { display: grid; position: fixed; inset: 0; z-index: 9999; place-items: center; background: #fff; transition: opacity .3s ease; }
        .splashing .splash.out { opacity: 0; pointer-events: none; }
        [data-theme="dark"] .splashing .splash, .splashing[data-theme="dark"] .splash { background: #0E0E13; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]).splashing .splash { background: #0E0E13; } }
        .splash-in { color: #6C5CE7; animation: splash-pop .6s cubic-bezier(.2, 1.4, .4, 1) both; }
        .splash .splash-in svg { width: 64px !important; height: 64px !important; display: block; }
        @media (max-width: 400px) { .splash .splash-in svg { width: 54px !important; height: 54px !important; } }
        .splash-word { position: absolute; left: 0; right: 0; bottom: calc(34px + env(safe-area-inset-bottom)); text-align: center; font: 800 19px/1 "Plus Jakarta Sans", system-ui, sans-serif; letter-spacing: .16em; text-transform: uppercase; color: #15151C; }
        .splash-word i { font-style: normal; color: #6C5CE7; } .splash-word .flip { display: inline-block; transform: scaleX(-1); }
        [data-theme="dark"] .splash-in { color: #8B7FF5; } [data-theme="dark"] .splash-word { color: #F2F2F6; } [data-theme="dark"] .splash-word i { color: #B4AAFF; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .splash-in { color: #8B7FF5; } :root:not([data-theme="light"]) .splash-word { color: #F2F2F6; } }
        @keyframes splash-pop { from { transform: scale(.6); opacity: 0; } }
        @media (prefers-reduced-motion: reduce) { .splash-in { animation: none; } }
    </style>
    {{-- Police : auto-hébergée si `php artisan fonts:local` a été lancé (aucune requête externe),
         sinon Google Fonts en NON bloquant : la page s'affiche sans l'attendre. --}}
    @if (is_file(public_path('css/plus-jakarta.css')))
        <link rel="stylesheet" href="{{ url('/a/plus-jakarta.'.filemtime(public_path('css/plus-jakarta.css')).'.css') }}">
    @else
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"></noscript>
    @endif
    <link rel="stylesheet" href="{{ url('/a/tokos.'.filemtime(public_path('css/tokos.css')).'.css') }}">
    {{-- Navigation instantanée : la page suivante est préchargée quand le doigt touche le lien (pas en économie de données) --}}
    <script type="speculationrules">
    {"prerender": [{"where": {"and": [{"selector_matches": ".mobile-nav a, .sidenav a.nav-link"}, {"not": {"href_matches": ["/deconnexion", "/admin*"]}}]}, "eagerness": "moderate"}],
     "prefetch": [{"where": {"and": [{"href_matches": "/*"}, {"not": {"href_matches": ["/deconnexion", "/admin*", "/livewire*", "/api/*", "/*/apercu.png"]}}, {"not": {"selector_matches": "[data-no-prefetch], [download], [target=_blank]"}}]}, "eagerness": "moderate"}]}
    </script>
</head>
<body>
<div class="splash" aria-hidden="true"><div class="splash-in">@icon('sosa')</div><span class="splash-word"><i>s</i>o<i>s</i>a</span></div>
<script>
    (function () {
        if (!document.documentElement.classList.contains('splashing')) return;
        var done = false, t0 = Date.now();
        function hide() { if (done) return; done = true; var s = document.querySelector('.splash'); var wait = Math.max(0, 450 - (Date.now() - t0));
            setTimeout(function () { s.classList.add('out'); setTimeout(function () { document.documentElement.classList.remove('splashing'); }, 320); }, wait); }
        addEventListener('load', hide); setTimeout(hide, 1800);
    })();
</script>
@unless($bare ?? false)
<header class="topbar {{ $isRoot ? '' : 'has-back' }}">
    <div class="topbar-in">
        @unless ($isRoot)
            <span class="top-nav">
                <a class="top-back" href="{{ $backTo }}" data-back aria-label="Retour">@icon('arrow-left')</a>
                <span class="top-title">{{ $title ?? 'Sosa' }}</span>
            </span>
        @endunless
        <a class="logo" href="/" aria-label="Sosa, accueil"><span class="logo-mark">@icon('sosa')</span><span class="logo-word"><i>s</i>o<i>s</i>a</span></a>
        <form class="search" action="/decouvrir" method="get" role="search">
            @icon('search')
            <label class="sr-only" for="top-search">Rechercher</label>
            <input id="top-search" name="q" value="{{ request('q') }}" placeholder="Rechercher un défi, un tournoi…">
        </form>
        <div class="top-actions">
            @auth
                <a class="btn btn-sm" href="/creer" style="margin-right:6px">@icon('plus', 'ico-sm')<span class="hide-sm">Créer</span></a>
                <a class="icon-btn" href="/defis-recus" aria-label="Reçus : ce qui attend ta réponse">@icon('vs')@if($pendingInvites)<span class="dot-count">{{ $pendingInvites }}</span>@endif</a>
                <a class="icon-btn" href="/notifications" aria-label="Notifications">@icon('bell')@if($unread)<span class="dot-count">{{ $unread > 9 ? '9+' : $unread }}</span>@endif</a>
            @else
                <a class="btn btn-ghost btn-sm guest-btn" href="/connexion">@icon('log-in', 'ico-sm')Se connecter</a>
                @feature('registration')<a class="btn btn-sm hide-sm" href="/inscription">@icon('user-plus', 'ico-sm')S'inscrire</a>@endfeature
            @endauth
        </div>
    </div>
</header>
@endunless

<div class="shell {{ ($hideRail ?? false) ? 'wide' : '' }}">
    @unless($bare ?? false)
    <nav class="sidenav" aria-label="Navigation principale">
        @foreach ($nav as [$key, $href, $ico, $label])
            <a class="nav-link" href="{{ $href }}" @if($current === $key) aria-current="page" @endif>@icon($ico){{ $label }}</a>
        @endforeach
        @auth
            <a class="nav-link" href="/notifications" @if($current === 'notifications') aria-current="page" @endif>@icon('bell')Notifications @if($unread)<span class="count">{{ $unread }}</span>@endif</a>
            <a class="nav-link" href="/defis-recus" @if($current === 'invitations') aria-current="page" @endif>@icon('vs')Reçus @if($pendingInvites)<span class="count">{{ $pendingInvites }}</span>@endif</a>
            <a class="nav-link" href="{{ $meP['url'] }}" @if($current === 'profile') aria-current="page" @endif><img loading="lazy" decoding="async" class="avatar xs" src="{{ $meP['avatar_url'] }}" alt="">Mon profil</a>
            <a class="btn btn-lg side-cta" href="/creer">@icon('plus')Créer</a>
            @if ($mySpaces->isNotEmpty() && \App\Support\Features::on('spaces'))
                <div class="side-title">Mes Espaces</div>
                <div class="side-spaces">
                    @foreach ($mySpaces as $sp)
                        <a href="/espaces/{{ $sp->slug }}"><img loading="lazy" decoding="async" class="avatar xs space" src="{{ $sp->avatar_url }}" alt="">{{ $sp->name }}</a>
                    @endforeach
                </div>
            @endif
            <div class="side-foot">
                <button type="button" class="nav-link theme-btn" data-theme-toggle>@icon('sun', 'sun')@icon('moon', 'moon')<span>Thème</span></button>
                <form method="post" action="/deconnexion">@csrf<button class="nav-link">@icon('log-out')Se déconnecter</button></form>
            </div>
        @else
            <div class="card card-pad" style="margin-top:14px">
                <h3>Rejoins la compétition</h3>
                <p class="muted small" style="margin:6px 0 12px">Crée un compte pour voter, relever des défis et monter au classement.</p>
                @feature('registration')<a class="btn btn-block" href="/inscription">@icon('user-plus', 'ico-sm')Créer un compte</a>@endfeature
            </div>
            <div class="side-foot"><button type="button" class="nav-link theme-btn" data-theme-toggle>@icon('sun', 'sun')@icon('moon', 'moon')<span>Thème</span></button></div>
        @endauth
    </nav>
    @endunless

    <main class="main" id="contenu">
        @if (session('ok'))<div class="flash flash-ok" role="status">@icon('check-circle'){{ session('ok') }}</div>@endif
        @if (session('error'))<div class="flash flash-err" role="alert">@icon('alert'){{ session('error') }}</div>@endif
        @if ($errors->any())<div class="flash flash-err" role="alert">@icon('alert'){{ $errors->first() }}</div>@endif
        @if ((session('push_nudge') || (auth()->guest() && request()->is('/'))) && \App\Support\Features::on('push'))
            {{-- Proposé une fois, juste après une publication ; montré seulement si le navigateur n'a encore rien décidé --}}
            <div class="card push-nudge" data-push-nudge hidden>
                @icon('bell')<span class="grow">@auth<b>Être prévenu quand on te répond ?</b><small>Une alerte quand on relève ton défi, vote ou commente.</small>@else<b>Être prévenu des nouveaux tournois ?</b><small>Une alerte pour les grands concours et défis, même sans compte.</small>@endauth</span>
                <button type="button" class="btn btn-sm" data-push>Activer</button>
                <button type="button" class="icon-btn" data-push-nudge-close aria-label="Plus tard">@icon('x', 'ico-sm')</button>
            </div>
        @endif
        @yield('content')
    </main>

    @unless(($hideRail ?? false) || ($bare ?? false))
        @include('web.partials.rail')
    @endunless
</div>

@unless($bare ?? false)
<nav class="mobile-nav" aria-label="Navigation">
    <a href="/" @if($current === 'home') aria-current="page" @endif>@icon('home')<span>Accueil</span></a>
    <a href="/decouvrir" @if($current === 'discover') aria-current="page" @endif>@icon('compass')<span>Découvrir</span></a>
    <a href="/creer" aria-label="Créer"><span class="create">@icon('plus')</span></a>
    @feature('leaderboard')<a href="/classements" @if($current === 'leaderboards') aria-current="page" @endif>@icon('chart')<span>Classement</span></a>@endfeature
    <a href="{{ $meP['url'] ?? '/connexion' }}" @if($current === 'profile') aria-current="page" @endif>@if($meP)<img loading="lazy" decoding="async" class="avatar xs" src="{{ $meP['avatar_url'] }}" alt="">@else @icon('user')@endif<span>Profil</span></a>
</nav>
@endunless
<template data-icon-wa>@icon('whatsapp')</template><template data-icon-link>@icon('link')</template><template data-icon-flag>@icon('flag')</template><template data-icon-more>@icon('more')</template>
@auth
{{-- Voter avec des coins (compétitions payantes) --}}
<dialog id="vote-coins" class="sheet coin-sheet">
    <div class="sheet-head"><h2>Voter pour <span data-vc-name></span></h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <div class="sheet-body">
        <p class="muted small" style="margin:0 0 12px"><span data-vc-cost></span> coin(s) par vote. Plus tu votes, plus ton camp avance. Tes votes vont tous au même.</p>
        <div class="qty-chips" data-vc-qty>
            @foreach (config('coins.vote_quantities') as $i => $q)<button type="button" class="qty {{ $i === 0 ? 'on' : '' }}" data-q="{{ $q }}">{{ $q }} vote{{ $q > 1 ? 's' : '' }}</button>@endforeach
            <input class="input qty-input" type="number" min="1" max="{{ config('coins.max_votes_at_once') }}" placeholder="Autre" data-vc-custom aria-label="Nombre de votes">
        </div>
        <div class="coin-total"><span>Total</span><b><span data-vc-total></span> coins</b></div>
        <p class="faint small" style="margin:6px 0 14px">Ton solde : <b data-coin-balance-full>{{ $me->coins }}</b> coins · <a href="/portefeuille" style="color:var(--primary);font-weight:700">Acheter des coins</a></p>
        <button type="button" class="btn btn-lg btn-block" data-vc-confirm>@icon('arrow-up', 'ico-sm')Voter</button>
    </div>
</dialog>
{{-- Offrir des coins --}}
<dialog id="gift" class="sheet coin-sheet">
    <div class="sheet-head"><h2>Offrir à <span data-gift-name></span></h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <form method="post" action="/cadeau" class="sheet-body" data-ajax data-gift-form>@csrf
        <input type="hidden" name="to_type"><input type="hidden" name="to_id"><input type="hidden" name="competition_id">
        <div class="qty-chips">
            @foreach (config('coins.gift_presets') as $i => $g)<label class="qty"><input type="radio" name="amount" value="{{ $g }}" @checked($i === 1) hidden>@icon('coin', 'ico-sm'){{ $g }}</label>@endforeach
        </div>
        <input class="input" name="message" maxlength="140" placeholder="Un petit mot (facultatif)" style="margin-top:12px">
        <p class="faint small" style="margin:8px 0 14px">Ton solde : <b data-coin-balance-full>{{ $me->coins }}</b> coins · <a href="/portefeuille" style="color:var(--primary);font-weight:700">Acheter des coins</a></p>
        <button class="btn btn-lg btn-block">@icon('gift', 'ico-sm')Offrir</button>
    </form>
</dialog>
<dialog id="report" class="sheet">
    <div class="sheet-head"><h2>Signaler</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <form method="post" action="/signaler" class="sheet-body" data-ajax data-report-form>@csrf
        <input type="hidden" name="type"><input type="hidden" name="id">
        <p class="muted small" style="margin:0 0 10px">Qu'est-ce qui ne va pas ? Personne ne saura que c'est toi.</p>
        <div class="report-reasons">
            @foreach (\App\Models\ContentReport::REASONS as $k => $label)
                <label class="report-reason"><input type="radio" name="reason" value="{{ $k }}" required><span>{{ $label }}</span></label>
            @endforeach
        </div>
        <textarea class="input" name="details" maxlength="1000" placeholder="Un détail ? (facultatif)" style="margin-top:10px;min-height:70px"></textarea>
        <button class="btn btn-block" style="margin-top:12px">@icon('flag', 'ico-sm')Envoyer le signalement</button>
    </form>
</dialog>
@endauth
<script src="{{ url('/a/tokos.'.filemtime(public_path('js/tokos.js')).'.js') }}" defer></script>
@feature('comments')
{{-- Commentaires de n'importe quelle publication : une fenêtre qui monte du bas, comme sur TikTok --}}
<dialog class="sheet comments-sheet" id="comments-sheet" aria-labelledby="cs-title">
    <div class="sheet-head"><h2 id="cs-title" data-cs-count>Commentaires</h2>
        <button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <div class="cs-body" data-cs-body></div>
</dialog>
@endfeature
{{-- Un défi depuis le fil : ses duels, et le relever --}}
<dialog class="sheet duels-sheet" id="duels-sheet" aria-labelledby="ds-title">
    <div class="sheet-head"><h2 id="ds-title" data-ds-title>Duels</h2>
        <button type="button" class="icon-btn" data-close aria-label="Fermer">@icon('x')</button></div>
    <div class="ds-body" data-ds-body></div>
</dialog>
</body>
</html>
