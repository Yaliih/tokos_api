/* Sosa — interactions du site. Sans JavaScript, les formulaires restent utilisables. */
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
  };

  // Économie de données : réglage de la personne, sinon celui du téléphone (« Économiseur de données »)
  const dataSaver = () => {
    const v = store.get('tokos-datasaver');
    return v === '1' || (v !== '0' && !!(navigator.connection && navigator.connection.saveData));
  };

  // ── Thème clair / sombre ──────────────────────────────────────
  document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('pointerdown', (e) => e.preventDefault()); // le champ garde le clavier ouvert
    btn.addEventListener('click', () => {
      const root = document.documentElement;
      const current = root.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      const next = current === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      store.set('tokos-theme', next);
      document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.setAttribute('content', next === 'dark' ? '#16161C' : '#FFFFFF'));
      btn.setAttribute('aria-label', next === 'dark' ? 'Passer au thème clair' : 'Passer au thème sombre');
    });
  });

  // La barre du téléphone suit le thème, y compris quand il change tout seul
  (() => {
    const scheme = matchMedia('(prefers-color-scheme: dark)');
    const paint = () => {
      const root = document.documentElement;
      const dark = root.dataset.theme ? root.dataset.theme === 'dark' : scheme.matches;
      document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.setAttribute('content', dark ? '#16161C' : '#FFFFFF'));
    };
    scheme.addEventListener?.('change', () => { if (!document.documentElement.dataset.theme) paint(); });
    paint();
  })();

  // ── Petit message ─────────────────────────────────────────────
  window.tokosToast = (m, err) => toast(m, err);
  function toast(message, isError) {
    let el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast';
      el.className = 'toast';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.style.background = isError ? 'var(--coral-600)' : 'var(--text)';
    el.style.color = isError ? '#fff' : 'var(--surface)';
    el.hidden = false;
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.hidden = true; }, 3200);
  }

  // Position du téléphone → ville (pour les compétitions réservées à une ville). Seule la ville est gardée.
  // OpenStreetMap appelé depuis le téléphone (plus fiable que depuis le serveur) ; le serveur vérifie ensuite
  async function reverseGeocode(lat, lng) {
    try {
      const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 6000);
      const r = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=10&addressdetails=1&accept-language=fr&lat=' + lat + '&lon=' + lng, { signal: ctl.signal });
      clearTimeout(t);
      const a = (await r.json()).address || {};
      // Ville d'abord ; en RD Congo, un quartier est souvent noté « village » : on préfère alors le territoire (county),
      // qui porte en général le nom de la ville principale (ex. Mbanza-Ngungu plutôt que Djalo)
      return { city: a.city || a.town || a.municipality || a.county || a.village || '', state: a.state || '', county: a.county || '', country: a.country || '', code: a.country_code || '' };
    } catch (e) { return {}; }
  }
  let locating = null;
  function locate() {
    if (locating) return locating; // un seul essai à la fois
    locating = new Promise((resolve, reject) => {
      // Les navigateurs ne donnent la position qu'en HTTPS (ou sur localhost) : sinon ils la « refusent » d'office
      if (!window.isSecureContext) { reject(new Error('La localisation demande une connexion sécurisée (https). Ouvre le site en https, ou sur localhost pendant les essais.')); return; }
      if (!navigator.geolocation) { reject(new Error('Ce navigateur ne donne pas la position.')); return; }
      document.dispatchEvent(new CustomEvent('tokos:locating', { detail: true }));
      // Avant la question du navigateur, on dit pourquoi ; si c'est déjà bloqué, on explique comment débloquer
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then((p) => {
          if (p.state === 'prompt') toast('Sosa a besoin de ta position une fois, pour vérifier ta ville. Elle n\'est pas affichée aux autres.');
        }).catch(() => {});
      }
      // On écoute la position quelques secondes et on garde la plus précise : le GPS a besoin d'un instant pour se fixer
      let best = null; let done = false;
      const finish = async () => {
        if (done) return; done = true;
        navigator.geolocation.clearWatch(watch); clearTimeout(timer);
        if (!best) { reject(new Error('Position introuvable. Active la localisation (GPS) de ton appareil, puis réessaie.')); return; }
        try {
          const { latitude: lat, longitude: lng, accuracy } = best.coords;
          const hint = await reverseGeocode(lat, lng);
          const fd = new FormData();
          fd.append('lat', lat); fd.append('lng', lng); fd.append('accuracy', Math.round(accuracy || 0));
          if (hint.city) fd.append('city_hint', hint.city);
          if (hint.state) fd.append('state_hint', hint.state);
          if (hint.county) fd.append('county_hint', hint.county);
          if (hint.country) fd.append('country_hint', hint.country);
          if (hint.code) fd.append('country_code', hint.code);
          const res = await fetch('/localiser', { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data.message || 'Position introuvable.');
          toast('Position vérifiée : ' + data.label);
          document.querySelectorAll('[data-my-city]').forEach((el) => { if (el.tagName === 'INPUT') el.value = data.city || ''; else el.textContent = data.city || ''; });
          resolve(data);
        } catch (e) { reject(e); }
      };
      const watch = navigator.geolocation.watchPosition((pos) => {
        if (!best || pos.coords.accuracy < best.coords.accuracy) best = pos;
        if (pos.coords.accuracy <= 100) finish(); // assez précis : on n'attend pas plus
      }, (err) => {
        if (best) return finish();
        done = true; navigator.geolocation.clearWatch(watch); clearTimeout(timer);
        reject(new Error(err.code === 1
          ? 'Position refusée. Touche le cadenas à côté de l\'adresse du site, autorise « Position », puis réessaie.'
          : 'Position introuvable. Active la localisation (GPS) de ton appareil, puis réessaie.'));
      }, { enableHighAccuracy: true, timeout: 25000, maximumAge: 0 });
      const timer = setTimeout(finish, 12000);
    }).finally(() => { locating = null; document.dispatchEvent(new CustomEvent('tokos:locating', { detail: false })); });
    return locating;
  }
  window.tokosLocate = locate;
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-locate]');
    if (!b) return;
    e.preventDefault();
    b.disabled = true; b.classList.add('is-loading');
    try { await locate(); if (b.dataset.locate === 'reload') location.reload(); } catch (err) { toast(err.message, true); }
    b.disabled = false; b.classList.remove('is-loading');
  });

  async function send(url, body, retried) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body,
      credentials: 'same-origin',
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* réponse vide */ }
    // Compétition réservée à une ville : on vérifie la position, puis on refait la même action
    if (res.status === 428 && data.code === 'location_required' && !retried) {
      await locate();
      return send(url, body, true);
    }
    if (res.status === 401) { window.location.href = '/connexion?suite=' + encodeURIComponent(location.pathname); return null; }
    if (res.status === 402) {
      // Pas assez de coins : on l'explique, puis direction le portefeuille
      toast((data.message || 'Pas assez de coins.') + ' On t\'emmène recharger.', true);
      setTimeout(() => { window.location.href = '/portefeuille'; }, 1800);
      return null;
    }
    if (!res.ok) {
      const first = data.errors ? Object.values(data.errors)[0] : null;
      throw new Error((Array.isArray(first) ? first[0] : first) || data.message || 'Action impossible. Réessaie.');
    }
    return data;
  }

  // ── Formulaires en arrière-plan (vote, suivre, rejoindre, aimer) ──
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-ajax]');
    if (!form) return;
    e.preventDefault();
    const btn = e.submitter || form.querySelector('button');
    const body = new FormData(form);
    if (btn && btn.name) body.append(btn.name, btn.value);
    // J'aime, suivre, rejoindre : affiché tout de suite, envoyé en arrière-plan ; en cas d'échec, on remet comme avant
    const instant = !!btn && (form.hasAttribute('data-like') || !!form.dataset.toggleText);
    const flip = (cheer) => {
      if (form.dataset.toggleText) {
        const old = btn.textContent.trim();
        btn.textContent = form.dataset.toggleText;
        form.dataset.toggleText = old;
        btn.classList.toggle('btn-ghost');
        btn.classList.toggle('btn-soft');
      }
      if (form.hasAttribute('data-like')) {
        const on = btn.getAttribute('aria-pressed') !== 'true';
        btn.setAttribute('aria-pressed', String(on));
        btn.classList.toggle('on', on);
        btn.style.color = '';
        const count = btn.querySelector('[data-like-count]');
        if (count) count.textContent = String(Math.max(0, parseInt(count.textContent || '0', 10) + (on ? 1 : -1)));
        // J'aime sur un duel : des cœurs remplissent le duel
        if (on && cheer) window.tokosCheer && window.tokosCheer(form.closest('.versus')?.querySelector('.versus-ring'), ['❤️', '❤️', '💜']);
      }
    };
    if (instant) {
      if (form.dataset.busy) return; // un envoi à la fois : le prochain toucher attend la réponse
      form.dataset.busy = '1';
      flip(true);
    } else if (btn) btn.disabled = true;
    try {
      const data = await send(form.action, body);
      if (!data) { if (instant) flip(false); return; }
      // Le serveur dit l'état exact (si deux appareils aiment en même temps)
      if (instant && form.hasAttribute('data-like')) {
        if (typeof data.liked === 'boolean' && (btn.getAttribute('aria-pressed') === 'true') !== data.liked) flip(false);
        const count = btn.querySelector('[data-like-count]');
        if (count && data.count !== undefined && data.count !== null) count.textContent = String(data.count);
      }
      if (form.dataset.dismiss) { const box = form.closest(form.dataset.dismiss); if (box) box.remove(); }
      if (data.html && form.dataset.replace) {
        const target = form.closest(form.dataset.replace);
        if (target) {
          const tpl = document.createElement('template');
          tpl.innerHTML = data.html.trim();
          const fresh = tpl.content.firstElementChild;
          target.replaceWith(fresh);
          applyLayout(); countdowns(); watchVideos(fresh);
          // Vote ajouté : des bravos remplissent le côté choisi
          if (/\/vote$/.test(form.action) && btn && btn.getAttribute('aria-pressed') !== 'true') window.tokosCheer && window.tokosCheer(fresh.querySelector('.side.is-mine .side-visual'));
        }
      }
      // « Reçus » : l'élément traité s'efface, le compteur du haut baisse
      if (form.hasAttribute('data-inbox-done')) {
        const li = form.closest('.inbox-item');
        if (li) { li.classList.add('done'); setTimeout(() => li.remove(), 350); }
        document.querySelectorAll('a[href="/defis-recus"] .dot-count, a[href="/defis-recus"] .count').forEach((c) => {
          const n = Math.max(0, (parseInt(c.textContent, 10) || 0) - 1); if (n) c.textContent = n; else c.remove();
        });
      }
      if (data.message && !form.hasAttribute('data-silent')) toast(data.message);
      if (form.hasAttribute('data-report-form')) { form.reset(); form.closest('dialog')?.close(); }
      if (form.hasAttribute('data-gift-form')) { form.closest('dialog')?.close(); }
      if (data.balance !== undefined) setBalance(data.balance);
      if (data.redirect) window.location.href = data.redirect;
    } catch (err) {
      if (instant) flip(false);
      toast(err.message, true);
    } finally {
      delete form.dataset.busy;
      if (btn && document.body.contains(btn)) btn.disabled = false;
    }
  });

  // ── Fenêtres (participer, répondre, créer un Espace) ──────────
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open]');
    if (opener) {
      const dlg = document.getElementById(opener.dataset.open);
      if (dlg && dlg.showModal) { e.preventDefault(); dlg.showModal(); }
    }
    if (e.target.closest('[data-close]')) e.target.closest('dialog')?.close();
    if (e.target.tagName === 'DIALOG') e.target.close(); // clic sur le fond
  });
  const hash = location.hash.slice(1);
  if (hash) {
    const dlg = document.getElementById(hash);
    if (dlg && dlg.tagName === 'DIALOG' && dlg.showModal) dlg.showModal();
  }

  // ── Zones de dépôt avec aperçu ────────────────────────────────
  function previewDrop(drop) {
    const input = drop.querySelector('input[type=file]');
    if (!input) return;
    drop.querySelector('.preview')?.remove();
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 50 * 1024 * 1024) {
      toast('Fichier trop lourd : 50 Mo maximum.', true);
      input.value = '';
      return;
    }
    const box = document.createElement('div');
    box.className = 'preview';
    const url = URL.createObjectURL(file);
    if (file.type.startsWith('image/')) {
      box.innerHTML = '<img alt="">';
      box.firstChild.src = url;
    } else if (file.type.startsWith('video/')) {
      box.innerHTML = '<video muted playsinline></video>';
      box.firstChild.src = url;
    } else {
      box.innerHTML = '<span class="file"></span>';
      box.firstChild.textContent = '♪ ' + file.name;
    }
    drop.appendChild(box);
  }
  document.addEventListener('change', (e) => {
    const drop = e.target.closest('[data-drop]');
    if (drop && e.target.type === 'file') previewDrop(drop);
  });
  document.addEventListener('dragover', (e) => {
    const drop = e.target.closest('[data-drop]');
    if (drop) drop.classList.add('is-over');
  });
  ['dragleave', 'drop'].forEach((ev) => document.addEventListener(ev, (e) => {
    const drop = e.target.closest('[data-drop]');
    if (drop) drop.classList.remove('is-over');
  }));

  // ── Disposition des duels : côte à côte ou empilés ────────────
  // Disposition des duels : côte à côte sur grand écran, l'un au-dessus de l'autre sur mobile (CSS)
  function applyLayout() {}

  // Une seule vidéo ou un seul son à la fois
  document.addEventListener('play', (e) => {
    if (!(e.target instanceof HTMLMediaElement)) return;
    document.querySelectorAll('video, audio').forEach((m) => { if (m !== e.target) m.pause(); });
  }, true);

  // ── Comptes à rebours ─────────────────────────────────────────
  // Passé : « Fini il y a … », comme partout ailleurs
  function agoText(s) {
    s = Math.abs(s);
    if (s < 60) return 'à l\'instant';
    if (s < 3600) return 'il y a ' + Math.floor(s / 60) + ' min';
    if (s < 86400) return 'il y a ' + Math.floor(s / 3600) + ' h';
    if (s < 7 * 86400) return 'il y a ' + Math.floor(s / 86400) + ' j';
    if (s < 30 * 86400) return 'il y a ' + Math.floor(s / 604800) + ' sem.';
    if (s < 365 * 86400) return 'il y a ' + Math.floor(s / 2592000) + ' mois';
    const y = Math.floor(s / 31536000); return 'il y a ' + y + ' an' + (y > 1 ? 's' : '');
  }
  function fmt(s) {
    if (s <= 0) return 'Terminé';
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    if (d > 0) return d + ' j ' + h + ' h';
    if (h > 0) return h + ' h ' + String(m).padStart(2, '0') + ' min';
    return m + ' min ' + String(sec).padStart(2, '0') + ' s';
  }
  function countdowns() {
    document.querySelectorAll('[data-ends]').forEach((el) => {
      const end = Date.parse(el.dataset.ends);
      if (!end) return;
      const raw = Math.round((end - Date.now()) / 1000);
      const left = Math.max(0, raw);
      // Passé : « Terminé il y a… » (le vrai écart, pas « à l'instant »), ou le texte prévu (« Inscriptions closes »)
      el.textContent = raw > 0 ? (el.dataset.prefix || '') + fmt(left) : (el.dataset.endedText || 'Terminé ' + agoText(raw));
      el.classList.toggle('soon', left > 0 && left < 3600);
      el.closest('.when')?.classList.toggle('over', raw <= 0);
    });
  }
  setInterval(countdowns, 1000);

  // ── Score en direct sur la page d'un duel ─────────────────────
  if (document.querySelector('[data-live-match]')) {
    setInterval(async () => {
      if (document.hidden) return;
      const el = document.querySelector('[data-live-match]');
      if (!el || el.contains(document.activeElement)) return;
      try {
        const res = await fetch(el.dataset.liveMatch, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', priority: 'low', quiet: true });
        const data = await res.json();
        if (data.html) {
          const tpl = document.createElement('template');
          tpl.innerHTML = data.html.trim();
          el.replaceWith(tpl.content.firstElementChild);
          applyLayout(); countdowns(); watchVideos(fresh);
        }
      } catch (e) { /* réseau indisponible : on réessaiera */ }
    }, 15000);
  }

  // ── Partage ───────────────────────────────────────────────────
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-share]');
    if (!b) return;
    const url = new URL(b.dataset.share, location.origin).toString();
    const title = b.dataset.title || document.title;
    const text = b.dataset.text || ('Viens voter : ' + title);
    // WhatsApp d'abord (c'est là que les votes se jouent), puis copier le lien, puis les autres applis
    let dlg = document.getElementById('share-sheet');
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.id = 'share-sheet';
      dlg.className = 'sheet share-sheet';
      dlg.innerHTML = '<div class="sheet-head"><h2>Partager</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">&times;</button></div>'
        + '<div class="share-list"><a class="share-item wa" data-share-wa target="_blank" rel="noopener"><span class="share-ico">' + (document.querySelector('[data-icon-wa]')?.innerHTML || 'W') + '</span>WhatsApp</a>'
        + '<button type="button" class="share-item" data-share-copy><span class="share-ico">' + (document.querySelector('[data-icon-link]')?.innerHTML || '') + '</span>Copier le lien</button>'
        + '<button type="button" class="share-item" data-share-more hidden><span class="share-ico">' + (document.querySelector('[data-icon-more]')?.innerHTML || '') + '</span>Autres applis</button>'
        + '<button type="button" class="share-item report" data-share-report hidden><span class="share-ico">' + (document.querySelector('[data-icon-flag]')?.innerHTML || '') + '</span>Signaler</button></div>';
      document.body.appendChild(dlg);
    }
    dlg.querySelector('[data-share-wa]').href = 'https://wa.me/?text=' + encodeURIComponent(text + ' ' + url);
    dlg.querySelector('[data-share-copy]').onclick = async () => {
      try { await navigator.clipboard.writeText(url); toast('Lien copié'); } catch (err) { prompt('Copie ce lien :', url); }
      dlg.close();
    };
    const more = dlg.querySelector('[data-share-more]');
    more.hidden = !navigator.share;
    more.onclick = async () => { dlg.close(); try { await navigator.share({ title, text, url }); } catch (err) { /* annulé */ } };
    // WhatsApp : le lien et le message, sans image fabriquée (l'aperçu du lien suffit)
    dlg.querySelector('[data-share-wa]').onclick = () => setTimeout(() => dlg.close(), 100);
    const rep = dlg.querySelector('[data-share-report]');
    rep.hidden = !b.dataset.report;
    rep.onclick = () => { dlg.close(); openReport(b.dataset.report); };
    dlg.showModal();
  });

  // ── Réglage « économie de données » ───────────────────────────
  function syncSaver() {
    document.querySelectorAll('[data-datasaver-toggle]').forEach((b) => b.setAttribute('aria-pressed', String(dataSaver())));
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-datasaver-toggle]');
    if (!b) return;
    const on = !dataSaver();
    store.set('tokos-datasaver', on ? '1' : '0');
    if (on) document.querySelectorAll('video[data-autoplay]').forEach((v) => { if (!v.paused) { v._auto = true; v.pause(); } });
    syncSaver();
    toast(on ? 'Économie de données activée : les vidéos ne se lancent plus toutes seules.' : 'Les vidéos se lancent de nouveau toutes seules.');
  });
  syncSaver();

  // ── Envoi des formulaires avec fichier : en une seule requête, avec la progression ──
  // Le fichier allégé sur le téléphone est envoyé tel quel (jamais réinjecté dans le champ,
  // ce qui provoque « ERR_UPLOAD_FILE_CHANGED » sur certains téléphones et en PWA).
  const lightFiles = new WeakMap(); // champ fichier -> fichier allégé à envoyer

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented) return;
    const inputs = Array.from(form.querySelectorAll('input[type=file]')).filter((i) => !i.disabled && (lightFiles.has(i) || (i.files && i.files.length)));
    if (!inputs.length) return;
    if (form.dataset.sending) { e.preventDefault(); return; }

    e.preventDefault();
    form.dataset.sending = '1';
    const btn = form.querySelector('button[type=submit], button:not([type])');
    if (btn) btn.disabled = true;

    const body = new FormData(form);
    inputs.forEach((i) => {
      const f = lightFiles.get(i) || (i.files && i.files[0]);
      if (f) body.set(i.name || 'file', f, f.name || 'fichier');
    });

    const done = (ok, msg) => {
      form.dataset.sending = '';
      if (btn) btn.disabled = false;
      if (!ok && msg) toast(msg, true);
    };
    // Réseau qui coupe : on recommence l'envoi deux fois avant d'abandonner
    let attempt = 0;
    const send = () => {
      const xhr = new XMLHttpRequest();
      let last = 0;
      xhr.open('POST', form.action || location.href, true);
      xhr.withCredentials = true;
      xhr.timeout = 0; // un gros fichier sur réseau lent peut prendre longtemps
      xhr.upload.onprogress = (ev) => {
        if (!ev.lengthComputable) return;
        const pct = Math.round(ev.loaded / ev.total * 100);
        if (pct >= 100 || pct - last >= 15) { last = pct; toast(pct >= 100 ? 'Envoi terminé, traitement…' : 'Envoi ' + pct + ' %'); }
      };
      xhr.onload = () => {
        if (xhr.status >= 400 && xhr.status !== 422) { done(false, 'Envoi refusé (' + xhr.status + '). Réessaie.'); return; }
        // Après l'envoi, on ouvre la page de destination (elle montre le résultat ou les erreurs)
        location.assign(xhr.responseURL || form.action || location.href);
      };
      const retry = () => {
        if (attempt++ < 2) { toast('Réseau instable : nouvelle tentative (' + (attempt + 1) + '/3)…'); setTimeout(send, 1500 * attempt); return; }
        done(false, 'Réseau trop faible : l\'envoi a échoué après 3 essais.');
      };
      xhr.onerror = retry;
      xhr.ontimeout = retry;
      xhr.send(body);
    };
    send();
  });
  // ── Modifier le profil : on choisit, on confirme, et c'est enregistré ──
  // Rien ne part tant qu'on n'a pas touché « ✓ » : le bouton « Enregistrer » du bas ne concerne que les textes.
  const csrfToken = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const photoOriginal = new WeakMap(); // emplacement -> image affichée avant le choix

  function photoButtons(form, data) {
    const a = form.querySelector('[data-photo-remove="avatar"]');
    const c = form.querySelector('[data-photo-remove="cover"]');
    if (a) a.hidden = !data.has_avatar;
    if (c) c.hidden = !data.cover;
  }
  function photoBar(root, kind, show) {
    const bar = root.closest('[data-preview]').querySelector('[data-photo-act="' + kind + '"]');
    const del = root.closest('[data-preview]').querySelector('[data-photo-remove="' + kind + '"]');
    if (bar) bar.hidden = !show;
    if (del && show) del.hidden = true;
  }

  // Jolie fenêtre de confirmation (même style que les feuilles de l'app)
  function askConfirm(title, text, okLabel) {
    return new Promise((resolve) => {
      const dlg = document.createElement('dialog');
      dlg.className = 'sheet confirm-sheet';
      dlg.innerHTML = '<div class="sheet-head"><h2>' + title + '</h2></div>'
        + '<p class="confirm-text">' + text + '</p>'
        + '<div class="confirm-actions">'
        + '<button type="button" class="btn btn-ghost" data-confirm-no>Annuler</button>'
        + '<button type="button" class="btn btn-danger" data-confirm-yes>' + okLabel + '</button>'
        + '</div>';
      document.body.appendChild(dlg);
      const close = (v) => { if (dlg.open) dlg.close(); dlg.remove(); resolve(v); };
      dlg.addEventListener('click', (e) => {
        if (e.target.closest('[data-confirm-yes]')) close(true);
        else if (e.target.closest('[data-confirm-no]') || e.target === dlg) close(false);
      });
      dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(false); });
      dlg.showModal();
    });
  }

  function photoUpload(form, input) {
    const url = form && form.dataset.photoUrl;
    const file = input.files && input.files[0];
    if (!url || !file) return;
    const body = new FormData();
    body.append('_token', csrfToken());
    body.append(input.name, file, file.name || 'photo');
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = (ev) => { if (ev.lengthComputable && ev.loaded >= ev.total) toast('Photo envoyée, enregistrement…'); };
    xhr.onload = () => {
      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { /* pas de JSON */ }
      if (xhr.status >= 400 || !data || !data.ok) {
        const first = data && data.errors ? Object.values(data.errors)[0] : null;
        toast((Array.isArray(first) ? first[0] : first) || 'Photo refusée (' + xhr.status + ').', true);
        return;
      }
      const box = input.closest('[data-preview]').querySelector('[data-preview-box]');
      if (input.name === 'avatar' && data.avatar) {
        box.innerHTML = '<img src="' + data.avatar + '" alt="">';
      } else if (input.name === 'cover' && data.cover) {
        box.innerHTML = data.cover_type === 'video'
          ? '<video src="' + data.cover + '#t=0.1" muted playsinline loop></video>'
          : '<img src="' + data.cover + '" alt="">';
      }
      photoOriginal.delete(box);
      form.dataset.hasAvatar = data.has_avatar ? '1' : '0';
      form.dataset.hasCover = data.cover ? '1' : '0';
      input.value = '';
      photoBar(input, input.name, false);
      photoButtons(form, data);
      toast('Photo enregistrée.');
    };
    xhr.onerror = () => toast('Réseau trop faible : la photo n\'a pas été envoyée. Réessaie.', true);
    xhr.send(body);
  }

  // « ✓ » : on envoie la photo choisie — « ✕ » : on garde l'ancienne
  document.addEventListener('click', (e) => {
    const save = e.target.closest('[data-photo-save]');
    if (save) {
      const form = save.closest('form');
      const input = save.closest('[data-preview]').querySelector('[data-preview-input]');
      if (input) photoUpload(form, input);
      return;
    }
    const cancel = e.target.closest('[data-photo-cancel]');
    if (cancel) {
      const input = cancel.closest('[data-preview]').querySelector('[data-preview-input]');
      const box = cancel.closest('[data-preview]').querySelector('[data-preview-box]');
      if (input) input.value = '';
      if (box && photoOriginal.has(box)) box.innerHTML = photoOriginal.get(box);
      photoOriginal.delete(box);
      photoBar(cancel, cancel.dataset.photoCancel, false);
      const form = cancel.closest('form');
      if (form) {
        const kind = cancel.dataset.photoCancel;
        const del = form.querySelector('[data-photo-remove="' + kind + '"]');
        if (del) del.hidden = (kind === 'avatar' ? form.dataset.hasAvatar : form.dataset.hasCover) !== '1';
      }
      toast('Changement annulé.');
    }
  });

  // Supprimer : on demande confirmation dans une vraie fenêtre, puis on supprime
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-photo-remove]');
    if (!b) return;
    const form = b.closest('form');
    const kind = b.dataset.photoRemove;
    if (!form || !form.dataset.photoUrl) return;
    const ok = await askConfirm(
      kind === 'avatar' ? 'Supprimer ta photo de profil ?' : 'Supprimer ta couverture ?',
      kind === 'avatar' ? 'Ton profil reprendra l\'image par défaut.' : 'Ta page reprendra une couverture vide.',
      'Supprimer'
    );
    if (!ok) return;
    const body = new FormData();
    body.append('_token', csrfToken());
    body.append('remove', kind);
    fetch(form.dataset.photoUrl, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        if (!data || !data.ok) { toast('Suppression impossible.', true); return; }
        const box = b.closest('[data-preview]').querySelector('[data-preview-box]');
        if (kind === 'avatar') {
          box.innerHTML = '<img src="' + data.avatar + '" alt="">';
        } else {
          box.innerHTML = '<span class="edit-cover-empty">Ajoute une photo ou une courte vidéo</span>';
        }
        photoButtons(form, data);
        form.dataset.hasAvatar = data.has_avatar ? '1' : '0';
        form.dataset.hasCover = data.cover ? '1' : '0';
        toast('Supprimé.');
      })
      .catch(() => toast('Réseau trop faible : suppression impossible.', true));
  });
  // ── Modifier le profil : aperçu des images choisies ───────────
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-preview-pick]');
    if (b) { e.preventDefault(); b.closest('[data-preview]').querySelector('[data-preview-input]').click(); }
  });
  // Durée d'une vidéo choisie (null si illisible : le serveur vérifiera)
  const videoSeconds = (f) => new Promise((resolve) => {
    const v = document.createElement('video'); v.preload = 'metadata';
    const done = (d) => { URL.revokeObjectURL(v.src); resolve(d); };
    v.onloadedmetadata = () => done(isFinite(v.duration) ? v.duration : null); v.onerror = () => done(null);
    v.src = URL.createObjectURL(f);
  });
  document.addEventListener('change', async (e) => {
    if (e.target.matches('[data-preview-input]')) {
      const f = e.target.files && e.target.files[0];
      const box = e.target.closest('[data-preview]').querySelector('[data-preview-box]');
      if (!f || !box) return;
      // Une photo est d'abord allégée (elle partira allégée), puis on l'affiche tout de suite :
      // l'aperçu ne doit jamais rester vide, même si le fichier est trop lourd pour le serveur.
      let send = f;
      if (f.type.startsWith('image/') && f.type !== 'image/gif') {
        const small = await shrinkImage(f);
        if (small && small !== f) { send = small; lightFiles.set(e.target, small); }
      }
      const maxMb = +e.target.dataset.maxMb || 50;
      if (send.size > maxMb * 1048576) {
        toast('Photo trop lourde (' + fmtSize(send.size) + ') : ' + maxMb + ' Mo maximum. Choisis-en une autre.', true);
        e.target.value = '';
        lightFiles.delete(e.target);
        return;
      }
      const maxSec = +e.target.dataset.maxVideo || 0;
      if (maxSec && f.type.startsWith('video/')) {
        const d = await videoSeconds(f);
        if (d && d > maxSec + 1) { toast('Vidéo trop longue (' + Math.round(d) + ' s) : ' + maxSec + ' s maximum. Choisis-en une plus courte.', true); e.target.value = ''; return; }
      }
      const url = URL.createObjectURL(send);
      box.innerHTML = '';
      const el = document.createElement(f.type.startsWith('video/') ? 'video' : 'img');
      el.src = url; if (el.tagName === 'VIDEO') { el.muted = true; el.loop = true; el.autoplay = true; el.playsInline = true; }
      box.appendChild(el);
      const box2 = e.target.closest('[data-preview]').querySelector('[data-preview-box]');
      if (box2 && !photoOriginal.has(box2)) photoOriginal.set(box2, box2.innerHTML);
      photoBar(e.target, e.target.name, true); // rien ne part : on attend « ✓ »
      const rm = e.target.closest('form').querySelector('[data-remove-cover]');
      if (rm && e.target.name === 'cover') rm.checked = false;
    }
    if (e.target.matches('[data-remove-cover]')) {
      const box = e.target.closest('[data-preview]').querySelector('[data-preview-box]');
      box.classList.toggle('removed', e.target.checked);
    }
  });
  document.addEventListener('input', (e) => {
    if (e.target.matches('[name="username"]')) { const h = document.querySelector('[data-handle-preview]'); if (h) h.textContent = e.target.value; }
    if (e.target.matches('[data-count]')) { const o = e.target.parentElement.querySelector('[data-count-out]'); if (o) o.textContent = e.target.value.length + ' / ' + e.target.maxLength; }
  });

  // Création : votes payants (on, prix par vote)
  document.addEventListener('change', (e) => {
    const t = e.target.closest('[data-paid-toggle]');
    if (!t) return;
    const box = t.closest('[data-paid-votes]');
    const input = box.querySelector('[data-cost-input]');
    box.querySelector('[data-paid-box]').hidden = !t.checked;
    if (t.checked && !(+input.value > 0)) { input.value = box.querySelector('[data-cost-preset]')?.dataset.costPreset || '1'; }
    if (!t.checked) input.value = '0';
    box.querySelectorAll('[data-cost-preset]').forEach((b) => b.classList.toggle('on', b.dataset.costPreset === input.value));
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cost-preset]');
    if (!b) return;
    const box = b.closest('[data-paid-votes]');
    box.querySelector('[data-cost-input]').value = b.dataset.costPreset;
    box.querySelectorAll('[data-cost-preset]').forEach((x) => x.classList.toggle('on', x === b));
  });
  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-cost-input]')) return;
    e.target.closest('[data-paid-votes]').querySelectorAll('[data-cost-preset]').forEach((x) => x.classList.toggle('on', x.dataset.costPreset === e.target.value));
  });

  // ── Coins : voter plusieurs fois (compétitions payantes) et offrir ──
  function setBalance(n) {
    document.querySelectorAll('[data-coin-balance]').forEach((el) => { el.textContent = n >= 10000 ? Math.floor(n / 1000) + 'k' : String(n); });
    document.querySelectorAll('[data-coin-balance-full]').forEach((el) => { el.textContent = String(n); });
  }
  const vc = document.getElementById('vote-coins');
  let vcForm = null;
  let vcQty = 1;
  function vcUpdate() {
    if (!vc || !vcForm) return;
    vc.querySelector('[data-vc-total]').textContent = String(vcQty * (+vcForm.dataset.cost || 1));
    vc.querySelectorAll('[data-q]').forEach((b) => b.classList.toggle('on', +b.dataset.q === vcQty && !vc.querySelector('[data-vc-custom]').value));
  }
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form.hasAttribute || !form.hasAttribute('data-paid-vote') || form._confirmed) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (!vc) { window.location.href = '/connexion?suite=' + encodeURIComponent(location.pathname); return; }
    vcForm = form; vcQty = 1;
    vc.querySelector('[data-vc-name]').textContent = form.dataset.name || '';
    vc.querySelector('[data-vc-cost]').textContent = form.dataset.cost || '1';
    vc.querySelector('[data-vc-custom]').value = '';
    vcUpdate();
    vc.showModal();
  }, true);
  vc?.addEventListener('click', (e) => {
    const q = e.target.closest('[data-q]');
    if (q) { vcQty = +q.dataset.q; vc.querySelector('[data-vc-custom]').value = ''; vcUpdate(); }
    if (e.target.closest('[data-vc-confirm]') && vcForm) {
      let input = vcForm.querySelector('input[name="quantity"]');
      if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'quantity'; vcForm.appendChild(input); }
      input.value = String(vcQty);
      vc.close();
      vcForm._confirmed = true;
      vcForm.requestSubmit();
      const f = vcForm; setTimeout(() => { f._confirmed = false; }, 50);
    }
  });
  vc?.querySelector('[data-vc-custom]')?.addEventListener('input', (e) => { vcQty = Math.max(1, Math.min(+e.target.max || 500, parseInt(e.target.value || '1', 10) || 1)); vcUpdate(); });
  document.addEventListener('click', (e) => {
    const g = e.target.closest('[data-gift]');
    if (!g) return;
    e.preventDefault();
    const dlg = document.getElementById('gift');
    if (!dlg) { window.location.href = '/connexion?suite=' + encodeURIComponent(location.pathname); return; }
    dlg.querySelector('[data-gift-name]').textContent = g.dataset.giftName || '';
    dlg.querySelector('[name="to_type"]').value = g.dataset.giftType || 'user';
    dlg.querySelector('[name="to_id"]').value = g.dataset.giftId;
    dlg.querySelector('[name="competition_id"]').value = g.dataset.giftComp || '';
    dlg.showModal();
  });

  // ── Signaler un contenu ───────────────────────────────────────
  function openReport(what) {
    const dlg = document.getElementById('report');
    if (!dlg) { window.location.href = '/connexion?suite=' + encodeURIComponent(location.pathname); return; }
    const [type, id] = String(what).split(':');
    dlg.querySelector('[name="type"]').value = type;
    dlg.querySelector('[name="id"]').value = id;
    dlg.showModal();
  }
  document.addEventListener('click', (e) => {
    const r = e.target.closest('[data-report-open]');
    if (r) { e.preventDefault(); openReport(r.dataset.reportOpen); }
  });

  // ── Champs qui dépendent du format (création) ─────────────────
  function syncConditional(form) {
    const type = form.querySelector('input[name="type"]:checked')?.value;
    const format = form.querySelector('input[name="format"]:checked')?.value;
    const match = (rules) => rules.split(' ').some((rule) => {
      const [t, f] = rule.split(':');
      return t === type && (!f || f === format);
    });
    const scope = form.closest('.wizard') || form;
    scope.querySelectorAll('[data-for]').forEach((el) => {
      const ok = match(el.dataset.for);
      el.hidden = !ok;
    });
    // Sur invitation : la liste des invités devient obligatoire
    const access = form.querySelector('input[name="access"]:checked')?.value;
    form.querySelectorAll('[data-invitees-required]').forEach((el) => { el.hidden = access !== 'invited'; });
    // Publier au nom d'un Espace débloque certaines options et relève les limites
    const spaceInput = form.querySelector('input[name="space"]:checked');
    const isSpace = !!(spaceInput && spaceInput.value);
    form.querySelectorAll('[data-space-field]').forEach((el) => { if (!isSpace) el.hidden = true; });
    // Un champ masqué n'est pas envoyé ; un champ visible l'est
    form.querySelectorAll('input, select, textarea').forEach((i) => {
      const hiddenParent = i.closest('[data-for][hidden], [data-space-field][hidden]');
      i.disabled = !!hiddenParent;
    });
    let limits = null;
    try { limits = form.dataset.limits ? JSON.parse(form.dataset.limits)[isSpace ? 'space' : 'user'] : null; } catch (e) { limits = null; }
    if (limits) {
      form.querySelectorAll('[data-limit-key]').forEach((i) => {
        const max = limits[i.dataset.limitKey];
        if (max) { i.max = max; i.placeholder = 'Jusqu\'à ' + max; }
      });
      const sizes = Array.from(form.querySelectorAll('input[data-size]'));
      // Tailles au-delà de la limite : cachées (un compte va jusqu'à 32, un Espace jusqu'à 2048)
      sizes.forEach((r) => {
        const over = +r.value > limits.tournament_max_entries;
        r.disabled = over || !!r.closest('[data-for][hidden]');
        const lab = r.closest('label'); if (lab) lab.hidden = over;
        if (over) r.checked = false;
      });
      if (type === 'tournament' && !sizes.some((r) => r.checked)) {
        const allowed = sizes.filter((r) => !r.disabled);
        const pick = allowed.find((r) => +r.value === 8) || allowed[allowed.length - 1];
        if (pick) pick.checked = true;
      }
      form.querySelectorAll('[data-space-only]').forEach((r) => {
        if (!isSpace) {
          r.disabled = true;
          if (r.checked) { r.checked = false; const open = form.querySelector('input[name="' + r.name + '"][value="open"]'); if (open) open.checked = true; }
        }
      });
      const value = form.querySelector('[data-limit-value]');
      const hint = form.querySelector('[data-limit-hint]');
      if (value && hint) {
        const max = type === 'tournament' ? limits.tournament_max_entries : limits.contest_max_entries;
        hint.innerHTML = '';
        hint.append((isSpace ? 'Publié par un Espace' : 'Publié en ton nom') + ' : jusqu\'à ');
        const b = document.createElement('b'); b.setAttribute('data-limit-value', ''); b.textContent = max;
        hint.append(b, ' participants.' + (isSpace ? '' : ' Publie depuis un Espace pour aller plus loin.'));
      }
    }
    // Format imposé des performances : on n'affiche que le bon sélecteur
    const fmtInput = form.querySelector('input[name="entry_format"]:checked');
    const entryFormat = fmtInput && !fmtInput.disabled ? fmtInput.value : null;
    if (entryFormat) {
      form.querySelectorAll('[data-follow-format]').forEach((p) => {
        const input = p.querySelector('[data-picker-input]');
        const isText = entryFormat === 'text';
        p.hidden = isText;
        if (isText || input.disabled) { input.disabled = true; } else { input.accept = entryFormat + '/*'; input.dataset.accept = input.accept; }
        p.querySelectorAll('[data-pick]').forEach((b) => { b.hidden = b.dataset.pick !== entryFormat; });
        p.querySelectorAll('[data-record]').forEach((b) => { b.hidden = b.dataset.record !== entryFormat; });
        // Un seul format possible : on passe directement à « filmer / importer »
        p.querySelectorAll('[data-kind]').forEach((b) => { b.hidden = b.dataset.kind !== entryFormat; });
        if (window.tokosPickerKind && !isText) window.tokosPickerKind(p);
        const meta = p.querySelector('[data-picker-meta]');
        if (meta) meta.disabled = input.disabled;
        // Un fichier d'un autre format choisi avant : on l'enlève
        if (input.files && input.files.length && !String(input.files[0].type).startsWith(entryFormat + '/')) clearPick(p);
      });
      form.querySelectorAll('[data-text-label]').forEach((l) => { l.textContent = entryFormat === 'text' ? 'Ton texte' : 'Légende (facultatif)'; });
      form.querySelectorAll('[data-versus-text]').forEach((t) => { t.placeholder = entryFormat === 'text' ? 'Le texte de la proposition' : 'Légende (facultatif)'; });
    }
    return { type, format, match, isSpace, entryFormat };
  }

  // ── Assistant en plusieurs étapes ─────────────────────────────
  // Les étapes visibles dépendent du format (un duel se lance en deux étapes).
  document.querySelectorAll('form[data-wizard]').forEach((form) => {
    const scope = form.closest('.wizard') || form.closest('.box') || form;
    const allSteps = Array.from(form.querySelectorAll('[data-step]'));
    const allDots = Array.from(scope.querySelectorAll('[data-step-dot]'));
    const prev = form.querySelector('[data-prev]');
    const next = form.querySelector('[data-next]');
    const submit = form.querySelector('[data-submit]');
    const err = form.querySelector('[data-step-error]');
    let index = 0;
    const steps = () => allSteps.filter((s) => !s.hidden);
    const dots = () => allDots.filter((d) => !d.hidden);

    function showError(msg) {
      if (!err) return toast(msg, true);
      err.querySelector('span').textContent = msg;
      err.hidden = false;
    }

    function go(i, scroll = true) {
      syncConditional(form);
      const list = steps();
      index = Math.max(0, Math.min(list.length - 1, i));
      allSteps.forEach((s) => s.classList.toggle('active', s === list[index]));
      dots().forEach((d, k) => { d.classList.toggle('current', k === index); d.classList.toggle('done', k < index); });
      prev.hidden = index === 0;
      // Création : à l'écran du type, toucher un type suffit (pas de « Continuer »)
      next.hidden = index === list.length - 1 || (form.id === 'create-form' && list[index]?.hasAttribute('data-type-step'));
      submit.hidden = index !== list.length - 1;
      if (err) err.hidden = true;
      if (scroll) scope.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const filled = (name) => {
      const f = form.querySelector('[name="' + name + '"]');
      return !!(f && !f.disabled && String(f.value).trim());
    };
    const pickerHasFile = (root) => Array.from(root.querySelectorAll('[data-picker-input]')).some((i) => !i.disabled && i.files && i.files.length);

    function validate() {
      const step = steps()[index];
      if (!step) return null;
      const state = syncConditional(form);
      const fields = Array.from(step.querySelectorAll('input, select, textarea')).filter((f) => !f.disabled && !f.closest('[hidden]') && f.type !== 'hidden');

      for (const f of fields) {
        let needs = f.required || (f.dataset.requiredFor && state.match(f.dataset.requiredFor));
        if (f.dataset.requiredWhen) {
          const [k, v] = f.dataset.requiredWhen.split('=');
          needs = needs || form.querySelector('[name="' + k + '"]:checked')?.value === v;
        }
        if (needs && f.dataset.unlessFilled && filled(f.dataset.unlessFilled)) needs = false;
        if (f.type === 'radio') {
          if (f.required && !form.querySelector('input[name="' + f.name + '"]:checked')) return 'Choisis une option pour continuer.';
          continue;
        }
        if (needs && !String(f.value).trim()) {
          f.focus();
          const label = (f.closest('label, .field')?.querySelector('span')?.firstChild?.textContent || f.placeholder || f.name).trim();
          return f.dataset.requiredWhen ? 'Sur invitation : ajoute au moins une personne (@pseudo) ou un Espace (#espace).' : 'Ce champ est obligatoire : ' + label + '.';
        }
        if (f.minLength > 0 && f.value && f.value.trim().length < f.minLength) { f.focus(); return 'Un peu court : ' + f.minLength + ' caractères minimum.'; }
        if (f.type === 'number' && f.value && f.max && +f.value > +f.max) { f.focus(); return 'Maximum ' + f.max + ' participants' + (state.isSpace ? '.' : ' en ton nom. Publie depuis un Espace pour aller plus loin.'); }
        if (f.type === 'number' && f.value && f.min && +f.value < +f.min) { f.focus(); return 'Minimum ' + f.min + '.'; }
        if (f.type === 'password' && f.name === 'password_confirmation') {
          const pw = form.querySelector('input[name="password"]');
          if (pw && pw.value !== f.value) { f.focus(); return 'Les deux mots de passe ne correspondent pas.'; }
        }
      }
      // Médias obligatoires (couverture d'un concours…)
      for (const input of step.querySelectorAll('[data-picker-input]')) {
        if (input.disabled || input.closest('[hidden]:not(input)')) continue;
        const req = input.hasAttribute('data-required-file') || (input.dataset.requiredFor && state.match(input.dataset.requiredFor));
        if (req && !(input.files && input.files.length)) return input.dataset.requiredMessage || 'Ajoute le média demandé.';
      }
      // Performance du créateur, dans le format choisi
      const names = { video: 'ta vidéo', audio: 'ton audio', image: 'ta photo', text: 'ton texte' };
      for (const ta of step.querySelectorAll('textarea[data-content-for]')) {
        if (ta.disabled || !state.match(ta.dataset.contentFor)) continue;
        const block = ta.closest('[data-for]') || step;
        if (state.entryFormat === 'text') {
          if (!ta.value.trim()) { ta.focus(); return 'Écris ton texte : les challengers répondront aussi en texte.'; }
        } else if (state.entryFormat) {
          if (!pickerHasFile(block)) return 'Ajoute ' + names[state.entryFormat] + ' : les challengers répondront dans le même format.';
        } else if (!ta.value.trim() && !pickerHasFile(block)) return 'Ajoute ta performance : un texte, une photo, une vidéo ou un audio.';
      }
      if (form.id === 'create-form' && state.type === 'duel' && state.format === 'versus' && step.querySelector('.versus-form')) {
        for (const k of ['a', 'b']) {
          const side = step.querySelector('[name="side_' + k + '_label"]')?.closest('.versus-form-side');
          if (side && !filled('side_' + k + '_label')) return 'Complète les deux propositions.';
          if (side && state.entryFormat && state.entryFormat !== 'text' && !pickerHasFile(side)) return 'Ajoute ' + names[state.entryFormat] + ' pour chaque proposition.';
        }
      }
      // Concours / tournoi : les récompenses et les dates doivent tenir dans le nombre de participants choisi
      if (form.id === 'create-form' && ['contest', 'tournament'].includes(state.type)) {
        let limits = null; try { limits = JSON.parse(form.dataset.limits)[state.isSpace ? 'space' : 'user']; } catch (e) { /* rien */ }
        const size = form.querySelector('input[data-size]:checked:not(:disabled)');
        const typed = form.querySelector('input[name="max_entries"][type="number"]:not(:disabled)');
        const max = state.type === 'tournament' ? (size ? +size.value : null)
          : ((typed && typed.value) ? +typed.value : (limits ? limits.contest_max_entries : null));
        const rows = Array.from(step.querySelectorAll('[data-pt-from]')).filter((f) => !f.disabled);
        let last = 0;
        for (const f of rows) {
          const from = +f.value || 0; const toF = f.closest('.pt-place')?.querySelector('[data-pt-to]');
          const to = Math.max(from, +(toF && toF.value) || from);
          if (!from) continue;
          if (from <= last) { f.focus(); return 'La place ' + from + ' a déjà une récompense : les places ne doivent pas se chevaucher.'; }
          if (max && to > max) { f.focus(); return 'Récompense pour la place ' + to + ', mais il n\'y aura que ' + max + ' participants au plus.'; }
          last = to;
        }
        const reg = form.querySelector('[name="registration_ends_at"]:not(:disabled)');
        const end = form.querySelector('[name="ends_at"]:not(:disabled)');
        if (reg && end && reg.value && end.value && new Date(end.value) <= new Date(reg.value)) return 'La fin des votes doit venir après la fin des inscriptions.';
      }
      return null;
    }

    next.addEventListener('click', async () => {
      const problem = validate();
      if (problem) return showError(problem);
      // Inscription : envoi du SMS en quittant l'étape du numéro
      if (next.dataset.sendCodeOn !== undefined && String(index) === next.dataset.sendCodeOn && !form.dataset.codeSent) {
        next.disabled = true;
        try {
          await sendCode(form);
          form.dataset.codeSent = '1'; // un seul SMS par numéro : « Renvoyer » reste disponible
        } catch (e) {
          next.disabled = false;
          return showError(e.message);
        }
        next.disabled = false;
      }
      go(index + 1);
    });
    prev.addEventListener('click', () => go(index - 1));
    form.addEventListener('change', (e) => {
      if (e.target.name === 'type' || e.target.name === 'format') go(index, false);
      else syncConditional(form);
      if (err) err.hidden = true;
    });
    // Toucher un choix suffit : le type (même déjà coché), ou le seul choix d'une étape
    form.addEventListener('click', (e) => {
      const r = e.target.closest('input[type="radio"]');
      if (!r || form.id !== 'create-form') return;
      const step = steps()[index];
      if (!step || !step.contains(r)) return;
      const visible = Array.from(step.querySelectorAll('input, select, textarea')).filter((f) => !f.disabled && f.type !== 'hidden' && !f.closest('[hidden]'));
      const groups = new Set(visible.filter((f) => f.type === 'radio').map((f) => f.name));
      const onlyChoice = groups.size === 1 && visible.every((f) => f.type === 'radio');
      if (r.name === 'type' || onlyChoice) {
        setTimeout(() => { syncConditional(form); const problem = validate(); if (!problem) go(index + 1); }, 150);
      }
    });
    form.addEventListener('input', () => { if (err) err.hidden = true; });
    form.addEventListener('submit', (e) => {
      const problem = validate();
      if (problem) { e.preventDefault(); showError(problem); }
      else submit.disabled = true;
    });
    form.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !e.target.closest('[data-suggest]') && index < steps().length - 1) { e.preventDefault(); next.click(); }
    });

    // Arrivée avec un type déjà choisi (/creer?type=…) : on saute directement à la suite
    // (le serveur masque déjà l'écran du type quand il est connu : on démarre au premier écran visible)
    const typeStep = form.querySelector('[data-type-step]');
    const preset = form.id === 'create-form' && typeStep && !typeStep.hidden && new URLSearchParams(location.search).get('type') && form.querySelector('input[name="type"]:checked');
    go(preset ? 1 : 0, false);
  });

  // ── Inscription : code SMS ────────────────────────────────────
  async function sendCode(form) {
    // Tout le formulaire part avec la demande de code : le serveur vérifie le nom, l'âge et le
    // mot de passe AVANT d'envoyer le SMS (pas de crédit dépensé pour une inscription invalide).
    form.phone_number.value = form.phone_number.value.replace(/\D/g, '').replace(/^0+/, '');
    const body = new FormData(form);
    // Numéro modifié : le prochain « Continuer » renverra un code
    form.querySelectorAll('[name="phone_number"], [name="phone_code"]').forEach((f) => f.addEventListener('input', () => { delete form.dataset.codeSent; }));
    await send('/api/auth/send-verification-code', body);
    toast('Code envoyé par SMS.');
  }
  const resend = document.getElementById('send-code');
  if (resend) {
    resend.addEventListener('click', async () => {
      resend.disabled = true;
      try { await sendCode(resend.closest('form')); } catch (e) { toast(e.message, true); }
      let left = 60;
      const t = setInterval(() => {
        resend.textContent = 'Renvoyer (' + left-- + ' s)';
        if (left < 0) { clearInterval(t); resend.textContent = 'Renvoyer le code'; resend.disabled = false; }
      }, 1000);
    });
  }

  // ── Sélecteur de média : aperçu, durée maximale, ondes de l'audio ──
  const fmtTime = (sec) => Math.floor(sec / 60) + ':' + String(Math.floor(sec % 60)).padStart(2, '0');
  const fmtSize = (b) => b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(b / 1024)) + ' ko';
  const fmtLimit = (sec) => sec >= 60 ? Math.floor(sec / 60) + ' min' + (sec % 60 ? ' ' + (sec % 60) + ' s' : '') : sec + ' s';

  function mediaDuration(file) {
    return new Promise((resolve) => {
      // Format illisible par le navigateur : le serveur vérifiera la durée
      setTimeout(() => resolve(null), 4000);
      const el = document.createElement(file.type.startsWith('video/') ? 'video' : 'audio');
      el.preload = 'metadata';
      el.onloadedmetadata = () => {
        if (el.videoWidth) { file._w = el.videoWidth; file._h = el.videoHeight; }
        resolve(isFinite(el.duration) ? el.duration : null); URL.revokeObjectURL(el.src);
      };
      el.onerror = () => resolve(null);
      el.src = URL.createObjectURL(file);
    });
  }

  // Ondes réelles calculées dans le navigateur (envoyées au serveur avec le fichier)
  async function audioPeaks(file, count = 96) {
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx || file.size > 30 * 1048576) return null;
      const ctx = new Ctx();
      const buf = await ctx.decodeAudioData(await file.arrayBuffer());
      const data = buf.getChannelData(0);
      const size = Math.floor(data.length / count);
      const peaks = [];
      for (let i = 0; i < count; i++) {
        let max = 0;
        for (let j = i * size; j < (i + 1) * size; j += 16) max = Math.max(max, Math.abs(data[j] || 0));
        peaks.push(+max.toFixed(3));
      }
      ctx.close && ctx.close();
      return peaks;
    } catch (e) { return null; }
  }

  // Ondes de remplacement (toujours les mêmes pour un même nom de fichier)
  function fakePeaks(seed, count = 48) {
    let h = 0; for (const c of seed) h = (h * 31 + c.charCodeAt(0)) | 0;
    const out = []; let v = 0.5;
    for (let i = 0; i < count; i++) {
      h = (h * 1103515245 + 12345) | 0;
      v = Math.max(0.15, Math.min(1, v + ((h >>> 16) % 60 - 30) / 100));
      out.push(v * (0.65 + 0.35 * Math.sin(i / count * Math.PI)));
    }
    return out;
  }

  function waveHtml(peaks, label) {
    const max = Math.max(...peaks, 0.01);
    const bars = [];
    const step = peaks.length / 48;
    for (let i = 0; i < 48; i++) {
      const slice = peaks.slice(Math.floor(i * step), Math.max(Math.floor(i * step) + 1, Math.ceil((i + 1) * step)));
      bars.push('<i style="height:' + Math.max(12, Math.round(Math.max(...slice) / max * 100)) + '%"></i>');
    }
    return '<div class="wave" data-wave><button type="button" class="wave-play" aria-label="Écouter">' + ICON_PLAY + ICON_PAUSE + '</button>'
      + '<div class="wave-bars" tabindex="0">' + bars.join('') + '</div><span class="wave-time" data-wave-time>' + label + '</span><audio preload="metadata"></audio></div>';
  }
  const ICON_PLAY = '<svg class="ico play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg>';
  const ICON_PAUSE = '<svg class="ico pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>';

  // Redimensionne une photo à 1600 px au plus (JPEG 82 %), seulement si c'est plus léger
  async function shrinkImage(file, max = 1600, quality = 0.82) {
    try {
      if (file.size < 350 * 1024) return file;
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const ratio = Math.min(1, max / Math.max(bmp.width, bmp.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * ratio);
      canvas.height = Math.round(bmp.height * ratio);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch (e) {
      return file;
    }
  }

  async function handlePick(picker, file, knownDuration) {
    const input = picker.querySelector('[data-picker-input]');
    const meta = picker.querySelector('[data-picker-meta]');
    const fail = (msg) => { toast(msg, true); clearPick(picker); };
    if (!file) return clearPick(picker);
    if (file.size > 50 * 1048576) return fail('Fichier trop lourd : 50 Mo maximum.');
    const kind = file.type.startsWith('video/') ? 'video' : file.type.startsWith('audio/') ? 'audio' : file.type.startsWith('image/') ? 'image' : null;
    const allowed = (input.dataset.accept || input.accept).split(',').map((a) => a.trim().replace('/*', ''));
    if (!kind || !allowed.includes(kind)) {
      const names = { image: 'une photo', video: 'une vidéo', audio: 'un audio' };
      return fail(allowed.length === 1 ? 'Ici, il faut ' + names[allowed[0]] + ', le même format que le défi.' : 'Ce type de fichier n\'est pas accepté ici.');
    }

    // Photo : une version allégée sert à l'aperçu seulement. On ne remplace PAS le fichier du
    // champ (input.files) : sur certains téléphones et en PWA, ce remplacement fait échouer
    // l'envoi (« Impossible d'accéder à votre fichier… ERR_UPLOAD_FILE_CHANGED »).
    // Le fichier d'origine part au serveur, qui l'allège lui-même.
    if (kind === 'image' && file.type !== 'image/gif') {
      const small = await shrinkImage(file);
      if (small && small !== file) { file = small; lightFiles.set(input, small); } // aperçu + envoi allégé
    }

    const maxMb = +picker.dataset.maxMb || 0;
    if (maxMb && file.size > maxMb * 1048576) return fail('Fichier trop lourd (' + fmtSize(file.size) + ') : ' + maxMb + ' Mo maximum.');

    let duration = null;
    let peaks = null;
    if (kind !== 'image') {
      const probed = await mediaDuration(file); // lit aussi la largeur et la hauteur d'une vidéo
      duration = knownDuration || probed;
      if (duration && !isFinite(duration)) duration = null;
      const max = +(kind === 'video' ? picker.dataset.maxVideo : picker.dataset.maxAudio);
      if (duration && max && duration > max + 1) return fail((kind === 'video' ? 'Vidéo' : 'Audio') + ' trop longue (' + fmtTime(duration) + ') : ' + fmtLimit(max) + ' maximum.');
      if (kind === 'audio') peaks = await audioPeaks(file);
    }
    if (meta) meta.value = meta.name.endsWith('_duration') ? (duration ? duration.toFixed(1) : '') : JSON.stringify({ duration: duration ? +duration.toFixed(1) : undefined, peaks: peaks || undefined, width: file._w || undefined, height: file._h || undefined });

    const url = URL.createObjectURL(file);
    const box = picker.querySelector('[data-picker-media]');
    box.innerHTML = '';
    if (kind === 'image') {
      const img = document.createElement('img'); img.src = url; img.alt = ''; box.appendChild(img);
    } else if (kind === 'video') {
      const v = document.createElement('video'); v.src = url; v.controls = true; v.playsInline = true; box.appendChild(v);
    } else {
      box.innerHTML = waveHtml(peaks || fakePeaks(file.name), duration ? fmtTime(duration) : '0:00');
      box.querySelector('audio').src = url;
    }
    picker.querySelector('[data-picker-name]').textContent = file.name;
    picker.querySelector('[data-picker-details]').textContent = ' · ' + fmtSize(file.size) + (duration ? ' · ' + fmtTime(duration) : '');
    picker.querySelector('.picker-empty').hidden = true;
    picker.querySelector('.picker-preview').hidden = false;
    picker.classList.add('has-file');
    // Le format du fichier choisi (même glissé-déposé) : le versus aligne l'autre choix dessus
    if (kind && picker.dataset.pickedKind !== kind) {
      picker.dataset.pickedKind = kind;
      picker.dispatchEvent(new CustomEvent('picker:kind', { bubbles: true, detail: kind }));
    }
  }

  // ── Enregistrer directement (caméra ou micro) ──────────────────
  async function openRecorder(picker, kind) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      return toast(location.protocol === 'https:' || location.hostname === 'localhost' ? 'Ton navigateur ne permet pas d\'enregistrer ici. Importe un fichier.' : 'L\'enregistrement demande une connexion sécurisée (https).', true);
    }
    const max = +(kind === 'video' ? picker.dataset.maxVideo : picker.dataset.maxAudio) || 180;
    let facing = 'user';
    let stream = null; let rec = null; let chunks = []; let started = 0; let timer = null;
    const dlg = document.createElement('dialog');
    dlg.className = 'sheet recorder';
    dlg.innerHTML = '<div class="sheet-head"><h2>' + (kind === 'video' ? 'Filmer' : 'Enregistrer') + '</h2><button type="button" class="icon-btn" data-rec-close aria-label="Fermer">&times;</button></div>'
      + '<div class="rec-stage ' + kind + '">' + (kind === 'video' ? '<video muted playsinline autoplay></video><button type="button" class="vbtn rec-flip" data-rec-flip aria-label="Changer de caméra">&#8635;</button>' : '<span class="rec-mic"><span class="rec-pulse"></span></span>') + '</div>'
      + '<div class="rec-bar"><span class="rec-time" data-rec-time>0:00 / ' + fmtLimit(max) + '</span><button type="button" class="rec-btn" data-rec-toggle aria-label="Démarrer"><i></i></button><span class="rec-hint">Touche pour démarrer</span></div>';
    document.body.appendChild(dlg);
    const video = dlg.querySelector('video');
    const stop = () => { if (stream) stream.getTracks().forEach((t) => t.stop()); clearInterval(timer); };
    const close = () => { stop(); dlg.close(); dlg.remove(); };
    async function startStream() {
      if (stream) stream.getTracks().forEach((t) => t.stop());
      try {
        stream = await navigator.mediaDevices.getUserMedia(kind === 'video'
          ? { video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } }, audio: { echoCancellation: true, noiseSuppression: true } }
          : { audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } });
      } catch (e) {
        close();
        return toast(kind === 'video' ? 'Autorise l\'accès à la caméra et au micro pour filmer.' : 'Autorise l\'accès au micro pour enregistrer.', true);
      }
      if (video) { video.srcObject = stream; video.classList.toggle('mirror', facing === 'user'); }
    }
    dlg.addEventListener('click', async (e) => {
      if (e.target.closest('[data-rec-close]') || e.target === dlg) { if (rec && rec.state === 'recording') { rec.onstop = null; rec.stop(); } close(); return; }
      if (e.target.closest('[data-rec-flip]') && !(rec && rec.state === 'recording')) { facing = facing === 'user' ? 'environment' : 'user'; await startStream(); return; }
      if (!e.target.closest('[data-rec-toggle]') || !stream) return;
      if (rec && rec.state === 'recording') { rec.stop(); return; }
      const types = kind === 'video' ? ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'] : ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
      const mime = types.find((t) => MediaRecorder.isTypeSupported(t)) || '';
      chunks = [];
      // Débit limité : une minute de vidéo pèse environ 10 Mo, une minute d'audio moins de 1 Mo
      const opts = kind === 'video' ? { videoBitsPerSecond: 1200000, audioBitsPerSecond: 96000 } : { audioBitsPerSecond: 64000 };
      if (mime) opts.mimeType = mime;
      rec = new MediaRecorder(stream, opts);
      rec.ondataavailable = (ev) => { if (ev.data.size) chunks.push(ev.data); };
      rec.onstop = () => {
        const seconds = (Date.now() - started) / 1000;
        const type = (rec.mimeType || mime || (kind + '/webm')).split(';')[0];
        const ext = type.includes('mp4') ? (kind === 'video' ? 'mp4' : 'm4a') : type.includes('ogg') ? 'ogg' : 'webm';
        const file = new File(chunks, (kind === 'video' ? 'video' : 'audio') + '-' + Date.now() + '.' + ext, { type });
        const input = picker.querySelector('[data-picker-input]');
        try { const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch (err) { toast('Impossible de joindre l\'enregistrement. Importe un fichier.', true); }
        close();
        handlePick(picker, file, seconds);
      };
      rec.start(250);
      started = Date.now();
      dlg.classList.add('recording');
      dlg.querySelector('.rec-hint').textContent = 'Touche pour arrêter';
      const timeEl = dlg.querySelector('[data-rec-time]');
      timer = setInterval(() => {
        const s = (Date.now() - started) / 1000;
        timeEl.textContent = fmtTime(s) + ' / ' + fmtLimit(max);
        if (s >= max) rec.stop(); // limite atteinte : on s'arrête tout seul
      }, 200);
    });
    dlg.showModal();
    await startStream();
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-record]');
    if (b) { e.preventDefault(); openRecorder(b.closest('[data-picker]'), b.dataset.record); }
  });

  // Sélecteur de média : d'abord le format (Vidéo, Audio, Photo, Texte), puis comment (filmer / importer…).
  // Un seul format possible : on va droit à « comment ». Texte : une zone d'écriture remplace le média.
  function pickerKind(p, kind, userBack) {
    if (!p) return;
    const btns = p.querySelector('[data-kind-btns]');
    const how = p.querySelector('[data-kind-how]');
    if (!btns || !how) return;
    const avail = Array.from(btns.querySelectorAll('[data-kind]')).filter((b) => !b.hidden);
    const only = avail.length === 1 ? avail[0].dataset.kind : null;
    kind = kind || only || null;
    const isText = kind === 'text';
    const txt = p.querySelector('[data-picker-text]');
    btns.hidden = !!kind;
    how.hidden = !kind || isText;
    how.querySelectorAll('[data-how]').forEach((g) => { g.hidden = g.dataset.how !== kind; });
    how.querySelectorAll('[data-kind-back]').forEach((b) => { b.hidden = !!only; });
    if (txt) {
      txt.hidden = !isText;
      const ta = txt.querySelector('textarea'); ta.disabled = !isText;
      txt.querySelectorAll('[data-kind-back]').forEach((b) => { b.hidden = !!only; });
      if (isText && !userBack) setTimeout(() => ta.focus(), 50);
    }
    const empty = p.querySelector('.picker-empty');
    if (!p.classList.contains('has-file')) empty.hidden = isText;
    const before = p.dataset.pickedKind || '';
    p.dataset.pickedKind = kind || '';
    if (before !== p.dataset.pickedKind) p.dispatchEvent(new CustomEvent('picker:kind', { bubbles: true, detail: kind }));
  }
  window.tokosPickerKind = (p, k) => pickerKind(p, k);
  document.querySelectorAll('[data-picker]').forEach((p) => pickerKind(p, null));

  // Versus : le format choisi pour un choix s'applique à l'autre (texte, photo, vidéo ou audio)
  document.addEventListener('picker:kind', (e) => {
    const p = e.target;
    const vf = p.closest && p.closest('.versus-form');
    if (!vf || vf.dataset.syncing) return;
    const other = Array.from(vf.querySelectorAll('[data-picker]')).find((x) => x !== p);
    if (!other) return;
    vf.dataset.syncing = '1';
    try {
      const kind = e.detail;
      const otherKind = other.classList.contains('has-file') ? other.dataset.pickedKind : null;
      if (kind) {
        other.querySelectorAll('[data-kind]').forEach((b) => { b.hidden = b.dataset.kind !== kind; });
        if (!other.classList.contains('has-file')) pickerKind(other, kind);
      } else if (otherKind) {
        // L'autre a déjà son média : ce côté reste dans le même format
        p.querySelectorAll('[data-kind]').forEach((b) => { b.hidden = b.dataset.kind !== otherKind; });
        pickerKind(p, otherKind);
      } else {
        // Plus de format choisi : les deux côtés proposent à nouveau tout
        [p, other].forEach((x) => { x.querySelectorAll('[data-kind]').forEach((b) => { b.hidden = false; }); });
        pickerKind(other, null, true);
      }
    } finally { delete vf.dataset.syncing; }
  });

  function clearPick(picker) {
    const input = picker.querySelector('[data-picker-input]');
    input.value = '';
    const meta = picker.querySelector('[data-picker-meta]');
    if (meta) meta.value = '';
    picker.querySelector('[data-picker-media]').innerHTML = '';
    picker.querySelector('.picker-empty').hidden = false;
    picker.querySelector('.picker-preview').hidden = true;
    picker.classList.remove('has-file');
    pickerKind(picker, null, true); // de nouveau le choix du format
  }

  document.addEventListener('click', (e) => {
    const pick = e.target.closest('[data-pick]');
    if (pick) {
      const picker = pick.closest('[data-picker]');
      const input = picker.querySelector('[data-picker-input]');
      input.dataset.accept = input.dataset.accept || input.accept;
      input.accept = pick.dataset.pick === 'any' ? input.dataset.accept : pick.dataset.pick + '/*';
      // « Prendre une photo » : l'appareil photo du téléphone ; « Importer » : la galerie
      if (pick.dataset.capture) input.setAttribute('capture', pick.dataset.capture); else input.removeAttribute('capture');
      input.click();
      setTimeout(() => { input.accept = input.dataset.accept; input.removeAttribute('capture'); }, 1000);
      return;
    }
    const kindBtn = e.target.closest('button[data-kind]');
    if (kindBtn) { pickerKind(kindBtn.closest('[data-picker]'), kindBtn.dataset.kind); return; }
    const back = e.target.closest('[data-kind-back]');
    if (back) { pickerKind(back.closest('[data-picker]'), null, true); return; }
    const change = e.target.closest('[data-picker-change]');
    if (change) { change.closest('[data-picker]').querySelector('[data-picker-input]').click(); return; }
    const clear = e.target.closest('[data-picker-clear]');
    if (clear) clearPick(clear.closest('[data-picker]'));
  });
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-picker-input]')) handlePick(e.target.closest('[data-picker]'), e.target.files[0]);
  });
  document.addEventListener('dragover', (e) => {
    const p = e.target.closest('[data-picker]');
    if (p) { e.preventDefault(); p.classList.add('is-over'); }
  });
  ['dragleave', 'drop'].forEach((ev) => document.addEventListener(ev, (e) => {
    const p = e.target.closest('[data-picker]');
    if (!p) return;
    p.classList.remove('is-over');
    if (ev === 'drop' && e.dataTransfer?.files?.length) {
      e.preventDefault();
      const input = p.querySelector('[data-picker-input]');
      try { input.files = e.dataTransfer.files; } catch (err) { /* navigateur ancien */ }
      handlePick(p, e.dataTransfer.files[0]);
    }
  }));

  // Formulaires simples (fenêtres) : médias obligatoires avant l'envoi
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.hasAttribute('data-wizard')) return;
    const missing = Array.from(form.querySelectorAll('[data-picker-input][data-required-file]')).find((i) => !i.disabled && !(i.files && i.files.length));
    if (missing) {
      e.preventDefault();
      e.stopImmediatePropagation();
      toast(missing.closest('[data-picker]').querySelector('.picker-label')?.firstChild?.textContent.trim() + ' : obligatoire.', true);
    }
  }, true);

  // ── Lecteur audio avec ondes ──────────────────────────────────
  document.addEventListener('click', (e) => {
    const wave = e.target.closest('[data-wave]');
    if (!wave) return;
    const audio = wave.querySelector('audio');
    const bars = wave.querySelector('.wave-bars');
    if (e.target.closest('.wave-bars') && isFinite(audio.duration) && audio.duration) {
      const r = bars.getBoundingClientRect();
      audio.currentTime = Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * audio.duration;
      if (audio.paused) audio.play();
      return;
    }
    if (e.target.closest('.wave-play')) audio.paused ? audio.play() : audio.pause();
  });
  ['play', 'pause', 'ended', 'timeupdate', 'loadedmetadata'].forEach((ev) => document.addEventListener(ev, (e) => {
    const audio = e.target;
    if (!(audio instanceof HTMLAudioElement)) return;
    const wave = audio.closest('[data-wave]');
    if (!wave) return;
    wave.classList.toggle('playing', !audio.paused && !audio.ended);
    const pct = isFinite(audio.duration) && audio.duration ? audio.currentTime / audio.duration : 0;
    const bars = wave.querySelectorAll('.wave-bars i');
    bars.forEach((b, i) => b.classList.toggle('on', i / bars.length < pct));
    const t = wave.querySelector('[data-wave-time]');
    if (t && audio.duration) t.textContent = fmtTime(ev === 'ended' || audio.currentTime === 0 ? audio.duration : audio.currentTime);
  }, true));

  // ── Suggestions « @… » ────────────────────────────────────────
  document.querySelectorAll('[data-suggest]').forEach((box) => {
    const input = box.querySelector('[data-suggest-input]');
    const list = box.querySelector('[data-suggest-list]');
    const type = box.querySelector('[data-suggest-type]');
    const url = input.closest('form')?.dataset.suggestUrl || '/recherche/participants';
    let timer = null; let active = -1; let items = [];
    const phoneToggle = box.parentElement.querySelector('[data-toggle-phone]');
    const close = () => { list.hidden = true; active = -1; };
    const render = (q) => {
      list.innerHTML = '';
      items.forEach((p, i) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.className = i === active ? 'active' : '';
        const img = document.createElement('img'); img.src = p.avatar_url; img.alt = ''; img.className = 'avatar sm' + (p.type === 'space' ? ' space' : '');
        const txt = document.createElement('span');
        const b = document.createElement('b'); b.textContent = p.name;
        const sm = document.createElement('small'); sm.textContent = (p.type === 'space' ? '#' : '@') + p.handle + (p.type === 'space' ? ' · Espace' : '');
        txt.append(b, sm); li.append(img, txt);
        li.addEventListener('mousedown', (ev) => { ev.preventDefault(); choose(p); });
        list.appendChild(li);
      });
      if (!items.length && q.length >= 2) {
        const li = document.createElement('li'); li.className = 'empty';
        li.textContent = box.dataset.suggestEmpty || 'Personne de ce nom sur Sosa. Invite-le par son numéro.';
        li.addEventListener('mousedown', (ev) => { ev.preventDefault(); openPhone(); });
        list.appendChild(li);
      }
      list.hidden = !list.children.length;
    };
    const choose = (p) => { input.value = p.handle; if (type) type.value = p.type; close(); input.dispatchEvent(new Event('change', { bubbles: true })); };
    const openPhone = () => { const f = box.parentElement.querySelector('[data-phone-fallback]'); if (f) { f.hidden = false; f.querySelector('input')?.focus(); } close(); };
    phoneToggle?.addEventListener('click', openPhone);
    input.addEventListener('input', () => {
      const q = input.value.replace(/^[@#]/, '').trim();
      if (type) type.value = input.value.startsWith('#') ? 'space' : 'user';
      clearTimeout(timer);
      if (!q) { items = []; close(); return; }
      timer = setTimeout(async () => {
        try {
          const res = await fetch(url + (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
          items = (await res.json()).data || [];
          active = items.length ? 0 : -1;
          render(q);
        } catch (e) { /* hors ligne */ }
      }, 180);
    });
    input.addEventListener('keydown', (e) => {
      if (list.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; render(input.value); }
      if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active]); }
      if (e.key === 'Escape') close();
    });
    input.addEventListener('blur', () => setTimeout(close, 120));
  });

  // ── Dates : raccourcis ────────────────────────────────────────
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-date-preset]');
    if (!b) return;
    const field = b.closest('[data-date-field]');
    const input = field.querySelector('input[type="datetime-local"]');
    const d = new Date(Date.now() + (+b.dataset.datePreset) * 3600 * 1000);
    d.setMinutes(0, 0, 0);
    const pad = (n) => String(n).padStart(2, '0');
    input.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':00';
    field.querySelectorAll('[data-date-preset]').forEach((x) => x.classList.toggle('on', x === b));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  document.addEventListener('input', (e) => {
    const field = e.target.closest('[data-date-field]');
    if (field && e.isTrusted) field.querySelectorAll('[data-date-preset]').forEach((x) => x.classList.remove('on'));
  });

  // ── Commentaires : répondre ───────────────────────────────────
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-reply]');
    if (!b) return;
    const form = document.getElementById(b.dataset.reply);
    if (!form) return;
    form.hidden = !form.hidden;
    if (!form.hidden) form.querySelector('input[name="content"]')?.focus();
  });

  // ── Colonne de droite : défile puis s'arrête en bas ───────────
  const rail = document.querySelector('.rail');
  function placeRail() {
    if (!rail) return;
    const header = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header'), 10) || 60;
    const h = rail.offsetHeight;
    rail.style.top = (h + header > innerHeight ? innerHeight - h : header) + 'px';
  }
  if (rail) {
    placeRail();
    addEventListener('resize', placeRail);
    if (window.ResizeObserver) new ResizeObserver(placeRail).observe(rail);
  }

  // ── Fil : défilement infini ───────────────────────────────────
  const more = document.querySelector('[data-feed-more]');
  if (more && 'IntersectionObserver' in window) {
    const feed = document.querySelector('[data-feed]');
    let loading = false;
    const io = new IntersectionObserver(async (entries) => {
      if (!entries[0].isIntersecting || loading || !more.dataset.next) return;
      loading = true;
      more.classList.add('loading');
      try {
        more._page = (more._page || 0) + 1;
        const res = await fetch('/fil?avant=' + encodeURIComponent(more.dataset.next) + '&page=' + more._page, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        const data = await res.json();
        const tpl = document.createElement('template');
        tpl.innerHTML = data.html || '';
        feed.appendChild(tpl.content);
        countdowns(); applyLayout(); watchVideos(feed);
        more.dataset.next = data.next || '';
        if (!data.next) { more.innerHTML = '<span class="muted small">Tu as tout vu. Reviens plus tard !</span>'; io.disconnect(); }
      } catch (e) { /* on réessaiera au prochain passage */ }
      loading = false;
      more.classList.remove('loading');
    }, { rootMargin: '600px' });
    io.observe(more);
  }

  // ── Vidéos : lecture seulement quand elles sont à l'écran ─────
  // Rien n'est téléchargé avant que la vidéo approche de l'écran (économie de données).
  // Muette en lecture automatique ; elle s'arrête dès qu'on la fait défiler.
  let soundOn = false; // la personne a activé le son : il reste activé pour les vidéos suivantes
  let videoIO = null;
  let nearIO = null;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  function watchVideos(root) {
    if (!('IntersectionObserver' in window)) return;
    if (!videoIO) {
      nearIO = new IntersectionObserver((entries) => entries.forEach((en) => {
        if (en.isIntersecting && en.target.preload === 'none' && !dataSaver()) en.target.preload = 'metadata';
      }), { rootMargin: '300px 0px' });
      videoIO = new IntersectionObserver((entries) => entries.forEach((en) => {
        const v = en.target;
        if (en.isIntersecting && en.intersectionRatio >= 0.6) {
          if (reduced || v.dataset.userPaused || dataSaver()) return;
          // Une seule vidéo à la fois
          document.querySelectorAll('video[data-autoplay]').forEach((o) => { if (o !== v && !o.paused) o.pause(); });
          v.muted = !soundOn;
          const p = v.play(); if (p && p.catch) p.catch(() => {});
        } else if (!v.paused) {
          v._auto = true; v.pause();
        }
      }), { threshold: [0, 0.6] });
    }
    (root || document).querySelectorAll('video[data-autoplay]').forEach((v) => {
      if (v._watched) return;
      v._watched = true;
      nearIO.observe(v); videoIO.observe(v);
      // Si la personne met en pause elle-même, on ne relance pas
      v.addEventListener('pause', () => { if (!v._auto && !v.ended) v.dataset.userPaused = '1'; v._auto = false; });
      v.addEventListener('play', () => { delete v.dataset.userPaused; });
      // L'aperçu montrait l'image vers 3 s (#t=3) : la vraie lecture repart du début
      if (/#t=/.test(v.getAttribute('src') || '')) v.addEventListener('play', () => { if (!v._started) { v._started = true; if (v.currentTime > 0.2) v.currentTime = 0; } }, { once: true });
      const box = v.closest('[data-vplayer]');
      if (box) {
        const sync = () => {
          box.classList.toggle('is-paused', v.paused);
          box.classList.toggle('is-muted', v.muted);
          const m = box.querySelector('[data-vmute]');
          if (m) m.setAttribute('aria-label', v.muted ? 'Activer le son' : 'Couper le son');
        };
        ['play', 'pause', 'volumechange', 'loadedmetadata'].forEach((ev) => v.addEventListener(ev, sync));
        // Format inconnu à l'avance : on le prend dès que le navigateur connaît la taille de la vidéo
        // Format inconnu à l'avance : on le prend dès qu'on connaît la taille (image d'aperçu ou en-tête de la vidéo).
        // Portrait très haut : 4:5 au plus, la vidéo remplit le cadre. Une fois fixé, le cadre ne bouge plus.
        const setAr = (w, h) => {
          if (!w || !h || !box.classList.contains('natural') || box.hasAttribute('data-ar-known')) return;
          const tall = h / w > 1.25;
          box.style.setProperty('--ar', w + ' / ' + h); // le vrai format, même en hauteur (format téléphone)
          box.style.setProperty('--arn', String(Math.round(w / h * 10000) / 10000));
          box.classList.toggle('tall', tall);
          box.setAttribute('data-ar-known', '');
        };
        const fit = () => setAr(v.videoWidth, v.videoHeight);
        v.addEventListener('loadedmetadata', fit);
        if (v.readyState >= 1) fit();
        if (v.poster && !box.hasAttribute('data-ar-known')) { const im = new Image(); im.onload = () => setAr(im.naturalWidth, im.naturalHeight); im.src = v.poster; }
        v.addEventListener('timeupdate', () => {
          const bar = box.querySelector('.vprog i');
          if (bar && isFinite(v.duration) && v.duration && !box.querySelector('.vprog.is-seeking')) {
            const pct = v.currentTime / v.duration * 100;
            bar.style.width = pct + '%';
            bar.parentNode.setAttribute('aria-valuenow', Math.round(pct));
          }
        });
        sync();
      }
    });
  }

  // Aller à un moment précis : toucher ou glisser la barre du bas (flèches au clavier : 5 s)
  const vfmt = (t) => { t = Math.max(0, Math.floor(t || 0)); const h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), sec = String(t % 60).padStart(2, '0'); return h ? h + ':' + String(m).padStart(2, '0') + ':' + sec : m + ':' + sec; };
  function seekReady(v) { return isFinite(v.duration) && v.duration > 0; }
  function seekAt(bar, v, clientX, commit) {
    if (!seekReady(v)) return;
    const r = bar.getBoundingClientRect();
    const x = Math.max(0, Math.min(1, (clientX - r.left) / r.width));
    bar.querySelector('i').style.width = (x * 100) + '%';
    const tip = bar.querySelector('.vtime');
    if (tip) { tip.hidden = false; tip.textContent = vfmt(x * v.duration) + ' / ' + vfmt(v.duration); tip.style.left = Math.max(34, Math.min(r.width - 34, x * r.width)) + 'px'; }
    if (commit) v.currentTime = x * v.duration;
    bar.setAttribute('aria-valuenow', Math.round(x * 100));
  }
  document.addEventListener('pointerdown', (e) => {
    const bar = e.target.closest('[data-vseek]');
    if (!bar) return;
    const v = bar.closest('[data-vplayer]')?.querySelector('video');
    if (!v) return;
    e.preventDefault();
    if (v.preload === 'none') v.preload = 'auto';
    const go = () => {
      if (!seekReady(v)) return;
      bar.classList.add('is-seeking');
      try { bar.setPointerCapture(e.pointerId); } catch (err) {}
      seekAt(bar, v, e.clientX, false);
      const move = (ev) => seekAt(bar, v, ev.clientX, false);
      const up = (ev) => {
        seekAt(bar, v, ev.clientX, true);
        bar.classList.remove('is-seeking');
        const tip = bar.querySelector('.vtime'); if (tip) tip.hidden = true;
        bar.removeEventListener('pointermove', move); bar.removeEventListener('pointerup', up); bar.removeEventListener('pointercancel', up);
      };
      bar.addEventListener('pointermove', move); bar.addEventListener('pointerup', up); bar.addEventListener('pointercancel', up);
    };
    if (seekReady(v)) go();
    else { v.addEventListener('durationchange', function once() { if (seekReady(v)) { v.removeEventListener('durationchange', once); seekAt(bar, v, e.clientX, true); } }); v.load(); }
  });
  document.addEventListener('click', (e) => { if (e.target.closest('[data-vseek]')) { e.preventDefault(); e.stopPropagation(); } }, true);
  document.addEventListener('keydown', (e) => {
    const bar = e.target.closest?.('[data-vseek]');
    if (!bar || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const v = bar.closest('[data-vplayer]')?.querySelector('video');
    if (!v || !seekReady(v)) return;
    e.preventDefault();
    v.currentTime = e.key === 'Home' ? 0 : e.key === 'End' ? v.duration - 0.5 : Math.max(0, Math.min(v.duration, v.currentTime + (e.key === 'ArrowLeft' ? -5 : 5)));
  });

  // Vidéos et audios enregistrés dans le navigateur (WebM) : le fichier ne dit pas sa durée,
  // donc impossible d'avancer. On la fait calculer une fois par le navigateur, puis on revient au début.
  document.addEventListener('loadedmetadata', (e) => {
    const m = e.target;
    if (!(m instanceof HTMLMediaElement) || isFinite(m.duration) || m._durFix) return;
    m._durFix = true;
    const back = m.currentTime || 0;
    const wasMuted = m.muted;
    const done = () => {
      if (!isFinite(m.duration)) return;
      m.removeEventListener('durationchange', done);
      m.currentTime = back;
      m.muted = wasMuted;
      m.dispatchEvent(new Event('timeupdate'));
    };
    m.addEventListener('durationchange', done);
    try { m.currentTime = 1e101; } catch (err) {}
  }, true);

  // Modifier une publication : les champs grandissent avec le texte (à l'ouverture et en écrivant)
  const editFit = (t) => { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 320) + 'px'; };
  document.addEventListener('input', (e) => { if (e.target.matches?.('.edit-form textarea')) editFit(e.target); });
  document.addEventListener('click', (e) => {
    const o = e.target.closest('[data-open="edit-sheet"]');
    if (o) requestAnimationFrame(() => document.querySelectorAll('#edit-sheet textarea').forEach(editFit));
  });
  // Le titre tient sur une ligne, Entrée passe au texte
  document.addEventListener('keydown', (e) => {
    const t = e.target.closest?.('[data-edit-title]');
    if (!t || e.key !== 'Enter') return;
    e.preventDefault();
    t.form?.querySelector('.edit-text')?.focus();
  });

  // Commandes : toucher la vidéo = lecture / pause ; bouton son
  document.addEventListener('click', (e) => {
    const box = e.target.closest('[data-vplayer]');
    if (!box) return;
    const v = box.querySelector('video');
    if (e.target.closest('[data-vmute]')) {
      e.preventDefault();
      v.muted = !v.muted;
      soundOn = !v.muted;
      if (v.paused) { const p = v.play(); if (p && p.catch) p.catch(() => {}); }
      return;
    }
    if (e.target.closest('[data-zoom-src]')) return;
    if (e.target === v || e.target.closest('[data-vtoggle]')) {
      e.preventDefault();
      if (v.preload === 'none') v.preload = 'auto';
      if (v.paused) {
        document.querySelectorAll('video[data-autoplay]').forEach((o) => { if (o !== v && !o.paused) { o._auto = true; o.pause(); } });
        const p = v.play(); if (p && p.catch) p.catch(() => {});
      } else { v.pause(); }
    }
  });

  // ── Audio des duels : photo en cercle, l'anneau se remplit dans le sens des aiguilles ──
  const fmtT = (t) => Math.floor(t / 60) + ':' + String(Math.floor(t % 60)).padStart(2, '0');
  function ringSync(p) {
    const a = p.querySelector('audio');
    const fg = p.querySelector('.rp-fg');
    const pct = a.duration ? a.currentTime / a.duration : 0;
    if (fg) fg.style.strokeDashoffset = String(100 - pct * 100);
    p.classList.toggle('playing', !a.paused && !a.ended);
    const t = p.parentElement.querySelector('[data-ring-time]');
    if (t) t.textContent = !a.paused && a.duration ? fmtT(a.duration - a.currentTime) : (t.dataset.total || (a.duration ? fmtT(a.duration) : ''));
  }
  document.addEventListener('click', (e) => {
    const p = e.target.closest('[data-ring-player]');
    if (!p) return;
    const a = p.querySelector('audio');
    // Toucher l'anneau pendant la lecture : avancer ou reculer
    if (!a.paused && a.duration && e.target.closest('.rp-ring') && !e.target.closest('[data-ring-toggle]')) {
      const r = p.getBoundingClientRect();
      let ang = Math.atan2(e.clientX - (r.left + r.width / 2), -(e.clientY - (r.top + r.height / 2)));
      if (ang < 0) ang += 2 * Math.PI;
      a.currentTime = ang / (2 * Math.PI) * a.duration;
      return;
    }
    e.preventDefault();
    if (a.paused) {
      document.querySelectorAll('audio').forEach((o) => { if (o !== a && !o.paused) o.pause(); });
      document.querySelectorAll('video[data-autoplay]').forEach((o) => { if (!o.paused) { o._auto = true; o.pause(); } });
      const pr = a.play(); if (pr && pr.catch) pr.catch(() => toast('Lecture impossible pour le moment.', true));
    } else a.pause();
  });
  ['play', 'pause', 'ended', 'timeupdate', 'loadedmetadata'].forEach((ev) => document.addEventListener(ev, (e) => {
    if (!(e.target instanceof HTMLAudioElement)) return;
    const p = e.target.closest('[data-ring-player]');
    if (!p) return;
    if (ev === 'ended') e.target.currentTime = 0;
    ringSync(p);
  }, true));

  // Description repliée : toucher pour tout lire
  document.addEventListener('click', (e) => { const d = e.target.closest('[data-clamp]'); if (d) d.classList.toggle('open'); });

  // ── Mode swipe : glisser vers son préféré ─────────────────────
  const swipe = document.querySelector('[data-swipe]');
  if (swipe) {
    const deck = swipe.querySelector('[data-swipe-deck]');
    const countEl = swipe.querySelector('[data-swipe-count]');
    const current = () => deck.querySelector('[data-swipe-card]:not([hidden]):not(.gone)');
    let busy = false;
    function next(card) {
      card.classList.add('gone');
      setTimeout(() => {
        card.remove();
        const n = deck.querySelector('[data-swipe-card]');
        if (n) { n.hidden = false; watchVideos(n); countdowns(); }
        else { swipe.querySelector('[data-swipe-end]').hidden = false; swipe.querySelector('[data-swipe-actions]').hidden = true; }
        if (countEl) countEl.textContent = String(deck.querySelectorAll('[data-swipe-card]').length);
        busy = false;
      }, 260);
    }
    // Téléphone : l'un sur l'autre (haut / bas). Grand écran : côte à côte (gauche / droite).
    const vertical = () => window.matchMedia('(max-width: 760px)').matches;
    async function vote(side) {
      const card = current();
      if (!card || busy) return;
      busy = true;
      card.querySelectorAll('video, audio').forEach((m) => m.pause());
      // Les bravos sur le côté choisi, puis la carte s'en va de ce côté
      const sides = card.querySelectorAll('.versus-ring > .side');
      window.tokosCheer && window.tokosCheer(sides[side === 'left' ? 0 : 1]?.querySelector('.side-visual'));
      const body = new FormData();
      body.append('entry_id', card.dataset[side]);
      let ok = true;
      try {
        const data = await send('/m/' + card.dataset.match + '/vote', body);
        if (!data) ok = false;
      } catch (e) { toast(e.message, true); }
      if (!ok) { busy = false; card.style.transform = ''; return; }
      setTimeout(() => {
        card.style.transform = vertical()
          ? 'translateY(' + (side === 'left' ? -110 : 110) + '%)'
          : 'translateX(' + (side === 'left' ? -110 : 110) + '%) rotate(' + (side === 'left' ? -8 : 8) + 'deg)';
        next(card);
      }, 650);
    }
    function skip() {
      const card = current();
      if (card && !busy) { busy = true; card.querySelectorAll('video, audio').forEach((m) => m.pause()); card.style.transform = vertical() ? 'translateX(110%)' : 'translateY(110%)'; next(card); }
    }
    swipe.addEventListener('click', (e) => {
      const b = e.target.closest('[data-swipe-vote]');
      if (b) vote(b.dataset.swipeVote);
      if (e.target.closest('[data-swipe-skip]')) skip();
    });
    document.addEventListener('keydown', (e) => {
      if (vertical()) {
        if (e.key === 'ArrowUp') { e.preventDefault(); vote('left'); }
        if (e.key === 'ArrowDown') { e.preventDefault(); vote('right'); }
        if (e.key === 'ArrowRight') skip();
      } else {
        if (e.key === 'ArrowLeft') vote('left');
        if (e.key === 'ArrowRight') vote('right');
        if (e.key === 'ArrowDown') skip();
      }
    });
    // Glisser au doigt (ou à la souris) : vers le haut / le bas sur téléphone, gauche / droite sur grand écran
    let start = null; let d = 0; let dragCard = null;
    deck.addEventListener('pointerdown', (e) => {
      if (busy || e.target.closest('button, a, .wave, [data-ring-player], [data-vplayer] .vctl')) return;
      dragCard = current(); if (!dragCard) return;
      start = vertical() ? e.clientY : e.clientX; d = 0; dragCard.style.transition = 'none';
    });
    addEventListener('pointermove', (e) => {
      if (start === null || !dragCard) return;
      const v = vertical();
      d = (v ? e.clientY : e.clientX) - start;
      dragCard.style.transform = v ? 'translateY(' + (d * 0.6) + 'px)' : 'translateX(' + d + 'px) rotate(' + (d / 30) + 'deg)';
      dragCard.classList.toggle('lean-left', d < -40);
      dragCard.classList.toggle('lean-right', d > 40);
    });
    addEventListener('pointerup', () => {
      if (start === null || !dragCard) return;
      const card = dragCard; start = null; dragCard = null;
      card.style.transition = '';
      card.classList.remove('lean-left', 'lean-right');
      card.style.transform = '';
      if (Math.abs(d) > 80) vote(d < 0 ? 'left' : 'right');
    });
  }

  // ── Agrandir un média ─────────────────────────────────────────
  function openViewer(src, type) {
    let dlg = document.getElementById('media-viewer');
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.id = 'media-viewer';
      dlg.className = 'viewer';
      dlg.innerHTML = '<button type="button" class="viewer-close" aria-label="Fermer" data-close>&times;</button><div class="viewer-body"></div>';
      document.body.appendChild(dlg);
      dlg.addEventListener('close', () => { dlg.querySelector('.viewer-body').innerHTML = ''; });
    }
    const body = dlg.querySelector('.viewer-body');
    body.innerHTML = '';
    if (type === 'video') {
      const v = document.createElement('video');
      v.src = src; v.controls = true; v.autoplay = true; v.playsInline = true;
      document.querySelectorAll('video').forEach((o) => { if (!o.paused) { o._auto = true; o.pause(); } });
      body.appendChild(v);
    } else {
      const img = document.createElement('img'); img.src = src; img.alt = '';
      body.appendChild(img);
    }
    dlg.showModal();
  }
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-zoom-src]');
    if (btn) { e.preventDefault(); openViewer(btn.dataset.zoomSrc, btn.dataset.zoomType); return; }
    const img = e.target.closest('img[data-zoom]');
    if (img) { e.preventDefault(); openViewer(img.currentSrc || img.src, 'image'); return; }
    if (e.target.classList?.contains('viewer-body')) e.target.closest('dialog')?.close();
  });

  // ── Commentaires : réponses repliées, « voir plus » ───────────
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-toggle-replies]');
    if (t) {
      const box = document.getElementById(t.dataset.toggleReplies);
      if (!box) return;
      box.hidden = !box.hidden;
      t.setAttribute('aria-expanded', String(!box.hidden));
      t.querySelector('[data-label-closed]').hidden = !box.hidden;
      t.querySelector('[data-label-open]').hidden = box.hidden;
      return;
    }
    const m = e.target.closest('[data-more]');
    if (m) {
      const items = Array.from(document.querySelectorAll('[data-more-item="' + m.dataset.more + '"][hidden]'));
      items.slice(0, 5).forEach((i) => { i.hidden = false; });
      const left = items.length - 5;
      if (left > 0) m.textContent = 'Voir plus de commentaires (' + left + ')'; else m.remove();
    }
  });
  // Un lien vers un commentaire précis : on déplie ce qu'il faut
  if (location.hash.startsWith('#commentaire-')) {
    const target = document.querySelector(location.hash);
    if (target) {
      target.closest('[data-more-item]')?.removeAttribute('hidden');
      const replies = target.closest('.replies');
      if (replies) { replies.hidden = false; }
      target.scrollIntoView({ block: 'center' });
    }
  }

  applyLayout();
  countdowns();
  watchVideos(document);
})();

