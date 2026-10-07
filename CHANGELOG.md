# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

---

## [1.9.0] — 2026-10-07

Lo que apareció al usar el portal de verdad: un PDF que salía vacío, campos del
sistema operativo que nunca se guardaban, y un alta que obligaba a pasar por dos
pantallas para cargar una computadora.

### Corregido

- **La papelera se cortaba a la mitad en cuanto tenía un activo.** El botón
  «Ver el detalle» de cada fila pasó a llevar `volver_aqui()` —para que
  «Cancelar» devuelva ahí— y faltó el `require_once` de
  `includes/volver.php`. PHP no avisa hasta que ejecuta esa línea, y esa línea
  sólo se ejecuta si hay filas: con la papelera vacía la pantalla salía
  perfecta. Con un activo archivado el HTML se cortaba a mitad del primer
  `<a href=`, sin encabezado roto, sin menú roto y sin ningún mensaje —y, como
  la tabla quedaba sin el formulario de eliminar, el POST siguiente salía sin
  token y volvía 419, que parece un problema de permisos y no un `require`
  que falta.

  Lo cubre `test/requires.php`, abajo.

- **El PDF de reportes salía con el encabezado y ninguna fila.** Las filas se
  pintaban con `color: var(--ink)` y `fillColor: var(--soft)` — variables CSS
  pasadas a pdfMake, que no es un navegador y no sabe resolverlas. El encabezado
  usaba `#663399` y por eso sí salía: el PDF bajaba con el título, la franja
  violeta y nada debajo. El CSV, que no pasa por ahí, siempre estuvo bien.

  Se verifica generando el documento en Chrome y contando lo que tiene adentro,
  no leyendo el código: la definición ahora trae las filas con su contenido y
  cero colores sin resolver.

- **La versión, la edición y la arquitectura del sistema operativo nunca se
  guardaron.** Las columnas se llaman `version`, `edicion` y `arquitectura`; los
  campos del formulario se llaman `so_version`, `so_edicion` y
  `so_arquitectura`, porque el nombre lo pone el tipo de catálogo. Las dos
  pantallas que guardaban buscaban la columna a secas, así que `so` y `kernel`
  entraban —se llaman igual de los dos lados— y los otros tres se perdían **en
  silencio**: se elegían, se enviaba el formulario y el campo volvía vacío.

- **«Cancelar» no deshacía el paso.** Se entraba a editar un activo desde el
  listado, se cancelaba, y la edición devolvía siempre al detalle del activo —
  una pantalla por la que nadie había pasado. Ahora cada pantalla de ida y
  vuelta recibe de dónde vino (`?volver=`) y vuelve ahí, conservando la página y
  los filtros que había. Una cadena de tres pantallas vuelve al principio y no
  al medio. El destino se valida contra una lista de secciones conocidas: un
  `?volver=//otro-sitio.com` es una redirección abierta, y no se acepta.

- **«Contraseña» en una empresa que entra sólo con Google o Microsoft.** La
  opción llevaba a cambiar algo que no sirve para entrar. Ahora no se muestra, y
  la pantalla tampoco se abre escribiendo la dirección.

### Agregado

- **El alta carga la ficha técnica entera en un solo paso.** Antes creaba el
  activo y mandaba a la ficha para cargar componentes, red, relaciones,
  contratos, documentos y notas: dos pantallas y ocho formularios separados para
  dar de alta UNA computadora, cada uno con su propio envío — cargabas un disco,
  se recargaba la página, cargabas la memoria, se recargaba otra vez.

  Las filas se agregan y se quitan sin recargar nada, porque no se sabe cuántas
  hay: una notebook tiene un disco, un servidor tiene seis, un switch tiene
  veinticuatro puertos. Una fila que no se puede guardar no aborta el alta: se
  anota el motivo y se sigue, y el aviso de llegada dice con números qué entró.

- **Versión y Edición salen de una lista, atadas al sistema operativo.** Los dos
  desplegables estaban vacíos, con el cartel «La lista está vacía. Cargá los
  valores en Listas desplegables»: para anotar «Windows 11 · 24H2 · Pro» había
  que ir a otra pantalla, dar de alta «24H2», dar de alta «Pro» y volver. Nadie
  hace eso.

  Ahora se elige el sistema y las dos listas pasan a ser las de ese sistema:
  Windows 11 trae 25H2, 24H2, 23H2, 22H2 y 21H2; Ubuntu trae las release desde
  16.04 LTS; macOS trae de Mojave a Tahoe. Son 63 sistemas con 210 versiones y
  98 ediciones, en `core/tenants/so_versiones.php`.

  La lista llega hasta lo que se puede afirmar. Una lista de versiones
  inventadas es peor que una corta: alguien elige una de un desplegable, la da
  por buena, y queda un inventario con un dato que no existe. Lo que falte se
  agrega con el `+` sin salir de la pantalla, y queda disponible para siempre.

- **`ihAvisar()`**, un aviso de un solo botón con el diseño del sistema.
  Quedaban tres `alert()` del navegador — dos en la edición y uno en la
  exportación — que salen con el nombre del dominio arriba y la tipografía del
  sistema operativo.

### Cambiado

- **Las tablas, compactas y todas a la misma altura.** Medidas en Chrome: una
  fila del listado de activos medía 87 px y una de reportes 43, y las de campos
  iban de 88 a 122 según lo que hubiera adentro. La diferencia no era el tamaño
  de la letra —todas usan la misma clase— sino tres datos apilados uno debajo
  del otro donde entraban en un renglón, y «Lista de opciones» partido en dos
  líneas por una columna angosta.

  Ahora: activos 51, reportes 37, campos 55–56 — y parejas dentro de cada tabla.
  En campos entran catorce filas donde antes entraban siete.

- **Reportes tiene la misma estructura que Activos**: los filtros y la tabla
  como dos tarjetas pegadas que se leen como una, con el paginador en el pie.
  Antes eran cuatro bloques sueltos apoyados sobre el fondo, y la misma tabla
  parecía más chica.

- **El color de los KPI sigue al número.** «Garantía próxima» estaba siempre en
  ámbar y «Archivados» siempre en rojo, incluso diciendo cero. Un tablero con
  dos de cinco tarjetas en alerta permanente no avisa nada: la alerta deja de
  significar algo.

- **Cincuenta y tres campos tenían rótulo y no ejemplo.** «N.º de remito» vacío
  no ayuda: quien no sabe qué va ahí no lo completa, y quien sabe no sabe en qué
  formato se espera. Ahora dice «Ej.: 0001-00045212».

- **El cartel de lista vacía apunta al botón `+` de al lado** en lugar de mandar
  a otra pantalla a cargar el valor y volver.

### Corregido en los auditores

`test/ayudas.php` daba verde con cincuenta y tres campos sin ejemplo, por dos
motivos que se tapaban entre sí:

- La regla «un `type` armado con PHP no se puede clasificar» descartaba la
  etiqueta entera si tenía un `<?php` en **cualquier** atributo — y casi todo
  campo real trae `value="<?= … ?>"`. Se estaba salteando la mayoría de los
  campos del proyecto.
- La expresión que recorta la etiqueta se cortaba en el `?>` del primer `value`,
  así que el `placeholder` que venía después quedaba afuera: campos que SÍ
  tenían ejemplo se reportaban como que no.

Y el desplegable propio del sistema dibujaba también las opciones con `hidden`,
que es lo que usa Versión para esconder lo que no es del sistema elegido.

### Agregado en los auditores

`test/requires.php` — para cada pantalla junta todo lo que llama, ella y sus
parciales, y exige que la función esté definida en algún archivo de su cadena de
`require`. Encuentra el caso de la papelera sin tener que acertar con los datos
que hacen entrar al `if`, que es la razón de que se haya desplegado roto.

