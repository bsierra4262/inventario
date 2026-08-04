# Requerimientos

Qué tiene que hacer el sistema y bajo qué reglas. Lo que está marcado con ✅ está implementado
y verificado por la batería (`php test/run_tests.php`).

---

## 1. Plataforma

| # | Requerimiento | Estado |
| --- | --- | :-: |
| P1 | Varias empresas cliente conviven sin verse entre sí, cada una con su base de datos | ✅ |
| P2 | Soporte para base propia (`external`) y para tablas prefijadas en la master (`prefix`) | ✅ |
| P3 | Alta, edición y baja lógica de empresas desde el panel | ✅ |
| P4 | Cada empresa tiene un subdominio legible (`ITH`) que identifica su login | ✅ |
| P5 | Respaldo completo de una empresa en JSON, restringido a superadmin | ✅ |
| P6 | Usuarios de plataforma con cuatro roles y overrides de permisos por base | ✅ |
| P7 | El alta del superadmin inicial no puede hacerse desde la web | ✅ |
| P8 | Las herramientas destructivas no pueden ser alcanzables por HTTP | ✅ |

## 2. Inventario

| # | Requerimiento | Estado |
| --- | --- | :-: |
| I1 | Alta de activos con modelo, marca, categoría, service tag, valor, garantía y ubicación | ✅ |
| I2 | Campos específicos por categoría (patchcord, bobina, conectores, cintas, software…) | ✅ |
| I3 | Listado con búsqueda, filtro por categoría, orden por columna y paginado | ✅ |
| I4 | KPIs: total, valor, sin asignar, archivados y garantías por vencer | ✅ |
| I5 | Historial completo por activo: qué cambió, quién y cuándo | ✅ |
| I6 | Archivar en vez de borrar, con motivo | ✅ |
| I7 | **Papelera con restauración**: un activo dado de baja por error se recupera | ✅ |
| I8 | Al archivar, la carpeta de Drive se mueve a «Archivados» y vuelve al restaurar | ✅ |
| I9 | Eliminación definitiva reservada al admin | ✅ |
| I10 | Importación y exportación CSV | ✅ |
| I11 | Reportes por categoría, ubicación, responsable, garantía y valor | ✅ |

## 3. Catálogos

| # | Requerimiento | Estado |
| --- | --- | :-: |
| C1 | "Asignado a" y "Ubicación" son desplegables, no texto libre | ✅ |
| C2 | El administrador (y el superadministrador) pueden agregar valores | ✅ |
| C3 | Un usuario común **no** puede agregar valores: sólo elige de la lista | ✅ |
| C4 | La restricción se aplica en el servidor, no sólo en el formulario | ✅ |
| C5 | Renombrar un valor arrastra el cambio a los activos que lo usaban | ✅ |
| C6 | Un valor en uso no se puede eliminar; se da de baja y sale del desplegable | ✅ |
| C7 | Los valores que ya existían al migrar no se pierden | ✅ |
| C8 | Un activo con un valor fuera de la lista se sigue mostrando, marcado | ✅ |

## 4. Usuarios y permisos

| # | Requerimiento | Estado |
| --- | --- | :-: |
| U1 | Cuatro roles incorporados por empresa: admin, manager, user, viewer | ✅ |
| U2 | Roles personalizados por empresa, sin herencia | ✅ |
| U3 | Editor de permisos por rol, tanto de empresa como de plataforma | ✅ |
| U4 | Cambio de contraseña obligatorio en el primer ingreso | ✅ |
| U5 | Un rol sin el permiso correspondiente recibe 403, no una pantalla vacía | ✅ |

## 5. Autenticación

| # | Requerimiento | Estado |
| --- | --- | :-: |
| A1 | Login local con usuario y contraseña (bcrypt) | ✅ |
| A2 | SSO con Google por empresa | ✅ |
| A3 | SSO con Microsoft por empresa | ✅ |
| A4 | El acceso al panel superadmin se restringe al dominio de la organización | ✅ |
| A5 | Una empresa con SSO puede deshabilitar el login local | ✅ |
| A6 | El usuario tiene que estar pre-registrado: el SSO no da de alta solo | ✅ |
| A7 | El ID de sesión rota al autenticar | ✅ |
| A8 | Protección de fuerza bruta en los logins | ⏳ |