/* Retrait : montant en FC calculé en direct */
document.querySelectorAll('[data-cashout]').forEach((f) => {
  const input = f.querySelector('[data-cashout-coins]');
  const out = f.querySelector('[data-cashout-amount]');
  if (!input || !out) return;
  const rate = Number(f.dataset.rate) || 0;
  const upd = () => { out.textContent = (Math.max(0, Number(input.value) || 0) * rate).toLocaleString('fr-FR'); };
  input.addEventListener('input', upd);
});

/* Téléphone : intl-tel-input (tous les pays, drapeaux, recherche). Le champ indicatif devient caché et suit le pays choisi. */
(function () {
  const boot = () => {
    if (!window.intlTelInput) return;
    const data = window.intlTelInput.getCountryData();
    document.querySelectorAll('[data-tel]').forEach((wrap) => {
      const code = wrap.querySelector('[data-tel-code]');
      const num = wrap.querySelector('[data-tel-number]');
      if (!code || !num || num.dataset.ready) return;
      num.dataset.ready = '1';
      const dial = (code.value || '+243').replace(/\D/g, '');
      const first = data.filter((c) => c.dialCode === dial).sort((a, b) => (a.priority || 0) - (b.priority || 0))[0];
      code.type = 'hidden';
      wrap.classList.add('tel-ready');
      const iti = window.intlTelInput(num, {
        initialCountry: first ? first.iso2 : 'cd',
        countryOrder: ['cd', 'cg', 'ao', 'cm', 'ga', 'cf', 'td', 'rw', 'bi', 'ug', 'tz', 'zm', 'ci', 'sn', 'fr', 'be'],
        separateDialCode: true,
        i18n: window.itiFr || {},
        countrySearch: true,
        placeholderNumberType: 'MOBILE',
        autoPlaceholder: num.placeholder ? 'off' : 'polite',
        formatAsYouType: false,
        loadUtils: () => import(wrap.dataset.utils),
      });
      const sync = () => { const c = iti.getSelectedCountryData(); if (c && c.dialCode) code.value = '+' + c.dialCode; };
      // Le numéro part sans espaces ni 0 du début (comme attendu par le serveur)
      const clean = () => { num.value = num.value.replace(/\D/g, '').replace(/^0+/, ''); };
      num.addEventListener('countrychange', sync);
      num.addEventListener('blur', clean);
      num.form && num.form.addEventListener('submit', () => { sync(); clean(); }, true);
      sync();
    });
  };
  if (document.readyState === 'complete') boot(); else addEventListener('load', boot);
})();

