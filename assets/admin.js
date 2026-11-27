/* Panel de administración */
(function () {
  'use strict';

  // Confirmaciones (sin JS en línea por la CSP)
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Selector deslizante del tema: se aplica al cambiarlo
  document.querySelectorAll('form[data-autosubmit] input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var f = cb.form;
      f.requestSubmit ? f.requestSubmit() : f.submit();
    });
  });

  // Estadísticas: las fechas solo aparecen con «Personalizado»
  var sf = document.querySelector('[data-stats-filter]');
  if (sf) {
    [['preset', '[data-preset]'], ['cmp', '[data-cmp]']].forEach(function (pair) {
      var sel = sf.querySelector(pair[1]);
      var sync = function () {
        sf.querySelectorAll('[data-dates="' + pair[0] + '"]').forEach(function (l) { l.hidden = sel.value !== 'custom'; });
      };
      sel.addEventListener('change', sync);
    });
  }

  // Subida de vídeo con indicador de progreso (va antes que «Evitar doble envío»: si se encarga del envío,
  // lo marca con preventDefault y ese manejador ya no hace nada).
  function fmtMB(b) { return (b / 1048576).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); }
  document.querySelectorAll('input[type=file][name=video_file]').forEach(function (input) {
    var f = input.form;
    if (!f || !window.XMLHttpRequest || !window.FormData) return; // sin soporte: envío normal
    var uploading = false;

    // Aviso inmediato si el archivo supera el límite (sin esperar a subirlo entero)
    input.addEventListener('change', function () {
      var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
      var file = input.files && input.files[0];
      if (file && max && file.size > max) {
        window.alert('El vídeo pesa ' + fmtMB(file.size) + ' MB y el máximo es ' + fmtMB(max) + ' MB.');
        input.value = '';
      }
    });

    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var file = input.files && input.files[0];
      if (!file) return; // sin vídeo nuevo: envío normal
      e.preventDefault();
      if (uploading) return;
      uploading = true;

      var btns = f.querySelectorAll('button');
      btns.forEach(function (b) { b.disabled = true; });
      var box = f.querySelector('.upload-progress');
      if (!box) {
        box = document.createElement('div');
        box.className = 'upload-progress';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');
        var bar = document.createElement('div'); bar.className = 'bar';
        var fill = document.createElement('span'); bar.appendChild(fill);
        var txt = document.createElement('p'); txt.className = 'txt';
        box.appendChild(bar); box.appendChild(txt);
        var submitBtn = f.querySelector('button.primary');
        (submitBtn ? submitBtn.parentNode : f).insertBefore(box, submitBtn || null);
      }
      var fillEl = box.querySelector('.bar span'), txtEl = box.querySelector('.txt');
      box.classList.remove('err', 'done');
      var set = function (pct, text) { fillEl.style.width = pct + '%'; txtEl.textContent = text; };
      set(0, 'Preparando la subida…');

      var leaveGuard = function (ev) { ev.preventDefault(); ev.returnValue = ''; };
      window.addEventListener('beforeunload', leaveGuard);
      var t0 = Date.now();
      var fail = function (msg) {
        uploading = false;
        window.removeEventListener('beforeunload', leaveGuard);
        box.classList.add('err');
        set(100, msg);
        btns.forEach(function (b) { b.disabled = false; });
      };

      var xhr = new XMLHttpRequest();
      xhr.open('POST', f.getAttribute('action') || location.href);
      xhr.setRequestHeader('X-Upload-Progress', '1');
      xhr.upload.onprogress = function (ev) {
        if (!ev.lengthComputable) { set(50, 'Subiendo vídeo… ' + fmtMB(ev.loaded) + ' MB'); return; }
        var pct = Math.min(100, Math.round(ev.loaded / ev.total * 100));
        var secs = (Date.now() - t0) / 1000, speed = secs > 0.5 ? ev.loaded / secs : 0;
        var left = speed > 0 ? Math.max(0, Math.round((ev.total - ev.loaded) / speed)) : null;
        set(pct, 'Subiendo vídeo… ' + pct + ' % (' + fmtMB(ev.loaded) + ' de ' + fmtMB(ev.total) + ' MB'
          + (left !== null && pct < 100 ? ', quedan ~' + (left >= 60 ? Math.ceil(left / 60) + ' min' : left + ' s') : '') + ')');
      };
      xhr.upload.onload = function () { set(100, 'Vídeo subido. Comprobando y guardando…'); };
      xhr.onload = function () {
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (err) {}
        if (xhr.status >= 200 && xhr.status < 300 && data && data.redirect) {
          box.classList.add('done');
          set(100, 'Guardado. Recargando…');
          window.removeEventListener('beforeunload', leaveGuard);
          var u = new URL(data.redirect, location.href);
          if (u.pathname + u.search === location.pathname + location.search) {
            // misma página (solo cambia el #): cambiar el ancla no recarga, así que se fuerza
            if (u.hash) history.replaceState(null, '', u.hash);
            location.reload();
          } else {
            location.href = u.href;
          }
          return;
        }
        fail('No se pudo guardar: ' + ((xhr.responseText || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 200) || ('error ' + xhr.status)));
      };
      xhr.onerror = function () { fail('Se cortó la conexión durante la subida. Inténtelo de nuevo.'); };
      xhr.send(new FormData(f));
    });
  });

  // Evitar doble envío
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      setTimeout(function () { f.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0);
    });
  });

  // Tamaño de cada texto central: mover el control pasa a Manual; AUTO pasa a Auto y guarda
  document.querySelectorAll('.ctext-block').forEach(function (blk) {
    var range = blk.querySelector('[data-size]');
    var out = blk.querySelector('[data-size-out]');
    var fixedRadio = blk.querySelector('[data-mode="fixed"]');
    var autoRadio = blk.querySelector('[data-mode="auto"]');
    range.addEventListener('input', function () {
      out.textContent = range.value;
      fixedRadio.checked = true;
    });
    blk.querySelector('[data-auto]').addEventListener('click', function () {
      autoRadio.checked = true;
      var f = autoRadio.form;
      f.requestSubmit ? f.requestSubmit() : f.submit();
    });
  });

  // Vista previa de iconos integrados
  function drawPreview(span, name) {
    if (!span || span.querySelector('img') || !window.kioskIcon) return;
    span.textContent = '';
    span.appendChild(window.kioskIcon(name));
  }
  document.querySelectorAll('.icon-preview').forEach(function (s) { drawPreview(s, s.getAttribute('data-icon')); });
  document.querySelectorAll('.icon-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var card = sel.closest('.icon-card');
      drawPreview(card && card.querySelector('.icon-preview'), sel.value);
    });
  });

  // Vista previa del kiosco
  var box = document.getElementById('previewBox');
  var frame = document.getElementById('preview');
  var orient = document.getElementById('orientBtn');
  var reload = document.getElementById('reloadPreview');
  try {
    if (localStorage.getItem('wk-orient') === 'landscape') { box.classList.replace('portrait', 'landscape'); }
  } catch (e) {}
  if (orient) orient.addEventListener('click', function () {
    var land = box.classList.contains('portrait');
    box.classList.toggle('portrait', !land);
    box.classList.toggle('landscape', land);
    try { localStorage.setItem('wk-orient', land ? 'landscape' : 'portrait'); } catch (e) {}
  });
  if (reload) reload.addEventListener('click', function () {
    frame.src = frame.src.split('?')[0] + '?preview=1&t=' + Date.now();
  });
})();
