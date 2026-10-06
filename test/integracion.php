<?php
/**
 * Batería de integración — corre contra una base de datos real.
 *
 * `run_tests.php` verifica el código sin base ni servidor, que es lo que permite
 * correrla en cualquier lado. Pero hay cosas que sólo se comprueban ejecutando:
 * que una migración aplique sin error, que sea idempotente, que el árbol de
 * ubicaciones recalcule bien las rutas, que un renombrado arrastre los activos.
 * Eso va acá.
 *
 * Uso:
 *
 *   AV_TEST_DB_HOST=127.0.0.1 AV_TEST_DB_PORT=3307 \
 *   AV_TEST_DB_USER=root AV_TEST_DB_PASS= AV_TEST_DB_NAME=av_tenant \
 *   php test/integracion.php
 *
 * Si no hay base configurada, informa y sale con 0: no rompe un pipeline donde
 * sólo se puede correr la batería estática.
 *
 * **Trabaja sobre una base descartable.** Al empezar borra todas las tablas del
 * esquema que se le indique, así que nunca se le apunta a una base con datos.
 */

declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

$PASS = 0; $FAIL = 0; $FALLOS = [];
$grupoActual = '';

function grupo(string $n): void {
    global $grupoActual;
    $grupoActual = $n;
    echo "\n\033[1m── {$n} " . str_repeat('─', max(0, 60 - strlen($n))) . "\033[0m\n";
}

function chequear(string $desc, bool $ok, string $detalle = ''): void {
    global $PASS, $FAIL, $FALLOS, $grupoActual;
    if ($ok) {
        $PASS++;
        echo "  \033[32m✓\033[0m {$desc}\n";
    } else {
        $FAIL++;
        $FALLOS[] = "[{$grupoActual}] {$desc}" . ($detalle ? " — {$detalle}" : '');
        echo "  \033[31m✗\033[0m {$desc}" . ($detalle ? "\n      \033[31m{$detalle}\033[0m" : '') . "\n";
    }
}

echo "\n\033[1;35m  Asset Vault · batería de integración\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
// Conexión
// ─────────────────────────────────────────────────────────────────────────────
$host = getenv('AV_TEST_DB_HOST') ?: '';
$port = (int)(getenv('AV_TEST_DB_PORT') ?: 3306);
$user = getenv('AV_TEST_DB_USER') ?: '';
$pass = (string)(getenv('AV_TEST_DB_PASS') ?: '');
$name = getenv('AV_TEST_DB_NAME') ?: '';

if ($host === '' || $user === '' || $name === '') {
    echo "\n  \033[33m·\033[0m Sin base de pruebas configurada (AV_TEST_DB_*). No se corre nada.\n";
    echo "    Esta batería es complementaria: run_tests.php cubre lo que no necesita base.\n\n";
    exit(0);
}

// MISMO modo de reporte que en producción. Desde PHP 8.1 mysqli LANZA
// excepciones por defecto, y la aplicación no lo cambia (salvo login.php y
// change_password.php, que piden explícitamente ERROR|STRICT, o sea lo mismo).
//
// La primera versión de esta batería lo ponía en OFF, y eso la volvía mentirosa:
// una consulta que acá fallaba en silencio, en el servidor aborta la migración.
// El operador `@` no suprime excepciones.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = @new mysqli($host, $user, $pass, $name, $port);
if ($conn->connect_error) {
    echo "\n  \033[31m✗\033[0m No se pudo conectar a {$host}:{$port}/{$name}: {$conn->connect_error}\n\n";
    exit(1);
}
$conn->set_charset('utf8mb4');
echo "  base: {$host}:{$port}/{$name}\n";

