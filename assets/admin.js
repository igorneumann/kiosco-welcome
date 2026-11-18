/* Panel de administración */
(function () {
  'use strict';

  // Confirmaciones (sin JS en línea por la CSP)
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Evitar doble envío
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      setTimeout(function () { f.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0);
    });
  });

  // Tamaño del texto central
  var range = document.getElementById('sizeRange');
  var out = document.getElementById('sizeOut');
  var fixedRadio = document.querySelector('input[name="center_font_mode"][value="fixed"]');
  var autoRadio = document.querySelector('input[name="center_font_mode"][value="auto"]');
  if (range && out) {
    range.addEventListener('input', function () {
      out.textContent = range.value;
      if (fixedRadio) fixedRadio.checked = true;
    });
  }
  var autoBtn = document.getElementById('autoBtn');
  if (autoBtn && autoRadio) {
    autoBtn.addEventListener('click', function () {
      autoRadio.checked = true;
      autoRadio.form.requestSubmit ? autoRadio.form.requestSubmit() : autoRadio.form.submit();
    });
  }

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