Reconoce los tres modismos del proyecto que SÍ son correctos y no los reporta:
la carga diferida (`$p = __DIR__ . '/x.php'; if (is_file($p)) require_once $p`),
la llamada protegida con `function_exists()` —el logger es opcional a
propósito— y la que vive dentro de un `try` con `catch (Throwable)`, porque una
función que no existe lanza `Error` y `Error` es un `Throwable`.

Sobre ese último: detectar los `try` obligó a contar llaves bien. La
interpolación de `"SELECT ... FROM `{$tabla}`"` abre con un token propio
(`T_CURLY_OPEN`) y cierra con una `}` suelta, así que contando sólo las llaves
literales el contador se iba a negativo en la primera consulta y el bloque
parecía terminar ahí. Pasó: con el contador ingenuo se detectaban 2 `try` en un
archivo que tiene once.

Lo único que encontró, además de la papelera: `core/tenants/infocom.php` llama a
`catalogo_normalizar()` y no traía `catalogos.php`. No estaba dando un fatal
porque las dos pantallas que lo incluyen sin el otro —el detalle y reportes—
nada más leen; el día que una guardara, se caía. Ahora lo trae él.

---

## [1.8.0] — 2026-10-07

Repaso del portal del cliente pantalla por pantalla, con una regla: nada de estilos escritos en
la página. Lo que una pantalla necesitaba y el sistema no tenía, se agregó al sistema; lo que ya
existía con otro nombre, se unificó. Y la papelera, que se podía llenar y no vaciar, se completó.

### Agregado

- **Eliminar definitivamente un activo** (`backend/eliminar_definitivo.php`). `asset.delete`
  —«Eliminar definitivamente»— figuraba en la pantalla de roles desde el principio, se podía
  tildar y destildar, y no hacía nada: ninguna pantalla ni ningún endpoint lo consultaba, así que
  la papelera se llenaba y nunca se vaciaba. El requerimiento I9 estaba marcado como cumplido sin
  estarlo.

  Tres condiciones: sólo se elimina lo que **ya está archivado** —desde el listado no se llega, y
  archivar sí se deshace—; hay que **escribir el número del activo**, porque un «¿está seguro?» se
  acepta sin leerlo y copiar un número obliga a mirar cuál; y el **historial sobrevive**, con una
  fila `Eliminado` que guarda el modelo, la marca, el service tag y quién lo borró. La carpeta de
  Drive no se toca: borrar los archivos de alguien desde acá sería una sorpresa desagradable.

- **La cascada en un módulo** (`core/tenants/papelera.php`). El esquema del tenant no tiene claves
  foráneas, así que las ocho tablas que cuelgan de un activo —más las direcciones IP, que cuelgan
  del puerto, y las relaciones, que apuntan en los dos sentidos— hay que nombrarlas a mano. Una
  lista escrita a mano en medio de un endpoint es una lista que se olvida de actualizar, así que
  está en un solo lugar, y la batería de integración la compara contra las tablas que el esquema
  realmente tiene: si aparece una tabla con `producto_id` y nadie la suma, el test falla.

- **Confirmación escrita en el diálogo del sistema.** `ihPedirTexto()` acepta `esperado`, que no
  deja seguir hasta que lo escrito coincida —sin espacios, sin mayúsculas y sin acentos: la idea
  es que la persona confirme, no que acierte un dictado—, `detalle` para explicar qué se está por
  hacer y `peligro` para pintar el botón de aceptar como el resto de lo destructivo.

- **Cinco componentes compartidos nuevos** en `assets/css/componentes.css`, la hoja que cargan los
  dos portales: `.ih-barra` (título de una sección con sus botones, que en pantalla angosta pasan
  abajo y a lo ancho), `.ih-alert--accion` (el aviso que trae el botón que lo resuelve),
  `.ih-buscador` (con la variante de lupa adentro del campo), `.ih-pills` y `.ih-permisos`.

### Cambiado

- **Los tres paginadores son uno.** Activos lo armaba con botones, Reportes con `.pagination-cool`
  y la papelera dibujaba **un botón por página**: con tres mil activos archivados eran ciento
  cincuenta botones y la tarjeta se desarmaba. Ahora las tres usan `.ih-pager`, con el rango de
  páginas acotado, el «mostrando X–Y de Z» y el selector de cuántas por página.

- **Las pastillas de rol estaban escritas dos veces.** `inventario/roles.php` las definía en 43
  líneas y `dashboard/superadmin_roles.php` en 12, con valores distintos: el mismo selector se
  veía de una manera en un portal y de otra en el otro. Ahora son `.ih-pill` y `.ih-permisos`, en
  la hoja compartida, sin un solo `!important`.

- **El listado de activos ya no define sus propios avisos.** Tenía un `<style>` que repintaba
  `.ih-flash` con `#16a34a`, `#d97706` y `#b91c1c` —un verde, un ámbar y un rojo de otra paleta— y
  como la hoja de la página carga después de la del sistema, le ganaba: en la misma pantalla
  convivían dos verdes distintos.

- **Reportes dejó de estar en verde.** Sus 69 líneas de estilos propios se mapearon a los
  componentes del sistema.

- **`.ih-alert` ya traía su margen inferior** y catorce pantallas lo volvían a escribir a mano, con
  tres medidas distintas —16, 20 y 24 px— para el mismo hueco. Se sacaron las catorce.

- **`roles.php` no tiene ni un `style=`.** Los paneles se muestran y se esconden con el atributo
  `hidden` en lugar de `style.display`, y las pastillas declaran `aria-pressed`, que es lo que son:
  botones de dos estados, no pestañas de navegación.

- **Textos.** «CUSTOM» y «Default» en inglés y a los gritos pasaron a «Editado» y «Sin cambios»;
  «Restaurar default» a «Restaurar originales»; «Click acá para reparar» explica qué hace el botón.
  Las confirmaciones a las que les faltaba el signo de apertura ya lo tienen.

### Corregido

- **El botón «Hoy» del calendario no estaba mal: el test sí.** Comparaba contra
  `toISOString()`, que es UTC, y fallaba todas las tardes a partir de las 21:00 de Argentina —el
  componente ponía el 6 y la prueba esperaba el 7—. El «hoy» que espera la persona es el de su
  reloj.

### El portal del cliente, pantalla por pantalla

Se recorrieron las dieciocho pantallas en el orden pedido —Activos, Reportes, Papelera, Datos,
Configuración y lo que cuelga de cada una— con una regla: **ninguna pantalla define estilos
propios**. Al final quedaron cero bloques `<style>` y cero atributos `style`, salvo dos que la
prueba acepta por nombre: el nivel de sangría de un árbol, que viaja como `--nivel` porque es un
dato, y el color de los logos de Google y Microsoft, que pintados de violeta dejan de ser los
logos.

No es prolijidad. La hoja de la página carga **después** de la del sistema, así que una regla de
pantalla le gana a la del sistema sin pedir permiso — y una regla escrita en una pantalla no la
ve ninguna otra, así que el mismo componente termina con dos formas.

Lo que estaba duplicado y ahora está una vez:

- **El paginador, en tres versiones.** Activos lo armaba con botones, Reportes con
  `.pagination-cool` y la papelera dibujaba **un botón por página**: con tres mil activos
  archivados eran ciento cincuenta botones.
- **Las pastillas de rol, en dos.** `inventario/roles.php` las definía en 43 líneas y
  `dashboard/superadmin_roles.php` en 12, con valores distintos: el mismo selector se veía de una
  manera en un portal y de otra en el otro.
- **El rótulo de sección, en tres,** con tres nombres: `.edit-section-title`, `.vd-section-title`
  y `.ih-resumen__titulo`.
- **La pantalla de una sola decisión, en tres.** Archivar, el aviso de archivo duplicado y el
  resultado son el mismo molde: 48 atributos `style` entre las tres, con la tarjeta de 540, 600 y
  560 px y el relleno de 2, 2 y 2.5 rem. El icono de dos de ellas tenía
  `linear-gradient(135deg, var(--warn), var(--warn))` — un degradado entre un color y el mismo
  color.