/* Certification : le justificatif devient obligatoire pour un étudiant */
document.querySelectorAll('.cert-form').forEach((f) => {
  const proof = f.querySelector('input[name="proof"]');
  const opt = f.querySelector('[data-cert-optional]');
  if (!proof || f.querySelector('input[name="space"]')) return;
  const upd = () => {
    const v = (f.querySelector('[data-cert-cat]:checked') || {}).value;
    proof.required = v === 'student';
    if (opt) opt.textContent = v === 'student' ? '' : '(facultatif)';
  };
  f.querySelectorAll('[data-cert-cat]').forEach((r) => r.addEventListener('change', upd));
  upd();
});

/* Application installable (PWA) : service worker, bouton « Installer », nettoyage à la déconnexion */
(function () {
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true || /TokosApp/.test(navigator.userAgent);
  if (standalone) document.documentElement.classList.add('is-app');
  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  let deferred = null;
  const show = () => document.querySelectorAll('[data-install]').forEach((el) => { el.hidden = standalone || (!deferred && !isIos); });
  addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; show(); });
  addEventListener('appinstalled', () => { deferred = null; document.querySelectorAll('[data-install]').forEach((el) => { el.hidden = true; }); });
  document.addEventListener('click', async (e) => {
    const close = e.target.closest('[data-install-dismiss]');
    if (close) {
      try { localStorage.setItem('tokos-install-dismissed', String(Date.now())); } catch (err) { /* rien */ }
      close.closest('[data-install]').hidden = true;
      return;
    }
    if (!e.target.closest('[data-install-go]')) return;
    if (deferred) {
      deferred.prompt();
      await deferred.userChoice.catch(() => null);
      deferred = null;
      show();
    } else if (isIos) {
      if (window.tokosToast) window.tokosToast('Sur iPhone : touche le bouton Partager, puis « Sur l\'écran d\'accueil ».');
    }
  });
  // Bandeau d'accueil : pas plus d'une fois par mois après « Plus tard »
  try {
    const d = +localStorage.getItem('tokos-install-dismissed') || 0;
    if (Date.now() - d < 30 * 86400000) document.querySelectorAll('[data-install].install-banner').forEach((el) => el.remove());
  } catch (err) { /* rien */ }
  show();
  // Déconnexion : on oublie les pages gardées pour le mode hors connexion
  document.addEventListener('submit', (e) => {
    if (e.target.action && e.target.action.endsWith('/deconnexion') && window.caches) {
      caches.keys().then((keys) => keys.filter((k) => k.endsWith('-pages')).forEach((k) => caches.delete(k)));
    }
  });
})();

