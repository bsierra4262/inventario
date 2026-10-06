# Política de seguridad

## Reporte de vulnerabilidades

Ante una vulnerabilidad de seguridad, **no debe abrirse un issue público**. El reporte se
realiza de forma privada a **bsierra@intellihelp.tech**.

Se solicita incluir, en la medida de lo posible: descripción, pasos de reproducción, impacto
y una posible mitigación. El reporte recibirá respuesta a la brevedad.

## Manejo de secretos

- Las credenciales reales (base de datos master, `client_secret` de Google y de Microsoft)
  viven en **`secure/config/secrets.php`**, que **no se versiona** (`.gitignore`) y está
  bloqueado por HTTP (`secure/.htaccess` con `Require all denied`). En el servidor se deja
  con permisos `600`.
- El repositorio incluye únicamente la plantilla `core/config/secrets.example.php`.
- Si el archivo no existe, `platform.php` cae a variables de entorno. Nunca hay credenciales
  escritas en el código.
- Las credenciales OAuth **por empresa** viven en la tabla `cloud_config` de la base de cada
  tenant, y el `credentials.json` de Google en `secure/tenants/{id}/google/`.
- El `.htaccess` bloquea **cualquier `.json`**, no sólo `composer.json`. Antes un
  `credentials.json` en el servidor se habría servido en texto plano: la protección era "el
  archivo no está", no "el archivo no se sirve".

### Qué verifica la batería, y qué no

`test/run_tests.php` controla dos cosas distintas:

1. **El árbol de trabajo.** Lee los secretos reales de `secure/config/secrets.php` y verifica
   que ninguno aparezca en un archivo versionado. No escribe los valores en el test: eso sería
   volver a meterlos en el repositorio.
2. **El historial de git.** Un archivo borrado sigue vivo en los commits anteriores, y el
   chequeo del punto 1 no lo ve. Se buscan patrones de secreto —por forma, no por valor— en
   los blobs de los archivos sensibles que alguna vez existieron.

> El punto 2 faltaba, y eso dejó pasar una fuga real: `auth/credentials.json` con el
> `client_secret` de Google quedó en el primer commit. No está en el árbol —se borró y está en
> `.gitignore`— así que la batería daba verde. **Borrar un archivo no lo saca de la historia.**

El punto 2 tiene una **línea base explícita** (`$fugasAceptadas`) con las fugas ya conocidas y
asumidas, y el motivo de cada una. Mantiene la batería en verde sobre lo que ya se decidió y
falla con cualquier fuga **nueva**. Un segundo chequeo avisa si una entrada de la línea base ya
se purgó, para que no quede como letra muerta. El punto 2 necesita `git` y `exec()`: cuando no
están —hosting compartido— el test **lo dice** en vez de dar un verde que no verificó nada.

## Herramientas destructivas

Las operaciones que borran datos (`bin/reset_plataforma.php`, `bin/reset_inventario.php`)
**no son alcanzables por HTTP**:

- viven en `bin/`, que el `.htaccess` bloquea;
- abortan si el SAPI no es `cli`;
- exigen `ASSET_VAULT_DEV=1`;
- piden confirmación tipeando una frase exacta.

> Esto responde a un incidente real: `bay.php` y `reset_inventario_ith.php` vivían en el web
> root y se autorizaban comparando el nombre del host contra `desarrollo.`. Como el host de
> producción **es** `desarrollo.intellihelp.tech`, el chequeo daba verdadero para cualquier
> visitante: un `POST` sin sesión ni CSRF hacía `DROP TABLE` de todas las tablas de todos los
> tenants. **Una autorización no se resuelve con un `if` sobre el hostname: la herramienta se
> saca del alcance del servidor web.**

El alta del superadmin se hace por consola (`bin/crear_superadmin.php`), con contraseña
generada al azar y mostrada una sola vez. Ninguna página pública crea usuarios.

## Sesiones

- Cookie con `Secure`, `HttpOnly` y `SameSite=Lax`; `session.use_strict_mode = 1`
  (declarado en `.user.ini` y reforzado en `core/security/session_guard.php`).
- El ID de sesión **se regenera en los tres puntos de autenticación** (login local del tenant,
  SSO del tenant y SSO/local del superadmin), y la sesión se escribe en disco *antes* del
  redirect. Sin eso, un `PHPSESSID` fijado de antemano sobrevivía al login.