- **Las utilidades de disposición, en las dos hojas de portal y con distinto alcance.** `app.css`
  tenía la escala de márgenes de 1 a 6 y el panel sólo los pares, así que una pantalla compartida
  usaba clases que en el panel no existen.
- **El margen inferior de `.ih-alert`,** que el componente ya traía y catorce pantallas volvían a
  escribir con tres medidas distintas para el mismo hueco.
- **La cruz de cerrar un aviso,** en el `onclick` de siete botones y de cuatro maneras: tres
  ponían `display:none` —que deja el margen ocupando lugar—, tres llamaban a `.remove()`, y una
  dibujaba la cruz con el carácter «×».

### Corregido (bugs que estaban en producción)

- **Dos zonas de arrastrar archivos, una dentro de la otra.** `inventario/agregar.php` e
  `inventario/importar.php` dibujaban su propia zona con el `<input type="file">` adentro, y
  `componentes.js` mejora *todos* los campos de archivo: el input se mudaba a su propio envoltorio
  y la zona de afuera quedaba sin nada que la activara al hacer clic. Las dos pantallas usan ahora
  el campo del sistema.

- **Un `<style>` de pantalla que pisaba los componentes del sistema.** `agregar.php` redefinía
  `.ih-alert`, `.ih-label` y `.ih-field__hint` con otros valores, y como su hoja carga después, le
  ganaba: los avisos de esa pantalla se veían distintos de los de todas las demás.

- **`agregar.php:322` tenía dos atributos `style` en el mismo `<div>`.** El navegador se queda con
  el primero, así que el margen que pedía el segundo nunca se aplicó.

- **Un permiso que bloqueaba la pantalla a quien tenía el otro.** `importar.php` exigía
  `asset.backup_full` para entrar, pero la tarjeta de *Datos* dejaba pasar a quien tenía sólo
  `asset.import_csv`: hacía clic y se chocaba con «acceso denegado». Ahora pide cualquiera de los
  dos y habilita cada mitad por separado.

- **Estilos de pantallas compartidas apoyados en tokens de otro portal.**
  `backend/view_details.php` y `backend/edit.php` los carga el inventario **y** el panel —la
  cabecera elige la hoja según la sesión— y definían `.vd-*` y `.edit-*` en su propio `<style>`.

- **Un grid de dos columnas fijas parcheado con un selector por subcadena del atributo `style`.**
  `importar.php` tenía `[style*="grid-template-columns:1fr 1fr"] { … !important }` para que en un
  teléfono no quedara de dos columnas.

- **Nombres internos a la vista de cualquiera.** La pantalla de respaldo mostraba
  `carpeta_raiz_drive`, `IMPORT_BACKUP_UPDATE` y `export_csv.php` en el texto que lee la persona
  que sube un archivo. Pasaron al docblock, que es para quien lee la base.

- **«Perdida» sin tilde en un desplegable, y «Scrapping» en una pantalla en castellano.** Los
  motivos para archivar estaban escritos en dos pantallas que no se hablan: `backend/delete.php`
  los ofrecía e `inventario/reportes.php` filtraba por ellos. Ahora salen de `PAPELERA_MOTIVOS`,
  con la etiqueta separada del valor guardado — el valor en la base no se toca, así que los
  activos ya archivados siguen encontrándose.

- **`esconder` mezclado de dos formas.** `componentes.css` declara
  `[hidden] { display: none !important }`, así que un `style.display = ''` no vuelve a mostrar un
  bloque que tenga el atributo. Se unificaron 61 usos en el alta, la edición, los reportes y el
  selector de columnas del listado.

### Agregado

- **El campo de archivo del sistema valida antes de enviar**: tamaño y formato, contra el `accept`
  del propio campo, y dice cuál archivo no entró y por qué. Deja **quitar uno** de la lista —antes,
  elegir cuatro y arrepentirse del tercero obligaba a volver a elegir los cuatro— y **suma** a la
  selección en lugar de reemplazarla.

- **`test/tokens.php`**, que compara los 43 tokens que usa la hoja compartida resueltos en cada
  portal. Difieren 36: nueve a propósito —los `--ih-ctl-*`, porque los controles del panel son más
  compactos— y veintisiete que son deuda. El panel todavía corre con la escala de grises de
  Tailwind y con `--ih-success: #16a34a`, que es el mismo verde ajeno que hubo que sacar de las
  pantallas del cliente. Cada diferencia está anotada una por una y la prueba **no exige que la
  deuda esté pagada: exige que no crezca.**

- **Un chequeo que busca bytes de control en el código.** Existe porque pasó dos veces: un `\b`
  escrito en una expresión regular llegó al archivo como un byte de retroceso de verdad (0x08), y
  el patrón quedó buscando algo que no existe en ningún HTML. No rompe, no avisa: simplemente deja
  de encontrar lo que buscaba.

### Corregido en los auditores

Tres resultaron estar midiendo mal, y en los tres casos lo que se arregló fue el auditor:

- `test/ayudas.php` no miraba dentro de `<script>`, así que un comentario de JavaScript que
  menciona «`<input>`» contaba como un campo de formulario sin ejemplo.
- `test/ortografia.php` leía la cola de un atributo partido por una etiqueta PHP como si fuera
  texto: `ih-alert--accion">` se reportaba como «accion» sin tilde.
- El chequeo de clases por portal trataba `includes/` entero como compartido, así que una clase
  del sidebar del cliente se reportaba como faltante en la hoja del panel, que nunca lo dibuja.
- El chequeo de las tarjetas del alta buscaba `class="ih-card"` exacto, así que al agregarles una
  clase de separación contaba cero cabeceras y fallaba por no encontrar nada.

### Pruebas

La batería estática pasó de 375 a **401** chequeos, la de componentes de 137 a **144** y la de
integración de 234 a **260**. Más dos auditores nuevos: `test/colores.php`, que busca colores
escritos a mano fuera de la identidad visual, y `test/tokens.php`, que compara los tokens de los
dos portales.

Lo que se verificó contra el servidor, no sólo en el repositorio:

- Las dieciocho pantallas del portal responden, traen contenido y **ninguna** define estilos
  propios — 58 comprobaciones, incluidas las de que los assets lleguen con su `?v=` y no una
  versión vieja de la caché del CDN.
- El recorrido completo de un activo, cada paso por su endpoint real y por HTTP: alta con sistema
  operativo, ficha, detalle, archivar, papelera, restaurar, archivar otra vez y eliminar — 35
  comprobaciones, y la base vuelve a quedar como estaba.
- El borrado definitivo, con sus negativos: sin confirmación no borra, con el número equivocado
  no borra, sin token no borra, un activo del inventario no se elimina desde la papelera, el
  historial sobrevive y el activo de al lado no se toca — 34 comprobaciones.

---

## [1.7.0] — 2026-10-06

Fases 4 y 5 del modelo de inventario, tomadas de GLPI: el activo por dentro —sistema operativo,
componentes, red y relaciones— y por fuera —proveedores, contratos, documentos, notas y
plantillas—. Migraciones 8 y 9.

### Agregado

- **Sistema operativo por activo** (`activo_so`): nombre, versión, edición, arquitectura, kernel,
  service pack, clave de producto, número de licencia y fecha de instalación. Con esto, «cuántas
  máquinas siguen en Windows 10» es una consulta y no una revisión equipo por equipo.

- **Componentes internos** (`componentes` + `activo_componente`): procesador, memoria, disco,
  placa de red, video, fuente, placa madre y firmware. El catálogo es compartido —«Intel Core
  i5-1135G7» se carga una vez y se usa en los cuarenta equipos que lo tienen—, que es lo que
  permite preguntar «cuántas máquinas tienen menos de 8 GB» antes de comprar memoria.

  GLPI usa una tabla de catálogo y una de enlace **por cada tipo**: dieciséis veces el mismo par.
  Acá son dos tablas con una columna `tipo`, porque los campos que varían entre tipos son cuatro
  —capacidad, frecuencia, núcleos, MAC— y entran en la misma fila quedando nulos donde no aplican.

