<p align="center">
  <img src="docs/banner.svg" alt="Asset Vault — INTELLIHELP" width="100%">
</p>

<p align="center">
  <b>Plataforma SaaS multiempresa de inventario de activos de IT.</b><br>
  Cada empresa cliente tiene su propio portal, sus usuarios, sus roles, su base de datos y su Google Drive.
  Historial completo por activo, papelera con restauración y respaldo documental ordenado.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL-MariaDB-4479A1?logo=mysql&logoColor=white" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/multiempresa-s%C3%AD-663399" alt="Multiempresa">
  <img src="https://img.shields.io/badge/Google%20Drive-integrado-0F9D58?logo=googledrive&logoColor=white" alt="Google Drive">
  <img src="https://img.shields.io/badge/SSO-Google%20%C2%B7%20Microsoft-2563EB" alt="SSO">
  <img src="https://img.shields.io/badge/licencia-propietaria-663399" alt="Licencia propietaria">
</p>

---

## Índice

- [Descripción](#descripción)
- [Características](#características)
- [Arquitectura del sistema](#arquitectura-del-sistema)
- [Multiempresa: cómo se resuelve la empresa](#multiempresa-cómo-se-resuelve-la-empresa)
- [Autenticación](#autenticación)
- [Permisos](#permisos)
- [Ciclo de vida de un activo](#ciclo-de-vida-de-un-activo)
- [Catálogos](#catálogos)
- [Papelera](#papelera)
- [Integración con Google Drive](#integración-con-google-drive)
- [Modelo de datos](#modelo-de-datos)
- [Esquema versionado y despliegue](#esquema-versionado-y-despliegue)
- [Sistema de diseño](#sistema-de-diseño)
- [Stack técnico](#stack-técnico)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Puesta en marcha](#puesta-en-marcha)
- [Tests](#tests)
- [Seguridad](#seguridad)
- [Licencia](#licencia)
- [Autor](#autor)

## Descripción

**Asset Vault** reemplaza la planilla de cálculo compartida por una aplicación con historial,
control de acceso por rol, respaldo documental en Drive y reportes.

Está armado en dos planos. Un **plano de plataforma** administra las empresas cliente, sus
usuarios de gestión y la configuración global. Un **plano de empresa** es el inventario
propiamente dicho: cada cliente entra a su portal, con su base de datos y su Drive, sin ver
nada de los demás.

> Producción (privada): `https://desarrollo.intellihelp.tech` · primera empresa: INTELLIHELP S.A. (`ITH`)

Documento maestro completo: [`md/PROYECTO.md`](md/PROYECTO.md).

## Características

- **Multiempresa real.** Cada cliente con base de datos propia (`external`) o con tablas
  prefijadas dentro de la master (`prefix`). Todo el código usa `tn()` y es indiferente al modo.
- **Doble matriz de permisos** —plataforma y empresa— con overrides por base de datos y
  **roles personalizados** que cada empresa crea a medida.
- **SSO Google y Microsoft** por empresa, con la opción de deshabilitar el login local para
  forzar que todos los accesos pasen por el proveedor.
- **Historial de estados.** Cada cambio guarda una copia completa del activo: el historial no
  depende de reconstruir nada ni se rompe con un cambio de esquema posterior.
- **Papelera con restauración.** Dar de baja un activo es reversible, y la carpeta de Drive
  vuelve a su lugar al restaurarlo.
- **Catálogos gestionados.** "Asignado a" y "Ubicación" son listas que mantiene el admin, no
  texto libre: se terminó el "Depósito / deposito / Dep." que rompía los reportes.
- **Respaldo en Google Drive** por activo, con estructura `Modelo - Marca / ServiceTag` y una
  carpeta `Archivados`. Drive nunca bloquea el inventario.
- **Importación y exportación CSV** y respaldo completo de una empresa en JSON.
- **Reportes** por categoría, ubicación, responsable, garantía y valor, con paginado.
- **Esquema versionado**, con migraciones que se aplican por consola y no en cada request.
- **Batería de regresión** que corre sin base ni servidor y verifica también que la
  documentación esté al día.

## Arquitectura del sistema

PHP 8.2 plano, sin framework. Dos planos con bases separadas.

```mermaid
flowchart TB
    subgraph Plano de plataforma
        SA([SuperAdmin]) --> PANEL[dashboard/<br/>panel de plataforma]
        PANEL --> MDB[(DB master<br/>companies · users_platform<br/>platform_config)]
    end

    subgraph Plano de empresa
        U([Usuario de la empresa]) --> PORTAL[inventario/<br/>portal del tenant]
        PORTAL --> TDB[(DB del tenant<br/>productos · movimientos<br/>users · catalogos)]
        PORTAL --> DRIVE[(Google Drive<br/>de la empresa)]
    end

    PANEL -. da de alta y configura .-> PORTAL
    MDB -. credenciales del tenant .-> TDB

    BOOT[core/tenant_bootstrap.php<br/>resuelve la empresa activa] --> TDB
    PORTAL --> BOOT

    CLI[[bin/ · consola]] --> MDB
    CLI --> TDB
```

Los dos planos **no comparten sesión**: el flag `is_superadmin` y `user_id` son excluyentes, y
cada portal redirige al otro si detecta la sesión equivocada.

## Multiempresa: cómo se resuelve la empresa

| Modo | Dónde viven sus tablas | Cuándo se usa |
| --- | --- | --- |
| `external` | base de datos propia, tablas sin prefijo | el caso normal: aislamiento real entre clientes |
| `prefix` | dentro de la master, tablas `t{id}_…` | cuando el hosting no permite crear más bases |

```mermaid
flowchart TD
    R([Request]) --> S{¿Hay company_id<br/>en la sesión?}
    S -- Sí --> T[TenantDB: leer companies<br/>y conectar]
    S -- No --> Q{¿Vino ?company=<br/>en la URL?}
    Q -- Sí --> V{¿Coincide con una<br/>empresa ACTIVA?}
    V -- Sí --> SET[Fijar company_id] --> T
    V -- No --> N[Ignorar el parámetro]
    Q -- No --> N
    N --> C{¿Cuántas empresas<br/>activas hay?}
    C -- Una --> SET
    C -- Varias --> SEL[select_empresa.php]
    C -- Ninguna --> INS[install.php]
    T --> M{¿El esquema está<br/>en la última versión?}
    M -- Sí --> APP[Servir la página]
    M -- No --> W[Avisar en el log<br/>correr bin/migrar.php] --> APP
```

El `company_id` se valida **siempre** contra la lista de empresas activas, tanto si viene por
querystring como si viene por POST.

## Autenticación

```mermaid
flowchart TD
    subgraph Empresa
        L1([Usuario]) --> LP[auth/login.php]
        LP --> D{¿La empresa tiene<br/>SSO configurado?}
        D -- Sí --> G[Botón Google / Microsoft]
        D -- No --> F[Usuario y contraseña]
        G --> CB[auth/superadmin_callback.php<br/>state = tg: o tm:]
        CB --> MAIL{¿El email existe en<br/>los usuarios de la empresa?}
        MAIL -- Sí --> OK1[Rotar sesión<br/>y entrar]
        MAIL -- No --> NO1[Sin acceso:<br/>lo tiene que dar de alta el admin]
        F --> PV{password_verify}
        PV -- OK --> OK1
        PV -- Falla --> NO2[Credenciales inválidas]
    end

    subgraph Plataforma
        L2([SuperAdmin]) --> SP[auth/superadmin_login.php]
        SP --> SG[SSO Google / Microsoft<br/>state = g: o m:]
        SG --> CB
        CB --> HD{¿El dominio del email<br/>coincide con sa_google_hd?}
        HD -- Sí --> UP{¿Existe en users_platform<br/>y está activo?}
        HD -- No --> NO3[Rechazado]
        UP -- Sí --> OK2[Rotar sesión<br/>y entrar al panel]
        UP -- No --> NO3
    end

    OK1 --> WC[session_write_close<br/>ANTES del redirect]
    OK2 --> WC
```

El `session_write_close()` antes del redirect no es un detalle: en hosting compartido, si el
`Location` sale primero, el request siguiente puede leer la sesión todavía vacía.

## Permisos

Dos matrices independientes, cada una con overrides por base de datos.

```mermaid
flowchart TD
    subgraph Plataforma
        PR[superadmin · admin<br/>support · viewer] --> PM[matriz por defecto<br/>session_role.php]
        PM --> PO{¿Hay overrides en<br/>platform_role_permissions?}
        PO -- Sí --> PE[La lista de la base<br/>REEMPLAZA la default]
        PO -- No --> PD[Se usa la default]
        PE --> PC[platform_can accion]
        PD --> PC
        SUP[superadmin] -. siempre todo<br/>anti-lockout .-> PC
    end

    subgraph Empresa
        TR[admin · manager<br/>user · viewer] --> TM[matriz por defecto<br/>permissions.php]
        CR[roles personalizados<br/>custom_roles] --> TDB2[sólo lo que diga<br/>role_permissions<br/>sin fallback]
        TM --> TO{¿Hay overrides en<br/>role_permissions?}
        TO -- Sí --> TE[La lista de la base<br/>REEMPLAZA la default]
        TO -- No --> TD[Se usa la default]
        TE --> UC[user_can accion]
        TD --> UC
        TDB2 --> UC
    end
```

### Acciones del tenant

| Acción | admin | manager | user | viewer |
| --- | :-: | :-: | :-: | :-: |
| `asset.view` | ✓ | ✓ | ✓ | ✓ |
| `asset.create` | ✓ | ✓ | ✓ | |
| `asset.edit` | ✓ | ✓ | ✓ | |
| `asset.archive` | ✓ | ✓ | | |
| `asset.trash.view` | ✓ | ✓ | | |
| `asset.restore` | ✓ | ✓ | | |
| `asset.delete` | ✓ | | | |
| `asset.export` | ✓ | ✓ | | |
| `asset.import_csv` | ✓ | ✓ | | |
| `asset.backup_full` | ✓ | | | |
| `report.view` | ✓ | ✓ | ✓ | ✓ |
| `catalog.manage` | ✓ | | | |
| `user.view` / `user.create` / `user.edit` / `user.delete` | ✓ | | | |
| `integration.view` / `integration.edit` | ✓ | | | |
| `self.change_password` | ✓ | ✓ | ✓ | ✓ |

### Acciones de plataforma

| Acción | superadmin | admin | support | viewer |
| --- | :-: | :-: | :-: | :-: |
| `company.view` | ✓ | ✓ | ✓ | ✓ |
| `company.create` / `company.edit` | ✓ | ✓ | | |
| `company.delete` | ✓ | | | |
| `company.backup` | ✓ | | | |
| `tenant_user.view` | ✓ | ✓ | ✓ | |
| `tenant_user.manage` | ✓ | ✓ | | |
| `platform_user.manage` | ✓ | | | |
| `integrations.manage` | ✓ | | | |
| `logs.view` | ✓ | ✓ | | |

`company.backup` está separado de `company.view` a propósito: el respaldo incluye hashes de
contraseña y el `client_secret` de OAuth, así que no puede depender de un permiso que tiene
hasta el rol de sólo lectura.

**Roles personalizados**: a diferencia de los cuatro incorporados, un rol personalizado **no
hereda nada**: sólo tiene lo que esté explícitamente en `role_permissions`.

## Ciclo de vida de un activo

```mermaid
flowchart TD
    A[Alta<br/>inventario/agregar.php] --> V{¿Asignado a y Ubicación<br/>están en el catálogo?}
    V -- No --> DESC[Se descartan:<br/>el catálogo manda]
    V -- Sí --> INS[(INSERT en productos)]
    DESC --> INS
    INS --> DR{¿Drive configurado?}
    DR -- Sí --> CAR[Crear Modelo - Marca / ServiceTag<br/>y guardar carpeta_raiz_drive]
    DR -- No --> SIN[Sigue sin Drive:<br/>nunca bloquea el alta]
    CAR --> ACT([Activo en inventario])
    SIN --> ACT

    ACT --> ED[Edición<br/>backend/edit.php]
    ED --> MOV[(Movimiento en el historial)]
    MOV --> ACT

    ACT --> AR[Archivar<br/>backend/delete.php]
    AR --> MARK[archivado = 1<br/>+ quién, cuándo y por qué]
    MARK --> MOVE[Mover la carpeta a Archivados<br/>y recordar el padre previo]
    MOVE --> PAP([Papelera])

    PAP --> RES[Restaurar<br/>backend/restaurar.php]
    RES --> BACK[archivado = 0<br/>y la carpeta vuelve a su lugar]
    BACK --> ACT

    PAP --> DEL[Eliminar definitivo<br/>sólo admin]
    DEL --> FIN([Borrado])
```

## Catálogos

"Asignado a" y "Ubicación" eran texto libre, y convivían "Depósito", "deposito" y "Dep." con
los reportes sin agrupar nada. Ahora son listas gestionadas:

- **El admin las mantiene** en *Listas* (`inventario/catalogos.php`): alta, renombrar, baja
  lógica, eliminar. También puede agregar un valor sin salir del formulario de alta o edición,
  con el botón `+`.
- **Un usuario común elige de la lista**, no la amplía (permiso `catalog.manage`).
- **Renombrar arrastra el cambio** a los activos que usaban ese valor.
- **Eliminar se rechaza** si hay activos usándolo; se da de baja y sale del desplegable sin
  tocar los históricos.
- **La validación es del lado del servidor.** El `<select>` es comodidad visual.
- Al migrar, el catálogo **se sembró con los valores que ya existían**.

## Papelera

Archivar era un camino de ida. Ahora *Papelera* lista los archivados con **quién, cuándo y por
qué**, con buscador y paginado. Restaurar:

1. devuelve el activo al inventario;
2. deja un movimiento `Restaurado` en el historial, citando el motivo original;
3. mueve la carpeta de Drive de vuelta a donde estaba y le saca el prefijo del nombre.

Ver la papelera pide `asset.trash.view`; restaurar, `asset.restore`.

## Integración con Google Drive

```
<root_folder_id de la empresa>
├── Modelo - Marca/
│   └── ServiceTag (o activo_<id>)/
│       ├── remito.pdf
│       └── foto.jpg
└── Archivados/
    └── [ARCHIVADO 2026-08-04] ServiceTag/
```

- El cliente vive en `core/integraciones/drive.php`, una sola vez para toda la aplicación.
- **Drive nunca bloquea el inventario**: si no está configurado, el token venció o la API
  falla, la operación en la base ya se hizo y sólo queda un aviso en el log.
- Los archivos se validan por MIME real y tamaño (5 MB). Los temporales del flujo de
  duplicados se guardan **fuera del web root**, con nombre aleatorio y sin la extensión del
  usuario.

## Modelo de datos

```mermaid
erDiagram
    companies ||--o{ users : "tiene"
    companies ||--o{ productos : "tiene"
    companies {
        int id PK
        varchar nombre
        varchar subdomain
        enum tenant_mode
        varchar db_name
        enum provider
        tinyint active
    }
    users_platform {
        int id PK
        varchar username UK
        varchar password
        varchar role
        tinyint active
    }
    platform_role_permissions {
        varchar role PK
        varchar action PK
    }
    users {
        int id PK
        varchar username UK
        varchar email UK
        varchar role
        tinyint active
        tinyint must_change_password
    }
    custom_roles {
        varchar role_key PK
        varchar label
    }
    role_permissions {
        varchar role PK
        varchar action PK
    }
    catalogos {
        int id PK
        varchar tipo
        varchar valor
        tinyint activo
    }
    productos ||--o{ producto_movimientos : "historial"
    productos {
        int id PK
        varchar modelo
        varchar marca
        varchar servis_tag
        varchar asignado_a
        varchar ubicacion
        varchar categoria
        decimal valor_usd
        date fecha_garantia
        tinyint archivado
        datetime archivado_en
        varchar archivado_por
        varchar archivado_motivo
        varchar carpeta_raiz_drive
        varchar drive_padre_previo
    }
    producto_movimientos {
        int id PK
        int producto_id FK
        varchar accion
        varchar realizado_por
        timestamp fecha
    }
    cloud_config {
        int id PK
        enum provider
        varchar config_key
        text config_value
    }
    tenant_settings {
        varchar setting_key PK
        text setting_value
    }
    custom_roles ||--o{ role_permissions : "define"
```

`producto_movimientos` guarda una copia del activo en cada cambio: es un historial de estados,
no de diferencias. Ocupa más, pero sobrevive a cualquier cambio de esquema posterior.

**Índices**: `productos` no tenía ninguno fuera de la clave primaria. La migración 2 agrega
`archivado`, `categoria`, `estado`, `servis_tag`, `asignado_a`, `ubicacion`, `fecha_garantia`,
`(archivado, categoria)` y `archivado_en`; y en `producto_movimientos`, `fecha`, `accion` y
`(producto_id, fecha)`.

## Esquema versionado y despliegue

```mermaid
sequenceDiagram
    participant Dev as Desarrollo
    participant GH as GitHub
    participant SRV as Servidor
    participant DB as Base del tenant

    Dev->>Dev: php test/run_tests.php
    Dev->>GH: git push
    GH->>SRV: git pull (hPanel)
    SRV->>SRV: deploy.sh → composer install
    SRV->>DB: php bin/migrar.php
    DB-->>SRV: schema_version actualizada
    SRV->>SRV: php test/run_tests.php
    Note over SRV,DB: Cada request sólo LEE la versión.<br/>Si falta migrar, lo avisa en el log.
```

| Versión | Qué hace |
| --- | --- |
| 1 | Estado base idempotente: tablas de settings, roles y permisos; `role` a `VARCHAR` |
| 2 | Índices de `productos` y `producto_movimientos` |
| 3 | Tabla `catalogos` + siembra desde los valores existentes |
| 4 | Columnas de papelera y `drive_padre_previo` |
| 5 | Unifica la collation de `tenant_settings` con la de la base |

Para cambiar el esquema se **agrega** una migración al final de `migraciones_tenant()`; nunca
se edita una ya aplicada.

## Sistema de diseño

El portal del tenant usa **exactamente** los mismos tokens que el CRM y APP Tickets, para que
las tres aplicaciones se vean como una sola familia.

| Token | Valor | Uso |
| --- | --- | --- |
| `--ink` | `#161922` | texto y neutros |
| `--muted` | `#6f6a7d` | texto secundario |
| `--line` | `#e7e4ef` | bordes |
| `--surface` | `#ffffff` | tarjetas, campos |
| `--bg` | `#f6f5fa` | fondo de la aplicación |
| `--primary` | `#663399` | color de marca |
| `--primary-700` | `#522a7a` | hover del primario |
| `--accent` | `#9CC930` | **sólo positivos** |
| `--danger` | `#a12a1e` | errores |
| `--warn` | `#a8620a` | advertencias |
| `--font` | `Saira` | tipografía |
| `--radius` | `15px` / `10px` / `9px` | radios |

Shell de topbar con la marca y las acciones, y debajo una fila de pestañas: la misma silueta
que el CRM y Tickets. Saira se carga con `<link>` en el `<head>` y los assets se versionan por
fecha de modificación. **El panel superadmin conserva su diseño** (`intellihelp.css`).

## Stack técnico

| Capa | Tecnología |
| --- | --- |
| Backend | PHP 8.1+ sin framework |
| Base de datos | MySQL 8 / MariaDB 10.4+ (`utf8mb4`), una master + una por empresa |
| Autenticación | Sesiones PHP + OAuth 2.0 (Google Workspace y Microsoft Entra) |
| Almacenamiento | Google Drive API v3 (`google/apiclient`) |
| HTTP | Guzzle 7 |
| Front-end | JavaScript sin build, CSS con variables de tema, `<dialog>` nativo |
| Iconos | Bootstrap Icons |
| Despliegue | Git + `composer install` sobre hosting Apache/LiteSpeed |

## Estructura del proyecto

```
assets/css/app.css          sistema de diseño del portal tenant
assets/css/intellihelp.css  sistema de diseño del panel superadmin
auth/                       login local, SSO Google/Microsoft, logout, cambio de contraseña
backend/                    endpoints: alta, edición, archivado, restaurar, import/export, CSRF
bin/                        consola: migrar, resets, alta de superadmin
core/config/                configuración de plataforma y carga de secretos
core/db/                    conexiones master y tenant, migraciones versionadas
core/integraciones/         cliente y helpers de Google Drive
core/security/              permisos, sesión, CSRF
core/tenants/               instalación de esquema, settings y catálogos por empresa
dashboard/                  panel superadmin
includes/                   layout compartido, iconos, campo de catálogo
inventario/                 portal del tenant
md/                         documento maestro y requerimientos
secure/                     secretos y temporales — nunca por HTTP
test/                       batería de regresión
```

## Puesta en marcha

```bash
git clone git@github.com:bsierra4262/inventario.git
cd inventario
composer install --no-dev --optimize-autoloader

cp core/config/secrets.example.php secure/config/secrets.php
chmod 700 secure/config && chmod 600 secure/config/secrets.php
# completar las credenciales de la base master

# estructura de la master
open https://tu-dominio/install.php

# superadmin (contraseña aleatoria, se muestra una sola vez)
php bin/crear_superadmin.php bsierra bsierra@intellihelp.tech

# esquema de las empresas
php bin/migrar.php
```

Detalle completo, permisos de directorios y configuración de Drive en [INSTALL.md](INSTALL.md).

## Tests

```bash
php test/run_tests.php            # todo
php test/run_tests.php seguridad  # un grupo
```

Corre **sin base de datos ni servidor**: es análisis estático sobre el árbol del repositorio.
Verifica sintaxis, tipos de `bind_param`, uso de la API de Drive, exposición y permisos,
catálogos, papelera, migraciones, identidad visual **y que esta documentación esté al día**.

**Regla del proyecto: cada defecto detectado incorpora un test.** Los grupos SEGURIDAD y
CODIGO son, casi uno a uno, los agujeros y los bugs que ya aparecieron.

## Seguridad

- Los secretos viven en `secure/config/secrets.php`: **no se versiona**, está bloqueado por
  HTTP y se deja con permisos `600`. El repositorio sólo incluye la plantilla.
- Las herramientas destructivas **no son alcanzables por HTTP**: viven en `bin/`, abortan si
  el SAPI no es `cli`, exigen `ASSET_VAULT_DEV=1` y piden confirmación por frase exacta.
- El alta del superadmin se hace por consola, con contraseña aleatoria mostrada una vez.
  Ninguna página pública crea usuarios.
- Cookie de sesión `Secure` + `HttpOnly` + `SameSite`, `use_strict_mode`, y rotación del ID en
  los tres puntos de autenticación.
- Todo el acceso a datos usa sentencias preparadas; toda acción sensible se valida en el
  servidor.

Política completa y proceso de reporte en [SECURITY.md](SECURITY.md).

## Licencia

Software **propietario**, desarrollado **íntegramente por Brian Sierra**. Todos los derechos
reservados. Uso interno de la organización y para la prestación del servicio a sus empresas
cliente; se prohíbe su redistribución o uso fuera de ese marco sin autorización expresa y por
escrito del autor. El autor conserva su derecho moral de reconocimiento de autoría
(Ley 11.723 art. 52; Convenio de Berna art. 6 bis). Ver [LICENSE](LICENSE).

## Autor

**Brian Sierra** — diseño, desarrollo e implementación completos: arquitectura multiempresa,
modelo de permisos, integración con Google Drive, front-end, despliegue y documentación.
