/**
 * Cuánto ocupa una fila de tabla, y cuánto de eso es aire.
 *
 * «La tabla se ve muy grande» es una impresión; esto la convierte en números:
 * alto de fila, relleno de celda, qué celda es la que estira la fila y cuánto
 * mide lo que tiene adentro. Así se sabe si lo que hay que achicar es el
 * relleno, el control o el contenido.
 *
 * Se usa igual que `auditor.js`: se inyecta al final de una pantalla descargada
 * y se lee el informe del `<title>`, con el prefijo `TABLA|`. No está dentro de
 * `auditor.js` porque no decide nada: no hay un alto de fila «correcto» —una
 * tabla con botones de acción no puede ser tan baja como una de sólo texto—,
 * así que esto informa y la decisión se toma comparando tablas entre sí.
 *
 * Con esto se bajó el alto de fila de seis tablas que medían 51, 54, 55, 60, 51
 * y 37 px a 50, 50, 50, 49, 47 y 37 — y se encontró que las causas eran tres y
 * ninguna era el relleno: el botón de acción, un campo de formulario dentro de
 * una celda, y un renglón de apoyo que medía distinto según tuviera texto, un
 * `<code>` o una insignia.
 */
(function () {
  'use strict';
  window.addEventListener('load', function () { setTimeout(medir, 120); });

  function nombrar(e) {
    if (!e || !e.tagName) { return '?'; }
    var c = (typeof e.className === 'string' ? e.className : '').trim().split(/\s+/)[0];
    return e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (c ? '.' + c : '');
  }

  function medir() {
    var L = [];
    var tablas = [].slice.call(document.querySelectorAll('table.ih-table'))
      .filter(function (t) { return t.offsetParent !== null && t.querySelector('tbody tr td'); });

    if (!tablas.length) { document.title = 'TABLA|sin tablas visibles'; return; }

    tablas.forEach(function (tabla) {
      var filas = [].slice.call(tabla.querySelectorAll('tbody tr'))
        .filter(function (tr) { return !tr.querySelector('td[colspan]'); });
      if (!filas.length) { return; }

      var th = tabla.querySelector('thead th');
      var td = filas[0].querySelector('td');
      var cs = getComputedStyle(td);
      var csh = th ? getComputedStyle(th) : cs;

      var altos = filas.map(function (f) { return Math.round(f.getBoundingClientRect().height); });
      var prom = Math.round(altos.reduce(function (a, b) { return a + b; }, 0) / altos.length);

      // Qué celda es la que estira la fila más alta.
      var peor = filas[altos.indexOf(Math.max.apply(null, altos))];
      var culpable = '';
      var maxCelda = 0;
      [].slice.call(peor.querySelectorAll('td')).forEach(function (c, i) {
        var h = c.getBoundingClientRect().height;
        if (h > maxCelda) {
          maxCelda = h;
          var cab = tabla.querySelectorAll('thead th')[i];
          culpable = (cab ? cab.textContent.trim() : 'col ' + i)
                   + ' (' + c.innerText.replace(/\s+/g, ' ').trim().slice(0, 40) + ')';
        }
      });

      /* Cuanto mide el CONTENIDO de cada celda, no la celda: en una tabla
         todas las celdas de una fila miden lo mismo —se estiran a la fila— asi
         que medirlas no dice cual la estira. Lo que hay que medir es lo que
         tienen adentro. */
      /* Y se mide del borde de arriba del primer hijo al de abajo del ultimo, no
         el mas alto de los hijos: cuando una celda apila dos renglones —la fecha
         y quien la hizo, el nombre y la clave— lo que estira la fila es la SUMA,
         y tomar el maximo informaba 19 px donde habia 34. Con ese numero la
         celda culpable parecia inocente y se buscaba el alto de la fila en el
         lugar equivocado. */
      var detalle = [].slice.call(peor.querySelectorAll('td')).map(function (c, i) {
        var cab = tabla.querySelectorAll('thead th')[i];
        var alto = 0;
        var hijos = [].slice.call(c.children).filter(function (h) {
          return h.getBoundingClientRect().height > 0;
        });
        if (hijos.length) {
          var arriba = Infinity, abajo = -Infinity;
          hijos.forEach(function (h) {
            var r = h.getBoundingClientRect();
            if (r.top < arriba) { arriba = r.top; }
            if (r.bottom > abajo) { abajo = r.bottom; }
          });
          alto = abajo - arriba;
        } else {
          alto = parseFloat(getComputedStyle(c).lineHeight) || 20;
        }
        return (cab ? cab.textContent.trim().slice(0, 12) : i)
             + '=' + Math.round(alto) + '/' + Math.round(c.getBoundingClientRect().width)
             + (hijos.length > 1 ? 'x' + hijos.length : '');
      }).join(' ');
      L.push('  contenido (alto/ancho): ' + detalle);

      L.push((tabla.id || nombrar(tabla))
        + ' | cols=' + tabla.querySelectorAll('thead th').length
        + ' filas=' + filas.length
        + ' ancho=' + Math.round(tabla.getBoundingClientRect().width)
        + ' altoFila=' + Math.min.apply(null, altos) + '–' + Math.max.apply(null, altos) + ' (prom ' + prom + ')'
        + ' | td: font=' + cs.fontSize + ' pad=' + cs.paddingTop + '/' + cs.paddingBottom
        + ' lh=' + cs.lineHeight
        + ' | th: font=' + csh.fontSize + ' pad=' + csh.paddingTop + '/' + csh.paddingBottom
        + ' | la mas alta la estira: ' + culpable);
    });

    document.title = 'TABLA|' + L.join(' ;; ');
  }
})();