- **Puertos de red y direcciones IP** (`puertos_red` + `direcciones_ip`): un activo tiene varios
  puertos —un switch tiene veinticuatro— y un puerto varias direcciones, IPv4 e IPv6. La MAC se
  guarda normalizada: llegan escritas con guiones, con puntos, pegadas y en minúscula, y guardadas
  tal cual no se podía buscar por MAC. Se puede preguntar «qué equipo tiene la 10.0.0.47».

- **Relaciones entre activos** (`activo_relacion`): conectado a, parte de, reemplaza a, depende de,
  es respaldo de. La relación se ve desde los dos activos, con la etiqueta que corresponde a cada
  lado. Sirve para saber qué se cae con qué antes de dar algo de baja.

- **Proveedores y contratos** (`proveedores`, `contratos`, `contrato_activo`): hasta acá, «¿hasta
  cuándo tenemos soporte de este servidor?» no tenía dónde contestarse —el proveedor era un texto
  suelto en los datos de compra y el contrato no existía—. Incluye la pantalla de lo que vence en
  los próximos noventa días, con el preaviso de cada contrato.

- **Documentos y notas** (`documentos`, `documento_activo`, `notas`): el archivo sigue viviendo en
  Drive o en OneDrive; lo que se guarda es **qué es** —factura, remito, garantía, manual—, que es
  justo lo que no se podía saber: en la carpeta de un activo hay seis PDF y ninguno dice cuál es
  cuál. Las notas son lo que alguien quiere dejar escrito y no es el cambio de ningún campo.

- **Plantillas de alta** (`plantillas`): cargar veinte notebooks iguales es llenar veinte veces los
  mismos ocho campos.

- **`inventario/ficha.php`**, la ficha técnica y administrativa de un activo: las siete secciones en
  **una sola pantalla**, una debajo de la otra, con un índice arriba para saltar. GLPI reparte esto
  en siete pestañas; lo que se busca casi siempre es «contame todo lo de este equipo», y con
  pestañas hay que acordarse de ir a mirar cada una.

- **`inventario/contratos.php`**, proveedores y contratos en una pantalla, con lo que vence arriba.

- **La batería de integración acepta `--prefijo=`**: trabaja en sus propias tablas y borra sólo
  ésas, así que corre contra una base compartida sin tocar un dato. De paso ejercita el modo
  `prefix` de la plataforma, soportado desde el principio y que hasta ahora no probaba nadie.
  234 chequeos contra MySQL real.

### Corregido

- **Sumarle un mes al 31 de enero daba el 3 de marzo.** PHP desborda el 31 de febrero hacia marzo.
  El cálculo de la fecha de fin de un contrato ahora cuenta el mes a mano: cuando el día no existe
  en el mes destino, el contrato termina el último día de ese mes.

- **Buscar por MAC devolvía una fila por cada dirección del puerto**: un puerto con IPv4 e IPv6
  aparecía dos veces, como si fueran dos equipos distintos.

- **La batería de integración tenía dieciocho tablas escritas a mano.** Con `--prefijo` eso
  escribía en las tablas **reales**: tres activos de prueba terminaron en el inventario de
  desarrollo. Todo su SQL pasa ahora por `$T['tabla']`, el segundo prefijo cuelga del principal
  para que la limpieza también lo alcance, y la batería **se lee a sí misma y aborta** si vuelve a
  aparecer una sin prefijar.

- **El test de migraciones fijaba la lista `[6, 7]`.** Había que editarlo cada vez que se sumaba
  una migración, y un test que hay que tocar para que siga pasando deja de servir como red. Ahora
  calcula lo que está pendiente.

### Cambiado

- **El rastreo de alcance de las pantallas sigue también `backend/`.** `view_details.php` y
  `edit.php` son pantallas, no endpoints, y desde ellas se llega a otras: la ficha técnica cuelga
  del detalle del activo.

- **El detalle del activo muestra un resumen de la ficha** —qué sistema operativo, cuántos
  componentes, cuántos contratos— y el acceso para cargarla. Sigue siendo la vista de lectura.

- **El listado tiene acceso directo a la ficha**, al lado de ver y editar.

### Seguridad

- **`backend/ficha_crud.php` y `backend/contratos_crud.php` exigen `asset.edit`**, no sólo sesión.
  Un endpoint por sección habría sido repetir siete veces la misma guardia de sesión, permiso y
  CSRF — y ya pasó que a cinco endpoints se les olvidara una de las tres.

- **El activo tiene que ser de este tenant.** Sin esa verificación, un id de otra empresa escrito a
  mano dejaría colgar componentes de un activo ajeno. Lo mismo con el puerto al agregarle una IP.

---

## [1.6.0] — 2026-10-06

Rediseño de los dos portales: capa propia para los controles que dibuja el navegador,
reorganización de la navegación del inventario, y una auditoría que mide el diseño en un
navegador de verdad en lugar de mirarlo.

### Agregado

- **Capa de componentes compartida** (`assets/css/componentes.css` + `assets/js/componentes.js`),
  que cargan los dos portales. El navegador dibuja por su cuenta la lista de un `<select>`, el
  calendario de `input[type=date]` y el cuadro de `confirm()`: ninguna regla CSS los alcanza, y
  salen con los colores del sistema operativo. La única salida es reemplazarlos.

  Es mejora progresiva: el control nativo **no se elimina**, queda oculto y sigue siendo el que
  guarda el valor y viaja en el formulario. Si el script no carga, los formularios funcionan con
  los controles del navegador. Por eso tampoco hubo que tocar los 45 desplegables, las 16 fechas
  ni las 5 confirmaciones una por una.

- **Dos pantallas índice en el portal del cliente**, `Datos` y `Configuración`, con una frase por
  opción que explica para qué sirve. La fila de pestañas pasó de **once entradas a cinco**.

- **`ihPedirTexto()`**, el diálogo para pedir un valor. Reemplaza al segundo diálogo hecho a mano
  que vivía dentro de `includes/campo_catalogo.php` —sesenta líneas de JavaScript y una hoja de
  estilos en un `<style>` que se reimprimía en cada página y que el navegador no podía cachear.

- **`test/diseno/`**, auditoría que mide las pantallas en Chrome: alturas de control, tamaños de
  letra fuera de la escala, desborde horizontal, botones más chicos que un dedo, etiquetas que
  apuntan a un `id` que no existe. Corre a 1440, 1024, 768 y 500 px.

- **`test/ortografia.php`**, revisión del texto visible contra una lista cerrada de faltas y de
  decisiones de vocabulario. Separa el texto del código con `token_get_all()`, el lexer de PHP:
  la primera versión usaba expresiones regulares y marcaba nombres de columna y comentarios.

- **`test/componentes.js`**, prueba funcional de la capa de componentes en un DOM real (jsdom):
  abre los desplegables, escribe en el buscador, navega el calendario con el teclado, confirma y
  cancela. 137 chequeos.

### Corregido

- **`CSS.escape` no existe en todos los entornos**, y al faltar cortaba el recorrido completo:
  media pantalla quedaba con los controles del navegador y la otra media con los propios. La
  etiqueta ahora se busca con la propiedad `labels` del control, y cada control se mejora
  protegido para que uno que falle no se lleve puestos a los demás.

- **El selector `[onsubmit*="confirm("]` devuelve cero coincidencias** en algún motor de
  selectores, por el paréntesis dentro de la cadena. Y no falla: simplemente no encuentra nada,
  así que los `confirm()` seguían siendo los del navegador sin ningún aviso. Se filtra en
  JavaScript.

- **Desplazar la lista de opciones la cerraba.** `scroll` no burbujea, hay que escucharlo en fase
  de captura, y en captura también llegan los de adentro.

- **Cambiar el tamaño de la ventana cerraba el desplegable.** En un teléfono, abrir el teclado
  dispara `resize`: se cerraba el buscador justo cuando la persona iba a escribir en él. Ahora
  recoloca en vez de cerrar.

