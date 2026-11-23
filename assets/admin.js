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
