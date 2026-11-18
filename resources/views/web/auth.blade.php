<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Connexion' }} · Sosa</title>
    <meta name="theme-color" content="#6C5CE7">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Sosa">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="stylesheet" href="{{ asset('css/tokos.css') }}?v={{ filemtime(public_path('css/tokos.css')) }}">
    <script>document.documentElement.classList.add('js'); try { var t = localStorage.getItem('tokos-theme'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}</script>
</head>
<body>
<div class="auth">
    <aside class="auth-art">
        {{-- Fond vidéo (grand écran seulement) : une suite de courts plans de talents (percussion, chant, danse),
             en 360p, environ 4 s chacun, en boucle (après le premier tour, les clips viennent du cache du navigateur).
             Tes propres clips dans public/videos/talents/*.mp4 remplacent ceux de Mixkit (licence gratuite). --}}
        @php
            $local = collect(glob(public_path('videos/talents/*.mp4')) ?: [])->sort()->map(fn ($f) => asset('videos/talents/'.basename($f)))->values();
            $clips = $local->isNotEmpty() ? $local->all() : collect([45547, 40623, 48365, 45452])
                ->map(fn ($id) => "https://assets.mixkit.co/videos/$id/$id-360.mp4")->all();
        @endphp
        <div class="auth-reel" aria-hidden="true" data-auth-reel='@json($clips)'>
            <video muted playsinline preload="none"></video><video muted playsinline preload="none"></video>
        </div>
        <span class="auth-veil" aria-hidden="true"></span>
        <a class="logo" href="/"><span class="logo-mark">@icon('sosa')</span><span class="logo-word"><i>s</i>o<i>s</i>a</span></a>
        <div>
            <h2>Le réseau où chaque talent se mesure.</h2>
            <p>Lance des défis, affronte les autres en duel, fais voter le public. Seul, ou avec ton Espace.</p>
        </div>
        <div class="proof">@icon('trophy')Défis, duels, concours et tournois</div>
    </aside>
    <main class="auth-form">
        <div class="box">
            <a class="logo" href="/"><span class="logo-mark">@icon('sosa')</span><span class="logo-word"><i>s</i>o<i>s</i>a</span></a>
            @if (session('ok'))<div class="flash flash-ok">@icon('check-circle'){{ session('ok') }}</div>@endif
            @if ($errors->any())<div class="flash flash-err" role="alert">@icon('alert'){{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
    </main>
</div>
<script>
    // Bande de talents : chargée seulement sur grand écran, après l'affichage du formulaire,
    // jamais en mode économie de données ou en connexion lente. Un seul clip est chargé à la fois.
    (function () {
        var reel = document.querySelector('[data-auth-reel]');
        if (!reel || !matchMedia('(min-width: 821px)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var c = navigator.connection;
        if (c && (c.saveData || /(^|-)2g$/.test(c.effectiveType || ''))) return;
        var clips = JSON.parse(reel.dataset.authReel || '[]'), v = reel.querySelectorAll('video');
        if (!clips.length) return;
        if (clips.length === 1) { v[0].loop = true; v[0].src = clips[0]; v[0].addEventListener('playing', function () { v[0].classList.add('on'); }); v[0].play().catch(function () {}); return; }
        var SHOT = 4200, i = 0, cur = 0, timer;
        function load(el, k) { el.src = clips[k % clips.length]; el.preload = 'auto'; el.load(); }
        function show(el) {
            var p = el.play(); if (p && p.catch) p.catch(function () {});
            var old = v[1 - cur]; old.classList.remove('on');
            setTimeout(function () { old.pause(); }, 800);
        }
        function next() {
            i++;
            var nx = 1 - cur, el = v[nx];
            var go = function () { el.removeEventListener('canplay', go); el.currentTime = 0; cur = nx; show(el); schedule(); };
            if (el.readyState >= 3) go(); else { el.addEventListener('canplay', go); if (!el.getAttribute('src')) load(el, i); }
        }
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(next, SHOT);
            // Le clip suivant se prépare pendant que celui-ci joue
            setTimeout(function () { load(v[1 - cur], i + 1); }, 1200);
        }
        var fails = 0;
        v.forEach(function (el) {
            // Un clip injoignable : on passe au suivant (sans boucler à l'infini)
            el.addEventListener('error', function () {
                if (++fails > clips.length) return;
                if (el === v[cur]) { next(); } else { i++; load(el, i + 1); }
            });
        });
        function start() {
            var el = v[0];
            el.addEventListener('playing', function once() { el.removeEventListener('playing', once); el.classList.add('on'); schedule(); });
            load(el, 0); var p = el.play(); if (p && p.catch) p.catch(function () {});
        }
        if (document.readyState === 'complete') setTimeout(start, 300); else addEventListener('load', function () { setTimeout(start, 300); });
    })();
</script>
<script src="{{ asset('js/tokos.js') }}?v={{ filemtime(public_path('js/tokos.js')) }}" defer></script>
</body>
</html>
