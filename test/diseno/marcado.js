/**
 * ¿El HTML que llega al navegador está bien cerrado?
 *
 *   node test/diseno/marcado.js <carpeta-con-paginas-descargadas>
 *
 * No es prolijidad. El navegador no avisa: cuando una etiqueta se cierra con la
 * que no es, o falta un `</div>`, **reacomoda el árbol por su cuenta** y la
 * pantalla sale con el contenido en otro lado. Aparece como un problema de
 * diseño —«esta sección quedó estirada», «la tarjeta se fue a la otra
 * columna»— y se busca en el CSS, que está bien.
 *
 * Pasó dos veces en la misma tanda:
 *
 *   - El detalle del activo tenía `<h3 …>Archivos adjuntos</div>`: el `</div>`
 *     cerraba el cuerpo de la tarjeta antes de tiempo y las tarjetas que venían
 *     después se escapaban de su columna. Se veía como una disposición
 *     desordenada.
 *   - La pantalla de integración tenía un `</div>` de más: todo lo que venía
 *     después quedaba FUERA del contenedor de la página, sin sus márgenes, y
 *     con un hueco de setecientos píxeles en el medio.
 *
 * Y un tercero, que no se ve como un problema de diseño sino que directamente
 * saltea una confirmación: el detalle de la empresa tenía
 * `onsubmit="return confirm(<?= json_encode($texto) ?>)"`, y json_encode emite
 * comillas DOBLES, que cierran el atributo antes de tiempo. Al navegador le
 * llegaba `onsubmit="return confirm("` —un error de sintaxis, o sea ninguna
 * confirmación— y el resto del mensaje entraba como atributos sueltos del
 * `<form>`. El botón «Desactivar usuario» se disparaba sin preguntar nada.
 *
 * Se mira el HTML **renderizado** y no el `.php` a propósito: en el archivo, un
 * `<div>` puede abrirse dentro de un `if` y cerrarse dentro de otro, y contar
 * etiquetas ahí da falsos positivos sin parar. En lo que sale no hay PHP: o
 * está balanceado o no lo está.
 */

'use strict';

const fs = require('fs');
const path = require('path');

/* Las que no se cierran nunca. `</br>` y `</img>` no existen. */
const VACIAS = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
  'meta', 'param', 'source', 'track', 'wbr',
]);

/* Las que el navegador cierra solo cuando aparece la siguiente: exigirles un
   cierre explícito sería inventar un error que no existe. */
