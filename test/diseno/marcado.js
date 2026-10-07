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

    if (!cierra) { pila.push({ tag, i: m.index }); continue; }

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
