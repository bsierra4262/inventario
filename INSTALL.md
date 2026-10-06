# Instalación y despliegue

Asset Vault corre sobre PHP 8.1+ y MySQL/MariaDB. No usa framework ni build: se sirve el
directorio del proyecto y listo.

---

## 1. Requisitos

| Componente | Versión |
| --- | --- |
| PHP | 8.1 o superior (probado en 8.2) con `mysqli`, `curl`, `mbstring`, `fileinfo` |
| MySQL / MariaDB | 5.7+ / 10.4+, `utf8mb4` |
| Composer | para `google/apiclient` y `guzzlehttp/guzzle` |
| HTTPS | obligatorio (la cookie de sesión es `Secure`) |

---

## 2. Bases de datos

Hacen falta **dos**:

- **Master** — una sola para toda la plataforma: `companies`, `users_platform`,
  `platform_config`, `platform_role_permissions`.
- **Tenant** — una por empresa cliente (`tenant_mode = 'external'`), o bien tablas con
  prefijo `t{id}_` dentro de la master (`tenant_mode = 'prefix'`).

---

## 3. Instalación

```bash
git clone git@github.com:bsierra4262/inventario.git
cd inventario
composer install --no-dev --optimize-autoloader
```

### 3.1 Secretos

```bash
mkdir -p secure/config
cp core/config/secrets.example.php secure/config/secrets.php
chmod 700 secure/config
chmod 600 secure/config/secrets.php
```

Editar `secure/config/secrets.php` con las credenciales de la base master. Ese archivo
**no se versiona** y `secure/.htaccess` lo bloquea por HTTP.

### 3.2 Estructura de la master

```
https://tu-dominio/install.php
```

Crea `companies`, `users_platform` y `platform_config`. Es idempotente: si ya hay un
superadmin, redirige al login y no toca nada.

### 3.3 Superadmin

```bash
php bin/crear_superadmin.php bsierra bsierra@intellihelp.tech
```

Genera una contraseña al azar y la muestra **una sola vez**. Guardala en el gestor de
contraseñas. Ninguna página web crea usuarios.

### 3.4 Primera empresa

Entrar al panel superadmin → **Empresas** → *Nueva empresa*. Ahí se define el nombre, el
subdominio (`ITH`), el modo (`external` o `prefix`) y, en modo externo, los datos de conexión
de la base del tenant. Al guardarla se crea el esquema del inventario.

### 3.5 Migraciones

```bash
php bin/migrar.php --estado    # ver en qué versión está cada empresa
php bin/migrar.php             # aplicar lo pendiente a todas las activas
php bin/migrar.php ITH         # sólo a una
```

**Este paso es obligatorio después de cada `git pull`.** El esquema está versionado en
`core/db/migraciones.php`; la aplicación sólo lee la versión y avisa en el log si falta migrar.

---

## 4. Despliegue en hosting (Hostinger)

El proyecto se sirve desde el directorio del repositorio. `deploy.sh` corre `composer install`
después de cada `git pull`; configurarlo en hPanel → Git → Manage → Deploy Script.

Después de cada despliegue:

```bash
php bin/migrar.php
php test/run_tests.php
```

### Convivencia con WordPress

Este subdominio vive **dentro** de un WordPress con Wordfence en el dominio padre. Wordfence
setea `auto_prepend_file`, que rompe cada `.php` de la aplicación con un 500. El `.htaccess`
del proyecto lo anula, y el del dominio padre saltea la reescritura de WordPress para esta
ruta. **No tocar esos dos bloques.**

### Permisos de directorios

| Ruta | Permiso | Motivo |
| --- | --- | --- |
| `secure/config/` | `700` | secretos |
| `secure/config/secrets.php` | `600` | secretos |
| `secure/tenants/` | `700` | `credentials.json` por empresa |
| `secure/tmp/` | `700` | temporales de subida |
| `logs/` | `755` | la app escribe el log de auditoría |

---

## 5. Integración con Google Drive

1. En Google Cloud Console: proyecto, **Google Drive API** y **pantalla de consentimiento**.
2. Credenciales OAuth 2.0 de tipo *Aplicación web*, con URI de redirección
   `https://tu-dominio/auth/superadmin_callback.php`.
3. En el panel superadmin → *Integraciones* van las credenciales de la plataforma; en el
   portal de cada empresa → *Drive* van las suyas (pisan a las de plataforma).
4. En `root_folder_id` se pone el ID de la carpeta raíz del Drive de esa empresa. Debajo de
   ella la aplicación crea `Modelo - Marca / ServiceTag` y la carpeta `Archivados`.

---

## 6. Verificación

```bash
php test/run_tests.php
```

Corre la batería completa sin necesitar base ni servidor. Debe terminar en verde.

### Batería de integración (opcional, contra una base real)

Hay cosas que no se comprueban leyendo el código: que una migración aplique sin error, que sea
idempotente, que renombrar una ubicación recalcule todo su subárbol. Para eso está
`test/integracion.php`.

```bash
AV_TEST_DB_HOST=127.0.0.1 AV_TEST_DB_PORT=3306 \
AV_TEST_DB_USER=root AV_TEST_DB_PASS=secreto \
AV_TEST_DB_NAME=av_test AV_TEST_DB_MASTER=av_test_master \
php test/integracion.php
```

> **La base que se le indique se vacía al empezar.** Nunca apuntarla a una base con datos: creá
> dos bases descartables sólo para esto.

Sin esas variables no corre nada y sale en 0, así que se puede dejar en cualquier pipeline.