/* Notifications push : bouton « Activer » (paramètres, page Notifications) */
(function () {
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const els = () => document.querySelectorAll('[data-push]');
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content;
  const toast = (m, e) => window.tokosToast && window.tokosToast(m, e);
  const b64ToBytes = (b64) => { const s = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(s, (c) => c.charCodeAt(0)); };
  async function current() {
    if (!supported) return null;
    const reg = await navigator.serviceWorker.getRegistration();
    return reg ? reg.pushManager.getSubscription() : null;
  }
  async function paint() {
    const sub = await current().catch(() => null);
    els().forEach((el) => {
      el.hidden = !supported;
      el.classList.toggle('on', !!sub && Notification.permission === 'granted');
      el.setAttribute('aria-pressed', sub ? 'true' : 'false');
      const label = el.querySelector('[data-push-label]');
      if (label) label.textContent = Notification.permission === 'denied' ? 'Bloquées dans le navigateur' : (sub ? 'Activées sur cet appareil' : 'Reçois une alerte quand on te défie ou vote');
    });
  }
  async function enable() {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') { toast('Notifications refusées. Tu peux les autoriser dans les réglages du navigateur.', true); return paint(); }
    const reg = await navigator.serviceWorker.ready;
    const { key } = await (await fetch('/push/cle', { headers: { Accept: 'application/json' } })).json();
    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
    const res = await fetch('/push/abonner', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(sub.toJSON()) });
    toast(res.ok ? 'Notifications activées.' : 'Impossible d\'activer les notifications ici.', !res.ok);
    paint();
  }
  async function disable() {
    const sub = await current();
    if (sub) {
      await fetch('/push/desabonner', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ endpoint: sub.endpoint }) });
      await sub.unsubscribe();
    }
    toast('Notifications coupées sur cet appareil.');
    paint();
  }
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-push]');
    if (!b) return;
    e.preventDefault();
    try { (await current()) ? await disable() : await enable(); } catch (err) { toast('Les notifications ne marchent pas sur ce navigateur.', true); }
  });
  if (document.readyState === 'complete') paint(); else addEventListener('load', paint);
  // Proposition après une publication : seulement si le navigateur n'a encore rien décidé
  const nudge = document.querySelector('[data-push-nudge]');
  let nudgeSeen = false;
  try { nudgeSeen = localStorage.getItem('tokos-push-nudge') === '1'; } catch (e) { /* stockage indisponible */ }
  if (nudge && supported && Notification.permission === 'default' && !nudgeSeen) {
    nudge.hidden = false;
    try { localStorage.setItem('tokos-push-nudge', '1'); } catch (e) { /* stockage indisponible */ }
  }
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-push-nudge-close]') || (e.target.closest('[data-push]') && e.target.closest('[data-push-nudge]'))) {
      const n = document.querySelector('[data-push-nudge]'); if (n) setTimeout(() => { n.hidden = true; }, 50);
    }
  });
})();

