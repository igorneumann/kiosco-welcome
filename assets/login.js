/* Pantallas de acceso: botón «Ver contraseña» en cada campo de contraseña (sin JS en línea, por la CSP) */
(function () {
  'use strict';
  var EYE = 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z';
  var EYE_OFF = 'M3 3l18 18M10.6 5.1A10.9 10.9 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a10.6 10.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2';
  var NS = 'http://www.w3.org/2000/svg';
  function icon(d) {
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS(NS, 'path');
    p.setAttribute('d', d);
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke', 'currentColor');
    p.setAttribute('stroke-width', '1.8');
    p.setAttribute('stroke-linecap', 'round');
    p.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(p);
    return svg;
  }
  document.querySelectorAll('input[type=password]').forEach(function (input) {
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.setAttribute('aria-label', 'Ver contraseña');
    btn.setAttribute('aria-pressed', 'false');
    btn.title = 'Ver contraseña';
    btn.appendChild(icon(EYE));
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Ver contraseña');
      btn.title = btn.getAttribute('aria-label');
      btn.replaceChild(icon(show ? EYE_OFF : EYE), btn.firstChild);
      input.focus();
    });
    wrap.appendChild(btn);
    // Al enviar, vuelve a ocultarla (evita que el navegador la guarde como texto en el historial del formulario)
    if (input.form) input.form.addEventListener('submit', function () { input.type = 'password'; });
  });
})();
