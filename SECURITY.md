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

## Autorización

Dos matrices independientes, ambas con overrides por base de datos:

- **Plataforma** (`core/security/session_role.php`): `superadmin`, `admin`, `support`, `viewer`.
- **Tenant** (`core/security/permissions.php`): `admin`, `manager`, `user`, `viewer`
  + los roles personalizados que cree cada empresa.

Toda acción sensible se valida **en el servidor**. Los controles del formulario (un `<select>`,
un botón oculto) son comodidad visual, nunca el control de acceso.

## Entrada y salida de datos

- Todo el acceso a la base usa sentencias preparadas.
- Los valores de "Asignado a" y "Ubicación" se validan contra el catálogo del tenant en el
  servidor, no sólo con el `<select>`.
- Los archivos subidos se validan por MIME real (`mime_content_type`) y por tamaño; los
  temporales del flujo de duplicados se guardan **fuera del web root**, con nombre aleatorio
  y sin la extensión que eligió el usuario.
- Los nombres se escapan antes de entrar en una query `q` de la API de Drive.

## Auditoría

`logs/audit.log` registra autenticación, cambios de activos, archivado, restauración, gestión
de catálogos y bloqueos de CSRF. El directorio `logs/` **no es accesible por HTTP**.