/* Navigation instantanée : préchargement au toucher (navigateurs sans « speculation rules »)
   et petite barre de chargement si la page met du temps à venir */
(function () {
  const conn = navigator.connection || {};
  const saver = conn.saveData || /(^|-)2g$/.test(conn.effectiveType || '');
  const supportsRules = HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules');
  const done = new Set();
  const ok = (a) => a && a.href && a.origin === location.origin && !a.hasAttribute('download') && a.target !== '_blank' && !a.hasAttribute('data-no-prefetch')
    && !/^\/(deconnexion|admin|livewire|api)/.test(a.pathname) && a.href.split('#')[0] !== location.href.split('#')[0];
  if (!saver && !supportsRules) {
    const prefetch = (e) => {
      const a = e.target.closest && e.target.closest('a[href]');
      if (!ok(a) || done.has(a.href)) return;
      done.add(a.href);
      const l = document.createElement('link'); l.rel = 'prefetch'; l.href = a.href; document.head.appendChild(l);
    };
    document.addEventListener('touchstart', prefetch, { passive: true });
    document.addEventListener('mouseover', (e) => { clearTimeout(prefetch._t); prefetch._t = setTimeout(() => prefetch(e), 120); });
  }
  // Barre de chargement en haut, sur toutes les pages : elle part dès le clic, avance pendant le chargement
  // et se termine sur la nouvelle page. Elle suit aussi les chargements en arrière-plan (commentaires, votes…).
  const bar = document.createElement('div');
  bar.className = 'nav-progress'; bar.innerHTML = '<i></i>';
  document.body.appendChild(bar);
  let safety;
  const start = () => {
    clearTimeout(safety);
    bar.classList.remove('done'); void bar.offsetWidth; bar.classList.add('on');
    try { sessionStorage.setItem('tk-nav', String(Date.now())); } catch (err) { /* rien */ }
    safety = setTimeout(stop, 15000); // au cas où la page ne change jamais
  };
  const finish = () => { clearTimeout(safety); if (!bar.classList.contains('on')) return; bar.classList.add('done'); setTimeout(() => bar.classList.remove('on', 'done'), 450); };
  const stop = () => { clearTimeout(safety); bar.classList.remove('on', 'done'); };
  window.tokosProgress = { start, finish, stop };
  // Arrivée sur la nouvelle page : la barre termine sa course
  try {
    const t = +sessionStorage.getItem('tk-nav');
    if (t && Date.now() - t < 15000) {
      sessionStorage.removeItem('tk-nav');
      bar.classList.add('on', 'arrive');
      const end = () => { bar.classList.remove('arrive'); finish(); };
      if (document.readyState === 'complete') setTimeout(end, 60); else addEventListener('load', end);
    }
  } catch (err) { /* rien */ }
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!ok(a) || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    // On attend la fin du clic : s'il a ouvert une fenêtre (commentaires, infos…), il n'y a pas de page à charger
    setTimeout(() => { if (!e.defaultPrevented) start(); }, 0);
  });
  document.addEventListener('submit', (e) => {
    const f = e.target;
    setTimeout(() => { if (!e.defaultPrevented && !f.hasAttribute('data-ajax') && f.target !== '_blank') start(); }, 0);
  });
  addEventListener('pageshow', (e) => { if (e.persisted) stop(); });
  addEventListener('popstate', stop);
  // Chargements en arrière-plan : la barre aussi, s'ils durent plus de 0,2 s
  const nativeFetch = window.fetch.bind(window);
  let pending = 0; let showT;
  window.fetch = (...args) => {
    if (args[1] && args[1].quiet) return nativeFetch(...args); // rafraîchissement du score en direct : discret
    pending++;
    if (pending === 1) showT = setTimeout(() => { if (pending > 0 && !bar.classList.contains('on')) { bar.classList.remove('done'); bar.classList.add('on'); } }, 200);
    return nativeFetch(...args).finally(() => {
      pending = Math.max(0, pending - 1);
      if (pending === 0) { clearTimeout(showT); finish(); }
    });
  };
})();