// ─────────────────────────────────────────────────────────────────────────────
// Prefijo de tablas
//
// Sin prefijo, esta batería borra TODAS las tablas de la base: necesita una
// base descartable y en un hosting compartido no siempre se puede crear una.
//
// Con `--prefijo=pruebas_` se queda en sus propias tablas: crea
// `pruebas_productos`, `pruebas_catalogos`… y al terminar borra sólo ésas. Eso
// permite correrla contra la base de desarrollo sin tocar un solo dato, y de
// paso ejercita el modo `prefix` de la plataforma, que está soportado desde el
// principio y que hasta ahora no probaba nadie.
// ─────────────────────────────────────────────────────────────────────────────
$PREFIJO = '';
foreach ($argv as $a) {
    if (str_starts_with((string)$a, '--prefijo=')) {
        $PREFIJO = substr((string)$a, strlen('--prefijo='));
    }
}
if ($PREFIJO !== '' && !preg_match('/^[a-z][a-z0-9_]{1,28}_$/', $PREFIJO)) {
    echo "\n  \033[31m✗\033[0m El prefijo tiene que ser minúsculas, números y guiones bajos, y terminar en `_`.\n\n";
    exit(1);
}
if ($PREFIJO !== '') { echo "  prefijo: {$PREFIJO}\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// Salvaguarda
//
// Lo que viene borra las tablas de la base indicada. Este archivo se despliega
// al servidor junto con el resto del proyecto, así que un `AV_TEST_DB_NAME` mal
// puesto ahí dentro vacía el inventario de un cliente.
//
// Si la tabla de activos que se va a borrar tiene filas, no es descartable: es
// producción o una copia. Se aborta. El `--si-estoy-seguro` existe para el caso
// legítimo de probar sobre una copia con datos, y hay que escribirlo a mano.
//
// Con prefijo la pregunta es por SU tabla de activos, no por la del tenant: lo
// que importa es lo que esta batería va a borrar.
// ─────────────────────────────────────────────────────────────────────────────
$conActivos = 0;
try {
    $rsG = $conn->query('SELECT COUNT(*) FROM `' . $PREFIJO . 'productos`');
    if ($rsG) { $conActivos = (int)$rsG->fetch_array()[0]; }
} catch (Throwable) { /* la tabla no existe: nada que borrar, adelante */ }

if ($conActivos > 0 && !in_array('--si-estoy-seguro', $argv, true)) {
    $cual = $PREFIJO === '' ? "`{$name}`" : "`{$name}`.`{$PREFIJO}productos`";
    echo "\n  \033[31m✗\033[0m ABORTADO: {$cual} tiene {$conActivos} activos cargados.\n";
    echo "    Esta batería BORRA esas tablas. No se apunta a una base con datos.\n";
    echo "    Si de verdad querés hacerlo sobre una copia, agregá --si-estoy-seguro\n\n";
    exit(1);
}

// Base limpia: esto es destructivo a propósito, por eso sólo se apunta a una
// base descartable — o se usa un prefijo, y entonces sólo caen esas tablas.
$conn->query('SET FOREIGN_KEY_CHECKS = 0');
$rs = $conn->query('SHOW TABLES');
$borradas = 0;
while ($r = $rs->fetch_array()) {
    if ($PREFIJO !== '' && !str_starts_with((string)$r[0], $PREFIJO)) { continue; }
    $conn->query("DROP TABLE IF EXISTS `{$r[0]}`");
    $borradas++;
}
$conn->query('SET FOREIGN_KEY_CHECKS = 1');
if ($PREFIJO !== '') { echo "  tablas previas borradas: {$borradas}\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// Dependencias del proyecto. `tn()` se define acá para no arrastrar el
// bootstrap multiempresa entero, que necesita sesión y master.
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('tn')) {
    function tn(string $t): string { global $PREFIJO; return $PREFIJO . $t; }
}
// Los nombres de tabla ya prefijados, para interpolarlos en el SQL de esta
// batería.
//
// Escritos a mano, con --prefijo la batería escribía en las tablas REALES de la
// base: metía sus activos de prueba en el inventario de verdad. Pasó una vez,
// con tres filas en la base de desarrollo. Por eso todo el SQL de acá pasa por
// este arreglo, igual que el código de la aplicación pasa por tn().
$T = [];
foreach (['productos', 'catalogos', 'activo_infocom', 'campos_definicion',
          'campos_valor', 'ubicaciones', 'producto_movimientos', 'users',
          'tenant_settings', 'custom_roles', 'role_permissions'] as $__t) {
    $T[$__t] = $PREFIJO . $__t;
}

// ─────────────────────────────────────────────────────────────────────────────
// Autocontrol: ninguna tabla del tenant escrita a mano
//
// Con --prefijo, una sola referencia sin prefijar escribe en las tablas REALES
// de la base. Pasó: tres activos de prueba terminaron en el inventario de
// desarrollo porque `INSERT INTO \`productos\`` estaba escrito literal.
//
// El `$PREFIJO` es lo único que separa esta batería de los datos de alguien, y
// una revisión por ojo no es suficiente garantía. Así que antes de escribir una
// sola fila, la batería se lee a sí misma y aborta si encuentra una.
// ─────────────────────────────────────────────────────────────────────────────
if ($PREFIJO !== '') {
    $propio = (string)file_get_contents(__FILE__);
    $sueltas = [];
    foreach ($T as $tabla => $_) {
        // Dentro de SQL: detrás de FROM, INTO, UPDATE, TABLE o JOIN.
        if (preg_match_all('/\b(?:FROM|INTO|UPDATE|TABLE|JOIN)\s+`' . preg_quote($tabla, '/') . '`/i',
                           $propio, $m)) {
            $sueltas[] = $tabla . ' (x' . count($m[0]) . ')';
        }
    }
    if ($sueltas !== []) {
        echo "\n  \033[31m✗\033[0m ABORTADO: esta batería tiene tablas escritas sin prefijo.\n";
        echo "    Con --prefijo escribirían en las tablas reales de `{$name}`.\n";
        echo "    Sin prefijar: " . implode(', ', $sueltas) . "\n";
        echo "    Usá \$T['tabla'] en el SQL, como el resto del archivo.\n\n";
        exit(1);
    }
}


if (!function_exists('log_event')) {
    function log_event($o, $t, $m, $tag = 'app') {}
}

require_once RAIZ . '/core/config/platform.php';
require_once RAIZ . '/core/db/migraciones.php';
require_once RAIZ . '/core/tenants/install_tenant.php';
require_once RAIZ . '/core/tenants/ubicaciones.php';
require_once RAIZ . '/core/tenants/catalogos.php';
require_once RAIZ . '/core/tenants/infocom.php';
require_once RAIZ . '/core/tenants/campos.php';
require_once RAIZ . '/core/tenants/sistema_operativo.php';
require_once RAIZ . '/core/tenants/componentes.php';
require_once RAIZ . '/core/tenants/red.php';
require_once RAIZ . '/core/tenants/relaciones.php';
require_once RAIZ . '/core/tenants/contratos.php';
require_once RAIZ . '/core/tenants/documentos.php';

/** Columnas de una tabla. */
function columnas(mysqli $c, string $t): array {
    $out = [];
    try {
        $rs = $c->query("SHOW COLUMNS FROM `{$t}`");
        if ($rs) { while ($r = $rs->fetch_assoc()) { $out[] = $r['Field']; } }
    } catch (Throwable) {}
    return $out;
}

function existe_tabla(mysqli $c, string $t): bool {
    try {
        $rs = $c->query("SHOW TABLES LIKE '" . $c->real_escape_string($t) . "'");
        return $rs && $rs->num_rows > 0;
    } catch (Throwable) { return false; }
}

function una(mysqli $c, string $sql) {
    try {
        $rs = $c->query($sql);
        if (!$rs) { return null; }
        $r = $rs->fetch_array();
        return $r ? $r[0] : null;
    } catch (Throwable) { return null; }
}

// ═══════════════════════════════════════════════════════════════════════════
grupo('MIGRACIONES · aplicación');
// ═══════════════════════════════════════════════════════════════════════════
// Primero el esquema base (lo que crea el alta de una empresa), después las
// migraciones. Es exactamente la secuencia de una instalación nueva.
$errBase = '';
foreach (tenant_schema_sql($PREFIJO) as $q) {
    if (!$conn->query($q)) { $errBase = $conn->error; break; }
}
chequear('El esquema base del tenant se crea sin error', $errBase === '', $errBase);

$aplicadas = [];
$error = '';
try {
    $aplicadas = migrar_tenant($conn, $PREFIJO, function (string $m) {});
} catch (Throwable $e) {
    $error = $e->getMessage();
}
chequear('Las migraciones de tenant aplican sin error desde cero', $error === '', $error);
chequear('Se aplicaron todas las versiones disponibles (' . schema_version_objetivo() . ')',
    $aplicadas === range(1, schema_version_objetivo()),
    'aplicadas: ' . implode(',', $aplicadas));
chequear('La versión queda registrada', schema_version_actual($conn, $PREFIJO) === schema_version_objetivo());

// Idempotencia: volver a correrlas no puede hacer nada ni fallar.
$error2 = '';
$seg = [];
try { $seg = migrar_tenant($conn, $PREFIJO, function (string $m) {}); }
catch (Throwable $e) { $error2 = $e->getMessage(); }
chequear('Correr las migraciones de nuevo no aplica nada ni falla', $error2 === '' && $seg === [], $error2);

// ═══════════════════════════════════════════════════════════════════════════
grupo('ESQUEMA · tablas y columnas');
// ═══════════════════════════════════════════════════════════════════════════
foreach (['productos', 'producto_movimientos', 'catalogos', 'ubicaciones', 'activo_infocom',
          'tenant_settings', 'custom_roles', 'role_permissions'] as $t) {
    chequear("Existe la tabla `{$t}`", existe_tabla($conn, $t));
}

$colsProd = columnas($conn, 'productos');
foreach (['ubicacion_id', 'archivado_en', 'archivado_por', 'drive_padre_previo'] as $c) {
    chequear("productos tiene `{$c}`", in_array($c, $colsProd, true));
}

$colsUbi = columnas($conn, 'ubicaciones');
foreach (['padre_id', 'ruta', 'direccion', 'codigo_postal', 'localidad', 'latitud', 'longitud'] as $c) {
    chequear("ubicaciones tiene `{$c}`", in_array($c, $colsUbi, true));
}

$colsIc = columnas($conn, 'activo_infocom');
foreach (['numero_pedido', 'numero_factura', 'proveedor', 'presupuesto', 'fecha_puesta_servicio',
          'fecha_decomiso', 'amortizacion_anios', 'amortizacion_tipo', 'amortizacion_coeficiente',
          'criticidad'] as $c) {
    chequear("activo_infocom tiene `{$c}`", in_array($c, $colsIc, true));
}
// Lo que NO tiene que estar: los campos que ya viven en productos.
foreach (['valor_usd', 'cantidad', 'fecha_compra'] as $c) {
    chequear("activo_infocom NO duplica `{$c}`", !in_array($c, $colsIc, true));
}

// Índices: `productos` no tenía ninguno fuera de la PK y cada filtro era un
// recorrido completo de la tabla.
$idx = [];
$rs = $conn->query("SHOW INDEX FROM `{$T['productos']}`");
while ($r = $rs->fetch_assoc()) { $idx[$r['Key_name']] = true; }
foreach (['idx_archivado', 'idx_categoria', 'idx_ubicacion_id'] as $i) {
    chequear("productos tiene el índice `{$i}`", isset($idx[$i]));
}

// ═══════════════════════════════════════════════════════════════════════════
grupo('CATALOGOS · siembra y uso');
// ═══════════════════════════════════════════════════════════════════════════
chequear('Los estados por defecto quedaron sembrados',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['catalogos']}` WHERE tipo='estado'") === 4);
chequear('La criticidad arranca con la escala de GLPI',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['catalogos']}` WHERE tipo='criticidad'") === 5);
chequear('No quedan ubicaciones en `catalogos` (viven en su propia tabla)',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['catalogos']}` WHERE tipo='ubicacion'") === 0);

chequear('catalogo_agregar() da de alta un valor', catalogo_agregar($conn, 'marca', 'Lenovo'));
chequear('catalogo_valores() lo devuelve', in_array('Lenovo', catalogo_valores($conn, 'marca'), true));
chequear('catalogo_normalizar() acepta un valor de la lista',
    catalogo_normalizar($conn, 'marca', 'Lenovo') === 'Lenovo');
chequear('catalogo_normalizar() descarta un valor inventado',
    catalogo_normalizar($conn, 'marca', 'MarcaQueNoExiste') === '');

// ═══════════════════════════════════════════════════════════════════════════
grupo('UBICACIONES · árbol');
// ═══════════════════════════════════════════════════════════════════════════
$sede = ubicacion_agregar($conn, 'Sede Centro', null, ['localidad' => 'CABA', 'direccion' => 'Av. Siempreviva 742']);
chequear('Alta de una ubicación raíz', $sede > 0);
$piso = ubicacion_agregar($conn, 'Piso 2', $sede);
$dep  = ubicacion_agregar($conn, 'Depósito', $piso);
chequear('Alta de ubicaciones anidadas', $piso > 0 && $dep > 0);

$todas = ubicaciones_todas($conn, true);
chequear('La ruta completa se arma sola',
    ($todas[$dep]['ruta'] ?? '') === 'Sede Centro > Piso 2 > Depósito',
    'ruta: ' . ($todas[$dep]['ruta'] ?? '(nada)'));
chequear('Los datos de dirección se guardan',
    ($todas[$sede]['localidad'] ?? '') === 'CABA');
chequear('No se repite un nombre entre hermanos',
    ubicacion_agregar($conn, 'Piso 2', $sede) === 0);

// Un activo en esa ubicación, para verificar el arrastre del renombrado.
$st = $conn->prepare("INSERT INTO `{$T['productos']}` (modelo, marca, ubicacion, ubicacion_id, estado, archivado)
                      VALUES ('ThinkPad', 'Lenovo', ?, ?, 'Nuevo', 0)");
$ruta = 'Sede Centro > Piso 2 > Depósito';
$st->bind_param('si', $ruta, $dep);
$st->execute();
$prodId = (int)$conn->insert_id;
$st->close();
chequear('Se puede dar de alta un activo en una ubicación', $prodId > 0);

chequear('ubicacion_uso() cuenta los activos del nodo', ubicacion_uso($conn, $dep) === 1);
chequear('No se borra una ubicación con activos', ubicacion_eliminar($conn, $dep) === false);
chequear('No se borra una ubicación con hijos', ubicacion_eliminar($conn, $sede) === false);

// Renombrar la raíz tiene que recalcular todo el subárbol y los activos.
chequear('Renombrar la raíz', ubicacion_renombrar($conn, $sede, 'Sede Microcentro'));
$todas = ubicaciones_todas($conn, true);
chequear('La ruta del nieto se recalculó',
    ($todas[$dep]['ruta'] ?? '') === 'Sede Microcentro > Piso 2 > Depósito',
    'ruta: ' . ($todas[$dep]['ruta'] ?? '(nada)'));
chequear('El activo quedó apuntando a la ruta nueva',
    una($conn, "SELECT ubicacion FROM `{$T['productos']}` WHERE id = {$prodId}") === 'Sede Microcentro > Piso 2 > Depósito',
    'quedó: ' . (string)una($conn, "SELECT ubicacion FROM `{$T['productos']}` WHERE id = {$prodId}"));

// Mover un nodo: mismo recálculo, y no se puede colgar de un descendiente.
chequear('Un nodo no puede colgar de un descendiente suyo', ubicacion_mover($conn, $sede, $dep) === false);
chequear('Un nodo no puede colgar de sí mismo', ubicacion_mover($conn, $piso, $piso) === false);
chequear('Mover un nodo a la raíz', ubicacion_mover($conn, $dep, null));
$todas = ubicaciones_todas($conn, true);
chequear('La ruta se simplificó al mover', ($todas[$dep]['ruta'] ?? '') === 'Depósito');
chequear('El activo siguió al nodo movido',
    una($conn, "SELECT ubicacion FROM `{$T['productos']}` WHERE id = {$prodId}") === 'Depósito');

// `catalogo_valores` tiene que seguir devolviendo rutas planas: es lo que
// consumen los <select> de alta y edición sin saber que hay un árbol detrás.
$rutas = catalogo_valores($conn, 'ubicacion');
chequear('catalogo_valores(ubicacion) devuelve rutas planas',
    in_array('Depósito', $rutas, true) && in_array('Sede Microcentro', $rutas, true),
    implode(' | ', $rutas));

chequear('Una ubicación dada de baja sale del desplegable',
    ubicacion_activar($conn, $dep, false)
 && !in_array('Depósito', catalogo_valores($conn, 'ubicacion'), true));
ubicacion_activar($conn, $dep, true);

// ═══════════════════════════════════════════════════════════════════════════
grupo('INFOCOM · datos financieros');
// ═══════════════════════════════════════════════════════════════════════════
chequear('Cada activo tiene su fila de Infocom',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['activo_infocom']}` WHERE producto_id = {$prodId}") === 1
 || (infocom_asegurar_fila($conn, $prodId) === null
     && (int)una($conn, "SELECT COUNT(*) FROM `{$T['activo_infocom']}` WHERE producto_id = {$prodId}") === 1));

