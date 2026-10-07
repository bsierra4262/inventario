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
| P9 | Una sesión autenticada en una empresa **no sirve** en otra: la identidad y la empresa viajan juntas y se verifican en cada request | ✅ |
| P10 | El esquema de la master se versiona y se aplica por consola, igual que el del tenant | ✅ |

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
| I9 | **Eliminación definitiva** reservada al admin, sólo desde la papelera, confirmando con el número del activo y conservando el historial | ✅ |
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
| C9 | Marca, modelo, categoría, subcategoría y estado también son catálogos, no texto libre | ✅ |
| C10 | Las ubicaciones son un **árbol** (`Sede > Piso > Depósito`), con dirección, edificio, habitación y coordenadas | ✅ |
| C11 | Renombrar una ubicación recalcula la ruta de todo su subárbol y la propaga a los activos | ✅ |
| C12 | No se puede colgar una ubicación de un descendiente suyo, ni borrar una que tenga hijos o activos | ✅ |
| C13 | Los campos específicos por categoría se definen **desde la aplicación**: agregar uno no requiere tocar el esquema | ✅ |
| C14 | Los catorce campos que ya existían conservan su columna, para no mover datos ni romper export/import/respaldo | ✅ |
| C15 | El valor de un campo configurable se valida contra su definición en el servidor | ✅ |
| C16 | Un campo con valores cargados no se puede eliminar; se oculta y los activos lo conservan | ✅ |

## 3b. Información financiera y administrativa

Tomado de lo que GLPI llama *Infocom*.

| # | Requerimiento | Estado |
| --- | --- | :-: |
| F1 | Datos de compra por activo: pedido, remito, factura, número contable, proveedor y presupuesto | ✅ |
| F2 | Fechas de orden, entrega, puesta en servicio, último inventario físico y decomiso | ✅ |
| F3 | **Amortización por activo** (duración, tipo y coeficiente), no un parámetro global del reporte | ✅ |
| F4 | Criticidad de negocio por activo | ✅ |
| F5 | Los campos que ya vivían en `productos` no se duplican en la tabla nueva | ✅ |
| F6 | La edición del activo carga estos campos desde el formulario | ✅ |
| F7 | El reporte de amortización usa el plazo guardado por activo, con el del reporte como respaldo | ✅ |
| F8 | El alta de activos carga también estos campos | ✅ |

## 3c. El activo por dentro (fase 4)

Modelo tomado de GLPI. Donde GLPI usa veintitrés tablas —una de catálogo y una de enlace por
cada tipo de componente, más siete para el sistema operativo— acá son seis: los campos que
varían entre tipos son cuatro y entran en la misma fila, quedando nulos donde no aplican.

| # | Requerimiento | Estado |
| --- | --- | :-: |
| T1 | Sistema operativo por activo: nombre, versión, edición, arquitectura, kernel, service pack | ✅ |
| T2 | Licencia del sistema operativo: clave de producto, número y fecha de instalación | ✅ |
| T3 | «Cuántas máquinas siguen en Windows 10» es una consulta, no una revisión equipo por equipo | ✅ |
| T4 | Componentes internos con catálogo compartido: procesador, memoria, disco, red, video, fuente, placa, firmware | ✅ |
| T5 | Cada componente puesto guarda lo suyo: cantidad, serie, capacidad, frecuencia, núcleos, hilos, MAC | ✅ |
| T6 | Un componente en uso no se puede eliminar: se desactiva y los activos lo conservan | ✅ |
| T7 | Puertos de red por activo, con número, nombre, tipo, MAC, velocidad, MTU y VLAN | ✅ |
| T8 | Varias direcciones IP por puerto, IPv4 e IPv6, con máscara, gateway, asignación y nombre DNS | ✅ |
| T9 | La MAC se guarda en un solo formato, se escriba con guiones, con puntos o pegada | ✅ |
| T10 | Buscar qué activo tiene una IP o una MAC | ✅ |
| T11 | Relaciones entre activos: conectado a, parte de, reemplaza, depende de, es respaldo de | ✅ |
| T12 | La relación se ve desde los dos activos, con la etiqueta que corresponde a cada lado | ✅ |
| T13 | No se vincula un activo consigo mismo ni se repite una pareja, en ningún sentido | ✅ |