/* Bouton retour (téléphone) : page précédente du site, sinon la page de repli */
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-back]');
  if (!b) return;
  let fromHere = false;
  try { fromHere = document.referrer && new URL(document.referrer).origin === location.origin; } catch (err) { /* rien */ }
  if (fromHere && history.length > 1) { e.preventDefault(); history.back(); }
});

/* Émojis : un bouton à côté des champs de texte (commentaires, présentations, défis) */
(function () {
  const SETS = {
    'Réactions': ['🔥', '😂', '🤣', '😍', '🥰', '😮', '😱', '👏', '🙌', '💯', '❤️', '💪', '🙏', '😎', '🤩', '😅', '😭', '😤', '🤔', '👀'],
    'Talent': ['🎤', '🎶', '🎧', '🥁', '🎸', '🎹', '💃', '🕺', '⚽', '🏀', '🥊', '🏃', '🎨', '📸', '🎬', '📚', '✍️', '🧠', '🏆', '🥇'],
    'Drapeaux': ['🇨🇩', '🇨🇬', '🇦🇴', '🇨🇲', '🇨🇮', '🇸🇳', '🇳🇬', '🇬🇭', '🇰🇪', '🇷🇼', '🇧🇮', '🇺🇬', '🇹🇿', '🇿🇦', '🇲🇦', '🇩🇿', '🇹🇳', '🇪🇬', '🇬🇦', '🇲🇱', '🇧🇯', '🇹🇬', '🇫🇷', '🇧🇪', '🇺🇸', '🇬🇧', '🇨🇦', '🇧🇷', '🇵🇹', '🏁'],
    'Signes': ['👍', '👎', '✌️', '🤝', '👊', '✊', '👑', '⭐', '✨', '⚡', '🎉', '🎯', '🚀', '💥', '😇', '😈', '🤫', '🥶', '🥵'],
  };
  const smile = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8"/><path d="M9 9.5h.01"/><path d="M15 9.5h.01"/></svg>';
  let panel = null; let target = null;
  function build() {
    panel = document.createElement('div');
    panel.className = 'emoji-panel'; panel.hidden = true;
    panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Émojis');
    panel.innerHTML = Object.entries(SETS).map(([name, list]) => '<div class="ep-group"><span>' + name + '</span><div class="ep-grid">'
      + list.map((e) => '<button type="button" data-e="' + e + '" aria-label="' + e + '">' + e + '</button>').join('') + '</div></div>').join('');
    document.body.appendChild(panel);
    panel.addEventListener('mousedown', (e) => e.preventDefault()); // le champ garde le curseur
    panel.addEventListener('click', (e) => {
      const b = e.target.closest('[data-e]');
      if (!b || !target) return;
      const v = target.value; const s = target.selectionStart ?? v.length; const end = target.selectionEnd ?? v.length;
      target.value = v.slice(0, s) + b.dataset.e + v.slice(end);
      const pos = s + b.dataset.e.length;
      try { target.setSelectionRange(pos, pos); } catch (err) { /* rien */ }
      target.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }
  function place(btn) {
    if (!btn) return;
    const r = btn.getBoundingClientRect();
    const vh = window.visualViewport ? visualViewport.height : innerHeight;
    const w = panel.offsetWidth; const h = Math.min(panel.offsetHeight, vh - 16);
    const left = Math.max(8, Math.min(innerWidth - w - 8, r.right - w));
    const top = r.top - h - 8 > 8 ? r.top - h - 8 : Math.min(r.bottom + 8, vh - h - 8);
    panel.style.left = left + 'px'; panel.style.top = Math.max(8, top) + 'px';
  }
  function attach(field) {
    if (field.dataset.emojiReady) return;
    field.dataset.emojiReady = '1';
    const wrap = document.createElement('span');
    wrap.className = 'emoji-wrap' + (field.tagName === 'TEXTAREA' ? ' area' : '');
    field.parentNode.insertBefore(wrap, field);
    wrap.appendChild(field);
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'emoji-btn'; btn.setAttribute('aria-label', 'Ajouter un émoji'); btn.innerHTML = smile;
    wrap.appendChild(btn);
    btn.addEventListener('click', () => {
      if (!panel) build();
      if (!panel.hidden && target === field) { panel.hidden = true; return; }
      target = field;
      // Dans une fenêtre (commentaires…), le tableau doit être dedans, sinon il passe dessous
      const host = field.closest('dialog[open]') || document.body;
      if (panel.parentNode !== host) host.appendChild(panel);
      panel.hidden = false;
      place(btn);
    });
  }
  const scan = (root) => (root || document).querySelectorAll('[data-emoji]').forEach(attach);
  document.addEventListener('click', (e) => { if (panel && !panel.hidden && !e.target.closest('.emoji-panel, .emoji-btn')) panel.hidden = true; });
  // On suit le champ quand la page bouge (clavier qui s'ouvre, défilement), au lieu de fermer
  const follow = () => { if (panel && !panel.hidden && target) place(target.parentNode.querySelector('.emoji-btn')); };
  addEventListener('scroll', follow, { passive: true });
  addEventListener('resize', follow);
  if (window.visualViewport) visualViewport.addEventListener('resize', follow);
  // Champs ajoutés après coup (répondre à un commentaire, fil infini…)
  new MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach((n) => {
    if (n.nodeType !== 1) return;
    if (n.matches && n.matches('[data-emoji]')) attach(n); else if (n.querySelectorAll) scan(n);
  }))).observe(document.body, { childList: true, subtree: true });
  if (document.readyState !== 'loading') scan(); else document.addEventListener('DOMContentLoaded', () => scan());
  window.tokosEmojiScan = scan;
})();

/* Réactions : mise à jour immédiate des compteurs */
document.addEventListener('submit', (e) => {
  const form = e.target.closest('form[data-reaction]');
  if (!form) return;
  const box = form.closest('.reactions');
  const btn = form.querySelector('.react');
  const wasOn = btn.classList.contains('on');
  box.querySelectorAll('.react.on').forEach((b) => {
    b.classList.remove('on'); b.setAttribute('aria-pressed', 'false');
    const n = b.querySelector('[data-react-count]'); const v = Math.max(0, (parseInt(n.textContent, 10) || 0) - 1); n.textContent = v || '';
  });
  if (!wasOn) {
    btn.classList.add('on'); btn.setAttribute('aria-pressed', 'true');
    const n = btn.querySelector('[data-react-count]'); n.textContent = (parseInt(n.textContent, 10) || 0) + 1;
    btn.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.25)' }, { transform: 'scale(1)' }], { duration: 260 });
    // Comme au vote : l'émoji choisi monte et remplit le duel
    const emoji = btn.querySelector('.e')?.textContent.trim();
    const duel = document.querySelector('.versus .versus-ring');
    if (emoji && duel) window.tokosCheer(duel, [emoji]);
  }
}, true);

/* Navigation fluide : on indique au navigateur le sens du passage d'une page à l'autre
   (avant, arrière, ou changement d'onglet) pour choisir l'animation */
(function () {
  const TABS = ['/', '/decouvrir', '/voter', '/classements', '/espaces', '/notifications'];
  const isTab = (u) => { try { const p = new URL(u, location.href).pathname.replace(/\/$/, '') || '/'; return TABS.includes(p) || p.startsWith('/@'); } catch (e) { return false; } };
  addEventListener('pageswap', (e) => {
    if (!e.viewTransition || !e.activation) return;
    const nav = e.activation.navigationType;
    const to = e.activation.entry && e.activation.entry.url;
    e.viewTransition.types.add(nav === 'traverse' ? 'back' : (isTab(to) && isTab(location.href) ? 'tab' : 'forward'));
  });
  addEventListener('pagereveal', (e) => {
    if (!e.viewTransition || !window.navigation || !navigation.activation) return;
    const a = navigation.activation;
    const from = a.from && a.from.url;
    e.viewTransition.types.add(a.navigationType === 'traverse' ? 'back' : (from && isTab(from) && isTab(location.href) ? 'tab' : 'forward'));
  });
})();

/* Chargement infini des listes paginées (Découvrir, Espaces, Notifications) :
   la page suivante arrive toute seule en bas, le bouton « Suivant » reste là sans JavaScript */
(function () {
  if (!('IntersectionObserver' in window)) return;
  document.querySelectorAll('[data-infinite]').forEach((pager) => {
    const list = document.querySelector('[data-infinite-list]');
    if (!list) return;
    pager.classList.add('is-auto');
    let busy = false;
    const io = new IntersectionObserver(async (entries) => {
      if (!entries[0].isIntersecting || busy) return;
      const next = [...pager.querySelectorAll('a[href]')].find((a) => !/Précédent/.test(a.textContent));
      if (!next) { io.disconnect(); pager.remove(); return; }
      busy = true; pager.classList.add('loading');
      try {
        const res = await fetch(next.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const more = doc.querySelector('[data-infinite-list]');
        if (more) list.append(...[...more.children].filter((c) => !c.classList.contains('empty')));
        const np = doc.querySelector('[data-infinite]');
        const nextLink = np && [...np.querySelectorAll('a[href]')].find((a) => !/Précédent/.test(a.textContent));
        if (nextLink) { pager.innerHTML = ''; pager.appendChild(nextLink); } else { io.disconnect(); pager.remove(); }
        if (window.tokosEmojiScan) window.tokosEmojiScan(list);
      } catch (e) { io.disconnect(); }
      busy = false; pager.classList.remove('loading');
    }, { rootMargin: '600px 0px' });
    io.observe(pager);
  });
})();

/* Grand écran avec souris : flèches gauche / droite sur les rangées qui défilent à l'horizontale.
   Sur téléphone, on glisse au doigt : pas de flèches. */
(function () {
  const mq = matchMedia('(hover: hover) and (pointer: fine) and (min-width: 761px)');
  const SEL = '.stories, .bracket, .tabs, .seg, .facts, .media-strip, .rank-types, .rank-filters, .ss-row, .chips-row, .filter-row, .qc-chips, .type-tabs';
  const arrow = (d) => '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="' + (d < 0 ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6') + '"/></svg>';
  function update(el, wrap) {
    const max = el.scrollWidth - el.clientWidth;
    wrap.classList.toggle('can-l', el.scrollLeft > 4);
    wrap.classList.toggle('can-r', el.scrollLeft < max - 4);
  }
  function setup(el) {
    if (el.dataset.hs || el.scrollWidth <= el.clientWidth + 4) return;
    el.dataset.hs = '1';
    const wrap = document.createElement('div');
    wrap.className = 'hs-wrap';
    el.parentNode.insertBefore(wrap, el);
    wrap.appendChild(el);
    [-1, 1].forEach((d) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'hs-btn ' + (d < 0 ? 'l' : 'r');
      b.setAttribute('aria-label', d < 0 ? 'Défiler à gauche' : 'Défiler à droite');
      b.innerHTML = arrow(d);
      b.addEventListener('click', () => el.scrollBy({ left: d * Math.max(160, el.clientWidth * 0.8), behavior: 'smooth' }));
      wrap.appendChild(b);
    });
    el.addEventListener('scroll', () => update(el, wrap), { passive: true });
    addEventListener('resize', () => update(el, wrap));
    update(el, wrap);
  }
  const scan = () => { if (mq.matches) document.querySelectorAll(SEL).forEach(setup); };
  if (document.readyState === 'complete') scan(); else addEventListener('load', scan);
  mq.addEventListener && mq.addEventListener('change', scan);
  window.tokosScrollArrows = scan;
})();

/* Espace : la sous-catégorie suit la catégorie choisie */
document.addEventListener('change', (e) => {
  const sel = e.target.closest('[data-cat-select]');
  if (!sel) return;
  const box = sel.closest('[data-space-cat]');
  const sub = box.querySelector('[data-sub-select]');
  let any = false;
  sub.querySelectorAll('option[data-cat]').forEach((o) => {
    const on = o.dataset.cat === sel.value;
    o.hidden = !on; o.disabled = !on; any = any || on;
    if (!on && o.selected) sub.value = '';
  });
  box.querySelector('[data-sub-field]').hidden = !any;
});

/* « Réservé à des zones » : l'organisateur cherche et ajoute un ou plusieurs lieux (pays, province, ville, village) */
document.addEventListener('change', (e) => {
  const t = e.target.closest('[data-geo-toggle]');
  if (!t) return;
  const box = t.closest('[data-geo]');
  box.querySelector('[data-geo-more]').hidden = !t.checked;
  // Coupé : les zones choisies ne sont pas envoyées
  box.querySelectorAll('input[name="geo_place[]"]').forEach((i) => { i.disabled = !t.checked; });
  if (t.checked && !box.querySelector('input[name="geo_place[]"]')) setTimeout(() => box.querySelector('[data-geo-q]').focus(), 50);
});
(function () {
  const esc = (v) => String(v || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  document.querySelectorAll('[data-geo]').forEach((box) => {
    const q = box.querySelector('[data-geo-q]');
    if (!q) return;
    const list = box.querySelector('[data-geo-results]');
    const picked = box.querySelector('[data-geo-picked]');
    let timer = null; let seq = 0; let items = [];
    const chosen = () => Array.from(picked.querySelectorAll('input')).map((i) => i.value);
    async function run() {
      const term = q.value.trim();
      if (term.length < 2) { list.hidden = true; return; }
      const mine = ++seq;
      list.hidden = false;
      list.innerHTML = '<li class="geo-wait"><span class="spin"></span>Recherche…</li>';
      try {
        const r = await fetch('/lieux?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await r.json();
        if (mine !== seq) return; // une recherche plus récente est partie
        const have = chosen();
        items = (d.items || []).filter((p) => !have.includes(String(p.id)));
        list.innerHTML = items.length ? items.map((p, i) => '<li><button type="button" role="option" data-i="' + i + '"><span class="geo-lvl">' + esc(p.level_label) + '</span><span><b>'
          + esc(p.label) + '</b>' + (p.context ? '<small>' + esc(p.context) + '</small>' : '') + '</span></button></li>').join('')
          : '<li class="geo-wait">Aucun lieu trouvé. Vérifie l\'orthographe.</li>';
      } catch (err) {
        if (mine === seq) list.innerHTML = '<li class="geo-wait">Recherche impossible pour l\'instant. Réessaie.</li>';
      }
    }
    q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 350); });
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(); } });
    list.addEventListener('click', (e) => {
      const b = e.target.closest('[data-i]');
      if (!b) return;
      const p = items[+b.dataset.i];
      if (chosen().length >= 10) { window.tokosToast && window.tokosToast('10 zones au maximum.', true); return; }
      const li = document.createElement('li');
      li.innerHTML = '<input type="hidden" name="geo_place[]" value="' + esc(p.id) + '"><span class="geo-lvl">' + esc(p.level_label) + '</span><span><b>' + esc(p.label) + '</b>'
        + (p.context ? '<small>' + esc(p.context) + '</small>' : '') + '</span><button type="button" class="icon-btn" data-geo-remove aria-label="Retirer ' + esc(p.label) + '">&times;</button>';
      picked.appendChild(li);
      list.hidden = true; q.value = ''; q.focus();
    });
    picked.addEventListener('click', (e) => { const b = e.target.closest('[data-geo-remove]'); if (b) b.closest('li').remove(); });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) list.hidden = true; });
  });
})();

