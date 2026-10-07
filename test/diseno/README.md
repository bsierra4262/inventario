# Auditoría de diseño

Mide las pantallas **en un navegador de verdad** y avisa cuando algo se sale del
sistema: un campo que mide distinto del de al lado, una letra fuera de la escala
tipográfica, un botón más chico que el dedo de alguien, la página desbordada en
horizontal, una etiqueta que apunta a un `id` que no existe.

## Por qué existe

`test/run_tests.php` es análisis estático: ve que una clase tenga regla, no cómo
se ve. `test/componentes.js` ejecuta el JavaScript en un DOM, pero jsdom no
calcula layout: no sabe cuánto mide nada.

Y mirar capturas tampoco alcanza. Dos píxeles de diferencia entre un `<input>` y
el botón de al lado no se ven en una imagen reducida, pero sí se ven en la
pantalla — y son exactamente el tipo de cosa que hace que una aplicación parezca
mal terminada sin que nadie sepa decir por qué.

Esto es lo que encontró la primera vez que corrió:

- El botón `+` de los catálogos y el del calendario salían en Arial 13,33px en
  vez de Saira: un `<button>` no hereda la tipografía.
- El mismo `.ih-btn--sm` medía 27px en el panel y 34px en el portal del cliente,
  porque la hoja del panel no declaraba alto mínimo.
- Una insignia escrita con `style="font-size:9px"`.

## Cómo se corre

Necesita Chrome instalado. Desde la raíz del proyecto:

1. Descargar las pantallas con una sesión abierta (o renderizarlas localmente) a
   una carpeta.
2. Inyectar `test/diseno/auditor.js` al final de cada una, con los `assets`
   apuntando a los archivos locales.
3. Abrir cada una con Chrome en modo headless y leer el `<title>`: el auditor
   escribe ahí `AUDIT|OK` o `AUDIT|` seguido de los hallazgos.

```
chrome --headless=new --disable-gpu --no-sandbox --hide-scrollbars \
       --window-size=1440,1000 --virtual-time-budget=3000 --dump-dom \
       file:///ruta/pantalla.html
```

Conviene repetirlo en 1440, 1024, 768 y 500 px. Por debajo de 500 Chrome en modo
headless no hace layout: achica la ventana y recorta la captura, así que medir
ahí no dice nada.

## El verificador de marcado

`test/diseno/marcado.js` revisa que el HTML que llega al navegador esté bien
cerrado:

```
node test/diseno/marcado.js <carpeta-con-paginas-descargadas>
```

No es prolijidad. Cuando una etiqueta se cierra con la que no es, o falta un
`</div>`, el navegador **no avisa**: reacomoda el árbol por su cuenta y la
pantalla sale con el contenido en otro lado. Aparece como un problema de diseño
—«esta sección quedó estirada», «la tarjeta se fue a la otra columna»— y se
busca en el CSS, que está bien.

Encontró dos a la primera: un `<h3 …>Archivos adjuntos</div>` en el detalle del
activo, cuyo `</div>` cerraba la tarjeta antes de tiempo, y un `</div>` de más
en la pantalla de integración que cerraba el `<main>` y dejaba media pantalla
fuera del contenedor.

Mira el HTML **renderizado** y no el `.php`: en el archivo, un `<div>` puede
abrirse dentro de un `if` y cerrarse dentro de otro, y contar etiquetas ahí da
falsos positivos sin parar. En lo que sale no hay PHP: o está balanceado o no.

## El medidor de tablas

`test/diseno/medir_tablas.js` se inyecta igual y escribe su informe en el
`<title>` con el prefijo `TABLA|`: alto de fila por tabla, relleno y tipografía
de la celda, qué celda es la que estira la fila más alta y cuánto mide el
contenido de cada una.

Está aparte del auditor porque **no decide nada**. No hay un alto de fila
«correcto»: una tabla con botones de acción en cada fila no puede ser tan baja
como una de sólo texto. Esto informa, y la decisión sale de comparar una tabla
con la de al lado — que es justamente cómo se ve el problema al usar la
aplicación.

Mide del borde de arriba del primer hijo de la celda al de abajo del último, y
no el más alto de los hijos: cuando una celda apila dos renglones —la fecha y
quién la hizo, el nombre y la clave— lo que estira la fila es la **suma**.
Tomando el máximo informaba 19 px donde había 34, y con ese número la celda
culpable parecía la inocente.

## Dos detalles que cuestan una tarde si no se saben

**El auditor tiene que esperar.** `assets/js/componentes.js` se carga con
`defer`, o sea que corre después de parsear el documento. Un `<script>` inyectado
en el `<body>` corre *durante* el parseo, antes que él, y mediría la pantalla sin
los controles propios. Por eso el auditor se engancha a `load` y espera un tick.

**Los controles nativos siguen en el DOM.** La capa de componentes no los
elimina: los deja ocultos con `clip`. Pero `min-height: 40px` le gana a la altura
de 1px, así que en el modelo de caja siguen midiendo 40px y aparecían en el
informe como campos visibles descolgados. El auditor los descarta mirando el
ancho y el `clip`, no sólo `display` y `visibility`.