## Aislamiento entre empresas

`user_id` es el id de una fila en la tabla `users` **de una empresa**. Fuera de ella no
identifica a nadie: el usuario 1 de una empresa y el usuario 1 de otra son dos personas
distintas. De ahí la regla:

> La empresa activa y la identidad del usuario viajan juntas. Si una cambia sin la otra, la
> sesión no es de fiar.

- Al autenticar se graba `$_SESSION['auth_company_id']`, la empresa contra la que se validó la
  credencial (login local y SSO del tenant).
- `backend/db.php` la compara con `$_SESSION['company_id']` **en cada request**. Si no
  coinciden, destruye la sesión y manda al login; no reinterpreta el `user_id`.
- Elegir empresa en `dashboard/select_empresa.php` descarta la autenticación entera
  (`app_session_rotar([])`) y manda al login de esa empresa. "Tener acceso a dos empresas" son
  dos cuentas distintas, no una que viaja.

> Esto responde a un agujero real. `select_empresa.php` no pedía sesión ni token CSRF:
> aceptaba `company_id` por POST verificando sólo que la empresa estuviera **activa** —nunca
> que el usuario perteneciera a ella— y dejaba `user_id` intacto. A partir de ahí `tn()`
> resuelve contra las tablas de la empresa nueva y el `SELECT ... WHERE id = $user_id` de cada
> página devuelve a otra persona: se quedaba logueado como el usuario con ese mismo id
> numérico allá, **con su rol**. En un tenant recién creado ese id 1 es el admin.
> `auth/login.php` abría el mismo desfasaje por otro lado, sobrescribiendo `company_id` con el
> del POST aunque hubiera una sesión viva de otra empresa.
>
> **Cerrar los dos caminos no alcanza: cualquier código que escriba `company_id` los reabre.
> Por eso la verificación es central y corre en cada request, no en los puntos de entrada.**

## Autorización

Dos matrices independientes, ambas con overrides por base de datos:

- **Plataforma** (`core/security/session_role.php`): `superadmin`, `admin`, `support`, `viewer`.
- **Tenant** (`core/security/permissions.php`): `admin`, `manager`, `user`, `viewer`
  + los roles personalizados que cree cada empresa.

Toda acción sensible se valida **en el servidor**. Los controles del formulario (un `<select>`,
un botón oculto) son comodidad visual, nunca el control de acceso.

Un endpoint que escribe pide **permiso, no sólo sesión**. `require_tenant_permission($accion)`
(`core/security/permissions.php`) lee el rol de la base en cada request —así una baja de rol
tiene efecto en el request siguiente, no cuando se vence la cookie— y aborta con 403.

> Cinco endpoints verificaban únicamente que hubiera un `user_id`, así que el rol `viewer`
> llegaba a todos: `upload_to_drive.php` (el alta de activos), `upload_renamed.php`,
> `renombrar.php`, `sobrescribir.php` y `confirmar_accion.php`. Tener el token CSRF no es
> tener permiso: el token prueba que el pedido salió de nuestro formulario, no que quien lo
> mandó tenga derecho a la acción.

## Entrada y salida de datos

- Todo el acceso a la base usa sentencias preparadas.
- Los valores de "Asignado a" y "Ubicación" se validan contra el catálogo del tenant en el
  servidor, no sólo con el `<select>`.
- Los archivos subidos se validan por MIME real (`mime_content_type`) y por tamaño; los
  temporales del flujo de duplicados se guardan **fuera del web root**, con nombre aleatorio
  y sin la extensión que eligió el usuario.
- Esa validación vive **una sola vez**, en `drive_adjunto_valido()`
  (`core/integraciones/drive.php`), y la usa todo camino que escriba en Drive. Estaba escrita
  sólo dentro de `upload_to_drive.php`, así que `upload_renamed.php` —que también sube a
  Drive— no validaba nada: usaba `mime_content_type()` para *etiquetar* el upload, no para
  decidir si aceptarlo, y no tenía tope de tamaño. **El MIME se saca siempre del contenido,
  nunca de `$_FILES[...]['type']`, que lo manda el navegador.**
- Los nombres se escapan antes de entrar en una query `q` de la API de Drive.

## Auditoría

`logs/audit.log` registra autenticación, cambios de activos, archivado, restauración, gestión
de catálogos y bloqueos de CSRF. El directorio `logs/` **no es accesible por HTTP**.