/* Publier un défi ou un duel (un seul écran) : mode duel / versus, texte qui grandit, vérifications simples */
(function () {
  const form = document.querySelector('form[data-quick]');
  if (!form) return;
  const fmt = form.querySelector('[data-qc-format]');
  const err = form.querySelector('[data-qc-error]');
  const btn = form.querySelector('[data-qc-submit]');
  const isDuel = form.querySelector('input[name="type"]').value === 'duel';
  // Les blocs d'un mode masqué ne sont pas envoyés
  function sync() {
    const mode = fmt.value === 'versus' ? 'versus' : (isDuel ? 'challenge' : 'post');
    form.querySelectorAll('[data-qc-mode]').forEach((el) => {
      const on = el.dataset.qcMode === mode;
      el.hidden = !on;
      el.querySelectorAll('input, textarea, select').forEach((i) => { if (i.type !== 'hidden' || i.name.includes('media') || i.name === 'opponent_type') i.disabled = !on; });
    });
    const t = form.querySelector('[data-qc-title]');
    const c = form.querySelector('[data-qc-content]');
    if (t && c) {
      t.placeholder = mode === 'versus' ? 'Ta question, ex. Pondu ou Moambe ?' : (isDuel ? 'Titre de ton duel (facultatif)' : 'Ton défi, en une phrase');
      c.placeholder = mode === 'versus' ? 'Ajoute une description… (facultatif)' : (isDuel ? 'Un mot pour ton adversaire… (facultatif)' : 'Ajoute des détails… (facultatif)');
    }
  }
  // Duel : « Défier quelqu'un » ou « Deux choix » (le public choisit entre deux propositions)
  form.querySelectorAll('[data-qc-set]').forEach((b) => b.addEventListener('click', () => {
    fmt.value = b.dataset.qcSet;
    form.querySelectorAll('[data-qc-set]').forEach((o) => o.setAttribute('aria-pressed', o === b ? 'true' : 'false'));
    sync(); hideErr();
  }));
  sync();

  // Discipline : toucher la même pastille la désélectionne
  form.querySelectorAll('.qc-chips input').forEach((r) => r.addEventListener('click', () => {
    if (r.dataset.was === '1') { r.checked = false; r.dataset.was = '0'; } else { form.querySelectorAll('.qc-chips input').forEach((o) => { o.dataset.was = '0'; }); r.dataset.was = '1'; }
  }));
  // Le texte grandit avec ce qu'on écrit ; plus il est court, plus il s'affiche gros
  const fitters = [];
  form.querySelectorAll('[data-autogrow]').forEach((t) => {
    const fit = () => {
      const n = t.value.length;
      const tt = form.querySelector('[data-qc-title]');
      const titled = !!(tt && !tt.hidden);
      t.style.fontSize = titled ? '1rem' : (n <= 80 ? '1.25rem' : (n <= 220 ? '1.1rem' : '1rem'));
      t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 320) + 'px';
    };
    t.addEventListener('input', fit); fit(); fitters.push(fit);
  });

  // Défi / duel : la première ligne est le titre, en grand pendant qu'on l'écrit.
  // Arrivé au nombre de caractères permis (ou en appuyant sur Entrée), on passe à la ligne suivante, en taille normale.
  const title = form.querySelector('[data-qc-title]');
  const content = form.querySelector('[data-qc-content]');
  const count = form.querySelector('[data-qc-count]');
  function counter() {
    const n = content.value.length; const max = +content.maxLength;
    count.hidden = n < max * 0.8;
    count.textContent = n + ' / ' + max;
    count.classList.toggle('full', n >= max);
  }
  if (title && content) {
    title.hidden = false;
    form.classList.add('titled');
    sync();
    fitters.forEach((f) => f());
    const toContent = (at = 0) => { content.focus(); content.setSelectionRange(at, at); };
    title.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); toContent(); } });
    title.addEventListener('input', () => {
      // Titre plein : la suite part sur la ligne suivante, sans couper le mot en cours
      if (title.value.length >= +title.maxLength) {
        const sp = title.value.lastIndexOf(' ');
        let carry = '';
        if (sp > title.maxLength * 0.5) { carry = title.value.slice(sp + 1); title.value = title.value.slice(0, sp); }
        content.value = carry + content.value;
        toContent(carry.length);
      }
    });
    // Effacer au tout début du texte : on remonte au titre
    content.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && content.selectionStart === 0 && content.selectionEnd === 0) {
        e.preventDefault();
        title.focus();
        const at = title.value.length; title.setSelectionRange(at, at);
      }
    });
    content.addEventListener('input', counter);
    counter();
  }

  const hideErr = () => { err.hidden = true; };
  const showErr = (m) => { err.querySelector('span').textContent = m; err.hidden = false; err.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
  form.addEventListener('input', hideErr);
  const hasFile = (root) => Array.from(root.querySelectorAll('[data-picker-input]')).some((i) => !i.disabled && i.files && i.files.length);
  const val = (n) => { const f = form.querySelector('[name="' + n + '"]'); return f && !f.disabled ? String(f.value).trim() : ''; };

  function check() {
    if (fmt.value === 'versus') {
      if (!val('side_a_label') || !val('side_b_label')) return 'Donne un nom à tes deux choix.';
      if (val('side_a_label').toLowerCase() === val('side_b_label').toLowerCase()) return 'Tes deux choix doivent être différents.';
      return null;
    }
    if (isDuel && !val('opponent') && !val('opponent_phone') && !form.querySelector('input[name="opponents[]"]:not(:disabled)')) return 'Choisis qui tu défies : tape @ puis son nom.';
    if (!val('content') && !val('title') && !hasFile(form.querySelector('.qc-body'))) return isDuel ? 'Ajoute ta performance : une vidéo, un audio, une photo ou un texte.' : 'Ajoute ta performance (vidéo, audio, photo) ou écris ton défi.';
    return null;
  }
  // Deux temps : la performance, puis les réglages. Rien ne dépasse l'écran.
  const s1 = form.querySelector('[data-qc-step="1"]');
  const s2 = form.querySelector('[data-qc-step="2"]');
  const next = form.querySelector('[data-qc-next]');
  const back = form.querySelector('[data-qc-back]');
  function step(n) {
    s1.hidden = n !== 1; s2.hidden = n !== 2;
    next.hidden = n !== 1; btn.hidden = n !== 2; back.hidden = n !== 2;
    hideErr();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    // Le choix par défaut reste visible dans chaque rangée qui défile (ex. « 3 jours »)
    if (n === 2 && window.tokosScrollArrows) setTimeout(window.tokosScrollArrows, 50); // flèches des rangées, une fois visibles
    if (n === 2) form.querySelectorAll('.qc-chips').forEach((row) => {
      const on = row.querySelector('input:checked');
      const sc = on && on.closest('.qc-chips, .chips-choice');
      if (on) { const l = on.closest('label'); const r = row.scrollWidth > row.clientWidth ? row : (sc && sc.scrollWidth > sc.clientWidth ? sc : null); if (r) r.scrollLeft = Math.max(0, l.offsetLeft - r.clientWidth / 2 + l.offsetWidth / 2); }
    });
  }
  next.addEventListener('click', () => { const p = check(); if (p) showErr(p); else step(2); });
  back.addEventListener('click', () => step(1));
  // Entrée dans un champ d'une ligne : passe à la suite au lieu d'envoyer
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !s1.hidden && !e.target.closest('[data-suggest]')) { e.preventDefault(); next.click(); }
  });

  form.addEventListener('submit', (e) => {
    if (!s1.hidden) { e.preventDefault(); next.click(); return; }
    const problem = check();
    if (problem) { e.preventDefault(); step(1); showErr(problem); return; }
    btn.disabled = true;
    btn.querySelector('span').textContent = hasFile(form) ? 'Envoi en cours…' : 'Publication…';
  });
})();

