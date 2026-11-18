/* Kiosco welcome.bezares.com */
(function () {
  'use strict';

  var API = 'api/kiosk-config.php';
  var POLL_MS = 60000;           // comprobar cambios cada minuto
  var CACHE_KEY = 'wk-config';   // copia local para funcionar sin red

  var cfg = null;
  var welcomeTimer = null, welcomeSwap = null;
  var modal = null, pending = null, idleTimer = null;
  var $ = function (s) { return document.querySelector(s); };

  /* ---------------- Carga de configuración ---------------- */
  function load() {
    return fetch(API + '?t=' + Date.now(), { cache: 'no-store', credentials: 'omit' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
  }

  function saveLocal(c) { try { localStorage.setItem(CACHE_KEY, JSON.stringify(c)); } catch (e) {} }
  function readLocal() { try { return JSON.parse(localStorage.getItem(CACHE_KEY) || 'null'); } catch (e) { return null; } }

  function cssUrl(p) { return 'url("' + encodeURI(p).replace(/"/g, '%22') + '")'; }

  /* ---------------- Pintar ---------------- */
  function apply(c) {
    cfg = c;
    var r = document.documentElement.style;
    r.setProperty('--page-bg', c.page_bg);
    r.setProperty('--welcome-color', c.welcome_color);
    r.setProperty('--text-color', c.text_color);
    r.setProperty('--line-color', c.line_color);
    r.setProperty('--footer-bg', c.footer_bg);
    r.setProperty('--footer-fg', c.footer_fg);
    r.setProperty('--footer-strip', c.footer_strip || 'transparent');
    r.setProperty('--footer-strip-h', (c.footer_strip_height || 0) + 'vh');
    r.setProperty('--footer-title-color', c.footer_title_color || '#12100b');

    var ft = $('#footer-title');
    ft.textContent = c.footer_title || '';
    ft.hidden = !(c.footer_title || '').trim();
    r.setProperty('--pulse-scale', String(c.bg_pulse_scale || 1));
    r.setProperty('--pulse-duration', (c.bg_pulse_duration || 2.4) + 's');

    $('#bg').style.backgroundImage = c.background_image ? cssUrl(c.background_image) : 'none';

    var header = $('#header');
    if (c.header_image) {
      var himg = header.querySelector('img');
      himg.onload = fit;
      himg.src = c.header_image;
      header.hidden = false;
    }
    else { header.hidden = true; }

    startWelcome(c.welcome_texts || [], c.welcome_interval || 3, [c.welcome_color, c.welcome_color2 || c.welcome_color]);

    var t1 = c.center_text || '', t2 = c.center_text2 || '';
    $('#center-text').textContent = t1;
    $('#center-text2').textContent = t2;
    $('#center-text').hidden = t1.trim() === '';
    $('#center-text2').hidden = t2.trim() === '';
    var has = t1.trim() !== '' || t2.trim() !== '';
    $('#center').hidden = !has;
    $('#deco2').hidden = !has;

    var logo = $('#logo img');
    logo.onload = fit;
    logo.src = c.logo;

    buildFooter(c.icons || []);
    fit();
  }

  /* ---------------- Welcome / Bienvenidos ---------------- */
  function startWelcome(texts, interval, colors) {
    clearInterval(welcomeTimer); clearTimeout(welcomeSwap);
    var el = $('#welcome-text');
    if (!texts.length) texts = ['Welcome!'];
    var i = 0;
    el.textContent = texts[0];
    el.style.color = colors[0];
    el.className = 'show';
    if (texts.length < 2) return;
    welcomeTimer = setInterval(function () {
      el.className = 'hide';
      welcomeSwap = setTimeout(function () {
        i = (i + 1) % texts.length;
        el.textContent = texts[i];
        el.style.color = colors[i % 2];
        el.className = 'show';
      }, 600);
    }, Math.max(1.5, interval) * 1000);
  }

  /* ---------------- Tamaño de los textos centrales ---------------- */
  // Cada texto tiene su modo (auto/manual), tamaño y negrita. Los automáticos se ajustan al ancho;
  // si entre todos no caben en el alto disponible, los automáticos se reducen en proporción.
  function fit() {
    if (!cfg) return;
    var center = $('#center');
    if (center.hidden) return;

    var items = [
      { el: $('#center-text'),  mode: cfg.center_font_mode,  size: cfg.center_font_size,  bold: cfg.center_bold },
      { el: $('#center-text2'), mode: cfg.center_font_mode2, size: cfg.center_font_size2, bold: cfg.center_bold2 }
    ].filter(function (it) { return !it.el.hidden; });

    var autos = [];
    items.forEach(function (it) {
      it.el.classList.toggle('bold', !!it.bold);
      if (it.mode === 'auto') {
        it.el.classList.add('auto');
        it.el.style.fontSize = '8px';
        autos.push(it);
      } else {
        it.el.classList.remove('auto');
        it.el.style.fontSize = (it.size || 6) + 'vw';
      }
    });
    if (!autos.length) return;

    var main = $('#main'), cs = getComputedStyle(main);
    var availW = main.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    var minDeco = parseFloat(getComputedStyle($('#deco1')).minHeight) || 0; // alto mínimo de cada línea decorativa
    var gap = items.length > 1 ? parseFloat(getComputedStyle(center).rowGap) || 0 : 0;
    var availH = main.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom)
               - $('#welcome').offsetHeight - $('#logo').offsetHeight - 2 * minDeco - gap;
    var fixedH = 0;
    items.forEach(function (it) { if (it.mode !== 'auto') fixedH += it.el.offsetHeight; });
    var roomH = Math.max(availH - fixedH, 20);

    // 1) Tamaño máximo de cada texto automático por separado (ancho y alto disponibles)
    autos.forEach(function (it) {
      var lo = 8, hi = Math.max(roomH, 9);
      for (var n = 0; n < 22; n++) {
        var mid = (lo + hi) / 2;
        it.el.style.fontSize = mid + 'px';
        var b = it.el.getBoundingClientRect();
        if (b.width <= availW && b.height <= roomH) lo = mid; else hi = mid;
      }
      it.px = Math.floor(lo);
      it.el.style.fontSize = it.px + 'px';
    });

    // 2) Si juntos no caben en el alto, reducir todos en proporción
    var sumH = 0;
    autos.forEach(function (it) { sumH += it.el.offsetHeight; });
    if (sumH > roomH) {
      var k = roomH / sumH;
      autos.forEach(function (it) { it.px = Math.max(8, Math.floor(it.px * k)); it.el.style.fontSize = it.px + 'px'; });
    }

    // 3) Seguridad: si aún desborda (imágenes cargando, etc.), reducir hasta que quepa
    var guard = 0;
    while (guard++ < 200 && (center.scrollHeight > center.clientHeight + 1 || main.scrollHeight > main.clientHeight + 1)) {
      var shrunk = false;
      autos.forEach(function (it) { if (it.px > 8) { it.px -= 2; it.el.style.fontSize = it.px + 'px'; shrunk = true; } });
      if (!shrunk) break;
    }
  }

  /* ---------------- Rodapié ---------------- */
  function buildFooter(icons) {
    var box = $('#footer-icons');
    box.textContent = '';
    icons.forEach(function (ic) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ficon';
      if (ic.icon_image) {
        var img = new Image(); img.src = ic.icon_image; img.alt = ''; b.appendChild(img);
      } else {
        b.appendChild(window.kioskIcon(ic.icon));
      }
      if (ic.label) {
        var l = document.createElement('span'); l.className = 'lbl'; l.textContent = ic.label; b.appendChild(l);
      }
      b.setAttribute('aria-label', ic.label || ic.icon);
      if (ic.icon_image) b.querySelector('img').onload = fit;
      b.addEventListener('click', function (ev) { ev.stopPropagation(); openModal(ic); });
      box.appendChild(b);
    });
  }

  /* ---------------- Ventanas emergentes ---------------- */
  function openModal(ic) {
    closeModal();
    var full = ic.display === 'fullscreen';
    var ov = document.createElement('div');
    ov.className = 'overlay ' + (full ? 'full' : 'popup');

    var box = document.createElement('div');
    box.className = 'box';
    var fr = document.createElement('iframe');
    fr.title = ic.label || '';
    fr.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

    if (ic.type === 'youtube') {
      box.classList.add('video');
      fr.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(ic.youtube_id) +
               '?autoplay=1&rel=0&playsinline=1&modestbranding=1';
      fr.allow = 'autoplay; encrypted-media; fullscreen; picture-in-picture';
      fr.setAttribute('allowfullscreen', '');
      fr.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
    } else {
      fr.src = ic.url;
      // Sin allow-top-navigation: la web cargada no puede sacar al kiosco de esta página
      fr.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms');
    }

    if (!full) {
      box.style.width = ic.width || '80%';
      if (ic.type !== 'youtube' || ic.height !== 'auto') {
        fr.style.height = (ic.height && ic.height !== 'auto') ? ic.height : '80vh';
        if (ic.type === 'youtube') fr.style.aspectRatio = 'auto';
      }
    }
    box.appendChild(fr);
    ov.appendChild(box);

    var btn = document.createElement('button');
    btn.type = 'button';
    var pos = ic.close_position || 'bottom-center';
    var flow = !full && pos === 'bottom-center';
    btn.className = 'close size-' + (ic.close_size || 'large') + ' pos-' + pos + (flow ? ' flow' : ' fixed');
    btn.textContent = ic.close_text || '✕';
    btn.style.background = ic.close_bg || '#e30613';
    btn.style.color = ic.close_fg || '#fff';
    btn.addEventListener('click', closeModal);
    ov.appendChild(btn);

    if (!full) {
      ov.addEventListener('click', function (e) { if (e.target === ov) closeModal(); });
    }
    ov.addEventListener('pointerdown', bumpIdle);
    document.body.appendChild(ov);
    modal = ov;
    bumpIdle();
  }

  function closeModal() {
    clearTimeout(idleTimer);
    if (modal) { modal.remove(); modal = null; }
    if (pending) { var p = pending; pending = null; apply(p); }
  }

  function bumpIdle() {
    clearTimeout(idleTimer);
    if (modal && cfg && cfg.popup_idle_close > 0) {
      idleTimer = setTimeout(closeModal, cfg.popup_idle_close * 1000);
    }
  }
  // Al tocar dentro del iframe la ventana pierde el foco: lo contamos como actividad
  window.addEventListener('blur', bumpIdle);

  /* ---------------- 3 clics en el fondo = recarga sin caché ---------------- */
  // Cuenta los clics en cualquier zona de fondo de la página o del rodapié (no en los iconos
  // ni dentro de una ventana abierta). 3 clics en menos de 2 segundos recargan.
  var clicks = [];
  document.addEventListener('click', function (e) {
    if (modal) return;
    if (e.target.closest && e.target.closest('.ficon, button, a, .overlay')) return;
    var now = Date.now();
    clicks = clicks.filter(function (t) { return now - t < 2000; });
    clicks.push(now);
    if (clicks.length >= 3) { clicks = []; hardReload(); }
  });

  function hardReload() {
    $('#reloading').hidden = false;
    var tasks = [];
    try { localStorage.removeItem(CACHE_KEY); } catch (e) {}
    if (window.caches && caches.keys) {
      tasks.push(caches.keys().then(function (ks) { return Promise.all(ks.map(function (k) { return caches.delete(k); })); }));
    }
    ['assets/kiosk.css', 'assets/kiosk.js', 'assets/icons.js', location.pathname].forEach(function (u) {
      tasks.push(fetch(u, { cache: 'reload' }).catch(function () {}));
    });
    Promise.all(tasks).catch(function () {}).then(function () {
      location.replace(location.pathname + '?r=' + Date.now());
    });
  }

  /* ---------------- Bloqueos propios de kiosco ---------------- */
  document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  document.addEventListener('dragstart', function (e) { e.preventDefault(); });
  document.addEventListener('gesturestart', function (e) { e.preventDefault(); }); // pellizco (iOS)
  // El zoom por doble toque se desactiva con CSS (touch-action: manipulation).

  /* ---------------- Arranque ---------------- */
  var resizeT;
  window.addEventListener('resize', function () { clearTimeout(resizeT); resizeT = setTimeout(fit, 150); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);

  function start() {
    load().then(function (c) { saveLocal(c); apply(c); })
      .catch(function () {
        var local = readLocal();
        if (local && !cfg) apply(local);
        setTimeout(start, 10000);
      });
  }
  start();

  setInterval(function () {
    load().then(function (c) {
      if (!cfg || c.version !== cfg.version) {
        saveLocal(c);
        if (modal) pending = c; else apply(c);
      }
    }).catch(function () {});
  }, POLL_MS);
})();
