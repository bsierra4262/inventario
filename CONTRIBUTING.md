# Cómo se trabaja en este proyecto

## Reglas que no se negocian

1. **La documentación se actualiza en el mismo cambio, no después.**
   `md/PROYECTO.md` es el documento maestro; `README.md` lo refleja **1:1**, diagramas
   incluidos. Si un cambio agrega una capacidad y no aparece en los dos, el cambio está
   incompleto. La batería lo verifica.

2. **Cada defecto encontrado incorpora un test.**
   `test/run_tests.php` no es un adorno: los grupos SEGURIDAD y CODIGO son, casi uno a uno,
   los agujeros y los bugs que ya aparecieron. Un arreglo sin test vuelve.

3. **Sin secretos en el repositorio.**
   Van a `secure/config/secrets.php`, que está en `.gitignore`. La batería falla si encuentra
   una credencial conocida en cualquier archivo versionado.

4. **Los commits son de Brian Sierra, sin atribución de herramientas.**
   Nada de `Co-Authored-By` de asistentes ni firmas automáticas.

5. **El esquema se cambia con una migración nueva, nunca editando una aplicada.**
   Se suma una entrada al final de `migraciones_tenant()` en `core/db/migraciones.php`.
   Nada de DDL suelto en el código de las páginas.

## Antes de commitear

```bash
php test/run_tests.php
```

Tiene que terminar en verde. Corre sin base de datos ni servidor.

## Estructura

```
assets/css/app.css          sistema de diseño del portal tenant
assets/css/intellihelp.css  sistema de diseño del panel superadmin
auth/                       login local, SSO (Google / Microsoft), logout, cambio de contraseña
backend/                    endpoints: alta, edición, archivado, restaurar, import/export, CSRF
bin/                        herramientas de consola (migrar, resets, alta de superadmin)
core/config/                configuración de plataforma y carga de secretos
core/db/                    conexiones master y tenant, migraciones versionadas
core/integraciones/         cliente y helpers de Google Drive
core/security/              permisos (plataforma y tenant), sesión, CSRF
core/tenants/               instalación de esquema, settings y catálogos por empresa
dashboard/                  panel superadmin
includes/                   layout compartido, iconos, campo de catálogo
inventario/                 portal del tenant
md/                         documento maestro y requerimientos
secure/                     secretos y temporales — nunca por HTTP
test/                       batería de regresión
```

## Convenciones

- **Idioma**: código, comentarios, mensajes de commit y documentación en español.
- **Comentarios**: explican *por qué*, no *qué*. Si un comentario describe lo que la línea
  siguiente ya dice, sobra. Si documenta una decisión o un incidente, se queda.
- **SQL**: siempre preparado. Los nombres de tabla salen de `tn()`, nunca del request.
- **Tipos de `bind_param`**: se derivan de la lista de columnas, no se escriben a mano.
  Un desajuste es un `ArgumentCountError` fatal en PHP 8 — ya pasó dos veces.
- **Permisos**: toda acción sensible se valida en el servidor. Ocultar un botón no es control
  de acceso.
- **CSS**: el portal tenant usa los tokens de CMR y APP Tickets. No se inventan colores nuevos;
  si hace falta uno, se agrega al bloque `:root` y se documenta.

## Mensajes de commit

Una línea de asunto en minúsculas con el ámbito adelante, y un cuerpo que explique **por qué**:

```
fix: el guardado de una edición devolvia HTTP 500

edit.php declaraba 33 caracteres de tipos para 32 parametros en bind_param.
En PHP 8 eso lanza ArgumentCountError, que no esta atrapado: fatal error.
Los tipos ahora se derivan del mapa de columnas.
```