catalogo_agregar($conn, 'proveedor', 'Distribuidora Norte');
$ok = infocom_guardar($conn, $prodId, [
    'numero_factura'           => 'A-0001-00012345',
    'proveedor'                => 'Distribuidora Norte',
    'fecha_puesta_servicio'    => '2026-03-15',
    'fecha_decomiso'           => '',
    'amortizacion_anios'       => '5',
    'amortizacion_tipo'        => 'lineal',
    'amortizacion_coeficiente' => '1.5',
    'criticidad'               => 'Alta',
    'comentarios_infocom'      => 'Compra conjunta con el lote de notebooks',
]);
chequear('infocom_guardar() guarda', $ok);

$ic = infocom_de($conn, $prodId);
chequear('Se guardó el número de factura', ($ic['numero_factura'] ?? '') === 'A-0001-00012345');
chequear('Se guardó el proveedor del catálogo', ($ic['proveedor'] ?? '') === 'Distribuidora Norte');
chequear('Se guardó la fecha de puesta en servicio', ($ic['fecha_puesta_servicio'] ?? '') === '2026-03-15');
// Ojo con `$x ?? 'y'`: el ?? ya reemplaza el null, así que comparar el
// resultado contra null nunca da true. Hay que mirar la clave y el valor aparte.
chequear('Una fecha vacía queda en NULL, no en 0000-00-00',
    array_key_exists('fecha_decomiso', $ic) && $ic['fecha_decomiso'] === null,
    'quedó: ' . var_export($ic['fecha_decomiso'] ?? '(clave ausente)', true));
chequear('Se guardó la amortización por activo', (int)($ic['amortizacion_anios'] ?? 0) === 5);
chequear('Se guardó el tipo de amortización', ($ic['amortizacion_tipo'] ?? '') === 'lineal');

// El catálogo manda: un proveedor inventado se descarta en el servidor.
infocom_guardar($conn, $prodId, ['proveedor' => 'Proveedor Trucho', 'numero_factura' => 'A-0001-00012345']);
$ic = infocom_de($conn, $prodId);
chequear('Un proveedor fuera del catálogo se descarta',
    array_key_exists('proveedor', $ic) && $ic['proveedor'] === null,
    'quedó: ' . var_export($ic['proveedor'] ?? '(clave ausente)', true));

// Un tipo de amortización inventado tampoco entra.
infocom_guardar($conn, $prodId, ['amortizacion_tipo' => 'exponencial']);
$ic = infocom_de($conn, $prodId);
chequear('Un tipo de amortización inválido se descarta',
    array_key_exists('amortizacion_tipo', $ic) && $ic['amortizacion_tipo'] === null,
    'quedó: ' . var_export($ic['amortizacion_tipo'] ?? '(clave ausente)', true));

chequear('infocom_amortizacion_anios() usa el valor del activo',
    infocom_amortizacion_anios(['amortizacion_anios' => 7], 4) === 7);
chequear('infocom_amortizacion_anios() cae en el del reporte si el activo no tiene',
    infocom_amortizacion_anios([], 4) === 4);

