<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $heading }} · Sosa</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
  :root { color-scheme: light dark; --p: #6C5CE7; }
  body { margin: 0; min-height: 100dvh; display: grid; place-items: center; font: 16px/1.5 "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif; background: #fff; color: #14141a; text-align: center; padding: 24px; box-sizing: border-box; }
  @media (prefers-color-scheme: dark) { body { background: #0c0c10; color: #f2f2f5; } .muted { color: #a0a0ad !important; } .ghost { background: #1e1e26 !important; color: #f2f2f5 !important; } }
  .badge { width: 76px; height: 76px; border-radius: 24px; background: #efedff; color: var(--p); display: grid; place-items: center; margin: 0 auto 18px; }
  .badge svg { width: 38px; height: 38px; }
  h1 { font-size: 1.35rem; margin: 0 0 6px; }
  .muted { margin: 0 auto 24px; color: #5d5d6b; max-width: 34ch; }
  .row { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
  a, button { font: inherit; font-weight: 700; text-decoration: none; border: 0; border-radius: 999px; padding: 12px 22px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
  .primary { background: var(--p); color: #fff; }
  .ghost { background: #f1f1f5; color: #14141a; }
  svg.i { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
</style>
</head>
<body>
<main>
  <div class="badge">{!! $icon !!}</div>
  <h1>{{ $heading }}</h1>
  <p class="muted">{{ $text }}</p>
  <div class="row">
    <button type="button" class="ghost" onclick="history.length > 1 ? history.back() : location.assign('/')"><svg class="i" viewBox="0 0 24 24"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>Retour</button>
    <a class="primary" href="/"><svg class="i" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>Accueil</a>
  </div>
</main>
</body>
</html>