const AUTOCIERRAN = new Set(['li', 'dt', 'dd', 'p', 'option', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th']);

/* Lo de adentro no es marcado. */
const OPACAS = new Set(['script', 'style', 'textarea', 'pre']);

/**
 * ¿El código de un manejador tiene los paréntesis sin cerrar?
 *
 * Se saltean las cadenas: un `alert(')')` está perfecto. Y se cuenta sólo de
 * más: `foo)` cerrando de más también está mal, pero lo que importa acá es el
 * atributo truncado, que siempre deja paréntesis ABIERTOS.
 */
function desbalanceado(codigo) {
  let prof = 0;
  let comilla = '';
  for (let i = 0; i < codigo.length; i++) {
    const ch = codigo[i];
    if (comilla) {
      if (ch === '\\') { i++; continue; }
      if (ch === comilla) { comilla = ''; }
      continue;
    }
    if (ch === '"' || ch === "'") { comilla = ch; continue; }
    if (ch === '(') { prof++; }
    else if (ch === ')') { prof--; }
  }
  return prof !== 0 || comilla !== '';
}

function revisar(html) {
  const fallas = [];
  const pila = [];
  const rx = /<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|'[^']*'|[^>"'])*)>/g;
  let m;

  const linea = (i) => html.slice(0, i).split('\n').length;

  while ((m = rx.exec(html)) !== null) {
    const cierra = m[1] === '/';
    const tag = m[2].toLowerCase();
    const attrs = m[3] || '';

    if (VACIAS.has(tag) || attrs.trimEnd().endsWith('/')) { continue; }

    if (OPACAS.has(tag) && !cierra) {
      // Saltar hasta su cierre: adentro puede haber `a < b` o un `</div>` en un string.
      const fin = html.toLowerCase().indexOf('</' + tag, rx.lastIndex);
      if (fin === -1) { fallas.push(`linea ${linea(m.index)}: <${tag}> nunca cierra`); break; }
      rx.lastIndex = fin + tag.length + 3;
      continue;
    }

    if (!cierra) {
      /* Un atributo repetido: el navegador se queda con el primero y descarta el
         resto, sin decir nada. Pasó con `<td class="ih-text-center"
         class="ih-table__nowrap">`, donde la segunda clase simplemente no
         existía — y eso se busca en el CSS, que estaba bien. */
      /* Se recorre saltando el valor de cada atributo: un `onclick` puede
         traer `class=` adentro de su texto —`this.innerHTML='<i class=...>'`—
         y contarlo seria inventar un error donde no hay ninguno. */
      const vistos = new Set();
      let k = 0;
      while (k < attrs.length) {
        const resto = attrs.slice(k);
        const nom = /^\s*([a-zA-Z_:][-a-zA-Z0-9_:.]*)/.exec(resto);
        if (!nom) { k++; continue; }
        k += nom[0].length;
        const nombre = nom[1].toLowerCase();
        const igual = /^\s*=\s*/.exec(attrs.slice(k));
        /* El valor SIN las comillas que lo delimitan: con ellas, un atributo
           truncado como `onsubmit="return confirm("` se lee como una cadena
           entera y bien cerrada, y el desbalanceo no se ve. */
        let desdeValor = k;
        let hastaValor = k;
        if (igual) {
          k += igual[0].length;
          const q = attrs[k];
          if (q === '"' || q === "'") {
            const fin = attrs.indexOf(q, k + 1);
            desdeValor = k + 1;
            hastaValor = fin === -1 ? attrs.length : fin;
            k = fin === -1 ? attrs.length : fin + 1;
          } else {
            const sinComillas = /^[^\s]*/.exec(attrs.slice(k));
            desdeValor = k;
            k += sinComillas ? sinComillas[0].length : 0;
            hastaValor = k;
          }
        }
        if (vistos.has(nombre)) {
          fallas.push(`linea ${linea(m.index)}: <${tag}> repite el atributo ${nombre}`);
        }
        vistos.add(nombre);

        /* Un manejador con los paréntesis sin cerrar es un atributo que se
           cortó a la mitad: casi siempre, una interpolación que metió una
           comilla igual a la que delimita el atributo. El navegador no avisa
           —el handler no compila y el formulario se manda igual—, así que la
           confirmación que uno cree que puso no existe. */
        if (nombre.startsWith('on')) {
          const valor = attrs.slice(desdeValor, hastaValor);
          if (desbalanceado(valor)) {
            fallas.push(
              `linea ${linea(m.index)}: <${tag} ${nombre}> se corta a la mitad ` +
              `(parentesis sin cerrar): ${valor.trim().slice(0, 60)}`
            );
          }
        }
      }
      pila.push({ tag, i: m.index });
      continue;
    }

    // Un cierre. Buscarlo en la pila, de adentro hacia afuera.
    let k = pila.length - 1;
    while (k >= 0 && pila[k].tag !== tag) { k--; }

    if (k < 0) {
      fallas.push(`linea ${linea(m.index)}: </${tag}> de mas (no hay ningun <${tag}> abierto)`);
      continue;
    }

    /* Lo que quedó abierto entre medio. Si todo lo que sobra se autocierra, el
       navegador hace lo mismo que nosotros y no hay nada que reportar. */
    const colgando = pila.slice(k + 1).filter((e) => !AUTOCIERRAN.has(e.tag));
    if (colgando.length) {
      const e = colgando[colgando.length - 1];
      fallas.push(
        `linea ${linea(e.i)}: <${e.tag}> se cierra con un </${tag}> de linea ${linea(m.index)}`
      );
    }
    pila.length = k;
  }

  for (const e of pila.filter((x) => !AUTOCIERRAN.has(x.tag))) {
    fallas.push(`linea ${linea(e.i)}: <${e.tag}> queda sin cerrar`);
  }
  return fallas;
}

module.exports = { revisar };

if (require.main === module) {
  const dir = process.argv[2];
  if (!dir || !fs.existsSync(dir)) {
    console.error('Uso: node test/diseno/marcado.js <carpeta-con-paginas-descargadas>');
    process.exit(2);
  }
  let mal = 0;
  let vistas = 0;
  for (const f of fs.readdirSync(dir).sort()) {
    if (!f.endsWith('.html')) { continue; }
    const html = fs.readFileSync(path.join(dir, f), 'utf8');
    if (!html.trim()) { continue; }
    vistas++;
    const fallas = revisar(html);
    if (!fallas.length) { console.log(`  OK   ${f}`); continue; }
    mal++;
    console.log(`  MAL  ${f}`);
    for (const x of fallas.slice(0, 6)) { console.log(`         ${x}`); }
    if (fallas.length > 6) { console.log(`         y ${fallas.length - 6} mas`); }
  }
  console.log(`\n  ${vistas} paginas · ${mal ? mal + ' con el marcado roto' : 'ninguna con el marcado roto'}`);
  process.exit(mal ? 1 : 0);
}
