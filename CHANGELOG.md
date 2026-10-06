# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

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