- **La lista del desplegable quedaba cacheada de la primera vez.** Varias pantallas rellenan un
  `<select>` por código —en el alta, elegir una categoría reescribe las opciones de
  subcategoría— y la segunda vez mostraba las opciones viejas.

- **El buscador no encontraba «Córdoba» si se escribía sin tilde**, y los catálogos están
  cargados con acentos.

- **Bootstrap 5.3.3 se cargaba al final del `<body>` en dos pantallas del panel**, después del
  sistema de diseño: su reset le ganaba a todo y esas dos páginas no se parecían al resto.

- **Exportar e importar perdía 15 de 32 columnas, en silencio.** La normalización de cabeceras
  borra los guiones bajos, así que un alias `patchcord_tipo` nunca podía coincidir con la
  cabecera `Patchcord tipo`. Además `fecha_compra` estaba en el esquema y en el export, pero el
  `INSERT` de importación no la escribía. Quedan dos columnas sin volver, a propósito: la fecha
  de carga la pone el sistema, y dejar que un CSV escriba el estado de archivado permitiría que
  una importación mande activos a la papelera sin avisar.

- **En el panel, un campo sin la clase `ih-input` quedaba sin ningún estilo**: 21px de alto, sin
  radio y con el borde gris del navegador, al lado de campos de 40px redondeados. Esa hoja
  estilaba sólo las clases; `app.css` ya cubría también los elementos.

- **Un `<button>` no hereda la tipografía**: el icono del `+` de los catálogos y el del
  calendario salían en Arial 13,33px en vez de Saira.

- **El mismo `.ih-btn--sm` medía 27px en el panel y 34px en el portal del cliente**, porque la
  hoja del panel no declaraba alto mínimo.

- **Cuatro pantallas forzaban letras de 9 y 10px** con `style` a mano, una de ellas con un color
  que no es de la marca.

- **El icono de la cabecera de las tarjetas** salía del color del texto y pegado al título en el
  portal del cliente: la regla estaba sólo en la hoja del panel.

- **Ortografía y vocabulario**: 21 correcciones en el texto visible. «Locación» y «Ubicación»
  eran el mismo campo con dos nombres; «Resetear» pasó a «Restablecer»; «CSRF invalido» a «La
  validación de seguridad falló»; voseo donde estaba mezclado.

### Cambiado

- **La navegación del portal del cliente.** Once pestañas en una fila: `Nuevo` repetía el botón
  del topbar, `Listas` y `Campos` no significan nada fuera de contexto, e `Importar` abría una
  pantalla titulada «Backup & Migración». Quedaron `Activos`, `Reportes`, `Papelera`, `Datos` y
  `Configuración`. Lo que se usa todos los días no quedó a más clics: «Nuevo activo» y
  «Exportar» siguen siendo botones del topbar.

- **La geometría de los controles propios sale de tokens `--ih-ctl-*`** que cada hoja define con
  los valores que ya usa en sus propios campos. Escrita a mano, el botón del desplegable quedaba
  1px menos redondeado y un poco más alto que el campo de al lado.

- **El campo Subcategoría del alta ya no aparece y desaparece.** Queda siempre a la vista y
  cuando no aplica se deshabilita diciendo por qué. Escondido, la tarjeta «Categorización»
  quedaba con un solo campo y media fila en blanco, y al elegir categoría el formulario pegaba
  un salto.

- **`auth/change_password.php` pasó a usar el layout compartido** y a avisar del cambio en la
  pantalla de acceso, en vez de con un `alert()` del navegador.

### Seguridad

- **`require_tenant_permission_any()`**: las pantallas índice exigen al menos uno de los permisos
  de lo que muestran. Pedirles uno fijo hubiera sido arbitrario, y no pedir ninguno le mostraría
  el mapa del sistema a un rol de sólo lectura. Cada opción de adentro vuelve a chequear el suyo.

---

## [1.5.0] — 2026-10-06

Revisión de diseño contra las pantallas reales, renderizadas a 1920, 1440, 1280, 1024, 768 y
500 px, y alineación con el sistema de diseño del CRM y APP Tickets.

### Corregido

- **El logo del topbar tapaba la primera tarjeta del panel.** `ih-topbar__brand-logo` y toda su
  familia estaban definidas **sólo en `app.css`**, que carga el portal tenant; el panel carga
  `intellihelp.css`, donde no existían. Sin la regla de 32×32 con `overflow:hidden`, el SVG se
  dibujaba a tamaño natural. Eran **nueve clases** en esa situación en el panel y tres en el
  tenant.

  La batería daba verde porque comparaba el markup contra la **unión** de las dos hojas y no
  contra la que cada portal carga. El chequeo pasó a ser **por portal**.

- **Dos tarjetas del alta quedaron dentro de la cabecera de otra.** Se insertaron usando como
  ancla el `<h3>` de la tarjeta siguiente, que vive dentro de `ih-card__header`: en pantalla
  salían como una columna angosta con media tarjeta vacía.

- **El panel se quedaba sin navegación por debajo de 991px.** La hoja esconde el sidebar y lo
  muestra con `.ih-app.is-open`, pero el topbar —compartido— nunca dibujaba el botón para
  abrirlo, y el único JS que había servía para cerrarlo.

- **Desborde horizontal en pantallas chicas**, por el `min-width:auto` que flexbox le da a los
  items: una tabla ancha estiraba el shell entero. Con `min-width:0` cada caja ancha se
  desplaza adentro suyo.

- **El detalle del activo** mostraba una tarjeta de garantía vacía y no mostraba nada de los
  datos de compra y amortización, que sí se podían cargar.

### Cambiado

- **Controles de formulario alineados con el CRM y APP Tickets.** El panel no estilaba ningún
  control nativo —sólo los checkbox dentro de `.ih-check`—, así que pantallas como Roles y
  permisos salían con el azul del sistema operativo. Ahora casillas y radios usan el color de
  marca, los desplegables llevan flecha propia y el icono del calendario se atenúa hasta el
  hover.

- **Las tablas de reportes** dibujaban el encabezado en violeta sólido con `!important` mientras
  todas las demás lo hacen en gris claro: puestas una al lado de la otra no parecían la misma
  aplicación. Unificadas.

- **El alta rápida de catálogos** abría un cuadro nativo del navegador. Pasó a un diálogo propio
  que cierra con Escape, confirma con Enter, devuelve el foco y declara `aria-modal`.

- **Escala responsive completa.** Había dos cortes (860 y 640) y entre ellos nada: una grilla de
  tres columnas seguía en tres hasta los 640px. Se agregaron 1200, 1024 y 400, con área táctil
  de 44px donde no hay mouse.

### Pendiente

- La hoja canónica de APP Tickets usa otra convención de nombres (`.btn`, `.card`, sin prefijo
  `ih-`). Converger del todo implicaría reescribir el markup de más de veinte pantallas; por
  ahora lo que se igualó son los **tokens y los componentes**, que es lo que se ve.

---

## [1.4.0] — 2026-10-06

Fase 3 del modelo de inventario y una batería de integración que corre contra una base real.

> Requiere `php bin/migrar.php` (migración de tenant **7**). Ningún dato se mueve.

### Agregado

- **Campos específicos por categoría, definidos en datos y no en el esquema.** `productos`
  tenía **catorce columnas** para campos que sólo aplican a una subcategoría —`patchcord_tipo`,
  `bobina_longitud`, `cintas_generacion`, `subsoftware`…—. Agregar una categoría significaba un
  `ALTER TABLE` más tocar el alta, la edición, el detalle, los dos export y los dos import: unas
  450 menciones repartidas en trece archivos. Es la misma familia de la que salió el HTTP 500 al
  editar: listas de columnas y tipos de `bind_param` escritas a mano que se desincronizan.

  Ahora `campos_definicion` dice qué campos existen, para qué categoría o subcategoría, de qué
  tipo y con qué opciones; `campos_valor` guarda el valor por activo. **Agregar un campo es una
  fila, no una migración.**

