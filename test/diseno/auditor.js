/**
 * Auditoría de diseño: mide la página en un navegador de verdad y escribe los
 * hallazgos en document.title, de donde los lee el proceso que la capturó.
 *
 * Mirar capturas no alcanza: una diferencia de 2px entre un campo y el botón de
 * al lado no se ve en una imagen reducida, pero sí se ve en la pantalla. Esto lo
 * mide.
 */
(function () {
  'use strict';

  // La pagina carga componentes.js con `defer`: ese corre despues de parsear.
  // Un script inyectado en el body corre DURANTE el parseo, o sea antes, y
  // medía la pantalla sin mejorar. Hay que esperar a que termine todo.
  window.addEventListener('load', function () { setTimeout(medir, 80); });

  function medir() {

  var hallazgos = [];
  function anotar(tipo, detalle) { hallazgos.push(tipo + ': ' + detalle); }

  /**
   * Visible de verdad.
   *
   * No alcanza con mirar display y visibility: los controles nativos que
   * reemplaza la capa de componentes siguen en el DOM ocultos con
   * `clip: rect(0 0 0 0)` y 1px de ancho — pero `min-height: 40px` les gana a
   * la altura, así que miden 40px de alto y aparecían en el informe como si
   * fueran campos visibles que no coinciden con los demás.
   */
  function visible(e) {
    var r = e.getBoundingClientRect();
    var c = getComputedStyle(e);
    if (c.visibility === 'hidden' || c.display === 'none') { return false; }
    if (r.width <= 2 || r.height <= 2) { return false; }
    if (c.clip && c.clip !== 'auto') { return false; }
    if (c.clipPath && c.clipPath !== 'none') { return false; }
    if (parseFloat(c.opacity) === 0) { return false; }
    return true;
  }
  function nombrar(e) {
    return e.tagName.toLowerCase()
      + (e.id ? '#' + e.id : '')
      + (e.className && typeof e.className === 'string'
          ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
  }

  // ── 1. Controles de formulario: todos tienen que medir lo mismo ──────────
  var controles = [].slice.call(document.querySelectorAll(
    'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]),' +
    '.ihc-select__btn, .ihc-fecha__campo, .cat-add, select:not(.ihc-select__nativo)'
  )).filter(visible);

  var alturas = {};
  controles.forEach(function (c) {
    var h = Math.round(c.getBoundingClientRect().height * 10) / 10;
    (alturas[h] = alturas[h] || []).push(nombrar(c));
  });
  var distintas = Object.keys(alturas);
  if (distintas.length > 1) {
    // Se tolera el textarea, que es alto a propósito.
    var sinTextarea = {};
    controles.forEach(function (c) {
      if (c.tagName === 'TEXTAREA') { return; }
      var h = Math.round(c.getBoundingClientRect().height * 10) / 10;
      (sinTextarea[h] = sinTextarea[h] || []).push(nombrar(c));
    });
    var hs = Object.keys(sinTextarea);
    if (hs.length > 1) {
      anotar('ALTURAS DE CONTROL', hs.map(function (h) {
        return h + 'px: ' + sinTextarea[h].slice(0, 3).join(' + ')
             + (sinTextarea[h].length > 3 ? ' y ' + (sinTextarea[h].length - 3) + ' mas' : '');
      }).join(' | '));
    }
  }

  // ── 2. Radios de borde: el mismo sistema, no cinco valores sueltos ───────
  var radios = {};
  [].slice.call(document.querySelectorAll(
    '.ih-card, .ih-btn, .ih-input, .ihc-select__btn, .ih-hub__item, .cat-add, .ih-alert'
  )).filter(visible).forEach(function (e) {
    var r = getComputedStyle(e).borderTopLeftRadius;
    (radios[r] = radios[r] || []).push(nombrar(e));
  });
  var rs = Object.keys(radios).filter(function (r) { return r !== '0px'; });
  if (rs.length > 4) {
    anotar('RADIOS', rs.length + ' distintos: ' + rs.join(', '));
  }

  // ── 3. Tamaños de letra fuera de la escala ───────────────────────────────
  var ESCALA = ['10px', '10.5px', '11px', '11.5px', '12px', '12.5px', '13px', '13.5px',
                '14px', '14.5px', '15px', '15.5px', '16px', '17px', '18px', '19px',
                '20px', '21px', '22px', '24px', '26px', '28px', '30px', '32px', '36px'];
  var fuera = {};
  [].slice.call(document.querySelectorAll('body *')).filter(visible).forEach(function (e) {
    // Los iconos y el <code> tienen su propio tamano a proposito.
    if (e.tagName === 'CODE' || e.tagName === 'PRE') { return; }
    if (e.namespaceURI && e.namespaceURI.indexOf('svg') !== -1) { return; }
    if (e.tagName === 'INPUT' && /^(checkbox|radio|range|color|file)$/.test(e.type)) { return; }
    var cls = (typeof e.className === 'string' ? e.className : '').split(/\s+/);
    if (cls.some(function (c) {
          return c === 'bi' || c.indexOf('bi-') === 0
              || c === 'ih-empty__icon' || c === 'ih-stat__icon' || c === 'ih-hub__icono';
        })) { return; }
    var f = getComputedStyle(e).fontSize;
    if (ESCALA.indexOf(f) === -1) { (fuera[f] = fuera[f] || []).push(nombrar(e)); }
  });
  Object.keys(fuera).forEach(function (f) {
    anotar('TAMAÑO FUERA DE ESCALA', f + ' en ' + fuera[f].length + ': ' + fuera[f].slice(0, 3).join(' + '));
  });

  // ── 4. Contenido al que no se llega ──────────────────────────────────────
  //
  // «La página no scrollea a lo ancho» es la mitad de la pregunta. La otra es
  // si lo que se pasa del borde se puede alcanzar. Hay tres casos y sólo uno
  // está mal:
  //
  //   dentro de algo con `overflow-x: auto`  → se llega arrastrando. Bien: es
  //       la tabla de treinta columnas dentro de su `.ih-table-wrap`.
  //   dentro de algo con `overflow-x: hidden` → queda recortado y NO se llega.
  //       Mal: hay contenido perdido y nadie se enteró.
  //   sin nada que lo contenga → arrastra la página entera. Mal.
  //
  // Esto se midió dos veces mal antes de quedar así, y las dos equivocaciones
  // valen la pena anotarlas:
  //
  //   1. Comparar `documentElement.scrollWidth` con `innerWidth` reportaba el
  //      listado de activos, donde la tabla mide 1048 px dentro de un
  //      contenedor de 966 que la scrollea — funcionando como se diseñó.
  //   2. Intentar un scroll por código y ver si se movió tampoco sirve: un
  //      contenedor con `overflow: hidden` SÍ se puede scrollear por código.
  //      Lo que no se puede es scrollearlo con el dedo o con la rueda, que es
  //      lo único que importa acá. La página se "movía" 29 px y no había nada
  //      roto.
  (function () {
    var W = window.innerWidth;

    /** El primer ancestro que decide qué pasa con lo que se desborda. */
    function contenedorX(e) {
      for (var p = e.parentElement; p; p = p.parentElement) {
        var ox = getComputedStyle(p).overflowX;
        if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') { return { el: p, modo: ox }; }
      }
      return null;
    }

    var recortados = [];
    var sueltos = [];

    [].slice.call(document.querySelectorAll('body *')).forEach(function (e) {
      if (!visible(e)) { return; }
      var r = e.getBoundingClientRect();
      if (r.right <= W + 1) { return; }

      var c = contenedorX(e);
      if (c === null) { sueltos.push(nombrar(e)); return; }
      // Si el contenedor también se pasa, el problema es del contenedor y ya
      // va a aparecer por su cuenta.
      if (c.el.getBoundingClientRect().right > W + 1) { return; }
      if (c.modo === 'hidden') {
        recortados.push(nombrar(e) + ' dentro de ' + nombrar(c.el));
      }
      // 'auto' o 'scroll': se llega arrastrando. Es lo esperado.
    });

    if (sueltos.length) {
      anotar('SE PASA DEL BORDE Y NADA LO CONTIENE',
        sueltos.slice(0, 3).join(', ') + ' (ventana ' + W + 'px)');
    }
    if (recortados.length) {
      anotar('CONTENIDO RECORTADO AL QUE NO SE LLEGA',
        recortados.slice(0, 3).join(' | ') + ' (ventana ' + W + 'px)');
    }
  })();

  // ── 5. Botones: área táctil mínima ───────────────────────────────────────
  var chicos = [].slice.call(document.querySelectorAll('button, .ih-btn, a.ih-btn'))
    .filter(visible)
    .filter(function (b) { var r = b.getBoundingClientRect(); return r.height < 28 || r.width < 28; })
    .map(nombrar);
  if (chicos.length) { anotar('BOTÓN CHICO', chicos.slice(0, 4).join(', ')); }

  // ── 6. Texto sobre fondo sin contraste declarado ─────────────────────────
  var transparentes = [].slice.call(document.querySelectorAll('.ih-card, .ih-hub__item, .ih-modal__body'))
    .filter(visible)
    .filter(function (e) {
      var bg = getComputedStyle(e).backgroundColor;
      return bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent';
    }).map(nombrar);
  if (transparentes.length) { anotar('TARJETA SIN FONDO', transparentes.slice(0, 3).join(', ')); }

  // ── 7. Etiquetas que apuntan a un id que no existe ───────────────────────
  var rotas = [].slice.call(document.querySelectorAll('label[for]'))
    .filter(function (l) { return !document.getElementById(l.getAttribute('for')); })
    .map(function (l) { return l.getAttribute('for'); });
  if (rotas.length) { anotar('ETIQUETA ROTA', rotas.slice(0, 5).join(', ')); }

  // ── 8. Imágenes y SVG sin límite de ancho ────────────────────────────────
  var grandes = [].slice.call(document.querySelectorAll('img, svg')).filter(visible)
    .filter(function (e) { return e.getBoundingClientRect().width > window.innerWidth * 0.6; })
    .map(nombrar);
  if (grandes.length) { anotar('IMAGEN DESBORDADA', grandes.slice(0, 3).join(', ')); }

  document.title = hallazgos.length
    ? 'AUDIT|' + hallazgos.join(' ;; ')
    : 'AUDIT|OK';
  }
})();
