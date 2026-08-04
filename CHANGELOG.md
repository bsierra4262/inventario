# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

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
- `install.php` y `superadmin_login.php` declaraban `role ENUM('superadmin','support')`
  mientras la tabla real tiene cuatro valores: en una instalación limpia no se podían asignar
  `admin` ni `viewer`.

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
