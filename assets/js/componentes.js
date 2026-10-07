/* ============================================================================
   INTELLIHELP · Asset Vault — Componentes de interfaz
   ----------------------------------------------------------------------------
   El navegador dibuja algunos controles por su cuenta y ninguna regla CSS los
   alcanza: la lista desplegada de un <select>, el calendario de un
   input[type=date], el cuadro de confirm(). Salen con los colores del sistema
   operativo y cambian de forma entre Windows, macOS y Android. La única salida
   es reemplazarlos, y eso se hace acá.

   ## Mejora progresiva

   El control nativo NO se elimina: queda en el DOM, oculto, y sigue siendo el
   que guarda el valor y el que viaja en el formulario. Lo que se dibuja encima
   es sólo la cara visible. Si este archivo no carga o falla, los formularios
   siguen funcionando con los controles del navegador: feos, pero usables.

   Por eso tampoco hubo que tocar los 67 <select>, los 25 campos de fecha ni los
   17 confirm() uno por uno.

   ## Posicionamiento

   Los desplegables se anclan al <body> con position:fixed y coordenadas
   calculadas. Si se dibujaran dentro de su contenedor, cualquier ancestro con
   overflow —y las tablas tienen overflow-x para poder desplazarse— los
   recortaría.
   ============================================================================ */