// ═══════════════════════════════════════════════════════════════════════════
grupo('MIGRACIONES · siembra desde datos existentes');
// ═══════════════════════════════════════════════════════════════════════════
// La migración tiene que sembrar los catálogos con lo que ya estaba cargado:
// si alguien tenía 300 activos marca "HP", esa marca no puede desaparecer.
chequear('La marca del activo existente quedó en el catálogo',
    in_array('Lenovo', catalogo_valores($conn, 'marca', true), true));

// ═══════════════════════════════════════════════════════════════════════════
grupo('CAMPOS · específicos por categoría');
// ═══════════════════════════════════════════════════════════════════════════
// `productos` tenía catorce columnas para campos que sólo aplican a una
// subcategoría. Agregar una categoría era un ALTER TABLE más tocar trece
// archivos. Ahora las definiciones son datos.
chequear('Existe la tabla de definiciones', existe_tabla($conn, 'campos_definicion'));
chequear('Existe la tabla de valores', existe_tabla($conn, 'campos_valor'));
chequear('Se sembraron las 14 definiciones heredadas',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['campos_definicion']}` WHERE columna_legacy IS NOT NULL") === 14,
    'hay: ' . (string)una($conn, "SELECT COUNT(*) FROM `{$T['campos_definicion']}`"));

// El ámbito se resuelve por subcategoría, como estaba armado el formulario.
$dPatch = campos_para($conn, 'Redes', 'Patchcord');
chequear('Patchcord tiene sus dos campos', count($dPatch) === 2,
    'claves: ' . implode(',', array_column($dPatch, 'clave')));
chequear('Bobina UTP tiene sus tres campos', count(campos_para($conn, 'Redes', 'Bobina UTP')) === 3);
chequear('Una subcategoría sin campos propios no trae ninguno',
    campos_para($conn, 'Informática', 'Mouse') === []);
chequear('Un campo por categoría aplica a todas sus subcategorías',
    count(campos_para($conn, 'Software y licencias', 'Antivirus')) === 2);

// Las opciones se parsean a lista.
$tipoPatch = null;
foreach ($dPatch as $d) { if ($d['clave'] === 'patchcord_tipo') { $tipoPatch = $d; } }
chequear('Las opciones se leen como lista',
    ($tipoPatch['opciones'] ?? []) === ['UTP', 'STP', 'FTP'],
    implode('|', $tipoPatch['opciones'] ?? []));

// Validación en el servidor: el <select> no es el control.
chequear('Un valor de la lista se acepta',
    campos_normalizar_valor($tipoPatch, 'STP') === 'STP');
chequear('Un valor fuera de la lista se descarta',
    campos_normalizar_valor($tipoPatch, 'Inventado') === '');
chequear('Un número inválido se descarta',
    campos_normalizar_valor(['tipo' => 'numero', 'opciones' => []], 'abc') === '');
chequear('Una fecha mal formada se descarta',
    campos_normalizar_valor(['tipo' => 'fecha', 'opciones' => []], '15/03/2026') === '');
chequear('Una fecha válida se acepta',
    campos_normalizar_valor(['tipo' => 'fecha', 'opciones' => []], '2026-03-15') === '2026-03-15');

// Los campos heredados se leen de su columna de `productos`, no de campos_valor.
$conn->query("UPDATE `{$T['productos']}` SET categoria='Redes', subcategoria='Patchcord',
              patchcord_tipo='UTP', patchcord_longitud='3' WHERE id = {$prodId}");
$fila = $conn->query("SELECT * FROM `{$T['productos']}` WHERE id = {$prodId}")->fetch_assoc();
$vals = campos_valores_de($conn, $prodId, $fila);
chequear('Un campo heredado se lee de su columna de productos',
    ($vals['patchcord_tipo'] ?? '') === 'UTP', 'leyó: ' . ($vals['patchcord_tipo'] ?? '(nada)'));
chequear('Los datos heredados no se movieron de lugar',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['campos_valor']}` WHERE producto_id = {$prodId}") === 0);

// Un campo NUEVO: sin DDL, y su valor va a campos_valor.
$nuevoId = campo_definicion_agregar($conn, [
    'subcategoria' => 'Patchcord',
    'clave'        => 'color_capuchon',
    'etiqueta'     => 'Color del capuchón',
    'tipo'         => 'lista',
    'opciones'     => 'Azul|Rojo|Verde|Amarillo',
    'orden'        => 3,
]);
chequear('Se agrega un campo nuevo sin tocar el esquema', $nuevoId > 0);
chequear('El campo nuevo aparece en su subcategoría',
    count(campos_para($conn, 'Redes', 'Patchcord')) === 3);

chequear('Se guarda el valor del campo nuevo',
    campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => 'Rojo']));
$fila = $conn->query("SELECT * FROM `{$T['productos']}` WHERE id = {$prodId}")->fetch_assoc();
$vals = campos_valores_de($conn, $prodId, $fila);
chequear('El valor nuevo se lee junto con los heredados',
    ($vals['color_capuchon'] ?? '') === 'Rojo' && ($vals['patchcord_tipo'] ?? '') === 'UTP');

chequear('Un valor fuera de la lista no se guarda',
    campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => 'Fucsia'])
 && (int)una($conn, "SELECT COUNT(*) FROM `{$T['campos_valor']}` WHERE producto_id={$prodId} AND clave='color_capuchon'") === 0);

// Reglas de borrado.
campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => 'Azul']);
chequear('No se borra una definición en uso', campo_definicion_eliminar($conn, $nuevoId) === false);
$legacyDef = null;
foreach (campos_definiciones($conn) as $d) { if ($d['clave'] === 'patchcord_tipo') { $legacyDef = $d; } }
chequear('No se borra una definición heredada',
    campo_definicion_eliminar($conn, (int)$legacyDef['id']) === false);

campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => '']);
chequear('Vaciar el valor lo borra de la tabla',
    (int)una($conn, "SELECT COUNT(*) FROM `{$T['campos_valor']}` WHERE producto_id={$prodId} AND clave='color_capuchon'") === 0);
chequear('Ahora sí se puede borrar la definición', campo_definicion_eliminar($conn, $nuevoId));

// Una clave que pisaría una columna real de productos no se admite.
chequear('No se puede crear un campo que pise una columna de productos',
    campo_definicion_agregar($conn, ['clave' => 'marca', 'etiqueta' => 'Marca', 'tipo' => 'texto']) === 0);

// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASE 4 · sistema operativo');
// ═══════════════════════════════════════════════════════════════════════════
chequear('La tabla activo_so existe', existe_tabla($conn, $T['productos'] === 'productos' ? 'activo_so' : $PREFIJO . 'activo_so'));
chequear('so_disponible() lo detecta', so_disponible($conn));