- **Pantalla *Campos*** (`inventario/campos.php`), con permiso `catalog.manage`. Sin ella habría
  que definir los campos por SQL, que es volver al problema por otro camino.

- **Los catorce campos viejos no se movieron de lugar.** Cada definición puede declarar una
  `columna_legacy`: si la tiene, el valor se lee y se escribe en esa columna de `productos`. Eso
  es deliberado y es lo que hace que el cambio sea seguro —no hay datos que migrar, y los
  export, los import y el respaldo siguen leyendo las mismas columnas sin tocarse—, y aun así
  agregar un campo nuevo ya no requiere DDL. Retirar esas columnas es una migración posterior,
  cuando ésta esté probada en producción: **agregar la forma nueva y sacar la vieja en un mismo
  movimiento es como se pierden datos.**

- **El alta también carga los datos de compra y administración**, no sólo la edición.

- **Batería de integración** (`test/integracion.php`), contra una base de datos real:
  aplicación e idempotencia de las migraciones, esquema resultante, índices, el árbol de
  ubicaciones, Infocom y los campos por categoría. 115 verificaciones. `run_tests.php` sigue
  corriendo sin base ni servidor; ésta es complementaria y sale limpio si no hay base
  configurada.

  Para que fuera posible, `install_tenant.php` ahora expone `tenant_schema_sql()`: el esquema
  base se puede armar sin conexión ni master.

### Corregido

- **Renombrar una ubicación no recalculaba más de un nivel.** Cambiar "Sede Centro" dejaba al
  depósito en `Sede Centro > Piso 2 > Depósito`. `ubicacion_recalcular_rutas()` armaba la ruta
  de cada nodo con `ubicacion_ruta_de()`, que lee la caché estática: al llegar al nieto, el
  padre todavía tenía ahí la ruta vieja. Ahora el recorrido es de arriba hacia abajo y cada hijo
  usa la ruta ya recalculada de su padre.

  **Lo encontró la batería de integración, no la estática ni la revisión del código.** Era
  invisible leyendo: la función parecía correcta y el caso de un solo nivel funcionaba.

---

## [1.3.0] — 2026-10-06

Primeras dos fases de un modelo de inventario tomado de GLPI. Se replicó **la forma de
modelar**, no una lista de campos: la instancia de GLPI de la empresa no tiene activos
cargados, así que lo que se tomó fue su esquema.

> Requiere `php bin/migrar.php` (migración de tenant **6**). Nada se pierde: todo lo que ya
> estaba cargado se siembra en las estructuras nuevas.

### Agregado

- **Catálogos para los cinco campos que seguían siendo texto libre**: marca, modelo,
  categoría, subcategoría y estado. La migración 3 había puesto bajo catálogo "Asignado a" y
  "Ubicación" justamente porque convivían "Depósito", "deposito" y "Dep." y los reportes no
  agrupaban nada — pero el mismo problema seguía intacto en otros cinco campos. Se siembran
  con los valores que ya existen, así que no se pierde nada.

- **Ubicaciones jerárquicas.** "Ubicación" era un texto suelto: la sede, el piso y el depósito
  eran tres strings sin relación, y no se podía preguntar "qué hay en la Sede Centro". Ahora es
  un árbol —`Sede Centro > Piso 2 > Depósito`— con dirección, código postal, localidad,
  provincia, país, edificio, habitación y coordenadas, como el de GLPI.

  Renombrar un nodo recalcula la ruta de todo su subárbol y la propaga a los activos, igual que
  ya hacía el renombrado de catálogos planos. Un nodo no puede colgar de un descendiente suyo,
  y no se borra una ubicación que tenga hijos o activos.

  `productos.ubicacion` sigue guardando la **ruta completa como texto**, así que el listado,
  los filtros, los reportes, el CSV y el backup siguen funcionando sin tocar una línea.
  `productos.ubicacion_id` es el enlace preciso, y es lo que permite filtrar por rama.

- **Información financiera y administrativa** (`activo_infocom`), el bloque que GLPI llama
  Infocom: número de pedido, remito, factura y número contable; proveedor y presupuesto;
  fechas de orden, entrega, puesta en servicio, último inventario físico y decomiso; datos y
  valor de extensión de garantía; criticidad de negocio; alertas y comentarios.

  **La amortización pasa a ser por activo** (duración, tipo y coeficiente). Hasta ahora el
  reporte de amortización calculaba con un `anios` que venía del querystring, el mismo para
  todos los activos, simplemente porque no había dónde guardarla.

  De los ~20 campos de Infocom, seis ya existían en `productos` (valor, cantidad, fecha de
  compra, fecha de garantía, período de garantía, fecha de baja) y **se quedan ahí**: los usan
  el listado, los reportes, el CSV y el backup. La tabla nueva no duplica ninguno — dos fuentes
  para el mismo dato terminan siempre en que no coinciden.

- Catálogos nuevos de proveedor, presupuesto y criticidad. La criticidad arranca con la escala
  de GLPI (Muy baja → Muy alta).

### Cambiado

- `CATALOGO_TIPOS` ahora declara **en qué tabla y columna** se usa cada valor. Antes se asumía
  que el tipo *era* el nombre de la columna en `productos`; con esa suposición, los catálogos
  de Infocom habrían contado siempre cero usos y el renombrado no habría arrastrado nada.

- La pantalla de *Listas* muestra el árbol con sangría y permite elegir de qué ubicación cuelga
  cada una. Para los catálogos planos no cambia nada.

- **«Estado» del activo dejó de ser un `<select>` fijo** con Nuevo/Usado y pasó a catálogo, así
  que cada empresa define su escala (en uso, en depósito, en reparación, de baja…).

### Diseño

- **El chequeo de cobertura de CSS validaba prefijos, no clases.** Su expresión regular era
  `/\bih-[a-zA-Z0-9_]+…/` — **sin guión** en la clase de caracteres —, así que `ih-form-grid`
  se truncaba a `ih-form` y `ih-portal-superadmin` a `ih-portal`, que sí existen: daba verde
  con clases que no tenían ninguna regla. Además comparaba contra `assets/css/styles.css`, una
  hoja que **ninguna página carga**. Ahora las clases se extraen de los atributos `class="…"` y
  se exigen enteras: pasaron de "127" truncadas a **142 reales**.

  Esto no lo encontró la batería, sino comparar las clases del HTML que sirve el servidor
  contra las hojas publicadas.

- **`.ih-form-grid` y `.ih-sort-link` vivían en un `<style>` dentro de `agregar.php` e
  `index.php`.** Funcionaban, pero una clase del sistema de diseño definida en una sola
  pantalla no la ve ninguna otra —ni el chequeo de cobertura—. Se movieron a `app.css`, y la
  batería ahora falla si vuelve a aparecer una clase definida sólo dentro de una página.

- **`.ih-portal-superadmin` no existía.** `head.php` emite `<body class="ih-portal-{portal}">`
  y sólo estaba definido `.ih-portal-tenant`. No había bug visual —el fondo del panel sale de
  la regla `body` de `intellihelp.css`— pero las dos hojas decían lo mismo de dos formas
  distintas. Se agregó el par que faltaba.

---

## [1.2.0] — 2026-10-06

Auditoría del proyecto entero —código, documentación, historial de git y el servidor de
producción— y corrección de lo que apareció. Se verificó también en vivo contra
`desarrollo.intellihelp.tech`, que es donde salieron dos de los hallazgos.

### Seguridad

