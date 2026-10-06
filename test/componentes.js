/**
 * Prueba FUNCIONAL de assets/js/componentes.js
 *
 *   npm install jsdom          (una sola vez; no va al repositorio)
 *   node test/componentes.js
 *
 * test/run_tests.php es análisis estático: ve que las clases existan y que el
 * script esté enlazado, no que FUNCIONE. Esto carga componentes.js en un DOM de
 * verdad y lo usa como lo usaría una persona: abre los desplegables, escribe en
 * el buscador, navega el calendario con el teclado, confirma y cancela.
 *
 * Así aparecieron dos cosas que el análisis estático no podía ver: que el
 * buscador no encontraba "Córdoba" si se escribía sin tilde, y que
 * `aria-activedescendant` se ponía en cadena vacía, que no le nombra nada a un
 * lector de pantalla.
 *
 * Lo que jsdom no implementa (showModal, scrollIntoView, medición de cajas) se
 * suple más abajo con lo mínimo que el código usa: lo que se prueba es el
 * código, no el navegador.
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const RAIZ = require('path').dirname(__dirname);
const JS = fs.readFileSync(RAIZ + '/assets/js/componentes.js', 'utf8');

const HTML = `<!doctype html><html lang="es"><body>
<form id="f1" method="post" onsubmit="return confirm('¿Archivar «TEST — test»?')">
  <label for="cat">Categoría</label>
  <select id="cat" name="cat" class="ih-select">
    <option value="">Sin categoría</option>
    <option value="pc">Computadoras</option>
    <option value="mon" selected>Monitores</option>
    <option value="imp">Impresoras</option>
  </select>
  <label for="muchas">Con buscador</label>
  <select id="muchas" name="muchas" class="ih-select">
    <option value="">Todas</option>
    <option>Buenos Aires</option><option>Catamarca</option><option>Chaco</option>
    <option>Chubut</option><option>Córdoba</option><option>Corrientes</option>
    <option>Entre Ríos</option><option>Formosa</option><option>Jujuy</option>
    <option>La Pampa</option>
  </select>
  <select id="agrupado" class="ih-select">
    <optgroup label="Equipos"><option>Notebook</option><option>Desktop</option></optgroup>
    <optgroup label="Periféricos"><option>Teclado</option></optgroup>
  </select>
  <select id="deshabilitado" class="ih-select" disabled><option>Nada</option></select>
  <label for="fecha">Fecha de compra</label>
  <input type="date" id="fecha" name="fecha" value="2026-03-15" class="ih-input">
  <label for="vacia">Fecha vacía</label>
  <input type="date" id="vacia" name="vacia" class="ih-input">
  <input type="file" id="arch" name="arch[]" multiple>
  <button type="submit" class="ih-btn ih-btn--danger">Archivar</button>
</form>
<a href="/backend/restaurar.php?id=7" id="enlace"
   onclick="return confirm('¿Restaurar «TEST — test» al inventario?')">Restaurar</a>
<form id="f2" method="post"><button type="submit" id="btnOtro"
   onclick="return confirm('¿Vaciar la papelera?')">Vaciar</button></form>
<select id="multi" multiple class="ih-select"><option>A</option><option>B</option></select>
<dialog class="ih-modal" id="modalConSelect">
  <div class="ih-modal__body"><select id="dentro" class="ih-select"><option>Uno</option><option>Dos</option></select></div>
</dialog>
</body></html>`;

const dom = new JSDOM(HTML, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://desarrollo.intellihelp.tech/inventario/index.php' });
const { window } = dom;

const excepciones = [];
window.addEventListener('error', (e) => excepciones.push(e.message));
window.onerror = (m) => { excepciones.push(String(m)); return true; };

// jsdom no implementa showModal/close de <dialog>: se suplen con lo mínimo que
// el código usa, porque lo que se está probando es el código, no el navegador.
Object.defineProperty(window.HTMLDialogElement.prototype, 'showModal', {
  value: function () { this.open = true; }, writable: true, configurable: true,
});
Object.defineProperty(window.HTMLDialogElement.prototype, 'close', {
  value: function () { this.open = false; this.dispatchEvent(new window.Event('close')); },
  writable: true, configurable: true,
});
// Tampoco mide: getBoundingClientRect devuelve ceros. ubicar() sólo necesita
// que no explote.
window.Element.prototype.scrollIntoView = function () {};
window.Element.prototype.getBoundingClientRect = function () {
  return { top: 100, left: 50, bottom: 140, right: 250, width: 200, height: 40, x: 50, y: 100 };
};

const script = window.document.createElement('script');
script.textContent = JS;
window.document.body.appendChild(script);

let ok = 0, mal = 0;
const fallas = [];
function t(nombre, cond, detalle) {
  if (cond) { ok++; console.log('  \x1b[32m✓\x1b[0m ' + nombre); }
  else {
    mal++; fallas.push(nombre + (detalle ? ' — ' + detalle : ''));
    console.log('  \x1b[31m✗\x1b[0m ' + nombre + (detalle ? '\n      \x1b[31m' + detalle + '\x1b[0m' : ''));
  }
}
const $ = (s) => window.document.querySelector(s);
const $$ = (s) => Array.from(window.document.querySelectorAll(s));
const clic = (el) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
const tecla = (el, key) => el.dispatchEvent(new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));

function seccion(n) { console.log('\n\x1b[1m── ' + n + ' ' + '─'.repeat(Math.max(0, 54 - n.length)) + '\x1b[0m'); }

// En la pagina real el script se carga con `defer`, asi que corre ANTES de
// DOMContentLoaded y es ese evento el que dispara la mejora. Hay que esperarlo:
// medir antes es medir nada.
async function main() {
await new Promise((r) => {
  if (window.document.readyState !== 'loading') { r(); return; }
  window.document.addEventListener('DOMContentLoaded', r, { once: true });
});

// ════════════════════════════════════════════════════════════════ SELECT
seccion('SELECT');
const cat = $('#cat');
const env = cat.closest('.ihc-select');
t('el <select> queda envuelto', !!env);
t('el <select> real sigue dentro del formulario', !!$('#f1 #cat'));
t('el <select> real conserva su name', cat.name === 'cat');
t('el nativo queda oculto', cat.classList.contains('ihc-select__nativo'));
const btn = env.querySelector('.ihc-select__btn');
t('se dibuja un botón en su lugar', !!btn);
t('el botón no envía el formulario (type=button)', btn.type === 'button');
t('el botón muestra el valor guardado, no el primero',
  btn.querySelector('.ihc-select__valor').textContent.trim() === 'Monitores',
  btn.textContent.trim());
t('la etiqueta pasa a señalar al botón', !!$('label[for="cat-btn"]'));
t('el botón declara que abre una lista', btn.getAttribute('aria-haspopup') === 'listbox');
t('y declara que está cerrado', btn.getAttribute('aria-expanded') === 'false');
t('un <select> deshabilitado deja el botón deshabilitado',
  $('#deshabilitado').closest('.ihc-select').querySelector('.ihc-select__btn').disabled === true);
t('un <select multiple> NO se toca: su interfaz es otra',
  $('#multi').closest('.ihc-select') === null && $('#multi').dataset.ihc !== '1');

clic(btn);
let pop = $('.ihc-select__pop');
t('se abre la lista', !!pop);
t('la lista se cuelga del <body>, no del contenedor', pop.parentNode === window.document.body);
t('se posiciona con position:fixed', pop.style.position === 'fixed');
t('el botón declara que está abierto', btn.getAttribute('aria-expanded') === 'true');
t('la lista tiene rol de listbox', pop.querySelector('.ihc-select__lista').getAttribute('role') === 'listbox');
let ops = pop.querySelectorAll('.ihc-select__op');
t('hay una opción por cada <option>', ops.length === cat.options.length, ops.length + ' vs ' + cat.options.length);
t('cada opción tiene rol de option', ops[0].getAttribute('role') === 'option');
t('la guardada aparece marcada como elegida', ops[2].classList.contains('is-elegida'));
t('y lo dice con aria-selected', ops[2].getAttribute('aria-selected') === 'true');
t('un <select> de 4 opciones no trae buscador', pop.querySelector('.ihc-select__buscador') === null);

let cambios = 0;
cat.addEventListener('change', () => { cambios++; });
clic(ops[1]);
t('elegir escribe el valor en el <select> real', cat.value === 'pc', cat.value);
t('y dispara `change` una sola vez', cambios === 1, String(cambios));
t('la cara visible se actualiza', btn.textContent.includes('Computadoras'));
t('la lista se cierra al elegir', $('.ihc-select__pop') === null);
t('el botón vuelve a declararse cerrado', btn.getAttribute('aria-expanded') === 'false');

cat.value = 'imp';
cat.dispatchEvent(new window.Event('change', { bubbles: true }));
t('un cambio hecho por código se refleja en la cara visible',
  btn.textContent.includes('Impresoras'), btn.textContent.trim());

cat.value = '';
cat.dispatchEvent(new window.Event('change', { bubbles: true }));
t('la opción vacía se muestra atenuada',
  btn.querySelector('.ihc-select__valor').classList.contains('is-vacio'));

// Varias pantallas rellenan un <select> por código: en el alta, elegir una
// categoría reescribe las opciones de subcategoría. Nada de eso dispara un
// evento, y la lista quedaba cacheada de la primera vez que se abrió.
seccion('SELECT · cambios hechos por código');
const dinamico = $('#cat');
clic(btn);
t('la lista arranca con las 4 opciones del markup', $$('.ihc-select__op').length === 4,
  $$('.ihc-select__op').length + ' opciones');
tecla(btn, 'Escape');

dinamico.innerHTML = '<option value="">Elegí</option><option value="a">Alfa</option>'
                   + '<option value="b">Beta</option><option value="c">Gama</option>'
                   + '<option value="d">Delta</option><option value="e">Epsilon</option>';
await new Promise((r) => setTimeout(r, 0));   // el observador es asíncrono
clic(btn);
t('al reescribir las opciones, la lista se rearma',
  $$('.ihc-select__op').length === 6, $$('.ihc-select__op').length + ' opciones');
t('y muestra las nuevas',
  $$('.ihc-select__op').some((li) => li.textContent.includes('Epsilon')));
tecla(btn, 'Escape');

dinamico.value = 'b';
dinamico.dispatchEvent(new window.Event('change', { bubbles: true }));
t('la cara visible toma el valor nuevo', btn.textContent.includes('Beta'), btn.textContent.trim());

dinamico.disabled = true;
await new Promise((r) => setTimeout(r, 0));
t('deshabilitar el <select> deshabilita el botón', btn.disabled === true);
clic(btn);
t('y deshabilitado no abre', $('.ihc-select__pop') === null);
dinamico.disabled = false;
await new Promise((r) => setTimeout(r, 0));
t('volver a habilitarlo habilita el botón', btn.disabled === false);

seccion('SELECT · buscador y teclado');
const b2 = $('#muchas').closest('.ihc-select').querySelector('.ihc-select__btn');
clic(b2);
const pop2 = $('.ihc-select__pop');
const buscador = pop2.querySelector('.ihc-select__buscador input');
t('un <select> de 11 opciones trae buscador', !!buscador);
t('el buscador tiene etiqueta para lector de pantalla', !!buscador.getAttribute('aria-label'));
buscador.value = 'ch';
buscador.dispatchEvent(new window.Event('input', { bubbles: true }));
let visibles = Array.from(pop2.querySelectorAll('.ihc-select__op')).filter((li) => !li.hidden);
t('el buscador filtra', visibles.length === 2,
  visibles.length + ' visibles, esperaba Chaco y Chubut');

// Los catalogos del inventario estan cargados con tildes y nadie las escribe.
buscador.value = 'cordoba';
buscador.dispatchEvent(new window.Event('input', { bubbles: true }));
visibles = Array.from(pop2.querySelectorAll('.ihc-select__op')).filter((li) => !li.hidden);
t('buscar sin tilde encuentra la opcion con tilde (cordoba -> Cordoba)',
  visibles.length === 1, visibles.length + ' visibles');
buscador.value = 'RIOS';
buscador.dispatchEvent(new window.Event('input', { bubbles: true }));
visibles = Array.from(pop2.querySelectorAll('.ihc-select__op')).filter((li) => !li.hidden);
t('y tampoco importan las mayusculas (RIOS -> Entre Rios)',
  visibles.length === 1, visibles.length + ' visibles');
buscador.value = 'zzzz';
buscador.dispatchEvent(new window.Event('input', { bubbles: true }));
t('sin resultados lo dice', !pop2.querySelector('.ihc-select__vacio').hidden);
buscador.value = '';
buscador.dispatchEvent(new window.Event('input', { bubbles: true }));
t('al borrar la búsqueda vuelven todas',
  Array.from(pop2.querySelectorAll('.ihc-select__op')).filter((li) => !li.hidden).length === 11);
tecla(buscador, 'ArrowDown');
tecla(buscador, 'ArrowDown');
const activa = pop2.querySelector('.ihc-select__op.is-activa');
t('las flechas mueven el resaltado', !!activa, activa ? activa.textContent : 'ninguna');
t('la lista nombra la opcion resaltada para el lector de pantalla',
  pop2.querySelector('.ihc-select__lista').getAttribute('aria-activedescendant') === activa.id,
  pop2.querySelector('.ihc-select__lista').getAttribute('aria-activedescendant'));
tecla(buscador, 'Enter');
t('Enter elige la opción resaltada', $('#muchas').value !== '', '[' + $('#muchas').value + ']');
t('y cierra la lista', $('.ihc-select__pop') === null);

clic(b2);
tecla(b2, 'Escape');
t('Escape cierra sin elegir', $('.ihc-select__pop') === null);
tecla(b2, 'ArrowDown');
t('la flecha abajo abre la lista estando cerrada', $('.ihc-select__pop') !== null);
tecla($('.ihc-select__buscador input'), 'Escape');

// Desplazarse DENTRO de la lista no puede cerrarla: `scroll` no burbujea, asi
// que hay que escucharlo en captura — y en captura tambien llegan los de
// adentro. Sin filtrar, mover la rueda sobre las opciones cerraba el
// desplegable justo cuando se estaba buscando una.
clic(b2);
const popScroll = $('.ihc-select__pop');
popScroll.querySelector('.ihc-select__lista')
  .dispatchEvent(new window.Event('scroll', { bubbles: false }));
t('desplazar la lista de opciones NO la cierra', $('.ihc-select__pop') !== null);
// Cambiar de tamano RECOLOCA, no cierra: en un telefono, abrir el teclado
// dispara `resize`, y cerrar ahi era cerrar el buscador justo cuando la
// persona iba a escribir en el.
window.dispatchEvent(new window.Event('resize'));
t('cambiar el tamano de la ventana NO cierra el desplegable', $('.ihc-select__pop') !== null);

// La pagina si: el desplegable quedaria flotando lejos de su campo.
window.dispatchEvent(new window.Event('scroll'));
t('desplazar la pagina si la cierra', $('.ihc-select__pop') === null);

const b3 = $('#agrupado').closest('.ihc-select').querySelector('.ihc-select__btn');
clic(b3);
const grupos = $$('.ihc-select__grupo');
t('los <optgroup> se dibujan como encabezados', grupos.length === 2,
  grupos.map((g) => g.textContent).join(' / '));
tecla(b3, 'Escape');

// ════════════════════════════════════════════════════════════════ FECHA
seccion('FECHA');
const fe = $('#fecha');
const fenv = fe.closest('.ihc-fecha');
t('el campo de fecha queda envuelto', !!fenv);
const txt = fenv.querySelector('.ihc-fecha__texto');
t('se muestra en formato argentino', txt.value === '15/03/2026', txt.value);
t('el <input type=date> sigue guardando el valor ISO', fe.value === '2026-03-15', fe.value);
t('y sigue dentro del formulario con su name', !!$('#f1 #fecha') && fe.name === 'fecha');
t('una fecha vacía se muestra vacía',
  $('#vacia').closest('.ihc-fecha').querySelector('.ihc-fecha__texto').value === '');
t('el botón del calendario tiene etiqueta', !!fenv.querySelector('.ihc-fecha__abrir').getAttribute('aria-label'));

clic(fenv.querySelector('.ihc-fecha__abrir'));
let cal = $('.ihc-cal');
t('se abre el calendario', !!cal);
t('tiene rol de diálogo', $('.ihc-pop').getAttribute('role') === 'dialog');
t('arranca en el mes del valor guardado',
  cal.querySelector('.ihc-cal__titulo').textContent.trim() === 'marzo 2026',
  cal.querySelector('.ihc-cal__titulo').textContent.trim());
t('la semana empieza en lunes', cal.querySelectorAll('.ihc-cal__dia')[0].textContent === 'LU');
t('hay 42 celdas (seis semanas, sin saltos de alto)',
  cal.querySelectorAll('.ihc-cal__celda').length === 42,
  String(cal.querySelectorAll('.ihc-cal__celda').length));
t('el día guardado está resaltado', !!cal.querySelector('.ihc-cal__celda.es-elegido'));
t('y lo dice con aria-current',
  cal.querySelector('.ihc-cal__celda.es-elegido').getAttribute('aria-current') === 'date');
let delMes = Array.from(cal.querySelectorAll('.ihc-cal__celda')).filter((c) => !c.classList.contains('es-otro-mes'));
t('marzo de 2026 tiene 31 días en la grilla', delMes.length === 31, String(delMes.length));
t('el 1 de marzo de 2026 cae domingo, así que es la 7ª celda',
  Array.from(cal.querySelectorAll('.ihc-cal__celda')).indexOf(delMes[0]) === 6,
  'índice ' + Array.from(cal.querySelectorAll('.ihc-cal__celda')).indexOf(delMes[0]));
t('cada celda tiene etiqueta legible',
  /^\d+ de \w+ de \d{4}$/.test(delMes[0].getAttribute('aria-label')), delMes[0].getAttribute('aria-label'));

clic(delMes[0]);
t('elegir un día lo guarda en ISO', fe.value === '2026-03-01', fe.value);
t('y actualiza el texto', txt.value === '01/03/2026', txt.value);
t('el calendario se cierra', $('.ihc-cal') === null);

txt.value = '25/12/2026';
txt.dispatchEvent(new window.Event('blur'));
t('se puede escribir la fecha a mano', fe.value === '2026-12-25', fe.value);
txt.value = '5/1/2027';
txt.dispatchEvent(new window.Event('blur'));
t('acepta un día y un mes de una cifra', fe.value === '2027-01-05', fe.value);
t('y lo normaliza al mostrarlo', txt.value === '05/01/2027', txt.value);
txt.value = '7-3-2027';
txt.dispatchEvent(new window.Event('blur'));
t('acepta guiones como separador', fe.value === '2027-03-07', fe.value);
txt.value = '31/02/2027';
txt.dispatchEvent(new window.Event('blur'));
t('el 31 de febrero no existe y se descarta', fe.value === '2027-03-07', fe.value);
t('y vuelve el texto anterior, no queda basura', txt.value === '07/03/2027', txt.value);
txt.value = 'cualquier cosa';
txt.dispatchEvent(new window.Event('blur'));
t('un texto ilegible se descarta', fe.value === '2027-03-07', fe.value);
txt.value = '   ';
txt.dispatchEvent(new window.Event('blur'));
t('dejarlo en blanco vacía el valor', fe.value === '', '[' + fe.value + ']');

fe.value = '2026-06-10';
fe.dispatchEvent(new window.Event('change', { bubbles: true }));
t('un cambio por código se refleja en el texto', txt.value === '10/06/2026', txt.value);

clic(fenv.querySelector('.ihc-fecha__abrir'));
const t0 = $('.ihc-cal__titulo').textContent.trim();
clic($$('.ihc-cal__nav')[0]);
const t1 = $('.ihc-cal__titulo').textContent.trim();
t('el mes anterior retrocede', t1 === 'mayo 2026', t0 + ' → ' + t1);
clic($$('.ihc-cal__nav')[1]); clic($$('.ihc-cal__nav')[1]);
t('el mes siguiente avanza', $('.ihc-cal__titulo').textContent.trim() === 'julio 2026',
  $('.ihc-cal__titulo').textContent.trim());
// Enero → diciembre del año anterior
for (let i = 0; i < 7; i++) { clic($$('.ihc-cal__nav')[0]); }
t('retroceder desde enero pasa a diciembre del año anterior',
  $('.ihc-cal__titulo').textContent.trim() === 'diciembre 2025',
  $('.ihc-cal__titulo').textContent.trim());
clic($$('.ihc-cal__accion').find((b) => b.textContent === 'Hoy'));
t('el botón «Hoy» pone la fecha de hoy',
  fe.value === new Date().toISOString().slice(0, 10), fe.value);
clic(fenv.querySelector('.ihc-fecha__abrir'));
clic($$('.ihc-cal__accion').find((b) => b.textContent === 'Borrar'));
t('el botón «Borrar» vacía el campo', fe.value === '' && txt.value === '');

// ════════════════════════════════════════════════════════════════ CONFIRMACION
seccion('CONFIRMACIÓN');
const f1 = window.document.getElementById('f1');
t('el confirm() se quitó del atributo onsubmit', !f1.hasAttribute('onsubmit'));
let enviado = 0;
f1.submit = () => { enviado++; };

f1.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
let md = $('dialog.ihc-modal');
t('aparece el diálogo propio', !!md);
t('es un <dialog>, no un div con z-index', md.tagName === 'DIALOG');
t('está abierto', md.open === true);
t('reusa el texto del confirm original',
  md.querySelector('.ihc-modal__titulo').textContent.includes('Archivar'),
  md.querySelector('.ihc-modal__titulo').textContent);
t('mantiene las comillas y el guión del texto original',
  md.querySelector('.ihc-modal__titulo').textContent.includes('«TEST — test»'),
  md.querySelector('.ihc-modal__titulo').textContent);
t('tiene rol de alertdialog', md.querySelector('.ihc-modal__caja').getAttribute('role') === 'alertdialog');
t('el botón de aceptar es rojo: la acción es destructiva',
  md.querySelector('[data-ihc-si]').className.includes('ih-btn--danger'),
  md.querySelector('[data-ihc-si]').className);
t('el detalle vacío no deja un párrafo suelto', md.querySelector('.ihc-modal__detalle').hidden);
t('el formulario todavía no se envió', enviado === 0);

clic(md.querySelector('[data-ihc-no]'));

  await new Promise((r) => setTimeout(r, 0));
  t('cancelar cierra el diálogo', md.open === false);
  t('y no envía el formulario', enviado === 0);

  f1.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  clic($('dialog.ihc-modal [data-ihc-si]'));
  await new Promise((r) => setTimeout(r, 0));
  t('confirmar envía el formulario', enviado === 1, String(enviado));
  t('y no lo envía dos veces', enviado === 1);

  // Escape
  f1.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  const m2 = $('dialog.ihc-modal');
  m2.dispatchEvent(new window.Event('cancel', { cancelable: true }));
  await new Promise((r) => setTimeout(r, 0));
  t('Escape cancela (no confirma)', m2.open === false && enviado === 1);

  // Clic en el fondo
  f1.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  clic($('dialog.ihc-modal'));
  await new Promise((r) => setTimeout(r, 0));
  t('el clic en el fondo oscuro cancela', $('dialog.ihc-modal').open === false && enviado === 1);

  seccion('CONFIRMACIÓN · enlaces y botones');
  const a = window.document.getElementById('enlace');
  t('el confirm() se quitó del atributo onclick', !a.hasAttribute('onclick'));
  clic(a);
  const m3 = $('dialog.ihc-modal');
  t('un enlace también abre el diálogo propio', m3.open === true);
  t('con su propio texto',
    m3.querySelector('.ihc-modal__titulo').textContent.includes('Restaurar'),
    m3.querySelector('.ihc-modal__titulo').textContent);
  clic(m3.querySelector('[data-ihc-no]'));
  await new Promise((r) => setTimeout(r, 0));
  t('cancelar en un enlace no navega',
    window.location.pathname === '/inventario/index.php', window.location.pathname);

  const bOtro = window.document.getElementById('btnOtro');
  let enviado2 = 0;
  window.document.getElementById('f2').submit = () => { enviado2++; };
  clic(bOtro);
  const m4 = $('dialog.ihc-modal');
  t('un botón dentro de un formulario también lo usa',
    m4.open === true && m4.querySelector('.ihc-modal__titulo').textContent.includes('Vaciar'),
    m4.querySelector('.ihc-modal__titulo').textContent);
  clic(m4.querySelector('[data-ihc-si]'));
  await new Promise((r) => setTimeout(r, 0));
  t('y al confirmar envía SU formulario', enviado2 === 1, String(enviado2));

  // API publica
  seccion('CONFIRMACIÓN · API');
  t('ihConfirmar está expuesta', typeof window.ihConfirmar === 'function');
  const prom = window.ihConfirmar({ titulo: 'Título', detalle: 'Un detalle.', aceptar: 'Dale', cancelar: 'No', peligro: false });
  const m5 = $('dialog.ihc-modal');
  t('respeta el título y el detalle',
    m5.querySelector('.ihc-modal__titulo').textContent === 'Título'
    && m5.querySelector('.ihc-modal__detalle').textContent === 'Un detalle.');
  t('el detalle presente se muestra', m5.querySelector('.ihc-modal__detalle').hidden === false);
  t('respeta los textos de los botones',
    m5.querySelector('[data-ihc-si]').textContent === 'Dale'
    && m5.querySelector('[data-ihc-no]').textContent === 'No');
  t('con peligro:false el botón no es rojo',
    !m5.querySelector('[data-ihc-si]').className.includes('danger'),
    m5.querySelector('[data-ihc-si]').className);
  clic(m5.querySelector('[data-ihc-si]'));
  t('la promesa resuelve en true al aceptar', (await prom) === true);
  const prom2 = window.ihConfirmar({ titulo: 'Otro' });
  clic($('dialog.ihc-modal [data-ihc-no]'));
  t('y en false al cancelar', (await prom2) === false);
  t('se reusa un solo diálogo, no uno por llamada',
    window.document.querySelectorAll('dialog.ihc-modal').length === 1,
    String(window.document.querySelectorAll('dialog.ihc-modal').length));

  // ════════════════════════════════════════════════════════════ PEDIR TEXTO
  seccion('PEDIR UN TEXTO');
  t('ihPedirTexto está expuesta', typeof window.ihPedirTexto === 'function');

  const pt = window.ihPedirTexto({ titulo: 'Agregar ubicación', etiqueta: 'Nombre', aceptar: 'Agregar' });
  const mt = $('dialog.ihc-modal--texto');
  t('aparece el diálogo de texto', !!mt && mt.open);
  t('es un <dialog>, no un div', mt.tagName === 'DIALOG');
  t('respeta el título y la etiqueta',
    mt.querySelector('.ihc-modal__titulo').textContent === 'Agregar ubicación'
    && mt.querySelector('.ihc-modal__campo-label').textContent === 'Nombre');
  t('el campo arranca vacío', mt.querySelector('#ihc-texto-input').value === '');
  t('el error arranca oculto', mt.querySelector('.ihc-modal__error').hidden);

  // Enviar vacío no puede cerrar el diálogo: `method="dialog"` lo cerraría solo.
  mt.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  t('enviar vacío no cierra y avisa',
    mt.open === true && mt.querySelector('.ihc-modal__error').hidden === false);

  mt.querySelector('#ihc-texto-input').value = '  Depósito Central  ';
  mt.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  t('devuelve el texto sin espacios de más', (await pt) === 'Depósito Central');
  t('y cierra el diálogo', mt.open === false);

  const pt2 = window.ihPedirTexto({ titulo: 'Otro' });
  clic($('dialog.ihc-modal--texto [data-ihc-no]'));
  t('cancelar devuelve null', (await pt2) === null);

  const pt3 = window.ihPedirTexto({ titulo: 'Y otro' });
  $('dialog.ihc-modal--texto').dispatchEvent(new window.Event('cancel', { cancelable: true }));
  t('Escape también devuelve null', (await pt3) === null);

  t('se reusa un solo diálogo de texto',
    window.document.querySelectorAll('dialog.ihc-modal--texto').length === 1);

  // ════════════════════════════════════════════════════════════ ARCHIVOS
  seccion('ARCHIVOS');
  const ar = window.document.getElementById('arch');
  t('el campo queda envuelto', !!ar.closest('.ihc-archivo'));
  t('el input real sigue en el formulario', !!$('#f1 #arch'));
  t('conserva su name con corchetes', ar.name === 'arch[]');
  t('la zona para arrastrar apunta al input real',
    $('.ihc-archivo__zona').getAttribute('for') === 'arch');
  t('hay un texto que explica qué se acepta',
    $('.ihc-archivo__ayuda').textContent.trim().length > 10);

  // ════════════════════════════════════════════════════════════ MODALES
  seccion('DESPLEGABLE DENTRO DE UN MODAL');
  const dlg = window.document.getElementById('modalConSelect');
  dlg.showModal();
  clic($('#dentro').closest('.ihc-select').querySelector('.ihc-select__btn'));
  const popD = $('.ihc-select__pop');
  t('la lista se cuelga del <dialog>, no del <body>',
    !!popD && popD.parentNode === dlg,
    popD ? popD.parentNode.tagName + '#' + popD.parentNode.id : 'no se abrió');
  dlg.close();

  // ════════════════════════════════════════════════════════════ IDEMPOTENCIA
  seccion('ROBUSTEZ');
  const antes = window.document.querySelectorAll('.ihc-select__btn').length;
  window.ihMejorar(window.document);
  t('volver a correr la mejora no duplica nada',
    window.document.querySelectorAll('.ihc-select__btn').length === antes,
    antes + ' → ' + window.document.querySelectorAll('.ihc-select__btn').length);

  const nuevo = window.document.createElement('div');
  nuevo.innerHTML = '<select id="tardio"><option>X</option></select><input type="date" id="ftardio">';
  window.document.body.appendChild(nuevo);
  window.ihMejorar(nuevo);
  t('se puede mejorar contenido agregado después',
    !!$('#tardio').closest('.ihc-select') && !!$('#ftardio').closest('.ihc-fecha'));

  t('ninguna excepción durante toda la prueba', excepciones.length === 0, excepciones.join(' | '));

  console.log('\n' + '═'.repeat(60));
  if (mal === 0) { console.log('  \x1b[32m' + ok + ' checks OK\x1b[0m'); }
  else {
    console.log('  \x1b[31m' + mal + ' fallaron\x1b[0m  ·  ' + ok + ' OK');
    fallas.forEach((f) => console.log('  · ' + f));
  }
  process.exit(mal === 0 ? 0 : 1);
}

main();