// Un activo nuevo para las fases 4 y 5, aparte del que usan los bloques de arriba.
$conn->query("INSERT INTO `{$PREFIJO}productos` (modelo, marca, categoria, estado, archivado)
              VALUES ('Dell R740', 'Dell', 'Servidores', 'Nuevo', 0)");
$srvId = (int)$conn->insert_id;
chequear('Se dio de alta un servidor de prueba', $srvId > 0);

so_asegurar_fila($conn, $srvId);
chequear('El activo tiene su fila de sistema operativo', so_de($conn, $srvId) !== []);

$okSo = so_guardar($conn, $srvId, [
    'so' => 'Ubuntu Server', 'version' => '22.04 LTS', 'arquitectura' => 'x86_64',
    'kernel' => '5.15.0-91-generic', 'numero_licencia' => 'N/A',
    'instalado_en' => '2026-03-15',
]);
chequear('so_guardar() guarda', $okSo);
$so = so_de($conn, $srvId);
chequear('Se guardó el sistema y la versión',
    ($so['so'] ?? '') === 'Ubuntu Server' && ($so['version'] ?? '') === '22.04 LTS');
chequear('Se guardó la fecha de instalación', ($so['instalado_en'] ?? '') === '2026-03-15');
chequear('so_resumen() arma una línea legible',
    so_resumen($so) === 'Ubuntu Server 22.04 LTS (x86_64)', 'dio: ' . so_resumen($so));

// Una fecha vacía tiene que quedar NULL, no '0000-00-00': en modo estricto la
// segunda hace fallar el UPDATE entero.
so_guardar($conn, $srvId, ['instalado_en' => '']);
chequear('Una fecha vacía se guarda como NULL', so_de($conn, $srvId)['instalado_en'] === null);

so_guardar($conn, $srvId, ['instalado_en' => 'no es una fecha']);
chequear('Una fecha ilegible no rompe el guardado', so_de($conn, $srvId)['instalado_en'] === null);

chequear('so_resumen() no deja paréntesis sueltos si falta la arquitectura',
    so_resumen(['so' => 'Windows 11', 'arquitectura' => '']) === 'Windows 11');

$conteo = so_conteo_por_sistema($conn);
chequear('so_conteo_por_sistema() cuenta el servidor',
    count($conteo) === 1 && (int)$conteo[0]['cuantos'] === 1, 'dio: ' . json_encode($conteo));

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASE 4 · componentes');
// ═══════════════════════════════════════════════════════════════════════════
chequear('componentes_disponible() lo detecta', componentes_disponible($conn));

$cpuId = componente_alta($conn, 'procesador', 'Intel Xeon Silver 4210', 'Intel');
$ramId = componente_alta($conn, 'memoria', 'Samsung DDR4 32GB ECC', 'Samsung');
$hddId = componente_alta($conn, 'disco', 'Seagate Exos 4TB', 'Seagate');
chequear('Alta de tres componentes en el catálogo', $cpuId > 0 && $ramId > 0 && $hddId > 0);

chequear('Un componente repetido devuelve el que ya existe',
    componente_alta($conn, 'procesador', 'Intel Xeon Silver 4210', 'Intel') === $cpuId);

chequear('Un tipo inventado se rechaza',
    componente_alta($conn, 'teletransportador', 'Algo') === 0);

chequear('componentes_catalogo() filtra por tipo',
    count(componentes_catalogo($conn, 'procesador')) === 1
    && count(componentes_catalogo($conn)) === 3);

$a1 = componente_asignar($conn, $srvId, $cpuId, ['cantidad' => 2, 'nucleos' => 10, 'hilos' => 20, 'frecuencia_mhz' => 2200]);
$a2 = componente_asignar($conn, $srvId, $ramId, ['cantidad' => 4, 'capacidad_mb' => 32768]);
$a3 = componente_asignar($conn, $srvId, $hddId, ['cantidad' => 6, 'capacidad_mb' => 4194304, 'numero_serie' => 'ZA1XY']);
chequear('Se asignaron los tres al servidor', $a1 > 0 && $a2 > 0 && $a3 > 0);

$puestos = componentes_de_activo($conn, $srvId);
chequear('componentes_de_activo() los devuelve', count($puestos) === 3);
chequear('Vienen ordenados por tipo (procesador primero)',
    ($puestos[0]['tipo'] ?? '') === 'procesador', 'primero: ' . ($puestos[0]['tipo'] ?? ''));
chequear('Trae los datos del catálogo junto con los de la instancia',
    ($puestos[0]['designacion'] ?? '') === 'Intel Xeon Silver 4210'
    && (int)($puestos[0]['nucleos'] ?? 0) === 10);

chequear('componente_capacidad_legible() convierte a GB', componente_capacidad_legible(32768) === '32 GB');
chequear('componente_capacidad_legible() convierte a TB', componente_capacidad_legible(4194304) === '4 TB');
chequear('componente_capacidad_legible() deja los MB chicos', componente_capacidad_legible(512) === '512 MB');
chequear('componente_capacidad_legible() con null no rompe', componente_capacidad_legible(null) === '');

chequear('componente_en_uso() cuenta los activos', componente_en_uso($conn, $cpuId) === 1);

$baja = componente_baja($conn, $cpuId);
chequear('Un componente en uso no se borra: se desactiva',
    ($baja['ok'] ?? false) && ($baja['desactivado'] ?? false) === true);
chequear('Desactivado sale del catálogo pero el activo lo conserva',
    count(componentes_catalogo($conn, 'procesador')) === 0
    && count(componentes_de_activo($conn, $srvId)) === 3);

chequear('Volver a darlo de alta lo reactiva',
    componente_alta($conn, 'procesador', 'Intel Xeon Silver 4210') === $cpuId
    && count(componentes_catalogo($conn, 'procesador')) === 1);

$libre = componente_alta($conn, 'otro', 'Cosa sin usar');
$bajaLibre = componente_baja($conn, $libre);
chequear('Un componente sin usar sí se borra',
    ($bajaLibre['ok'] ?? false) && ($bajaLibre['desactivado'] ?? true) === false);

chequear('componente_quitar() lo saca del activo sin tocar el catálogo',
    componente_quitar($conn, $a3)
    && count(componentes_de_activo($conn, $srvId)) === 2
    && count(componentes_catalogo($conn, 'disco')) === 1);

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASE 4 · red');
// ═══════════════════════════════════════════════════════════════════════════
chequear('red_disponible() lo detecta', red_disponible($conn));

chequear('La MAC se normaliza desde cualquier formato',
    red_normalizar_mac('a0-b1-c2-d3-e4-f5') === 'A0:B1:C2:D3:E4:F5'
    && red_normalizar_mac('a0b1.c2d3.e4f5') === 'A0:B1:C2:D3:E4:F5'
    && red_normalizar_mac('a0b1c2d3e4f5') === 'A0:B1:C2:D3:E4:F5');
chequear('Una MAC corta se descarta', red_normalizar_mac('a0:b1:c2') === '');

chequear('red_version_ip() distingue IPv4 de IPv6',
    red_version_ip('10.0.0.47') === 4 && red_version_ip('2001:db8::1') === 6
    && red_version_ip('999.1.1.1') === 0);

$p1 = red_puerto_guardar($conn, $srvId, 0, [
    'numero' => 1, 'nombre' => 'eno1', 'tipo' => 'ethernet',
    'mac' => 'a0-b1-c2-d3-e4-f5', 'velocidad_mbps' => 10000, 'mtu' => 9000, 'vlan' => '120',
]);
chequear('Alta de un puerto de red', $p1 > 0);

$ip1 = red_ip_guardar($conn, $p1, ['ip' => '10.0.0.47', 'mascara' => '255.255.255.0',
                                   'gateway' => '10.0.0.1', 'asignacion' => 'fija',
                                   'nombre_dns' => 'srv-01.intellihelp.local']);
$ip2 = red_ip_guardar($conn, $p1, ['ip' => '2001:db8::47']);
chequear('Dos direcciones en el mismo puerto', $ip1 > 0 && $ip2 > 0);
chequear('Una IP inválida se rechaza', red_ip_guardar($conn, $p1, ['ip' => '300.1.2.3']) === 0);

$puertos = red_puertos_de($conn, $srvId);
chequear('red_puertos_de() devuelve el puerto con sus IPs',
    count($puertos) === 1 && count($puertos[0]['ips'] ?? []) === 2);
chequear('La MAC quedó normalizada en la base', ($puertos[0]['mac'] ?? '') === 'A0:B1:C2:D3:E4:F5');
chequear('La versión de IP se dedujo sola',
    (int)($puertos[0]['ips'][0]['version_ip'] ?? 0) === 4
    && (int)($puertos[0]['ips'][1]['version_ip'] ?? 0) === 6);

chequear('red_buscar() encuentra el activo por IP',
    count(red_buscar($conn, '10.0.0.47')) === 1);
chequear('red_buscar() encuentra el activo por MAC en cualquier formato',
    count(red_buscar($conn, 'a0b1c2d3e4f5')) === 1);
chequear('red_buscar() no inventa resultados', red_buscar($conn, '192.168.99.99') === []);

chequear('Editar el puerto conserva el id',
    red_puerto_guardar($conn, $srvId, $p1, ['nombre' => 'eno1-renombrado', 'tipo' => 'fibra']) === $p1);
$puertos = red_puertos_de($conn, $srvId);
chequear('El cambio se guardó',
    ($puertos[0]['nombre'] ?? '') === 'eno1-renombrado' && ($puertos[0]['tipo'] ?? '') === 'fibra');

chequear('Borrar el puerto se lleva sus direcciones',
    red_puerto_borrar($conn, $srvId, $p1)
    && red_puertos_de($conn, $srvId) === []
    && (int)una($conn, "SELECT COUNT(*) FROM `{$PREFIJO}direcciones_ip` WHERE puerto_id = {$p1}") === 0);

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASE 4 · relaciones entre activos');
// ═══════════════════════════════════════════════════════════════════════════
chequear('relaciones_disponible() lo detecta', relaciones_disponible($conn));

$conn->query("INSERT INTO `{$PREFIJO}productos` (modelo, marca, categoria, estado, archivado)
              VALUES ('UPS 3000VA', 'APC', 'Energía', 'Nuevo', 0)");
$upsId = (int)$conn->insert_id;

$rel = relacion_crear($conn, $srvId, $upsId, 'depende', 'Alimentación principal');
chequear('Se crea una relación', $rel['ok'] ?? false, $rel['error'] ?? '');

chequear('Un activo no se vincula consigo mismo',
    (relacion_crear($conn, $srvId, $srvId, 'depende')['ok'] ?? true) === false);
chequear('No se repite la misma pareja',
    (relacion_crear($conn, $srvId, $upsId, 'depende')['ok'] ?? true) === false);
chequear('Tampoco al revés',
    (relacion_crear($conn, $upsId, $srvId, 'depende')['ok'] ?? true) === false);
chequear('Un tipo inventado se rechaza',
    (relacion_crear($conn, $srvId, $upsId, 'teletransporta')['ok'] ?? true) === false);
chequear('Un activo inexistente se rechaza',
    (relacion_crear($conn, $srvId, 999999, 'depende')['ok'] ?? true) === false);

$desdeSrv = relaciones_de($conn, $srvId);
$desdeUps = relaciones_de($conn, $upsId);
chequear('La relación se ve desde los dos activos',
    count($desdeSrv) === 1 && count($desdeUps) === 1);
chequear('Desde el origen se lee en sentido directo',
    ($desdeSrv[0]['sentido'] ?? '') === 'directa' && ($desdeSrv[0]['etiqueta'] ?? '') === 'Depende de');
chequear('Desde el destino se lee al revés',
    ($desdeUps[0]['sentido'] ?? '') === 'inversa' && ($desdeUps[0]['etiqueta'] ?? '') === 'Del que dependen');
chequear('Trae los datos del otro activo',
    ($desdeSrv[0]['modelo'] ?? '') === 'UPS 3000VA');

chequear('relaciones_cuantas() cuenta los dos lados',
    relaciones_cuantas($conn, $srvId) === 1 && relaciones_cuantas($conn, $upsId) === 1);

chequear('Se puede vincular la misma pareja con otro tipo',
    relacion_crear($conn, $srvId, $upsId, 'conectado')['ok'] ?? false);

chequear('Borrar un vínculo lo saca de los dos lados',
    relacion_borrar($conn, (int)$desdeSrv[0]['id'])
    && count(relaciones_de($conn, $srvId)) === 1
    && count(relaciones_de($conn, $upsId)) === 1);

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASE 5 · proveedores y contratos');
// ═══════════════════════════════════════════════════════════════════════════
chequear('contratos_disponible() lo detecta', contratos_disponible($conn));

$pv = proveedor_guardar($conn, 0, ['nombre' => 'Distribuidora Norte', 'cuit' => '30-12345678-9',
                                   'contacto' => 'Laura Gómez', 'email' => 'ventas@norte.com.ar']);
chequear('Alta de un proveedor', $pv['ok'] ?? false, $pv['error'] ?? '');
$pvId = (int)($pv['id'] ?? 0);

chequear('Un proveedor sin nombre se rechaza',
    (proveedor_guardar($conn, 0, ['nombre' => '  '])['ok'] ?? true) === false);
chequear('No se repite el nombre de un proveedor',
    (proveedor_guardar($conn, 0, ['nombre' => 'Distribuidora Norte'])['ok'] ?? true) === false);
chequear('proveedores_listar() lo devuelve', count(proveedores_listar($conn)) === 1);

chequear('contrato_fecha_fin() suma los meses y resta un día',
    contrato_fecha_fin('2026-01-01', 12) === '2026-12-31',
    'dio: ' . contrato_fecha_fin('2026-01-01', 12));
chequear('contrato_fecha_fin() respeta los meses cortos',
    contrato_fecha_fin('2026-01-31', 1) === '2026-02-28',
    'dio: ' . contrato_fecha_fin('2026-01-31', 1));
chequear('contrato_fecha_fin() sin duración devuelve vacío',
    contrato_fecha_fin('2026-01-01', 0) === '');

$ct = contrato_guardar($conn, 0, [
    'nombre' => 'Soporte 24x7 servidores', 'numero' => 'SOP-2026-014',
    'tipo' => 'soporte', 'proveedor_id' => $pvId,
    'inicio' => '2026-01-01', 'duracion_meses' => 12,
    'preaviso_dias' => 30, 'renovacion' => 'tacita',
    'costo' => '4500.50', 'moneda' => 'USD',
]);
chequear('Alta de un contrato', $ct['ok'] ?? false, $ct['error'] ?? '');
$ctId = (int)($ct['id'] ?? 0);

$fin = una($conn, "SELECT fin FROM `{$PREFIJO}contratos` WHERE id = {$ctId}");
chequear('La fecha de fin se calculó sola', $fin === '2026-12-31', 'quedó: ' . (string)$fin);
chequear('El costo se guardó con decimales',
    (float)una($conn, "SELECT costo FROM `{$PREFIJO}contratos` WHERE id = {$ctId}") === 4500.50);

chequear('Un contrato sin nombre se rechaza',
    (contrato_guardar($conn, 0, ['nombre' => ''])['ok'] ?? true) === false);
chequear('Un tipo inventado cae en «otro»',
    (contrato_guardar($conn, 0, ['nombre' => 'X', 'tipo' => 'inventado'])['ok'] ?? false)
    && una($conn, "SELECT tipo FROM `{$PREFIJO}contratos` WHERE nombre='X'") === 'otro');

chequear('contrato_vincular() cubre el activo', contrato_vincular($conn, $ctId, $srvId));
chequear('Vincular dos veces no duplica',
    contrato_vincular($conn, $ctId, $srvId)
    && (int)una($conn, "SELECT COUNT(*) FROM `{$PREFIJO}contrato_activo` WHERE contrato_id={$ctId}") === 1);

$ctsAct = contratos_de_activo($conn, $srvId);
chequear('contratos_de_activo() lo devuelve con el proveedor',
    count($ctsAct) === 1 && ($ctsAct[0]['proveedor'] ?? '') === 'Distribuidora Norte');

$lista = contratos_listar($conn);
chequear('contratos_listar() cuenta los activos cubiertos',
    count($lista) === 2 && (int)($lista[0]['activos'] ?? -1) >= 0);

// El contrato arranca el 2026-01-01 y dura 12 meses: con «hoy» en octubre de
// 2026 quedan menos de 90 días, así que tiene que aparecer.
$venc = contratos_por_vencer($conn, 365);
chequear('contratos_por_vencer() lo encuentra', count($venc) >= 1);

chequear('No se borra un proveedor con contratos: se desactiva',
    (proveedor_baja($conn, $pvId)['desactivado'] ?? false) === true
    && count(proveedores_listar($conn)) === 0
    && count(proveedores_listar($conn, true)) === 1);

chequear('contrato_desvincular() saca el activo',
    contrato_desvincular($conn, $ctId, $srvId) && contratos_de_activo($conn, $srvId) === []);
chequear('contrato_baja() lo desactiva sin borrarlo',
    contrato_baja($conn, $ctId)
    && count(contratos_listar($conn)) === 1
    && count(contratos_listar($conn, true)) === 2);

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASE 5 · documentos, notas y plantillas');
// ═══════════════════════════════════════════════════════════════════════════
chequear('documentos_disponible() lo detecta', documentos_disponible($conn));

$doc = documento_guardar($conn, 0, ['nombre' => 'Factura A 0001-00004521', 'tipo' => 'factura',
                                    'enlace' => 'https://drive.google.com/file/d/abc123/view']);
chequear('Alta de un documento', $doc['ok'] ?? false, $doc['error'] ?? '');
$docId = (int)($doc['id'] ?? 0);

chequear('Un documento sin enlace ni archivo se rechaza',
    (documento_guardar($conn, 0, ['nombre' => 'Suelto'])['ok'] ?? true) === false);
chequear('Un enlace que no es una URL se rechaza',
    (documento_guardar($conn, 0, ['nombre' => 'Malo', 'enlace' => 'no es una url'])['ok'] ?? true) === false);
chequear('Un documento con archivo de Drive y sin enlace se acepta',
    documento_guardar($conn, 0, ['nombre' => 'Manual', 'tipo' => 'manual', 'drive_id' => '1AbC'])['ok'] ?? false);

chequear('documento_vincular() lo cuelga del activo', documento_vincular($conn, $docId, $srvId));
chequear('Vincular dos veces no duplica',
    documento_vincular($conn, $docId, $srvId) && count(documentos_de_activo($conn, $srvId)) === 1);
chequear('El mismo documento cubre varios activos',
    documento_vincular($conn, $docId, $upsId) && count(documentos_de_activo($conn, $upsId)) === 1);

chequear('documento_borrar() se lleva los vínculos',
    documento_borrar($conn, $docId)
    && documentos_de_activo($conn, $srvId) === []
    && (int)una($conn, "SELECT COUNT(*) FROM `{$PREFIJO}documento_activo` WHERE documento_id={$docId}") === 0);

$n1 = nota_agregar($conn, $srvId, 'La fuente hace ruido desde marzo.', 'bsierra');
chequear('Alta de una nota', $n1 > 0);
chequear('Una nota vacía no se guarda', nota_agregar($conn, $srvId, '   ') === 0);
$notas = notas_de_activo($conn, $srvId);
chequear('notas_de_activo() la devuelve con el autor',
    count($notas) === 1 && ($notas[0]['autor'] ?? '') === 'bsierra');
chequear('nota_borrar() la saca', nota_borrar($conn, $n1) && notas_de_activo($conn, $srvId) === []);

$pl = plantilla_guardar($conn, 0, ['nombre' => 'Notebook de oficina', 'categoria' => 'Informática'],
                        ['marca' => 'Lenovo', 'estado' => 'Nuevo', 'vacio' => '  ', 'mal nombre' => 'x']);
chequear('Alta de una plantilla', $pl['ok'] ?? false, $pl['error'] ?? '');
$plId = (int)($pl['id'] ?? 0);

$vals = plantilla_valores($conn, $plId);
chequear('Los valores se guardaron', ($vals['marca'] ?? '') === 'Lenovo');
chequear('Los valores vacíos no se guardan', !array_key_exists('vacio', $vals));
chequear('Una clave con espacios se descarta', !array_key_exists('mal nombre', $vals));
chequear('No se repite el nombre de una plantilla',
    (plantilla_guardar($conn, 0, ['nombre' => 'Notebook de oficina'], [])['ok'] ?? true) === false);
chequear('plantillas_listar() la devuelve', count(plantillas_listar($conn)) === 1);
chequear('plantilla_baja() la desactiva',
    plantilla_baja($conn, $plId) && plantillas_listar($conn) === []
    && count(plantillas_listar($conn, true)) === 1);
chequear('Una plantilla dada de baja ya no entrega valores', plantilla_valores($conn, $plId) === []);

// ═══════════════════════════════════════════════════════════════════════════
grupo('FASES 4 y 5 · idempotencia');
// ═══════════════════════════════════════════════════════════════════════════
$antesSo = (int)una($conn, "SELECT COUNT(*) FROM `{$PREFIJO}activo_so`");
$err2 = '';
try { migrar_tenant($conn, $PREFIJO, function (string $m) {}); }
catch (Throwable $e) { $err2 = $e->getMessage(); }
chequear('Repetir las migraciones 8 y 9 no da error', $err2 === '', $err2);
chequear('No se duplican las filas de sistema operativo',
    (int)una($conn, "SELECT COUNT(*) FROM `{$PREFIJO}activo_so`") === $antesSo);
chequear('Los componentes siguen ahí',
    count(componentes_de_activo($conn, $srvId)) === 2);

// ═══════════════════════════════════════════════════════════════════════════
grupo('MIGRACIONES · actualizar una instalación CON DATOS');
// ═══════════════════════════════════════════════════════════════════════════
// Lo de arriba prueba una instalación limpia. Lo que va a pasar en producción es
// otra cosa: una base que ya existe, con activos cargados, que salta de la v5 a
// la v7. Ese es el camino riesgoso —el que puede perder datos— y es el que se
// prueba acá.
//
// Se usa un prefijo de tablas, así que de paso se ejercita el modo `prefix`
// (varias empresas dentro de la master), que el resto de la batería no toca.
// Las aserciones van por SQL directo y no por los helpers, para no depender de
// las cachés por request.
// Cuelga del prefijo principal: con --prefijo, la limpieza del arranque borra
// sólo las tablas que empiezan con él, y un 'up_' fijo sobrevivía de una corrida
// a la otra. La segunda vez el bloque encontraba los datos de la primera y
// contaba dieciséis activos donde esperaba cuatro.
$P2 = $PREFIJO . 'up_';

foreach (tenant_schema_sql($P2) as $q) { $conn->query($q); }

// Estado de partida: una instalación vieja, en la v5.
$migs = migraciones_tenant();
$errUp = '';
try {
    foreach ([1, 2, 3, 4, 5] as $v) { $migs[$v]($conn, $P2); }
    // Dejar registrada la versión, como la habría dejado `bin/migrar.php`. Sin
    // esto el motor la ve en 0 y vuelve a aplicar todo desde la 1 — que, dicho
    // sea de paso, resultó inofensivo, pero no es el caso que se quiere probar.
    $stV = $conn->prepare("INSERT INTO `{$P2}tenant_settings` (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $kV = SCHEMA_VERSION_KEY; $vV = '5';
    $stV->bind_param('ss', $kV, $vV);
    $stV->execute();
    $stV->close();
} catch (Throwable $e) { $errUp = $e->getMessage(); }
chequear('Se puede reconstruir una instalación vieja (v1..v5)', $errUp === '', $errUp);
chequear('La instalación vieja queda registrada en la v5',
    schema_version_actual($conn, $P2) === 5);

// Datos como los que ya hay cargados: ubicaciones y marcas escritas a mano, y
// los campos específicos en sus columnas.
$conn->query("INSERT INTO `{$P2}productos`
    (modelo, marca, categoria, subcategoria, estado, ubicacion, asignado_a,
     valor_usd, cantidad, archivado, patchcord_tipo, patchcord_longitud)
  VALUES
    ('ThinkPad E15', 'Lenovo', 'Informática', 'Notebook', 'Usado', 'Depósito Central', 'Soporte', 900.00, 1, 0, NULL, NULL),
    ('Cable 3m',     'Furukawa', 'Redes',     'Patchcord', 'Nuevo', 'Depósito Central', 'Soporte',   4.50, 50, 0, 'UTP', '3'),
    ('Switch 24p',   'TP-Link',  'Redes',     'Switch',    'Nuevo', 'Sala de servidores', 'Redes', 180.00, 2, 0, NULL, NULL),
    ('Monitor 24',   'Samsung',  'Informática','Monitor',  'Usado', 'Oficina 204', '', 120.00, 3, 1, NULL, NULL)");
$antesProd = (int)una($conn, "SELECT COUNT(*) FROM `{$P2}productos`");
// Una ubicación que ya estaba en el catálogo plano y otra que sólo está en los activos.
$conn->query("INSERT IGNORE INTO `{$P2}catalogos` (tipo, valor) VALUES ('ubicacion','Depósito Central'),('asignado_a','Soporte')");
chequear('Instalación vieja con datos cargados', $antesProd === 4);

// El salto.
$aplUp = [];
$errUp2 = '';
try { $aplUp = migrar_tenant($conn, $P2, function (string $m) {}); }
catch (Throwable $e) { $errUp2 = $e->getMessage(); }
chequear('Las migraciones 6 y 7 aplican sobre la base con datos', $errUp2 === '', $errUp2);
// La instalación quedó marcada en la versión 5, así que lo pendiente es de la 6
// en adelante. Se calcula en vez de escribirse: con la lista fija había que
// editar este test cada vez que se suma una migración, y un test que hay que
// tocar para que siga pasando deja de servir como red.
$esperadas = range(6, schema_version_objetivo());
chequear('Se aplicaron las pendientes y ninguna de las ya aplicadas',
    $aplUp === $esperadas,
    'aplicadas: ' . implode(',', $aplUp) . ' — esperadas: ' . implode(',', $esperadas));

// Lo que más importa: no se perdió nada.
chequear('No se perdió ningún activo',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}productos`") === $antesProd);
chequear('Los campos específicos siguen en su columna',
    una($conn, "SELECT patchcord_tipo FROM `{$P2}productos` WHERE modelo='Cable 3m'") === 'UTP');
chequear('El valor y la cantidad siguen intactos',
    (float)una($conn, "SELECT valor_usd FROM `{$P2}productos` WHERE modelo='ThinkPad E15'") === 900.00);

// Catálogos sembrados con lo que ya estaba escrito a mano.
foreach ([['marca', 'Lenovo'], ['marca', 'Furukawa'], ['categoria', 'Redes'],
          ['subcategoria', 'Patchcord'], ['estado', 'Usado']] as [$t, $v]) {
    chequear("El catálogo «{$t}» recogió «{$v}» de los activos",
        (int)una($conn, "SELECT COUNT(*) FROM `{$P2}catalogos` WHERE tipo='{$t}' AND valor='{$v}'") === 1);
}

// Ubicaciones: las tres que había en los activos pasan al árbol, incluida la que
// nunca llegó al catálogo plano.
chequear('Las ubicaciones de los activos quedaron en el árbol',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}ubicaciones`") === 3,
    'hay: ' . (string)una($conn, "SELECT GROUP_CONCAT(nombre) FROM `{$P2}ubicaciones`"));
chequear('Una ubicación que sólo estaba en los activos también entró',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}ubicaciones` WHERE nombre='Sala de servidores'") === 1);
chequear('Ya no quedan ubicaciones en el catálogo plano',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}catalogos` WHERE tipo='ubicacion'") === 0);
chequear('Los activos quedaron enlazados a su ubicación',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}productos` WHERE ubicacion_id IS NOT NULL") === 4,
    'enlazados: ' . (string)una($conn, "SELECT COUNT(*) FROM `{$P2}productos` WHERE ubicacion_id IS NOT NULL"));
chequear('El texto de la ubicación no cambió',
    una($conn, "SELECT ubicacion FROM `{$P2}productos` WHERE modelo='Switch 24p'") === 'Sala de servidores');

// Infocom y campos: una fila por activo, definiciones sembradas.
chequear('Cada activo existente tiene su fila de Infocom',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}activo_infocom`") === $antesProd);
chequear('Las definiciones de campos se sembraron',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}campos_definicion` WHERE columna_legacy IS NOT NULL") === 14);
chequear('No se movió ningún valor a campos_valor',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}campos_valor`") === 0);

// Y que el archivado siga archivado: la papelera no se toca.
chequear('Los activos archivados siguen archivados',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}productos` WHERE archivado = 1") === 1);

// Idempotencia sobre la base con datos: correrlo dos veces no duplica nada.
migrar_tenant($conn, $P2, function (string $m) {});
chequear('Repetir la migración no duplica ubicaciones',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}ubicaciones`") === 3);
chequear('Repetir la migración no duplica filas de Infocom',
    (int)una($conn, "SELECT COUNT(*) FROM `{$P2}activo_infocom`") === $antesProd);

// ═══════════════════════════════════════════════════════════════════════════
grupo('MIGRACIONES · master');
// ═══════════════════════════════════════════════════════════════════════════
// La master no tenía migraciones: la creaba install.php y después cada página la
// parchaba con ALTER TABLE en cada request. Acá se verifica la vía nueva, y
// sobre todo el arreglo del ENUM: `users_platform.role` se declaraba en tres
// archivos con tres definiciones distintas, y en una instalación limpia los
// roles `admin` y `viewer` no se podían guardar.
$mname = getenv('AV_TEST_DB_MASTER') ?: '';
if ($mname === '') {
    echo "  \033[33m·\033[0m Sin AV_TEST_DB_MASTER: no se prueban las migraciones de master.\n";
} else {
    $mc = @new mysqli($host, $user, $pass, $mname, $port);
    if ($mc->connect_error) {
        chequear('Conexión a la base master de pruebas', false, $mc->connect_error);
    } else {
        $mc->set_charset('utf8mb4');
        foreach (['users_platform', 'platform_config', 'companies'] as $t) {
            $mc->query("DROP TABLE IF EXISTS `{$t}`");
        }
        // Estado de partida: la tabla tal cual la dejaba install.php ANTES del
        // arreglo, con el ENUM de dos valores. Es el caso que hay que reparar.
        $mc->query("CREATE TABLE `users_platform` (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            password VARCHAR(255) DEFAULT NULL,
            email    VARCHAR(255) DEFAULT NULL,
            role     ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
            UNIQUE KEY uk_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $errM = '';
        $aplM = [];
        try { $aplM = migrar_master($mc, function (string $m) {}); }
        catch (Throwable $e) { $errM = $e->getMessage(); }
        chequear('Las migraciones de master aplican sin error', $errM === '', $errM);
        chequear('Se aplicaron todas (' . master_schema_version_objetivo() . ')',
            $aplM === range(1, master_schema_version_objetivo()), 'aplicadas: ' . implode(',', $aplM));
        chequear('La versión de master queda registrada',
            master_schema_version_actual($mc) === master_schema_version_objetivo());
        chequear('Correr las migraciones de master de nuevo no hace nada',
            migrar_master($mc, function (string $m) {}) === []);

        $tipoRole = '';
        $rs2 = $mc->query("SHOW COLUMNS FROM `users_platform` LIKE 'role'");
        if ($rs2 && ($rr = $rs2->fetch_assoc())) { $tipoRole = strtolower((string)$rr['Type']); }
        chequear('`users_platform.role` dejó de ser ENUM', !str_starts_with($tipoRole, 'enum'),
            'quedó: ' . $tipoRole);

        // La prueba que importa: los cuatro roles de plataforma tienen que poder
        // guardarse. Con el ENUM de dos valores, `admin` y `viewer` se guardaban
        // como cadena vacía (o la fila se rechazaba).
        $rechazados = [];
        foreach (['superadmin', 'admin', 'support', 'viewer'] as $i => $rol) {
            $st2 = $mc->prepare('INSERT INTO `users_platform` (username, role) VALUES (?, ?)');
            $u = 'u' . $i;
            $st2->bind_param('ss', $u, $rol);
            $st2->execute();
            $st2->close();
            $g = $mc->query("SELECT role FROM `users_platform` WHERE username = '{$u}'")->fetch_array()[0] ?? '';
            if ($g !== $rol) { $rechazados[] = "{$rol} quedó como '" . $g . "'"; }
        }
        chequear('Los cuatro roles de plataforma se pueden guardar', $rechazados === [],
            implode('; ', $rechazados));

        foreach (['google_sub', 'ms_sub', 'last_login', 'active'] as $c) {
            chequear("users_platform tiene `{$c}`", in_array($c, columnas($mc, 'users_platform'), true));
        }
        chequear('platform_config existe', existe_tabla($mc, 'platform_config'));
        $mc->close();
    }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 68) . "\n";
if ($FAIL === 0) {
    echo "\033[1;32m  {$PASS} checks de integración OK\033[0m\n\n";
    exit(0);
}
echo "\033[1;31m  {$FAIL} fallaron\033[0m  ·  {$PASS} OK\n\n";
foreach ($FALLOS as $f) { echo "  \033[31m·\033[0m {$f}\n"; }
echo "\n";
exit(1);