- **Escalada de privilegios entre empresas.** `dashboard/select_empresa.php` no pedía sesión ni
  token CSRF: aceptaba `company_id` por POST verificando sólo que la empresa estuviera
  **activa** —nunca que el usuario perteneciera a ella— y escribía `$_SESSION['company_id']`
  dejando `user_id` intacto. A partir de ahí `tn()` resuelve contra las tablas de la empresa
  nueva y el `SELECT ... WHERE id = $user_id` que hace cada página devuelve a **otra persona**:
  el usuario 1 de una empresa quedaba logueado como el usuario 1 de otra, con su rol —y en un
  tenant recién creado ese id 1 es el admin que genera `dashboard_empresas.php`. Esto rompía la
  promesa central del sistema: que un cliente no ve nada de los demás.

  `auth/login.php` abría el mismo desfasaje por otro lado: sobrescribe `company_id` con el del
  POST aunque ya haya una sesión viva de otra empresa, así que un login fallido dejaba la
  sesión cruzada.

  Se cerraron los dos caminos y, sobre todo, se agregó la defensa de fondo: la empresa contra
  la que se autenticó queda grabada en `$_SESSION['auth_company_id']` y `backend/db.php` la
  compara con `company_id` **en cada request**. Si no coinciden, la sesión se destruye en vez
  de reinterpretar el `user_id` contra otro tenant. Cerrar sólo los dos caminos no alcanzaba:
  cualquier código futuro que escriba `company_id` reabría el agujero.

- **Cinco endpoints verificaban sesión pero no permiso**, así que un rol `viewer` llegaba a
  todos: `upload_to_drive.php` (el alta de activos), `upload_renamed.php`, `renombrar.php`,
  `sobrescribir.php` y `confirmar_accion.php`. Ahora piden `asset.create` / `asset.edit` con
  `require_tenant_permission()`, que reemplaza el bloque de seis líneas que estaba copiado en
  ocho archivos y faltaba en estos cinco.

- **`backend/upload_renamed.php` no tenía ninguna guardia.** No incluía `csrf.php` siquiera, no
  chequeaba permiso y no validaba el archivo: `mime_content_type()` se usaba sólo para
  *etiquetar* el upload hacia Drive, nunca para validarlo, y no había tope de tamaño. Cualquier
  usuario con sesión subía cualquier tipo y cualquier tamaño a una carpeta arbitraria del Drive
  de la empresa pasando un `folder_id`. La validación por tipo MIME real y tamaño vive ahora en
  `drive_adjunto_valido()` (`core/integraciones/drive.php`), compartida con el alta.

  El archivo además está **huérfano**: ningún formulario, link ni redirect del proyecto lo
  referencia. Se blindó en vez de borrarlo, pero conviene darlo de baja.

- **`core/` e `includes/` se servían por HTTP.** Verificado en producción:
  `/core/config/platform.php`, `/core/db/TenantDB.php`, `/core/tenant_bootstrap.php` y
  `/backend/db.php` devolvían 200 e `/includes/layout/head.php` devolvía 500 —la prueba de que
  correr un include fuera de contexto genera errores. Hoy no se filtraba nada porque
  `display_errors` está en 0; con un warning visible, sí. El `.htaccess` bloquea los dos
  árboles.

- **Cualquier `.json` se servía en texto plano.** El patrón de archivos sensibles del
  `.htaccess` cubría sólo `composer.json`. `auth/credentials.json` da 404 en producción nada
  más que porque `.gitignore` lo mantiene fuera del deploy: la protección era "el archivo no
  está", no "el archivo no se sirve". Ahora se bloquea la extensión.

- **El chequeo de secretos de la batería mirába sólo el árbol de trabajo**, no el historial de
  git. Ese era el punto ciego: un archivo borrado sigue vivo en los commits anteriores y el
  test daba verde igual. Ahora se buscan patrones de secreto en los blobs de los archivos
  sensibles que alguna vez existieron, con una línea base explícita de fugas ya asumidas, de
  forma que cualquier fuga **nueva** rompe la batería. Los valores no se escriben en el test ni
  se pasan por línea de comandos: se busca por forma, no por valor.

### Corregido

- **`users_platform.role` seguía siendo un ENUM de dos valores**, el defecto que la versión
  1.1.0 daba por corregido. La tabla se declaraba en **tres** archivos, cada uno con un `role`
  distinto: `ENUM('superadmin','support')` en `install.php` y en `auth/superadmin_login.php`, y
  el ENUM de los cuatro valores en `dashboard/superadmin_usuarios.php`. Qué roles eran
  asignables dependía de qué archivo hubiera corrido primero. En producción funcionaba por un
  motivo involuntario: `superadmin_usuarios.php` corría un `MODIFY COLUMN` en **cada carga**,
  así que la tabla quedaba bien sólo después de que alguien abriera esa pantalla. Ahora es
  `VARCHAR(40)` en los tres lugares y en la migración.

- El 403 de `require_permission()` moría con un *undefined function* en vez de mostrar la
  pantalla de acceso denegado cuando lo disparaba un endpoint que no incluye `platform.php`
  —`renombrar.php` y `sobrescribir.php`, justamente los que no tenían chequeo de permiso.

- `upload_renamed.php` pasaba el nombre de archivo y el `folder_id` por `htmlspecialchars()`,
  que escapa para HTML: un contexto que ahí no existe, porque los dos valores van a la API de
  Drive. Además ensuciaba el nombre real (`Remito & factura.pdf` se guardaba como
  `Remito &amp; factura.pdf`).

### Agregado

- **Migraciones versionadas de la master** (`migraciones_master()`, `migrar_master()`), con la
  versión en `platform_config` y aplicadas por `bin/migrar.php` antes que las de los tenants.
  La master no tenía migraciones: la creaba `install.php` y después cada página la parchaba con
  `ALTER TABLE` sueltos en cada request. Se sacaron los nueve `ALTER TABLE` sobre
  `users_platform` que corrían en `auth/superadmin_login.php` —una página **pública**—,
  `auth/superadmin_callback.php` y `dashboard/superadmin_usuarios.php`.

- **Sentencias preparadas en las tres pestañas de reportes que faltaban.** Inventario, Valor y
  Amortización armaban su `WHERE` concatenando `real_escape_string()`, mientras Trazabilidad y
  Archivados ya usaban `?`: el mismo archivo hacía las dos cosas, y `SECURITY.md` afirmaba que
  todo el acceso era preparado. No era explotable —la conexión fija `utf8mb4` con
  `set_charset()`, así que el escapado es consciente del charset—, pero la regla existe para no
  depender de eso.

- Tests de regresión para todo lo anterior: grupos `SEGURIDAD · aislamiento entre empresas` y
  `ESQUEMA · master`, más el chequeo del historial de git y los de autorización por endpoint.

### Pendiente, decidido no hacer por ahora

- **El `client_secret` de Google del primer commit (`e811ff7b`) sigue en el historial y sigue en
  uso.** Está en `auth/credentials.json`, archivo que hoy no se versiona ni se despliega, pero
  el commit es alcanzable desde `main` y desde los dos remotos. Se verificó que el valor del
  commit es idéntico al que está en uso. Los repositorios son privados y se decidió no rotar
  todavía. Queda registrado en la línea base de la batería, que falla con cualquier fuga nueva.

- No se reescribió el historial de git: la historia completa se conserva a propósito como
  registro del proyecto.

---

## [1.1.0] — 2026-08-04

Auditoría completa del proyecto (código, servidor y las dos bases de datos), corrección de los
agujeros y los bugs encontrados, y las dos funcionalidades pedidas: catálogos y papelera.

### Seguridad

- **La plataforma se podía borrar entera desde internet.** `bay.php` y
  `reset_inventario_ith.php` vivían en el web root y se autorizaban con
  `str_starts_with($host, 'desarrollo.')`. Como el host de producción **es**
  `desarrollo.intellihelp.tech`, el chequeo daba verdadero para cualquier visitante: un `POST`
  sin sesión ni CSRF hacía `DROP TABLE` de todas las tablas de todos los tenants y vaciaba la
  master. Los dos archivos salieron del web root; su funcionalidad vive en `bin/`, sólo por
  consola, con `ASSET_VAULT_DEV=1` y confirmación por frase exacta.