## 3d. El activo por fuera (fase 5)

| # | Requerimiento | Estado |
| --- | --- | :-: |
| X1 | Proveedores con CUIT, contacto, teléfono, email, web y dirección | ✅ |
| X2 | Los proveedores que ya figuraban como texto en los datos de compra se trajeron al migrar | ✅ |
| X3 | Un proveedor con contratos no se elimina: se desactiva | ✅ |
| X4 | Contratos con número, tipo, proveedor, inicio, duración, preaviso, renovación, costo y número contable | ✅ |
| X5 | La fecha de fin se calcula con el inicio y la duración, no se escribe a mano | ✅ |
| X6 | El cálculo no desborda el mes: un mes desde el 31 de enero termina el 28 de febrero | ✅ |
| X7 | Un contrato cubre varios activos y un activo puede estar cubierto por varios contratos | ✅ |
| X8 | «Qué vence en los próximos noventa días», con el preaviso de cada contrato | ✅ |
| X9 | Documentos por activo, con tipo: factura, remito, garantía, manual, foto, certificado | ✅ |
| X10 | El archivo sigue en Drive o en OneDrive: acá se guarda qué es, que es lo que no se podía saber | ✅ |
| X11 | Borrar un documento no borra el archivo de la nube | ✅ |
| X12 | El mismo documento cubre varios activos: una factura, diez monitores | ✅ |
| X13 | Notas por activo, con autor y fecha, aparte del historial de cambios | ✅ |
| X14 | Plantillas de alta, para no llenar veinte veces los mismos ocho campos | ✅ |
| X15 | Todo esto en una sola pantalla por activo, no en siete pestañas | ✅ |

## 4. Usuarios y permisos

| # | Requerimiento | Estado |
| --- | --- | :-: |
| U1 | Cuatro roles incorporados por empresa: admin, manager, user, viewer | ✅ |
| U2 | Roles personalizados por empresa, sin herencia | ✅ |
| U3 | Editor de permisos por rol, tanto de empresa como de plataforma | ✅ |
| U4 | Cambio de contraseña obligatorio en el primer ingreso | ✅ |
| U5 | Un rol sin el permiso correspondiente recibe 403, no una pantalla vacía | ✅ |
| U6 | Todo endpoint que escribe exige **permiso**, no sólo sesión: tener el token CSRF no es tener derecho a la acción | ✅ |

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
| Q10 | Batería de **integración** contra una base real: migraciones, idempotencia, esquema y comportamiento | ✅ |
| Q3 | La batería verifica que la documentación esté al día | ✅ |
| Q4 | `README.md` refleja el documento maestro 1:1, diagramas incluidos | ✅ |
| Q5 | Cada defecto detectado incorpora un test que impide su reaparición | ✅ |
| Q6 | Ningún secreto en el árbol de trabajo | ✅ |
| Q9 | Ningún secreto en el **historial de git** | ⚠ |
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
| V8 | Ninguna clase del sistema de diseño se define sólo dentro de una página | ✅ |
| V9 | Cada clase está en **la hoja que su portal carga**, no en la otra | ✅ |
| V10 | Los controles nativos (casillas, radios, desplegables, calendario) usan la identidad de marca en los dos portales | ✅ |
| V11 | Escala responsive con cortes en 1200, 1024, 860, 640 y 400, y área táctil de 44px sin mouse | ✅ |
| V12 | El panel se puede navegar en tablet y teléfono | ✅ |
| V13 | Convergencia total con la convención de nombres de APP Tickets (`.btn`/`.card` sin prefijo) | ⏳ |
| V7 | Iconos SVG inline en vez de una fuente de iconos por CDN | ⏳ |

---

## Pendientes