(function () {
  'use strict';

  var MES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
             'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIA = ['LU', 'MA', 'MI', 'JU', 'VI', 'SA', 'DO'];

  var seq = 0;
  function id(p) { seq += 1; return p + '-' + seq; }

  /**
   * Texto comparable: sin mayúsculas y sin tildes. Los catálogos del inventario
   * están cargados con acentos ("Córdoba", "Periféricos") y nadie los escribe
   * al buscar. NFD separa la letra de su tilde y después se descarta la tilde.
   */
  function plano(t) {
    return (t || '').trim().toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  /**
   * Hace que la etiqueta del formulario señale al control visible en vez del
   * nativo, que queda oculto: si no, hacer clic en el texto no enfoca nada.
   *
   * Se usa la propiedad `labels` del control y no un selector por id. Un
   * selector obliga a escapar el id, y `CSS.escape` no existe en todos los
   * entornos: cuando falta, tira una excepción que cortaba el recorrido y
   * dejaba sin mejorar todos los controles que venían después.
   */
  function reapuntarEtiqueta(nativo, nuevoId) {
    if (!nuevoId) { return; }
    var etiquetas = nativo.labels;
    if (!etiquetas || !etiquetas.length) { return; }
    Array.prototype.forEach.call(etiquetas, function (lbl) {
      if (lbl.getAttribute('for')) { lbl.setAttribute('for', nuevoId); }
    });
  }

  function el(tag, clase, texto) {
    var n = document.createElement(tag);
    if (clase) { n.className = clase; }
    if (texto !== undefined) { n.textContent = texto; }
    return n;
  }

  /**
   * Dónde se cuelga el desplegable. Normalmente el <body>, pero si el control
   * está dentro de un <dialog> abierto con showModal() hay que colgarlo del
   * diálogo: ese vive en la "capa superior" del navegador y todo lo que quede
   * fuera se dibuja por debajo, así que la lista aparecería tapada.
   */
  function anfitrion(ancla) {
    var d = ancla.closest('dialog');
    return (d && d.open) ? d : document.body;
  }

  /**
   * Cierra el desplegable cuando se desplaza la PAGINA, no cuando se desplaza
   * la lista de adentro.
   *
   * El evento `scroll` no burbujea, asi que hay que escucharlo en fase de
   * captura — y en captura tambien pasan los scroll de los elementos de
   * adentro. Sin este filtro, mover la rueda sobre las opciones cerraba el
   * desplegable justo cuando el usuario estaba buscando una.
   */
  function alDesplazar(pop, cerrar) {
    return function (e) {
      // El objetivo no siempre es un nodo: al desplazar la pagina entera puede
      // ser `document` o la propia ventana, y contains() tira TypeError si se
      // le pasa algo que no sea un Node.
      var t = e.target;
      if (pop && t && t.nodeType === 1 && pop.contains(t)) { return; }
      cerrar();
    };
  }

  /** Coloca un desplegable pegado a su disparador, sin salirse de la pantalla. */
  function ubicar(pop, ancla) {
    var r = ancla.getBoundingClientRect();
    pop.style.position = 'fixed';
    pop.style.minWidth = r.width + 'px';
    pop.style.visibility = 'hidden';
    pop.style.left = '0';
    pop.style.top = '0';
    anfitrion(ancla).appendChild(pop);

    var alto = pop.offsetHeight;
    var ancho = pop.offsetWidth;
    var margen = 8;
    // Si abajo no entra pero arriba sí, se abre hacia arriba.
    var abajo = window.innerHeight - r.bottom;
    var arriba = r.top;
    var top = (abajo < alto + margen && arriba > abajo) ? (r.top - alto - 4) : (r.bottom + 4);
    var left = Math.min(r.left, window.innerWidth - ancho - margen);

    pop.style.top = Math.max(margen, top) + 'px';
    pop.style.left = Math.max(margen, left) + 'px';
    pop.style.visibility = '';
  }

  // ==========================================================================
  //  SELECT
  // ==========================================================================
  function mejorarSelect(nativo) {
    if (nativo.dataset.ihc === '1') { return; }
    if (nativo.multiple || nativo.size > 1) { return; }   // no aplica
    nativo.dataset.ihc = '1';

    var envoltorio = el('div', 'ihc-select');
    nativo.parentNode.insertBefore(envoltorio, nativo);
    envoltorio.appendChild(nativo);
    nativo.classList.add('ihc-select__nativo');

    var btn = el('button', 'ihc-select__btn');
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    btn.disabled = nativo.disabled;
    if (nativo.id) { btn.id = nativo.id + '-btn'; }

    var valor = el('span', 'ihc-select__valor');
    var flecha = el('span', 'ihc-select__flecha');
    flecha.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="m6 9 6 6 6-6"/></svg>';
    btn.appendChild(valor);
    btn.appendChild(flecha);
    envoltorio.appendChild(btn);

    reapuntarEtiqueta(nativo, btn.id);

    var pop = null, lista = null, buscador = null, activa = -1, opciones = [], alScroll = null, alCambiarTamano = null;

    function pintarValor() {
      var o = nativo.options[nativo.selectedIndex];
      var t = o ? o.textContent.trim() : '';
      valor.textContent = t || '—';
      valor.classList.toggle('is-vacio', !o || o.value === '');
    }

    function construir() {
      pop = el('div', 'ihc-pop ihc-select__pop');
      var conBusqueda = nativo.options.length > 8;

      if (conBusqueda) {
        buscador = el('div', 'ihc-select__buscador');
        var inp = el('input');
        inp.type = 'text';
        inp.placeholder = 'Buscar…';
        inp.setAttribute('aria-label', 'Buscar una opción');
        buscador.appendChild(inp);
        pop.appendChild(buscador);
        inp.addEventListener('input', function () { filtrar(inp.value); });
        inp.addEventListener('keydown', teclado);
      }

      lista = el('ul', 'ihc-select__lista');
      lista.setAttribute('role', 'listbox');
      if (nativo.id) { lista.id = nativo.id + '-lista'; }
      pop.appendChild(lista);

      opciones = [];
      Array.prototype.forEach.call(nativo.options, function (o, i) {
        if (o.parentNode.tagName === 'OPTGROUP' && !o.previousElementSibling) {
          lista.appendChild(el('li', 'ihc-select__grupo', o.parentNode.label));
        }
        var li = el('li', 'ihc-select__op');
        li.setAttribute('role', 'option');
        li.id = id('ihc-op');
        li.dataset.i = String(i);
        var chk = el('span', 'ihc-select__op-check');
        chk.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M20 6 9 17l-5-5"/></svg>';
        li.appendChild(chk);
        li.appendChild(el('span', '', o.textContent.trim() || '—'));
        if (o.disabled) { li.setAttribute('aria-disabled', 'true'); }
        li.addEventListener('click', function () { if (!o.disabled) { elegir(i); } });
        li.addEventListener('mousemove', function () { marcar(opciones.indexOf(li)); });
        lista.appendChild(li);
        opciones.push(li);
      });

      var vacio = el('div', 'ihc-select__vacio', 'Sin resultados');
      vacio.hidden = true;
      pop.appendChild(vacio);
      pop.__vacio = vacio;
    }

    function filtrar(q) {
      q = plano(q);
      var visibles = 0;
      opciones.forEach(function (li) {
        var ok = !q || plano(li.textContent).indexOf(q) !== -1;
        li.hidden = !ok;
        if (ok) { visibles += 1; }
      });
      pop.__vacio.hidden = visibles > 0;
      marcar(opciones.findIndex(function (li) { return !li.hidden; }));
    }

    function marcar(i) {
      opciones.forEach(function (li, k) { li.classList.toggle('is-activa', k === i); });
      activa = i;
      if (i >= 0 && opciones[i]) {
        if (opciones[i].scrollIntoView) { opciones[i].scrollIntoView({ block: 'nearest' }); }
        // El lector de pantalla necesita el id de la opcion resaltada; antes
        // esto se ponia en cadena vacia, que no nombra nada.
        lista.setAttribute('aria-activedescendant', opciones[i].id);
        btn.setAttribute('aria-activedescendant', opciones[i].id);
      } else {
        lista.removeAttribute('aria-activedescendant');
        btn.removeAttribute('aria-activedescendant');
      }
    }

    function sincronizarElegida() {
      opciones.forEach(function (li, k) {
        var sel = k === nativo.selectedIndex;
        li.classList.toggle('is-elegida', sel);
        li.setAttribute('aria-selected', sel ? 'true' : 'false');
      });
    }

    function abrir() {
      if (nativo.disabled) { return; }
      construir();
      sincronizarElegida();
      ubicar(pop, btn);
      envoltorio.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      if (buscador) {
        var inp = buscador.querySelector('input');
        inp.value = '';
        filtrar('');
        setTimeout(function () { inp.focus(); }, 0);
      } else {
        marcar(nativo.selectedIndex);
        btn.focus();
      }
      document.addEventListener('mousedown', afuera, true);
      alCambiarTamano = function () { ubicar(pop, btn); };
      window.addEventListener('resize', alCambiarTamano);
      alScroll = alDesplazar(pop, cerrar);
      window.addEventListener('scroll', alScroll, true);
    }

    function cerrar() {
      if (!envoltorio.classList.contains('is-open')) { return; }
      envoltorio.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      if (pop && pop.parentNode) { pop.parentNode.removeChild(pop); }
      document.removeEventListener('mousedown', afuera, true);
      window.removeEventListener('resize', alCambiarTamano);
      window.removeEventListener('scroll', alScroll, true);
    }

    function afuera(e) {
      if (!pop.contains(e.target) && !envoltorio.contains(e.target)) { cerrar(); }
    }

    function elegir(i) {
      nativo.selectedIndex = i;
      // `change` para que el código existente de las páginas siga enterándose.
      nativo.dispatchEvent(new Event('change', { bubbles: true }));
      pintarValor();
      cerrar();
      btn.focus();
    }

    function teclado(e) {
      var abierto = envoltorio.classList.contains('is-open');
      if (!abierto && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) {
        e.preventDefault(); abrir(); return;
      }
      if (!abierto) { return; }
      if (e.key === 'Escape') { e.preventDefault(); cerrar(); btn.focus(); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (activa >= 0 && !opciones[activa].hidden) { elegir(parseInt(opciones[activa].dataset.i, 10)); }
        return;
      }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var paso = e.key === 'ArrowDown' ? 1 : -1;
        var k = activa;
        for (var n = 0; n < opciones.length; n += 1) {
          k = (k + paso + opciones.length) % opciones.length;
          if (!opciones[k].hidden && opciones[k].getAttribute('aria-disabled') !== 'true') { marcar(k); break; }
        }
      }
    }

    btn.addEventListener('click', function () {
      if (envoltorio.classList.contains('is-open')) { cerrar(); } else { abrir(); }
    });
    btn.addEventListener('keydown', teclado);
    // Si otro script cambia el <select> por código, la cara visible se actualiza.
    nativo.addEventListener('change', pintarValor);

    /**
     * La cara visible sigue al control real aunque lo cambie otra página.
     *
     * Varias pantallas rellenan un <select> por código y lo habilitan o lo
     * deshabilitan según lo que se haya elegido antes: en el alta, elegir una
     * categoría reescribe las opciones de subcategoría. Nada de eso dispara un
     * evento, así que sin esto el botón seguía mostrando el valor viejo y
     * quedaba habilitado cuando el <select> ya no lo estaba.
     */
    if (window.MutationObserver) {
      new MutationObserver(function () {
        btn.disabled = nativo.disabled;
        pintarValor();
        // Si la lista está abierta mientras cambian las opciones, se rearma.
        if (envoltorio.classList.contains('is-open')) {
          cerrar();
          abrir();
        }
      }).observe(nativo, { childList: true, attributes: true, attributeFilter: ['disabled'] });
    }

    pintarValor();
  }

  // ==========================================================================
  //  FECHA
  // ==========================================================================
  function dosDig(n) { return (n < 10 ? '0' : '') + n; }
  function aISO(d) { return d.getFullYear() + '-' + dosDig(d.getMonth() + 1) + '-' + dosDig(d.getDate()); }
  function aTexto(iso) {
    if (!iso) { return ''; }
    var p = iso.split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : '';
  }
  function deTexto(t) {
    var m = /^(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{4})$/.exec((t || '').trim());
    if (!m) { return ''; }
    var d = parseInt(m[1], 10), mes = parseInt(m[2], 10), a = parseInt(m[3], 10);
    if (mes < 1 || mes > 12 || d < 1 || d > 31) { return ''; }
    var f = new Date(a, mes - 1, d);
    return (f.getDate() === d && f.getMonth() === mes - 1) ? aISO(f) : '';
  }

  function mejorarFecha(nativo) {
    if (nativo.dataset.ihc === '1') { return; }
    nativo.dataset.ihc = '1';

    var envoltorio = el('div', 'ihc-fecha');
    nativo.parentNode.insertBefore(envoltorio, nativo);
    envoltorio.appendChild(nativo);
    nativo.classList.add('ihc-select__nativo');   // mismo ocultamiento accesible

    var campo = el('div', 'ihc-fecha__campo');
    var texto = el('input', 'ihc-fecha__texto');
    texto.type = 'text';
    texto.placeholder = 'dd/mm/aaaa';
    texto.inputMode = 'numeric';
    texto.autocomplete = 'off';
    texto.value = aTexto(nativo.value);
    if (nativo.id) { texto.id = nativo.id + '-txt'; }
    if (nativo.disabled) { texto.disabled = true; }
    if (nativo.required) { texto.setAttribute('aria-required', 'true'); }

    var abrirBtn = el('button', 'ihc-fecha__abrir');
    abrirBtn.type = 'button';
    abrirBtn.setAttribute('aria-label', 'Abrir calendario');
    abrirBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';

    campo.appendChild(texto);
    campo.appendChild(abrirBtn);
    envoltorio.appendChild(campo);

    reapuntarEtiqueta(nativo, texto.id);

    var pop = null, verMes = null, alScroll = null, alCambiarTamano = null;

    function valorActual() {
      var iso = nativo.value;
      return iso ? new Date(iso + 'T00:00:00') : null;
    }

    function pintar() {
      var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
      var elegido = valorActual();
      pop.innerHTML = '';

      var cal = el('div', 'ihc-cal');
      var cab = el('div', 'ihc-cal__cab');
      var atras = el('button', 'ihc-cal__nav');
      atras.type = 'button';
      atras.setAttribute('aria-label', 'Mes anterior');
      atras.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="m15 18-6-6 6-6"/></svg>';
      var ade = el('button', 'ihc-cal__nav');
      ade.type = 'button';
      ade.setAttribute('aria-label', 'Mes siguiente');
      ade.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="m9 18 6-6-6-6"/></svg>';
      var tit = el('div', 'ihc-cal__titulo', MES[verMes.getMonth()] + ' ' + verMes.getFullYear());
      cab.appendChild(atras); cab.appendChild(tit); cab.appendChild(ade);
      cal.appendChild(cab);

      var dias = el('div', 'ihc-cal__dias');
      DIA.forEach(function (d) { dias.appendChild(el('div', 'ihc-cal__dia', d)); });
      cal.appendChild(dias);

      var grilla = el('div', 'ihc-cal__grilla');
      var primero = new Date(verMes.getFullYear(), verMes.getMonth(), 1);
      // La semana arranca el lunes, como en el resto de las apps.
      var offset = (primero.getDay() + 6) % 7;
      var inicio = new Date(primero); inicio.setDate(1 - offset);

      for (var i = 0; i < 42; i += 1) {
        var d = new Date(inicio); d.setDate(inicio.getDate() + i);
        var celda = el('button', 'ihc-cal__celda', String(d.getDate()));
        celda.type = 'button';
        celda.setAttribute('aria-label', d.getDate() + ' de ' + MES[d.getMonth()] + ' de ' + d.getFullYear());
        if (d.getMonth() !== verMes.getMonth()) { celda.classList.add('es-otro-mes'); }
        if (d.getTime() === hoy.getTime()) { celda.classList.add('es-hoy'); }
        if (elegido && aISO(d) === aISO(elegido)) {
          celda.classList.add('es-elegido');
          celda.setAttribute('aria-current', 'date');
        }
        (function (fecha) {
          celda.addEventListener('click', function () { fijar(aISO(fecha)); cerrar(); texto.focus(); });
        })(d);
        grilla.appendChild(celda);
      }
      cal.appendChild(grilla);

      var pie = el('div', 'ihc-cal__pie');
      var borrar = el('button', 'ihc-cal__accion es-borrar', 'Borrar');
      borrar.type = 'button';
      borrar.addEventListener('click', function () { fijar(''); cerrar(); texto.focus(); });
      var hoyBtn = el('button', 'ihc-cal__accion', 'Hoy');
      hoyBtn.type = 'button';
      hoyBtn.addEventListener('click', function () { fijar(aISO(new Date())); cerrar(); texto.focus(); });
      pie.appendChild(borrar); pie.appendChild(hoyBtn);
      cal.appendChild(pie);

      atras.addEventListener('click', function () { verMes.setMonth(verMes.getMonth() - 1); pintar(); ubicar(pop, campo); });
      ade.addEventListener('click', function () { verMes.setMonth(verMes.getMonth() + 1); pintar(); ubicar(pop, campo); });

      pop.appendChild(cal);
    }

    function fijar(iso) {
      nativo.value = iso;
      texto.value = aTexto(iso);
      nativo.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function abrir() {
      if (nativo.disabled) { return; }
      var v = valorActual();
      verMes = v ? new Date(v.getFullYear(), v.getMonth(), 1) : new Date();
      pop = el('div', 'ihc-pop');
      pop.setAttribute('role', 'dialog');
      pop.setAttribute('aria-label', 'Elegir fecha');
      pintar();
      ubicar(pop, campo);
      envoltorio.classList.add('is-open');
      document.addEventListener('mousedown', afuera, true);
      document.addEventListener('keydown', esc, true);
      alCambiarTamano = function () { ubicar(pop, campo); };
      window.addEventListener('resize', alCambiarTamano);
      alScroll = alDesplazar(pop, cerrar);
      window.addEventListener('scroll', alScroll, true);
    }

    function cerrar() {
      if (!envoltorio.classList.contains('is-open')) { return; }
      envoltorio.classList.remove('is-open');
      if (pop && pop.parentNode) { pop.parentNode.removeChild(pop); }
      document.removeEventListener('mousedown', afuera, true);
      document.removeEventListener('keydown', esc, true);
      window.removeEventListener('resize', alCambiarTamano);
      window.removeEventListener('scroll', alScroll, true);
    }

    function afuera(e) { if (!pop.contains(e.target) && !envoltorio.contains(e.target)) { cerrar(); } }
    function esc(e) { if (e.key === 'Escape') { cerrar(); texto.focus(); } }

    abrirBtn.addEventListener('click', function () {
      if (envoltorio.classList.contains('is-open')) { cerrar(); } else { abrir(); }
    });
    // Se puede escribir la fecha a mano: es más rápido que navegar el calendario.
    texto.addEventListener('blur', function () {
      var iso = deTexto(texto.value);
      if (texto.value.trim() === '') { fijar(''); }
      else if (iso) { fijar(iso); }
      else { texto.value = aTexto(nativo.value); }   // se descarta lo ilegible
    });
    texto.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); texto.blur(); }
      if (e.key === 'ArrowDown' && !envoltorio.classList.contains('is-open')) { e.preventDefault(); abrir(); }
    });
    nativo.addEventListener('change', function () { texto.value = aTexto(nativo.value); });
  }

  // ==========================================================================
  //  CONFIRMACIÓN
  // ==========================================================================
  var modal = null;

  function construirModal() {
    if (modal) { return modal; }
    // <dialog> y no un div con z-index: showModal() lo pone en la capa superior
    // del navegador, así que queda por encima incluso de los modales de las
    // pantallas (que también son <dialog>), y Escape y el foco salen gratis.
    modal = el('dialog', 'ihc-modal');
    modal.innerHTML =
      '<div class="ihc-modal__caja" role="alertdialog" aria-labelledby="ihc-modal-tit" aria-describedby="ihc-modal-det">' +
        '<div class="ihc-modal__cuerpo">' +
          '<div class="ihc-modal__icono" aria-hidden="true"></div>' +
          '<div class="ihc-modal__texto">' +
            '<h2 class="ihc-modal__titulo" id="ihc-modal-tit"></h2>' +
            '<p class="ihc-modal__detalle" id="ihc-modal-det"></p>' +
          '</div>' +
        '</div>' +
        '<div class="ihc-modal__acciones">' +
          '<button type="button" class="ih-btn ih-btn--ghost" data-ihc-no></button>' +
          '<button type="button" class="ih-btn ih-btn--primary" data-ihc-si></button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    return modal;
  }

  var ICONO_PELIGRO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>';
  var ICONO_INFO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>';

  /**
   * Confirmación con el diseño del sistema. Devuelve una promesa que resuelve
   * en true o false. Reemplaza a confirm(), que el navegador dibuja a su manera
   * y encima muestra el nombre del dominio arriba.
   *
   *   ihConfirmar({ titulo, detalle, aceptar, cancelar, peligro })
   *
   * `peligro` es true por omisión: las confirmaciones de esta app son casi
   * siempre destructivas, y el botón rojo tiene que ser el caso fácil.
   */
  window.ihConfirmar = function (op) {
    op = op || {};
    var m = construirModal();
    var peligro = op.peligro !== false;

    m.classList.toggle('ihc-modal--peligro', peligro);
    m.querySelector('.ihc-modal__icono').innerHTML = peligro ? ICONO_PELIGRO : ICONO_INFO;
    m.querySelector('.ihc-modal__titulo').textContent = op.titulo || '¿Confirmás la acción?';
    var det = m.querySelector('.ihc-modal__detalle');
    det.textContent = op.detalle || '';
    det.hidden = !op.detalle;

    var si = m.querySelector('[data-ihc-si]');
    var no = m.querySelector('[data-ihc-no]');
    si.textContent = op.aceptar || 'Confirmar';
    no.textContent = op.cancelar || 'Cancelar';
    si.className = 'ih-btn ' + (peligro ? 'ih-btn--danger' : 'ih-btn--primary');

    m.showModal();
    // El foco arranca en Cancelar, no en el botón que borra: si alguien venía
    // tecleando Enter, no tiene que confirmar un borrado sin leerlo.
    setTimeout(function () { no.focus(); }, 0);

    return new Promise(function (resolve) {
      function cerrar(r) {
        m.removeEventListener('click', onClick);
        m.removeEventListener('cancel', onCancel);
        if (m.open) { m.close(); }
        resolve(r);
      }
      function onClick(e) {
        if (e.target.closest('[data-ihc-si]')) { cerrar(true); }
        else if (e.target.closest('[data-ihc-no]')) { cerrar(false); }
        else if (e.target === m) { cerrar(false); }   // clic en el fondo oscuro
      }
      function onCancel(e) { e.preventDefault(); cerrar(false); }   // tecla Escape
      m.addEventListener('click', onClick);
      m.addEventListener('cancel', onCancel);
    });
  };

  // ==========================================================================
  //  PEDIR UN TEXTO
  // ==========================================================================
  var modalTexto = null;

  function construirModalTexto() {
    if (modalTexto) { return modalTexto; }
    modalTexto = el('dialog', 'ihc-modal ihc-modal--texto');
    modalTexto.innerHTML =
      '<form method="dialog" class="ihc-modal__caja">' +
        '<div class="ihc-modal__cuerpo">' +
          '<div class="ihc-modal__texto">' +
            '<h2 class="ihc-modal__titulo" id="ihc-texto-tit"></h2>' +
            '<p class="ihc-modal__detalle" hidden></p>' +
            '<label class="ihc-modal__campo-label" for="ihc-texto-input"></label>' +
            '<input type="text" id="ihc-texto-input" class="ihc-modal__campo" autocomplete="off">' +
            '<p class="ihc-modal__error" role="alert" hidden></p>' +
          '</div>' +
        '</div>' +
        '<div class="ihc-modal__acciones">' +
          '<button type="button" class="ih-btn ih-btn--ghost" data-ihc-no></button>' +
          '<button type="submit" class="ih-btn ih-btn--primary" data-ihc-si></button>' +
        '</div>' +
      '</form>';
    document.body.appendChild(modalTexto);
    return modalTexto;
  }

  /**
   * Pide un texto con el diseño del sistema. Devuelve una promesa con lo
   * escrito, o null si se canceló. Reemplaza a prompt(), que el navegador
   * dibuja gris y con el nombre del dominio arriba.
   *
   *   ihPedirTexto({ titulo, detalle, etiqueta, valor, aceptar, cancelar,
   *                  max, requerido, peligro, esperado })
   *
   * `esperado` convierte el cuadro en una confirmación escrita: no deja seguir
   * hasta que lo escrito coincida. Se usa para lo que no se puede deshacer
   * —eliminar un activo de la papelera— porque un sí/no se acepta sin leerlo,
   * y copiar un número obliga a mirar cuál. La comparación ignora espacios y
   * mayúsculas: la idea es que la persona confirme, no que acierte un dictado.
   *
   * `peligro` pinta el botón de aceptar en rojo, igual que en ihConfirmar().
   */
  /** Si lo escrito confirma lo esperado. Sin espacios, sin mayúsculas y sin
      acentos: la idea es que la persona confirme, no que acierte un dictado. */
  function coincide(escrito, esperado) {
    return plano(String(escrito).replace(/\s+/g, ''))
        === plano(String(esperado).replace(/\s+/g, ''));
  }

  window.ihPedirTexto = function (op) {
    op = op || {};
    var m = construirModalTexto();
    var inp = m.querySelector('#ihc-texto-input');
    var err = m.querySelector('.ihc-modal__error');
    var det = m.querySelector('.ihc-modal__detalle');
    var si  = m.querySelector('[data-ihc-si]');
    var no  = m.querySelector('[data-ihc-no]');

    m.querySelector('.ihc-modal__titulo').textContent = op.titulo || 'Nuevo valor';
    m.querySelector('.ihc-modal__campo-label').textContent = op.etiqueta || '';
    det.textContent = op.detalle || '';
    det.hidden = !op.detalle;
    si.textContent = op.aceptar || 'Agregar';
    no.textContent = op.cancelar || 'Cancelar';
    si.className = 'ih-btn ' + (op.peligro ? 'ih-btn--danger' : 'ih-btn--primary');
    inp.value = op.valor || '';
    inp.maxLength = op.max || 255;
    inp.setAttribute('inputmode', op.esperado && /^[0-9]+$/.test(String(op.esperado)) ? 'numeric' : 'text');
    err.hidden = true;

    m.showModal();
    setTimeout(function () { inp.focus(); inp.select(); }, 0);

    return new Promise(function (resolve) {
      function cerrar(v) {
        m.removeEventListener('click', onClick);
        m.removeEventListener('submit', onSubmit);
        m.removeEventListener('cancel', onCancel);
        if (m.open) { m.close(); }
        resolve(v);
      }
      function onSubmit(e) {
        // `method="dialog"` cerraría el diálogo aunque el valor esté vacío.
        e.preventDefault();
        var v = inp.value.trim();
        if (v === '' && op.requerido !== false) {
          err.textContent = 'Escribí un valor.';
          err.hidden = false;
          inp.focus();
          return;
        }
        if (op.esperado != null && !coincide(v, op.esperado)) {
          err.textContent = 'Eso no es «' + op.esperado + '». Escribilo igual para confirmar.';
          err.hidden = false;
          inp.focus();
          inp.select();
          return;
        }
        cerrar(v);
      }
      function onClick(e) {
        if (e.target.closest('[data-ihc-no]')) { cerrar(null); }
        else if (e.target === m) { cerrar(null); }   // clic en el fondo oscuro
      }
      function onCancel(e) { e.preventDefault(); cerrar(null); }   // tecla Escape
      m.addEventListener('submit', onSubmit);
      m.addEventListener('click', onClick);
      m.addEventListener('cancel', onCancel);
    });
  };

  /**
   * Los elementos cuyo atributo 'attr' llama a confirm().
   *
   * Se filtra en JavaScript y no con un selector por subcadena del atributo:
   * el parentesis de "confirm(" dentro de la cadena del selector rompe algun
   * motor (nwsapi, el de jsdom, devuelve cero coincidencias) y ahi no hay
   * error — sencillamente no encuentra nada y las confirmaciones se quedan
   * siendo las del navegador, sin ningun aviso.
   */
  function conAtributo(raiz, attr) {
    return Array.prototype.filter.call(
      raiz.querySelectorAll('[' + attr + ']'),
      function (n) { return (n.getAttribute(attr) || '').indexOf('confirm(') !== -1; }
    );
  }

  function interceptarConfirm(raiz) {
    var RX = /confirm\(\s*(['"])([\s\S]*?)\1\s*\)/;

    conAtributo(raiz, 'onsubmit').forEach(function (form) {
      var m = RX.exec(form.getAttribute('onsubmit') || '');
      if (!m) { return; }
      var msg = m[2];
      form.removeAttribute('onsubmit');
      form.addEventListener('submit', function (e) {
        if (form.dataset.ihcOk === '1') { form.dataset.ihcOk = ''; return; }
        e.preventDefault();
        window.ihConfirmar({ titulo: msg, aceptar: 'Sí, continuar' }).then(function (ok) {
          if (ok) { form.dataset.ihcOk = '1'; form.submit(); }
        });
      });
    });

    conAtributo(raiz, 'onclick').forEach(function (nodo) {
      var crudo = nodo.getAttribute('onclick') || '';
      var m = RX.exec(crudo);
      if (!m) { return; }
      var msg = m[2];
      // Lo que venga después del confirm se conserva y se ejecuta sólo si acepta.
      var resto = crudo.replace(/^\s*return\s+/, '').replace(RX, '').replace(/^\s*&&\s*/, '').trim();
      nodo.removeAttribute('onclick');
      nodo.addEventListener('click', function (e) {
        e.preventDefault();
        window.ihConfirmar({ titulo: msg, aceptar: 'Sí, continuar' }).then(function (ok) {
          if (!ok) { return; }
          if (resto) { try { new Function(resto).call(nodo); } catch (err) { /* nada */ } }
          else if (nodo.tagName === 'A' && nodo.href) { window.location.href = nodo.href; }
          else if (nodo.form) { nodo.form.submit(); }
        });
      });
    });
  }

  // ==========================================================================
  //  ARCHIVO
  // ==========================================================================
  function pesoLegible(b) {
    if (b < 1024) { return b + ' B'; }
    if (b < 1048576) { return (b / 1024).toFixed(0) + ' KB'; }
    return (b / 1048576).toFixed(1) + ' MB';
  }

  /**
   * Los tipos que el campo acepta, leídos de su propio `accept`.
   *
   * Se usa para avisar ANTES de enviar. Sin esto, elegir un .zip de 40 MB se
   * acepta sin decir nada, se sube, y el servidor contesta un error genérico
   * cuando la persona ya se fue a hacer otra cosa.
   */
  function aceptados(nativo) {
    var crudo = (nativo.getAttribute('accept') || '').toLowerCase();
    if (crudo === '') { return null; }   // sin `accept`: entra cualquier cosa
    return crudo.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }

  /** Si el archivo entra en lo que el campo acepta. */
  function entraEnAccept(f, reglas) {
    if (reglas === null) { return true; }
    var nombre = (f.name || '').toLowerCase();
    var tipo   = (f.type || '').toLowerCase();
    for (var i = 0; i < reglas.length; i++) {
      var r = reglas[i];
      if (r.charAt(0) === '.') {
        if (nombre.slice(-r.length) === r) { return true; }
      } else if (r.slice(-2) === '/*') {
        if (tipo.indexOf(r.slice(0, -1)) === 0) { return true; }
      } else if (tipo === r) {
        return true;
      }
    }
    return false;
  }

  function mejorarArchivo(nativo) {
    if (nativo.dataset.ihc === '1') { return; }
    nativo.dataset.ihc = '1';

    var maxMb   = parseFloat(nativo.dataset.maxMb || '5');
    var reglas  = aceptados(nativo);
    var varios  = nativo.multiple;

    var envoltorio = el('div', 'ihc-archivo');
    nativo.parentNode.insertBefore(envoltorio, nativo);
    envoltorio.appendChild(nativo);
    nativo.classList.add('ihc-archivo__nativo');

    var zona = el('label', 'ihc-archivo__zona');
    if (!nativo.id) { nativo.id = id('ihc-file'); }
    zona.setAttribute('for', nativo.id);
    zona.innerHTML =
      '<span class="ihc-archivo__icono" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="26" height="26">' +
        '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>' +
      '</span>' +
      '<span class="ihc-archivo__cta">' +
        (varios ? 'Arrastrá los archivos o hacé clic para elegirlos'
                : 'Arrastrá el archivo o hacé clic para elegirlo') +
      '</span>' +
      '<span class="ihc-archivo__ayuda">' +
        (nativo.dataset.ayuda || 'JPG, PNG, PDF, Word, Excel o PowerPoint · hasta ' + maxMb + ' MB cada uno') +
      '</span>';
    envoltorio.appendChild(zona);

    var lista = el('ul', 'ihc-archivo__lista');
    envoltorio.appendChild(lista);
    var aviso = el('p', 'ihc-archivo__error');
    aviso.setAttribute('role', 'alert');
    aviso.hidden = true;
    envoltorio.appendChild(aviso);

    /* La lista que la persona ve y la que se va a enviar son la misma: se
       guarda acá y se escribe en el input con un DataTransfer. Hace falta
       porque el `files` de un input es de sólo lectura para agregar o quitar de
       a uno, y porque elegir archivos dos veces REEMPLAZA la selección
       anterior en lugar de sumarla — que no es lo que espera nadie que acaba de
       elegir tres fotos y después se acuerda de la cuarta. */
    var elegidos = [];

    function sincronizar() {
      try {
        var dt = new DataTransfer();
        elegidos.forEach(function (f) { dt.items.add(f); });
        nativo.files = dt.files;
      } catch (e) {
        /* Algún navegador viejo no deja escribir `files`. Entonces se deja la
           última selección nativa tal cual: se pierde el poder quitar uno,
           pero el formulario sigue enviando archivos. */
      }
    }

    function decir(msg) {
      aviso.textContent = msg;
      aviso.hidden = (msg === '');
    }

    function pintar() {
      lista.innerHTML = '';
      elegidos.forEach(function (f, i) {
        var li = el('li', 'ihc-archivo__item');
        li.appendChild(el('span', 'ihc-archivo__item-nombre', f.name));
        li.appendChild(el('span', 'ihc-archivo__item-peso', pesoLegible(f.size)));
        var quitar = el('button', 'ihc-archivo__quitar');
        quitar.type = 'button';
        quitar.innerHTML = '&times;';
        quitar.setAttribute('aria-label', 'Quitar ' + f.name);
        quitar.addEventListener('click', function () {
          elegidos.splice(i, 1);
          sincronizar();
          pintar();
          decir('');
        });
        li.appendChild(quitar);
        lista.appendChild(li);
      });
    }

    /** Suma archivos a la selección, avisando de los que no entran. */
    function sumar(archivos) {
      var rechazados = [];
      Array.prototype.forEach.call(archivos || [], function (f) {
        if (f.size > maxMb * 1024 * 1024) {
          rechazados.push(f.name + ' (' + pesoLegible(f.size) + ', el límite es ' + maxMb + ' MB)');
          return;
        }
        if (!entraEnAccept(f, reglas)) {
          rechazados.push(f.name + ' (no es uno de los formatos que se aceptan)');
          return;
        }
        var repetido = elegidos.some(function (a) { return a.name === f.name && a.size === f.size; });
        if (repetido) { return; }
        if (varios) { elegidos.push(f); } else { elegidos = [f]; }
      });
      sincronizar();
      pintar();
      decir(rechazados.length === 0 ? ''
          : (rechazados.length === 1 ? 'No se pudo agregar ' : 'No se pudieron agregar ')
            + rechazados.join('; ') + '.');
    }

    nativo.addEventListener('change', function () {
      // Lo que el navegador acaba de poner en `files` todavía no pasó por el
      // control, así que se lo saca de ahí y se vuelve a escribir.
      var nuevos = Array.prototype.slice.call(nativo.files || []);
      var yaEstaban = nuevos.length === elegidos.length && nuevos.every(function (f, i) {
        return elegidos[i] && f.name === elegidos[i].name && f.size === elegidos[i].size;
      });
      if (yaEstaban) { pintar(); return; }   // es el `change` que disparó sincronizar()
      sumar(nuevos);
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      zona.addEventListener(ev, function (e) { e.preventDefault(); envoltorio.classList.add('is-arrastrando'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zona.addEventListener(ev, function (e) { e.preventDefault(); envoltorio.classList.remove('is-arrastrando'); });
    });
    zona.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) { sumar(e.dataTransfer.files); }
    });

    // Si la página llegó con archivos ya puestos (poco común, pero pasa al
    // volver atrás en el navegador), se toman como la selección inicial.
    if (nativo.files && nativo.files.length) {
      elegidos = Array.prototype.slice.call(nativo.files);
    }
    pintar();
  }

  // ==========================================================================
  //  Arranque
  // ==========================================================================
  /**
   * Cada control se mejora por separado y protegido: si uno falla, el resto de
   * la pagina tiene que seguir funcionando. Antes una sola excepcion cortaba el
   * recorrido y dejaba media pantalla con los controles del navegador y la otra
   * media con los propios.
   */
  function aCadaUno(nodos, fn) {
    Array.prototype.forEach.call(nodos, function (n) {
      try { fn(n); } catch (e) {
        if (window.console && console.warn) {
          console.warn('[componentes] no se pudo mejorar un control:', e, n);
        }
      }
    });
  }

  // ==========================================================================
  //  CERRAR UN AVISO
  // ==========================================================================
  /**
   * La cruz de un `.ih-alert` o un `.ih-flash`.
   *
   * Estaba escrita en el atributo `onclick` de cada botón, en siete pantallas y
   * de cuatro maneras distintas: tres ponían `style.display='none'` —que deja
   * el margen inferior del aviso ocupando lugar, así que la pantalla queda con
   * un hueco donde había algo—, tres llamaban a `.remove()`, y una de ellas
   * dibujaba la cruz con el carácter «×» en lugar del icono.
   *
   * Un solo oyente en el documento, delegado: así también funciona en un aviso
   * que aparezca después, sin tener que volver a enganchar nada.
   */
  function escucharCierres() {
    if (document.documentElement.dataset.ihcCierres === '1') { return; }
    document.documentElement.dataset.ihcCierres = '1';

    document.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || t.nodeType !== 1) { return; }
      var cruz = t.closest('.ih-alert__close, .ih-flash__close');
      if (!cruz) { return; }
      var aviso = cruz.closest('.ih-alert, .ih-flash');
      if (aviso) { ev.preventDefault(); aviso.remove(); }
    });
  }

  function mejorar(raiz) {
    raiz = raiz || document;
    escucharCierres();
    aCadaUno(raiz.querySelectorAll('select'), mejorarSelect);
    aCadaUno(raiz.querySelectorAll('input[type="date"]'), mejorarFecha);
    aCadaUno(raiz.querySelectorAll('input[type="file"]'), mejorarArchivo);
    try { interceptarConfirm(raiz); } catch (e) {
      if (window.console && console.warn) { console.warn('[componentes] confirmaciones:', e); }
    }
  }

  // Se expone para el contenido que se agregue después de cargar la página.
  window.ihMejorar = mejorar;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { mejorar(document); });
  } else {
    mejorar(document);
  }
})();