- **Toma de control encadenada.** `auth/superadmin_login.php` —una página pública— re-creaba
  el superadmin con una contraseña fija escrita en el repositorio cada vez que
  `users_platform` quedaba vacía. Junto con el reset abierto, permitía tomar la plataforma sin
  ninguna credencial. Se quitó el auto-seed de ahí y de `install.php`; el alta ahora es
  `bin/crear_superadmin.php`, con contraseña aleatoria mostrada una sola vez.
- **`logs/audit.log` era público** (IPs, emails y eventos de login descargables), igual que
  `composer.lock` y `deploy.sh`. El `.htaccess` bloquea `logs/`, `bin/`, `test/`, `md/`,
  `backend/temp/`, `.git`, `composer.*` y `deploy.sh`, y agrega las cabeceras de seguridad.
- **Sesión.** La cookie salía sólo con `Secure`: sin `HttpOnly` la leía cualquier JavaScript y
  sin `SameSite` viajaba en peticiones cross-site. Además ningún login rotaba el ID de sesión,
  lo que dejaba abierta la fijación de sesión. Se agregó `core/security/session_guard.php`, se
  fijaron los flags en `.user.ini` y se rota el ID en los tres puntos de autenticación.
  Se verificó en el hosting real que la sesión sobrevive: el síntoma que había motivado
  desactivar la rotación era el `session_write_close()` faltante antes del redirect.
- **Credenciales del master DB versionadas** en `core/config/platform.php`. Pasaron a
  `secure/config/secrets.php`, fuera de git y bloqueado por HTTP.
- **Autorización del panel superadmin.** `empresa_eliminar.php` no pedía permiso ni token CSRF;
  `empresa_detalle.php` permitía a cualquier usuario de plataforma resetear contraseñas de
  usuarios de cualquier empresa; `empresa_backup.php` exponía hashes y `client_secret` con
  `company.view`, que tiene hasta el rol de sólo lectura (ahora exige `company.backup`).
- **`login.php` aceptaba cualquier `company_id` por POST**, sin validarlo contra las empresas
  activas —el camino por querystring sí lo validaba.
- **Posible ejecución remota autenticada.** Los temporales del flujo de duplicados se
  escribían en `backend/temp/`, dentro del web root, sin `.htaccess` y conservando la
  extensión del usuario. Pasaron a `secure/tmp/`, con nombre aleatorio `.bin`.
- **Inyección en las queries `q` de Google Drive**: los nombres de archivo y carpeta se
  interpolaban crudos. Se escapan.

### Corregido

- **HTTP 500 al guardar la edición de un activo.** `backend/edit.php` declaraba 33 caracteres
  de tipos para 32 parámetros en `bind_param` (el comentario de al lado decía "32 chars"). En
  PHP 8 eso lanza `ArgumentCountError`, que nadie atrapa: error fatal. El mismo defecto estaba
  en `backend/import_csv.php` (32 tipos, 33 parámetros) y **rompía la importación de CSV**.
  Los tipos ahora se derivan del mapa de columnas, así que no pueden desincronizarse.
- **Tres errores fatales por indexar un `Google\Collection`.** `edit.php` hacía `$lista[0]`
  sobre resultados de Drive; esa clase implementa `Iterator` y `Countable` pero no
  `ArrayAccess`. Rompía la creación de carpeta para activos sin carpeta previa y todo el flujo
  de archivo duplicado.
- El caché de permisos de `role_can()` se indexaba sólo por rol, sin considerar la conexión.
- ~~`install.php` y `superadmin_login.php` declaraban `role ENUM('superadmin','support')`
  mientras la tabla real tiene cuatro valores: en una instalación limpia no se podían asignar
  `admin` ni `viewer`.~~
  **Esto no se corrigió en 1.1.0.** La auditoría de 1.2.0 encontró el ENUM de dos valores
  intacto en los dos archivos. Lo que lo tapaba era un `MODIFY COLUMN` que
  `dashboard/superadmin_usuarios.php` corría en cada carga de la página, así que la tabla
  quedaba bien sólo después de que alguien la abriera. Corregido de verdad en 1.2.0.

### Agregado

- **Catálogos.** "Asignado a" y "Ubicación" dejan de ser texto libre y pasan a listas
  gestionadas (`inventario/catalogos.php`). Alta, renombrar —arrastrando el cambio a los
  activos—, baja lógica y eliminación con protección. Alta rápida con `+` desde el formulario
  de alta y edición. Permiso `catalog.manage`, sólo admin por defecto. La validación es del
  lado del servidor: el `<select>` no es el control de acceso.
- **Papelera.** `inventario/archivados.php` lista los activos archivados con quién, cuándo y
  por qué, con buscador y paginado. `backend/restaurar.php` los devuelve al inventario y deja
  rastro en el historial. Permisos `asset.trash.view` y `asset.restore`.
- **Carpeta «Archivados» en Drive.** Al archivar, la carpeta del activo se mueve ahí y se
  recuerda de dónde salió; al restaurar vuelve a su lugar y se le saca el prefijo del nombre.
- **Migraciones versionadas** (`core/db/migraciones.php`, `bin/migrar.php`), con estado por
  empresa.
- **Índices**: `productos` no tenía **ninguno** fuera de la clave primaria, así que cada
  filtro del listado y de los reportes recorría la tabla entera. Se agregaron 8 en `productos`
  y 3 en `producto_movimientos`.
- **Batería de regresión** (`test/run_tests.php`, 105 checks) que corre sin base ni servidor y
  verifica también que la documentación esté al día y 1:1 con el documento maestro.
- **Documentación completa**: `md/PROYECTO.md` (documento maestro con 7 diagramas Mermaid),
  `README.md` reflejándolo 1:1, `INSTALL.md`, `SECURITY.md`, `CONTRIBUTING.md`,
  `md/REQUERIMIENTOS.md`, `LICENSE`, banner y `.editorconfig`.

### Cambiado

- **Diseño del portal tenant.** Pasa a `assets/css/app.css`, con los tokens exactos del CRM y
  APP Tickets (`--ink:#161922`, `--primary:#663399`, `--accent:#9CC930`, `--bg:#f6f5fa`,
  radios 15/10/9, espaciado en `rem`, sombras con tinte violeta) y el mismo shell de topbar
  más pestañas. Las clases siguen siendo `ih-`, así que no hubo que reescribir ninguna
  pantalla. **El panel superadmin conserva su diseño.**
- Saira se carga con `<link>` en el `<head>` en vez de `@import` dentro del CSS, y los assets
  se versionan para que un cambio no lo tape la caché.
- Se agregaron las 18 clases que el markup usaba y el CSS no definía, entre ellas `ih-label`
  (32 usos) y toda la familia `ih-flash`.
- `inventario/usuarios.php` tenía su propio topbar, su propio CSS y Bootstrap entero para dos
  modales. Ahora usa el layout compartido y `<dialog>` nativo.
- El bootstrap del cliente de Drive estaba copiado en cinco archivos con variantes distintas;
  ahora vive una sola vez en `core/integraciones/drive.php`.
- El DDL dejó de correr en cada request: `backend/db.php` hacía `SHOW TABLES`, dos
  `ALTER TABLE` y un `SHOW COLUMNS`, y varios módulos lanzaban `CREATE TABLE IF NOT EXISTS`.
- `composer.lock` pasa a estar versionado y se agregó `.gitattributes` (el repositorio tenía
  finales de línea mezclados).

---

## [1.0.0] — 2026-05-27

Primera versión en producción.

- Plataforma multiempresa con base master y una base por empresa (`external` / `prefix`).
- Panel superadmin: alta de empresas, usuarios de plataforma, integraciones, logs, respaldo.
- Portal del tenant: alta, edición, archivado, listado con filtros, reportes, import/export CSV.
- Autenticación local y SSO Google/Microsoft por empresa, con opción de deshabilitar el
  login local.
- Roles incorporados y roles personalizados por empresa, con editor de permisos.
- Integración con Google Drive: carpeta por activo y adjuntos con control de duplicados.