| Tema | Detalle |
| --- | --- |
| **Q9 · `client_secret` de Google en el historial** | `auth/credentials.json` quedó en el primer commit (`e811ff7b`), alcanzable desde `main` y desde los dos remotos. Se verificó que **el valor del commit es idéntico al que está en uso**. Los repositorios son privados y se decidió no rotar todavía; la historia se conserva a propósito, así que tampoco se reescribió. Registrado en la línea base de la batería, que falla con cualquier fuga nueva. Para cerrarlo: rotar en Google Cloud Console y después purgar el blob. |
| A8 · Fuerza bruta | Ni `login.php` ni `superadmin_login.php` tienen throttling, lockout ni captcha. Los intentos fallidos sólo se registran. |
| D6 · OneDrive | El login con Microsoft funciona; la subida de archivos a OneDrive/SharePoint no está implementada. |
| V7 · Iconos | El markup usa Bootstrap Icons por CDN. El resto de las aplicaciones usa SVG inline; migrarlo son ~500 reemplazos. |
| nOAuth | El flujo de Microsoft identifica por `mail`/`userPrincipalName` con `tenant_id = common`. No es explotable hoy porque Microsoft no está configurado, pero conviene fijar el `tenant_id` o exigir dominio antes de activarlo. |
| Cifrado de `companies.db_pass` | La contraseña de la base de cada empresa se guarda en claro en la master. |
| Unificar CSRF | Conviven **tres** implementaciones: `core/security/csrf.php` (campo `_csrf`), `backend/csrf.php` (campo `csrf_token`, con scopes, TTL y cookie shadow) y una hecha a mano en `auth/change_password.php` (`$_SESSION['csrf_token']` + `hash_equals`). La segunda es la buena; falta migrar el panel y el cambio de contraseña. Las tres son correctas, pero son tres lugares donde mantener lo mismo. |
| `csrf_tenant_ns()` | Lee `$_SESSION['tenant_id']`, pero la aplicación usa `company_id`: el namespace por empresa nunca se activa. |
| `assets/css/styles.css` muerta | No la carga ninguna página: son estilos de la época de Bootstrap (`.container`, `.nav-tabs`, `.btn`). Hasta 1.3.0 la batería la usaba para dar por definida una clase, lo que inflaba la cobertura. Se puede borrar. |
| Retirar las 14 columnas heredadas | La fase 3 ya permite definir campos sin DDL, pero las catorce columnas viejas siguen en `productos` y los campos que las usan escriben ahí. Es deliberado: primero se agrega la forma nueva, después se retira la vieja. Cuando esté probado en producción, una migración mueve los valores a `campos_valor`, se actualizan los dos export y los dos import, y recién ahí se borran las columnas. |
| Campos configurables en export/import | El CSV y el respaldo exportan las catorce columnas heredadas, no los campos configurables nuevos. |
| Fases 4 y 5 del modelo | Tipos de activo con campos propios, SO, componentes, red y relaciones entre activos; después contratos, documentos, notas y plantillas. |
| Podar `vendor/` | `google/apiclient-services` trae todos los servicios de Google: 328 MB. Con `Google\Task\Composer::cleanup` queda en ~15 MB. |
| `upload_renamed.php` huérfano | Ningún formulario, link ni redirect lo referencia: el flujo vivo de duplicados es `confirmar_accion.php` → `renombrar.php` / `sobrescribir.php`, y `renombrar.php` ya hace el renombrar-y-subir. En 1.2.0 se le agregaron CSRF, permiso y validación de adjunto en vez de borrarlo. Conviene darlo de baja: código muerto alcanzable por HTTP es superficie sin dueño. |
| `install.php` sigue publicado | Es una página pública que hace `CREATE TABLE` en la master. Está bien guardada (si `users_platform` tiene filas redirige, y ya no siembra el superadmin) y en producción devuelve 302, pero no tiene razón de existir después del día uno. |
| Podar los `CREATE TABLE` duplicados | `users_platform` se declara en tres archivos. En 1.2.0 se unificó la columna `role` en los tres, pero el esquema debería vivir sólo en `migraciones_master()`: tres declaraciones de la misma tabla fue la causa del bug del ENUM, no el ENUM en sí. |