/* « Publier en tant que » : petite fenêtre sous le nom, avec chargement, puis moi et mes Espaces */
(function () {
  const box = document.querySelector('[data-as]');
  if (!box) return;
  const btn = box.querySelector('[data-as-btn]');
  const pop = box.querySelector('[data-as-pop]');
  const input = box.querySelector('[data-as-input]');
  let loaded = false;
  const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function close() { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  function render(data) {
    pop.innerHTML = data.items.map((it) => '<button type="button" role="option" class="qc-pop-it' + (it.slug === input.value ? ' on' : '') + '" data-slug="' + esc(it.slug) + '" data-name="' + esc(it.name) + '" data-img="' + esc(it.avatar_url) + '">'
      + '<img class="avatar sm" src="' + esc(it.avatar_url) + '" alt=""><span><b>' + esc(it.name) + '</b><small>' + esc(it.sub) + '</small></span></button>').join('')
      + (data.can_create ? '<a class="qc-pop-new" href="/espaces#nouvel-espace">+ Créer un Espace</a>' : '');
    if (data.items.length === 1) pop.insertAdjacentHTML('afterbegin', '<p class="qc-pop-note">Tu ne gères pas encore d\'Espace. Publie au nom de ton école, ton club ou ton groupe en créant le sien.</p>');
  }
  btn.addEventListener('click', async () => {
    if (!pop.hidden) return close();
    pop.hidden = false; btn.setAttribute('aria-expanded', 'true');
    if (loaded) return;
    pop.innerHTML = '<div class="qc-pop-load"><span class="spin"></span>Chargement de tes Espaces…</div>';
    try {
      const res = await fetch('/mes-espaces', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      render(await res.json()); loaded = true;
    } catch (e) {
      pop.innerHTML = '<div class="qc-pop-load">Impossible de charger tes Espaces. Réessaie.</div>';
    }
  });
  pop.addEventListener('click', (e) => {
    const it = e.target.closest('[data-slug]');
    if (!it) return;
    input.value = it.dataset.slug;
    box.querySelector('[data-as-name]').textContent = it.dataset.name;
    box.querySelector('[data-as-img]').src = it.dataset.img;
    pop.querySelectorAll('.qc-pop-it').forEach((b) => b.classList.toggle('on', b === it));
    close();
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  // Les maximums suivent celui qui publie : un compte personnel ou un Espace
  const row = document.querySelector('[data-max-row]');
  if (row) input.addEventListener('change', () => {
    let caps = {}; try { caps = JSON.parse(row.dataset.caps); } catch (e) { return; }
    const isSpace = !!input.value; const cap = isSpace ? caps.space : caps.user;
    row.querySelectorAll('[data-cap]').forEach((l) => {
      const over = +l.dataset.cap >= cap; const r = l.querySelector('input');
      l.hidden = over; r.disabled = over;
      if (over && r.checked) { r.checked = false; row.querySelector('input[value=""]').checked = true; }
    });
    row.querySelector('[data-max-hint]').textContent = isSpace ? 'Depuis un Espace : jusqu\'à ' + cap + ' challengers.'
      : 'En ton nom : jusqu\'à ' + cap + ' challengers. Publie depuis un de tes Espaces pour aller jusqu\'à ' + caps.space + '.';
  });
  document.addEventListener('click', (e) => { if (!box.contains(e.target)) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();

/* Lien vers une fenêtre (ex. /espaces#nouvel-espace) : elle s'ouvre directement */
(function () {
  const id = decodeURIComponent(location.hash.slice(1));
  const d = id && document.getElementById(id);
  if (d && d.tagName === 'DIALOG' && !d.open) { try { d.showModal(); } catch (e) { /* rien */ } }
})();

/* Onglets de création : l'onglet actif reste visible */
document.querySelectorAll('.type-tabs a[aria-current="page"]').forEach((a) => {
  const row = a.closest('.type-tabs');
  if (row.scrollWidth > row.clientWidth) row.scrollLeft = a.offsetLeft - row.clientWidth / 2 + a.offsetWidth / 2;
});

/* Pendant la recherche de la position : on ne peut pas envoyer (boutons Suivant / Publier désactivés) */
document.addEventListener('tokos:locating', (e) => {
  document.querySelectorAll('[data-qc-submit], [data-qc-next], form [data-submit], form button[type="submit"]').forEach((b) => {
    if (e.detail) { if (!b.disabled) { b.disabled = true; b.dataset.geoLock = '1'; } }
    else if (b.dataset.geoLock) { b.disabled = false; delete b.dataset.geoLock; }
  });
});

/* Commentaires dans une fenêtre qui monte du bas : n'importe quel bouton « commentaires » l'ouvre,
   sans quitter la page. On peut commenter, aimer et répondre directement dedans. */
(function () {
  const sheet = document.getElementById('comments-sheet');
  if (!sheet) return;
  const body = sheet.querySelector('[data-cs-body]');
  const countEl = sheet.querySelector('[data-cs-count]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  let slug = null;
  const slugOf = (href) => { const m = /\/c\/([^/?#]+)/.exec(href || ''); return m ? m[1] : null; };
  let duel = null; // le duel d'où l'on vient : le commentaire le vise d'office
  async function load(keepScroll) {
    const top = body.scrollTop;
    if (!keepScroll) body.innerHTML = '<div class="cs-wait"><span class="spin"></span>Chargement des commentaires…</div>';
    try {
      const r = await fetch('/c/' + slug + '/commentaires' + (duel ? '?duel=' + duel : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const d = await r.json();
      body.innerHTML = d.html; countEl.textContent = d.count ? d.count + (d.count > 1 ? ' commentaires' : ' commentaire') : 'Commentaires';
      document.querySelectorAll('[data-comments-count="' + slug + '"]').forEach((el) => { el.textContent = d.count; });
      if (keepScroll) body.scrollTop = top;
      if (window.tokosEmojiScan) window.tokosEmojiScan(body);
    } catch (e) { body.innerHTML = '<div class="cs-wait">Impossible de charger les commentaires. Réessaie.</div>'; }
  }
  function open(s, d) {
    slug = s; duel = d || null; countEl.textContent = 'Commentaires';
    if (!sheet.open) sheet.showModal();
    load(false);
  }
  window.tokosComments = open;
  // Tous les liens « ?tab=commentaires » (fil, duels, publication) ouvrent la fenêtre
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href*="tab=commentaires"], [data-comments]');
    if (!a || e.metaKey || e.ctrlKey) return;
    const s = a.dataset.comments || slugOf(a.getAttribute('href')) || slugOf(location.pathname);
    if (!s) return;
    e.preventDefault();
    open(s, a.dataset.duel);
  });
  // Le commentaire apparaît tout de suite (« Envoi… »), il part en arrière-plan ;
  // en cas d'échec il disparaît, le texte revient dans le champ et on le dit.
  // Les j'aime de la fenêtre passent par le gestionnaire général (affichés tout de suite, eux aussi).
  const pending = (text, f) => {
    const main = body.querySelector('[data-cm-form]');
    const el = document.createElement('div');
    el.className = 'thread pending';
    const cm = document.createElement('div'); cm.className = 'comment';
    const av = main?.querySelector('.cm-row img, .cm-row .avatar');
    if (av) cm.appendChild(av.cloneNode(true));
    const grow = document.createElement('div'); grow.className = 'grow';
    const bubble = document.createElement('div'); bubble.className = 'bubble';
    const who = document.createElement('span'); who.className = 'cm-author'; who.textContent = main?.dataset.meName || 'Moi';
    const txt = document.createElement('span'); txt.className = 'cm-text'; txt.textContent = text;
    bubble.append(who, ' ', txt);
    const act = document.createElement('div'); act.className = 'comment-actions';
    const st = document.createElement('span'); st.className = 'faint'; st.textContent = 'Envoi…'; act.appendChild(st);
    grow.append(bubble, act); cm.appendChild(grow); el.appendChild(cm);
    const thread = f.classList.contains('reply-form') ? f.closest('.thread') : null;
    if (thread) thread.appendChild(el); else if (main) main.after(el); else body.prepend(el);
    return el;
  };
  body.addEventListener('submit', async (e) => {
    const f = e.target.closest('form');
    if (!f || f.hasAttribute('data-ajax')) return;
    e.preventDefault(); e.stopImmediatePropagation();
    const input = f.querySelector('[name="content"]');
    const text = (input?.value || '').trim();
    if (!text) return;
    const data = new FormData(f);
    const el = pending(text, f);
    input.value = '';
    if (f.classList.contains('reply-form')) f.hidden = true;
    const n = parseInt(countEl.textContent, 10) || 0;
    countEl.textContent = (n + 1) + (n + 1 > 1 ? ' commentaires' : ' commentaire');
    document.querySelectorAll('[data-comments-count="' + slug + '"]').forEach((c) => { c.textContent = (parseInt(c.textContent, 10) || 0) + 1; });
    try {
      const r = await fetch(f.action, { method: 'POST', body: data, credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      if (r.status === 401) { location.href = '/connexion?suite=' + encodeURIComponent(location.pathname); return; }
      if (!r.ok) { const d = await r.json().catch(() => ({})); throw new Error(d.message || 'Envoi impossible.'); }
      el.querySelector('.comment-actions .faint').textContent = 'À l\'instant';
      load(true); // la version du serveur (j'aime, répondre, mentions en liens) remplace l'aperçu
    } catch (err) {
      el.remove();
      if (!input.value) input.value = text;
      if (f.classList.contains('reply-form')) f.hidden = false;
      document.querySelectorAll('[data-comments-count="' + slug + '"]').forEach((c) => { c.textContent = Math.max(0, (parseInt(c.textContent, 10) || 1) - 1); });
      window.tokosToast && window.tokosToast(err.message || 'Envoi impossible. Vérifie ta connexion.', true);
    }
  }, true);
  // Ouvert directement par un lien partagé (…?tab=commentaires)
  if (/[?&]tab=commentaires/.test(location.search) && slugOf(location.pathname)) open(slugOf(location.pathname));
})();

/* Défi dans le fil : ses duels et « Relever le défi » dans une fenêtre qui monte du bas, deux onglets en haut. */
(function () {
  const sheet = document.getElementById('duels-sheet');
  if (!sheet) return;
  const body = sheet.querySelector('[data-ds-body]');
  const title = sheet.querySelector('[data-ds-title]');
  function show(tab) {
    body.querySelectorAll('[data-ds-tab]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.dsTab === tab)));
    body.querySelectorAll('[data-ds-panel]').forEach((p) => { p.hidden = p.dataset.dsPanel !== tab; });
  }
  async function open(slug, tab) {
    title.textContent = 'Duels';
    body.innerHTML = '<div class="cs-wait"><span class="spin"></span>Chargement…</div>';
    if (!sheet.open) sheet.showModal();
    try {
      const r = await fetch('/c/' + slug + '/duels-apercu', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!r.ok) throw new Error();
      const d = await r.json();
      body.innerHTML = d.html; title.textContent = d.title || 'Duels';
      show(tab || 'duels');
    } catch (e) { body.innerHTML = '<div class="cs-wait">Impossible de charger. Réessaie.</div>'; }
  }
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-duels]');
    if (a && !e.metaKey && !e.ctrlKey) { e.preventDefault(); open(a.dataset.duels, a.dataset.duelsTab); return; }
    const t = e.target.closest('[data-ds-tab]');
    if (t && sheet.contains(t)) show(t.dataset.dsTab);
  });
})();

/* Récompenses par place dans la création d'un concours ou d'un tournoi :
   ajouter une ligne (place ou plage), la retirer, et voir le total de coins qui sera bloqué. */
(function () {
  const box = document.querySelector('[data-prize-tiers]');
  if (!box) return;
  const rows = box.querySelector('[data-pt-rows]');
  const balance = +box.dataset.balance || 0;
  function renumber() {
    rows.querySelectorAll('[data-pt-row]').forEach((r, i) => {
      r.querySelectorAll('input').forEach((inp) => { inp.name = inp.name.replace(/prize_tiers\[\d+\]/, 'prize_tiers[' + i + ']'); });
    });
  }
  function total() {
    let sum = 0;
    rows.querySelectorAll('[data-pt-row]').forEach((r) => {
      const f = +r.querySelector('[data-pt-from]').value || 0;
      const t = Math.max(f, +r.querySelector('[data-pt-to]').value || f);
      const c = +(r.querySelector('[data-pt-coins]')?.value || 0);
      if (f > 0 && c > 0) sum += (t - f + 1) * c;
    });
    const tot = box.querySelector('[data-pt-total]');
    const short = box.querySelector('[data-pt-short]');
    if (tot) { tot.hidden = sum <= 0; box.querySelector('[data-pt-sum]').textContent = sum.toLocaleString('fr-FR'); }
    if (short) short.hidden = sum <= balance;
  }
  box.addEventListener('click', (e) => {
    if (e.target.closest('[data-pt-add]')) {
      const all = rows.querySelectorAll('[data-pt-row]');
      const last = all[all.length - 1];
      const row = last.cloneNode(true);
      const next = (+last.querySelector('[data-pt-to]').value || +last.querySelector('[data-pt-from]').value || 0) + 1;
      row.querySelector('[data-pt-from]').value = next;
      row.querySelector('[data-pt-to]').value = next;
      row.querySelectorAll('input:not([data-pt-from]):not([data-pt-to])').forEach((i) => { i.value = ''; });
      rows.appendChild(row); renumber(); total();
      row.querySelector('.pt-text').focus();
    }
    const del = e.target.closest('[data-pt-del]');
    if (del) {
      const all = rows.querySelectorAll('[data-pt-row]');
      if (all.length > 1) del.closest('[data-pt-row]').remove();
      else del.closest('[data-pt-row]').querySelectorAll('input:not([data-pt-from]):not([data-pt-to])').forEach((i) => { i.value = ''; });
      renumber(); total();
    }
  });
  box.addEventListener('input', (e) => {
    const r = e.target.closest('[data-pt-row]');
    if (r && e.target.matches('[data-pt-from]')) {
      const to = r.querySelector('[data-pt-to]');
      if (+to.value < +e.target.value) to.value = e.target.value;
    }
    total();
  });
  total();
})();

/* Fil : description repliée sur trois lignes. « Lire plus » n'apparaît que si le texte dépasse. */
(function () {
  const fit = (root) => (root || document).querySelectorAll('[data-readmore]:not([data-readmore-ok])').forEach((box) => {
    const p = box.querySelector('p'); const b = box.querySelector('[data-readmore-btn]');
    if (!p || !b || !p.offsetParent) return; // invisible pour l'instant : on réessaiera
    box.setAttribute('data-readmore-ok', '');
    b.hidden = p.scrollHeight <= p.clientHeight + 2;
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-readmore-btn]');
    if (!b) return;
    const box = b.closest('[data-readmore]');
    const open = box.classList.toggle('open');
    b.textContent = open ? 'Voir moins' : 'Voir plus';
  });
  new MutationObserver(() => fit()).observe(document.body, { childList: true, subtree: true });
  if (document.readyState !== 'loading') fit(); else document.addEventListener('DOMContentLoaded', () => fit());
  addEventListener('load', () => fit());
})();

/* Texte long replié : le texte est coupé à la fin d'une ligne, « … » collé au texte, puis « Voir plus » ouvre la suite par paliers */
(function () {
  const all = () => document.querySelectorAll('[data-rm]');
  const txt = (box) => box.querySelector('.rm-txt');
  const btn = (box) => box.querySelector('[data-rm-btn]');

  /* Position (nœud, décalage) après `idx` caractères du texte */
  function locate(root, idx) {
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    let acc = 0, n;
    while ((n = w.nextNode())) {
      const len = n.nodeValue.length;
      if (acc + len >= idx) return { node: n, offset: idx - acc };
      acc += len;
    }
    return null;
  }

  /* Les lignes réellement affichées : un haut distinct par ligne (tolérance d'une demi-ligne,
     pour ne pas compter deux fois une ligne qui mêle texte, lien de mention ou exposant) */
  function lineTops(root) {
    const r = document.createRange();
    r.selectNodeContents(root);
    const rects = Array.prototype.slice.call(r.getClientRects());
    if (!rects.length) return [];
    let lh = parseFloat(getComputedStyle(root).lineHeight);
    if (!isFinite(lh) || lh <= 0) lh = rects.reduce((m, x) => Math.max(m, x.height), 0) || 16;
    const tops = [];
    rects.forEach((rect) => {
      if (!tops.some((t) => Math.abs(t - rect.top) < lh / 2)) tops.push(rect.top);
    });
    return tops.sort((a, b) => a - b);
  }

  /* Nombre de caractères dont l'affichage tient dans `lines` lignes */
  function charsFor(root, lines) {
    const total = root.textContent.length;
    const tops = lineTops(root);
    if (tops.length <= lines) return total;
    const limit = tops[lines];
    let lo = 1, hi = total, best = 0;
    while (lo <= hi) {
      const mid = (lo + hi) >> 1;
      const loc = locate(root, mid);
      if (!loc) { hi = mid - 1; continue; }
      const rr = document.createRange();
      rr.setStart(root, 0);
      rr.setEnd(loc.node, loc.offset);
      if (rr.getBoundingClientRect().bottom <= limit + 0.5) { best = mid; lo = mid + 1; } else { hi = mid - 1; }
    }
    return best;
  }

  /* Garde les `chars` premiers caractères, supprime tout le reste */
  function cutAt(root, chars) {
    const loc = locate(root, chars);
    if (!loc) return;
    if (loc.node.nodeValue.length > loc.offset) loc.node.nodeValue = loc.node.nodeValue.slice(0, loc.offset);
    let cur = loc.node;
    while (cur && cur !== root) {
      let sib = cur.nextSibling;
      while (sib) { const nx = sib.nextSibling; sib.remove(); sib = nx; }
      cur = cur.parentNode;
    }
  }

  /* Enlève les espaces et retours à la ligne de la fin : ils ne doivent pas compter comme du texte caché */
  function trimTrailing(root) {
    while (root.lastChild && root.lastChild.nodeType === 3 && !root.lastChild.nodeValue.trim()) root.removeChild(root.lastChild);
    if (root.lastChild && root.lastChild.nodeType === 3) root.lastChild.nodeValue = root.lastChild.nodeValue.replace(/\s+$/, '');
  }

  function render(box, animate) {
    const st = box._rm, p = txt(box), b = btn(box);
    if (!st || !p || !b) return;
    const h0 = p.getBoundingClientRect().height;
    p.style.height = ''; p.style.overflow = ''; p.style.transition = ''; p.style.maxHeight = '';
    p.innerHTML = st.html;
    trimTrailing(p);
    const shown = Math.min(st.shown, st.total);
    let coupe = false;
    if (shown < st.total) {
      const n = charsFor(p, shown);
      if (n > 0 && n < st.len) {
        cutAt(p, n);
        trimTrailing(p);
        p.appendChild(document.createTextNode('…'));
        coupe = true;
      }
    }
    const done = !coupe; // plus rien à cacher : tout le texte est affiché
    box.classList.add('rm-ready');
    box.classList.toggle('rm-open', done);
    b.hidden = done && st.shown <= st.step;
    b.textContent = done && st.shown > st.step ? 'Voir moins' : 'Voir plus';
    if (animate) {
      const h1 = p.getBoundingClientRect().height;
      p.style.height = h0 + 'px';
      p.style.overflow = 'hidden';
      void p.offsetHeight;
      p.style.transition = 'height .45s cubic-bezier(.25,.7,.3,1)';
      p.style.height = h1 + 'px';
      p.addEventListener('transitionend', function end() {
        p.removeEventListener('transitionend', end);
        p.style.height = ''; p.style.overflow = ''; p.style.transition = '';
      });
    }
  }

  function measure(box) {
    const p = txt(box), b = btn(box);
    if (!p || !b || !p.offsetParent) return;
    const step = Math.max(1, parseInt(box.getAttribute('data-rm'), 10) || 4);
    const st = box._rm || (box._rm = { shown: 0 });
    st.step = step;
    if (!st.html) st.html = p.innerHTML;
    p.style.height = ''; p.style.overflow = ''; p.style.transition = '';
    p.innerHTML = st.html;
    trimTrailing(p);
    st.len = p.textContent.length;
    st.total = Math.max(1, lineTops(p).length);
    if (!st.shown || st.shown < step) st.shown = step;
    if (st.shown > st.total) st.shown = st.total;
    render(box, false);
  }

  function bind(box) {
    const b = btn(box);
    if (!b || box._rmBound) return;
    box._rmBound = true;
    b.addEventListener('click', () => {
      const st = box._rm;
      if (!st) return;
      const up = box.getBoundingClientRect().top < 0;
      st.shown = st.shown >= st.total ? st.step : Math.min(st.total, st.shown + st.step);
      render(box, true);
      if (up) box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }

  const fit = () => all().forEach((box) => {
    const p = txt(box);
    if (!p || !p.offsetParent) return;
    bind(box);
    if (!box._rm) measure(box);
  });
  new MutationObserver(() => fit()).observe(document.body, { childList: true, subtree: true });
  if (document.readyState !== 'loading') fit(); else document.addEventListener('DOMContentLoaded', fit);
  addEventListener('load', () => fit());
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => all().forEach((box) => { if (box._rm) measure(box); }));
  let rt;
  addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => all().forEach((box) => { if (box._rm) measure(box); }), 200); });
})();
/* Discipline : la fenêtre s'ouvre, on tape pour chercher (sans tenir compte des accents), on touche pour choisir */
(function () {
  const plain = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  document.querySelectorAll('[data-cat-field]').forEach((box) => {
    const input = box.querySelector('[data-cat-input]');
    const btn = box.querySelector('[data-cat-open]');
    const label = box.querySelector('[data-cat-label]');
    const sheet = box.querySelector('[data-cat-sheet]');
    const q = sheet.querySelector('[data-cat-q]');
    const empty = sheet.querySelector('[data-cat-empty]');
    const opts = Array.from(sheet.querySelectorAll('[data-cat-opt]'));
    function filter() {
      const term = plain(q.value);
      let shown = 0;
      opts.forEach((o) => {
        const ok = !term || (o.dataset.catOpt !== '' && plain(o.textContent).includes(term));
        o.parentElement.hidden = !ok; if (ok) shown++;
      });
      sheet.querySelectorAll('[data-cat-group]').forEach((g) => { g.hidden = !!term; });
      empty.hidden = shown > 0;
    }
    btn.addEventListener('click', () => {
      q.value = ''; filter();
      sheet.showModal();
      const on = sheet.querySelector('[aria-selected="true"]');
      if (on && on.dataset.catOpt) on.scrollIntoView({ block: 'center' });
      // Sur téléphone, le clavier ne s'ouvre pas d'office : on voit d'abord la liste
      if (window.matchMedia('(hover: hover)').matches) q.focus();
    });
    q.addEventListener('input', filter);
    q.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const first = opts.find((o) => !o.parentElement.hidden && o.dataset.catOpt !== '');
      if (first) first.click();
    });
    sheet.addEventListener('click', (e) => {
      if (e.target === sheet) { sheet.close(); return; } // toucher à côté ferme
      const o = e.target.closest('[data-cat-opt]');
      if (!o) return;
      input.name = 'category';
      input.value = o.dataset.catOpt;
      opts.forEach((x) => x.setAttribute('aria-selected', x === o ? 'true' : 'false'));
      label.textContent = o.dataset.catOpt ? o.querySelector('span').textContent : 'Choisir (facultatif)';
      btn.classList.toggle('is-set', !!o.dataset.catOpt);
      input.dispatchEvent(new Event('change', { bubbles: true }));
      sheet.close();
    });
  });
})();

/* Commentaire : viser un duel précis du défi (« X contre Y »), ou plus aucun */
document.addEventListener('click', (e) => {
  const form = e.target.closest('[data-cm-form]');
  if (!form) return;
  const list = form.querySelector('[data-cm-duels]');
  const chip = form.querySelector('[data-cm-duel-chip]');
  const id = form.querySelector('[data-cm-duel-id]');
  if (e.target.closest('[data-cm-duel-open]')) { if (list) list.hidden = !list.hidden; return; }
  const pick = e.target.closest('[data-cm-duel]');
  if (pick) {
    id.value = pick.dataset.cmDuel;
    chip.querySelector('[data-cm-duel-label]').textContent = pick.dataset.label;
    chip.hidden = false; list.hidden = true;
    form.querySelector('input[name="content"]')?.focus();
    return;
  }
  if (e.target.closest('[data-cm-duel-clear]')) { id.value = ''; chip.hidden = true; }
});

/* Bravos : des applaudissements montent et remplissent le côté choisi, puis disparaissent */
window.tokosCheer = function (el, list) {
  if (!el) return;
  const box = document.createElement('div');
  box.className = 'cheer'; box.setAttribute('aria-hidden', 'true');
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const h = el.clientHeight || 200;
  // Par défaut des bravos ; une réaction ou un j'aime envoie son propre émoji
  const emojis = list && list.length ? list : ['👏', '👏', '👏', '🙌', '🔥', '👏', '🙌'];
  const n = still ? 0 : Math.min(26, Math.max(12, Math.round(el.clientWidth * h / 5200)));
  for (let i = 0; i < n; i++) {
    const it = document.createElement('i');
    it.textContent = emojis[i % emojis.length];
    it.style.left = (Math.random() * 92) + '%';
    it.style.fontSize = (22 + Math.random() * 18) + 'px';
    it.style.animationDelay = (Math.random() * 0.45) + 's';
    it.style.setProperty('--h', (h + 60) + 'px');
    it.style.setProperty('--r', ((Math.random() * 40) - 20) + 'deg');
    box.appendChild(it);
  }
  el.appendChild(box);
  if (navigator.vibrate) { try { navigator.vibrate(18); } catch (e) { /* rien */ } }
  setTimeout(() => box.remove(), 2100);
};

/* Pseudo : un seul changement possible, on demande de confirmer avant d'enregistrer */
document.addEventListener('submit', (e) => {
  const input = e.target.querySelector && e.target.querySelector('[data-username-once]');
  if (!input) return;
  const before = input.dataset.usernameOnce.toLowerCase();
  const now = input.value.trim();
  if (!now || now.toLowerCase() === before) return;
  if (!window.confirm('Changer ton pseudo en @' + now + ' ?\n\nTu ne pourras le faire qu\'une seule fois. Ton ancien pseudo mènera à ton profil.')) e.preventDefault();
}, true);

// Formulaires à confirmer (ex. retirer un membre d'un Espace)
document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-confirm]');
  if (f && !window.confirm(f.dataset.confirm)) e.preventDefault();
}, true);

// Onglets simples (ex. Compétitions en cours / terminées du profil)
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-tabs] [data-tab]');
  if (!b) return;
  const box = b.closest('[data-tabs]');
  box.querySelectorAll('[data-tab]').forEach((x) => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
  box.querySelectorAll('[data-pane]').forEach((p) => { p.hidden = p.dataset.pane !== b.dataset.tab; });
});

/* Mentions « @ » : en écrivant @ suivi de quelques lettres dans une publication ou un commentaire,
   une liste de personnes et d'Espaces s'ouvre ; le choix insère leur identifiant (@pseudo). */
(function () {
  'use strict';
  const FIELDS = 'textarea[data-emoji], input[data-emoji], textarea[data-mention], input[data-mention]';
  let list = null; let field = null; let items = []; let active = 0; let timer = null; let seq = 0;

  const ensure = () => {
    if (list) return list;
    list = document.createElement('ul');
    list.className = 'suggest-list mention-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    document.body.appendChild(list);
    return list;
  };
  const close = () => { if (list) list.hidden = true; items = []; field = null; };
  // Le mot « @abc » juste avant le curseur
  const current = (el) => {
    const pos = el.selectionStart ?? el.value.length;
    const m = el.value.slice(0, pos).match(/(^|[\s(«"'])@([\w.\-]{1,40})$/u);
    return m ? { q: m[2], start: pos - m[2].length - 1, end: pos } : null;
  };
  const place = () => {
    if (!field || !list) return;
    const r = field.getBoundingClientRect();
    const below = window.innerHeight - r.bottom > 220 || r.top < 240;
    list.style.position = 'fixed';
    list.style.left = Math.max(8, r.left) + 'px';
    list.style.width = Math.min(360, Math.max(240, r.width)) + 'px';
    list.style.right = 'auto';
    if (below) { list.style.top = (r.bottom + 4) + 'px'; list.style.bottom = 'auto'; } else { list.style.bottom = (window.innerHeight - r.top + 4) + 'px'; list.style.top = 'auto'; }
  };
  const render = () => {
    ensure().innerHTML = '';
    items.forEach((p, i) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.className = i === active ? 'active' : '';
      const img = document.createElement('img'); img.src = p.avatar_url || ''; img.alt = ''; img.className = 'avatar sm' + (p.type === 'space' ? ' space' : '');
      const txt = document.createElement('span');
      const b = document.createElement('b'); b.textContent = p.name;
      const sm = document.createElement('small'); sm.textContent = '@' + p.handle + (p.type === 'space' ? ' · Espace' : '');
      txt.append(b, sm); li.append(img, txt);
      li.addEventListener('mousedown', (ev) => { ev.preventDefault(); choose(p); });
      list.appendChild(li);
    });
    list.hidden = !items.length;
    place();
  };
  const choose = (p) => {
    const el = field; const w = el && current(el);
    if (!w) return close();
    const insert = '@' + p.handle + ' ';
    el.value = el.value.slice(0, w.start) + insert + el.value.slice(w.end);
    const at = w.start + insert.length;
    el.setSelectionRange(at, at);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    close();
    el.focus();
  };

  document.addEventListener('input', (e) => {
    const el = e.target;
    if (!el.matches || !el.matches(FIELDS)) return;
    const w = current(el);
    clearTimeout(timer);
    if (!w) { if (field === el) close(); return; }
    field = el;
    const mine = ++seq;
    timer = setTimeout(async () => {
      try {
        const r = await fetch('/recherche/participants?q=' + encodeURIComponent(w.q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!r.ok || mine !== seq) return;
        const j = await r.json();
        items = (j.data || []).slice(0, 8); active = 0;
        if (field === el) render();
      } catch (err) { /* hors ligne : pas de liste */ }
    }, 180);
  });
  document.addEventListener('keydown', (e) => {
    if (!list || list.hidden || e.target !== field) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length; render(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { if (items[active]) { e.preventDefault(); e.stopPropagation(); choose(items[active]); } }
    else if (e.key === 'Escape') close();
  }, true);
  document.addEventListener('focusout', (e) => { if (e.target === field) setTimeout(close, 150); });
  window.addEventListener('scroll', place, { passive: true });
  window.addEventListener('resize', place);

  // Choix d'un fichier (remise du prix) : afficher son nom
  document.addEventListener('change', (e) => {
    const inp = e.target.closest && e.target.closest('[data-file-input]');
    const label = inp && inp.closest('label')?.querySelector('[data-file-label]');
    if (label && inp.files?.length) label.textContent = Array.from(inp.files).map((f) => f.name).join(', ');
  });
})();

/* Versus (deux choix) : les deux choix ont le même format. Un média d'un côté n'accepte que le même type de l'autre ;
   on ne publie pas « texte contre vidéo ». (Le serveur vérifie aussi.) */
(function () {
  'use strict';
  const kindOf = (file) => !file ? null : (file.type.startsWith('video/') ? 'video' : file.type.startsWith('audio/') ? 'audio' : file.type.startsWith('image/') ? 'image' : null);
  const ACCEPT = { video: 'video/*', audio: 'audio/*', image: 'image/*' };
  const WORD = { video: 'une vidéo', audio: 'un audio', image: 'une photo' };
  const inputs = (form) => ['a', 'b'].map((k) => form.querySelector('input[type="file"][name^="side_' + k + '_media"]'));
  document.addEventListener('change', (e) => {
    const inp = e.target;
    if (!inp.matches || !inp.matches('input[type="file"][name^="side_"]')) return;
    const form = inp.closest('form');
    const [a, b] = inputs(form);
    if (!a || !b) return;
    if (!a.dataset.acceptAll) { a.dataset.acceptAll = a.accept || ''; b.dataset.acceptAll = b.accept || ''; }
    const ka = kindOf(a.files?.[0]); const kb = kindOf(b.files?.[0]);
    // L'autre côté ne propose plus que le même type
    b.accept = ka ? ACCEPT[ka] : b.dataset.acceptAll;
    a.accept = kb ? ACCEPT[kb] : a.dataset.acceptAll;
  });
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form.querySelector || !form.querySelector('[data-qc-format]')) return;
    if (form.querySelector('[data-qc-format]').value !== 'versus') return;
    const [a, b] = inputs(form);
    if (!a || !b) return;
    // Sans média, un choix est en texte (son nom, et son texte s'il y en a un)
    const ka = kindOf(a.files?.[0]) || 'text'; const kb = kindOf(b.files?.[0]) || 'text';
    if (ka !== kb) {
      e.preventDefault(); e.stopImmediatePropagation();
      const need = ka !== 'text' ? ka : kb;
      (window.tokosToast || alert)('Les deux choix doivent avoir le même format : ajoute ' + WORD[need] + ' ' + (ka !== 'text' ? 'au choix 2' : 'au choix 1') + ', ou retire le média.', true);
    }
  }, true);
})();


/* Duel : défier plusieurs personnes d'un coup. Chaque adversaire choisi (@pseudo, #espace ou numéro) devient une étiquette ;
   à l'envoi, chacun reçoit son propre duel. */
(function () {
  'use strict';
  document.querySelectorAll('[data-opp-chips]').forEach((box) => {
    const form = box.closest('form');
    const input = form.querySelector('input[name="opponent"]');
    const type = form.querySelector('input[name="opponent_type"]');
    const MAX = 10;
    const has = (v) => Array.from(box.querySelectorAll('input')).some((i) => i.value.toLowerCase() === v.toLowerCase());
    const add = (value, label) => {
      if (!value || has(value)) return;
      if (box.querySelectorAll('input').length >= MAX) { (window.tokosToast || alert)('Tu peux défier jusqu\'à ' + MAX + ' personnes à la fois.', true); return; }
      const chip = document.createElement('span'); chip.className = 'opp-chip';
      const t = document.createElement('span'); t.textContent = label || value;
      const x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Retirer'); x.textContent = '×';
      const h = document.createElement('input'); h.type = 'hidden'; h.name = 'opponents[]'; h.value = value;
      x.addEventListener('click', () => { chip.remove(); box.hidden = !box.children.length; });
      chip.append(t, x, h); box.appendChild(chip); box.hidden = false;
    };
    // Un nom choisi dans la liste (ou tapé puis validé) devient une étiquette, et le champ se vide pour le suivant
    input?.addEventListener('change', () => {
      const v = input.value.trim().replace(/^[@#]/, '');
      if (!v) return;
      const sp = (type && type.value === 'space') || input.value.trim().startsWith('#');
      add((sp ? '#' : '@') + v);
      input.value = '';
      if (type) type.value = 'user';
    });
    input?.addEventListener('keydown', (e) => {
      if ((e.key === ',' || (e.key === 'Enter' && !form.querySelector('[data-suggest-list]:not([hidden])'))) && input.value.trim()) {
        e.preventDefault(); input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    // Numéro de téléphone : « Ajouter ce numéro »
    form.querySelector('[data-add-phone]')?.addEventListener('click', () => {
      const code = form.querySelector('[name="opponent_phone_code"]'); const num = form.querySelector('[name="opponent_phone"]');
      const digits = (num?.value || '').replace(/\D+/g, '').replace(/^0+/, '');
      if (digits.length < 6) { (window.tokosToast || alert)('Indique un numéro complet.', true); num?.focus(); return; }
      const c = (code?.value || '+243').trim();
      add(c + digits, c + ' ' + digits);
      num.value = ''; num.focus();
    });
  });
})();