## 6. Integración con almacenamiento

| # | Requerimiento | Estado |
| --- | --- | :-: |
| D1 | Cada empresa conecta su propio Google Drive | ✅ |
| D2 | Carpeta por activo, bajo `Modelo - Marca / ServiceTag` | ✅ |
| D3 | Control de duplicados con opción de sobrescribir o renombrar | ✅ |
| D4 | Validación de tipo real y tamaño de los adjuntos | ✅ |
| D5 | Drive nunca bloquea la operación del inventario | ✅ |
| D6 | Subida a OneDrive / SharePoint | ⏳ |

## 7. Calidad y operación

| # | Requerimiento | Estado |
| --- | --- | :-: |
| Q1 | El esquema se versiona y se aplica por consola, no en cada request | ✅ |
| Q2 | Batería de regresión que corre sin base ni servidor | ✅ |
| Q3 | La batería verifica que la documentación esté al día | ✅ |
| Q4 | `README.md` refleja el documento maestro 1:1, diagramas incluidos | ✅ |
| Q5 | Cada defecto detectado incorpora un test que impide su reaparición | ✅ |
| Q6 | Ningún secreto versionado | ✅ |
| Q7 | Los commits son de Brian Sierra, sin atribución de herramientas | ✅ |
| Q8 | Auditoría de acciones en `logs/audit.log`, no accesible por HTTP | ✅ |

## 8. Identidad visual

| # | Requerimiento | Estado |
| --- | --- | :-: |
| V1 | El portal tenant usa los mismos tokens que el CRM y APP Tickets | ✅ |
| V2 | Fuente Saira, primario `#663399`, texto `#161922`, acento `#9CC930` | ✅ |
| V3 | El acento verde se usa **sólo** para positivos | ✅ |
| V4 | Shell de topbar + pestañas, como las otras aplicaciones | ✅ |
| V5 | El panel superadmin conserva su diseño actual | ✅ |
| V6 | Ninguna clase del markup queda sin estilo definido | ✅ |
| V7 | Iconos SVG inline en vez de una fuente de iconos por CDN | ⏳ |

---

## Pendientes

| Tema | Detalle |
| --- | --- |
| A8 · Fuerza bruta | Ni `login.php` ni `superadmin_login.php` tienen throttling, lockout ni captcha. Los intentos fallidos sólo se registran. |
| D6 · OneDrive | El login con Microsoft funciona; la subida de archivos a OneDrive/SharePoint no está implementada. |
| V7 · Iconos | El markup usa Bootstrap Icons por CDN. El resto de las aplicaciones usa SVG inline; migrarlo son ~500 reemplazos. |
| nOAuth | El flujo de Microsoft identifica por `mail`/`userPrincipalName` con `tenant_id = common`. No es explotable hoy porque Microsoft no está configurado, pero conviene fijar el `tenant_id` o exigir dominio antes de activarlo. |
| Cifrado de `companies.db_pass` | La contraseña de la base de cada empresa se guarda en claro en la master. |
| Unificar CSRF | Conviven `core/security/csrf.php` (campo `_csrf`) y `backend/csrf.php` (campo `csrf_token`, con scopes y TTL). El segundo es el bueno; falta migrar el panel. |
| `csrf_tenant_ns()` | Lee `$_SESSION['tenant_id']`, pero la aplicación usa `company_id`: el namespace por empresa nunca se activa. |
| Podar `vendor/` | `google/apiclient-services` trae todos los servicios de Google: 328 MB. Con `Google\Task\Composer::cleanup` queda en ~15 MB. |
| `users_platform.role` | Sigue siendo `ENUM`, el mismo problema que ya se corrigió del lado del tenant: un rol de plataforma personalizado no se podría asignar. |
