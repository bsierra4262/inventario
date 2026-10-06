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
// Salvaguarda
//
// Lo que viene borra TODAS las tablas de la base indicada. Este archivo se
// despliega al servidor junto con el resto del proyecto, así que un
// `AV_TEST_DB_NAME` mal puesto ahí dentro vacía el inventario de un cliente.
//
// Si la base tiene activos cargados, no es descartable: es producción o una
// copia. Se aborta. El `--si-estoy-seguro` existe para el caso legítimo de
// probar sobre una copia con datos, y hay que escribirlo a mano.
// ─────────────────────────────────────────────────────────────────────────────
$conActivos = 0;
try {
    $rsG = $conn->query('SELECT COUNT(*) FROM `productos`');
    if ($rsG) { $conActivos = (int)$rsG->fetch_array()[0]; }
} catch (Throwable) { /* la tabla no existe: base vacía, adelante */ }

if ($conActivos > 0 && !in_array('--si-estoy-seguro', $argv, true)) {
    echo "\n  \033[31m✗\033[0m ABORTADO: `{$name}` tiene {$conActivos} activos cargados.\n";
    echo "    Esta batería BORRA todas las tablas. No se apunta a una base con datos.\n";
    echo "    Si de verdad querés hacerlo sobre una copia, agregá --si-estoy-seguro\n\n";
    exit(1);
}

// Base limpia: esto es destructivo a propósito, por eso sólo se apunta a una
// base descartable.
$conn->query('SET FOREIGN_KEY_CHECKS = 0');
$rs = $conn->query('SHOW TABLES');
while ($r = $rs->fetch_array()) { $conn->query("DROP TABLE IF EXISTS `{$r[0]}`"); }
$conn->query('SET FOREIGN_KEY_CHECKS = 1');

// ─────────────────────────────────────────────────────────────────────────────
// Dependencias del proyecto. `tn()` se define acá para no arrastrar el
// bootstrap multiempresa entero, que necesita sesión y master.
// ─────────────────────────────────────────────────────────────────────────────
$PREFIJO = '';
if (!function_exists('tn')) {
    function tn(string $t): string { global $PREFIJO; return $PREFIJO . $t; }
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
$rs = $conn->query('SHOW INDEX FROM `productos`');
while ($r = $rs->fetch_assoc()) { $idx[$r['Key_name']] = true; }
foreach (['idx_archivado', 'idx_categoria', 'idx_ubicacion_id'] as $i) {
    chequear("productos tiene el índice `{$i}`", isset($idx[$i]));
}

// ═══════════════════════════════════════════════════════════════════════════
grupo('CATALOGOS · siembra y uso');
// ═══════════════════════════════════════════════════════════════════════════
chequear('Los estados por defecto quedaron sembrados',
    (int)una($conn, "SELECT COUNT(*) FROM `catalogos` WHERE tipo='estado'") === 4);
chequear('La criticidad arranca con la escala de GLPI',
    (int)una($conn, "SELECT COUNT(*) FROM `catalogos` WHERE tipo='criticidad'") === 5);
chequear('No quedan ubicaciones en `catalogos` (viven en su propia tabla)',
    (int)una($conn, "SELECT COUNT(*) FROM `catalogos` WHERE tipo='ubicacion'") === 0);

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
$st = $conn->prepare("INSERT INTO `productos` (modelo, marca, ubicacion, ubicacion_id, estado, archivado)
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
    una($conn, "SELECT ubicacion FROM `productos` WHERE id = {$prodId}") === 'Sede Microcentro > Piso 2 > Depósito',
    'quedó: ' . (string)una($conn, "SELECT ubicacion FROM `productos` WHERE id = {$prodId}"));

// Mover un nodo: mismo recálculo, y no se puede colgar de un descendiente.
chequear('Un nodo no puede colgar de un descendiente suyo', ubicacion_mover($conn, $sede, $dep) === false);
chequear('Un nodo no puede colgar de sí mismo', ubicacion_mover($conn, $piso, $piso) === false);
chequear('Mover un nodo a la raíz', ubicacion_mover($conn, $dep, null));
$todas = ubicaciones_todas($conn, true);
chequear('La ruta se simplificó al mover', ($todas[$dep]['ruta'] ?? '') === 'Depósito');
chequear('El activo siguió al nodo movido',
    una($conn, "SELECT ubicacion FROM `productos` WHERE id = {$prodId}") === 'Depósito');

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
    (int)una($conn, "SELECT COUNT(*) FROM `activo_infocom` WHERE producto_id = {$prodId}") === 1
 || (infocom_asegurar_fila($conn, $prodId) === null
     && (int)una($conn, "SELECT COUNT(*) FROM `activo_infocom` WHERE producto_id = {$prodId}") === 1));

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
    (int)una($conn, "SELECT COUNT(*) FROM `campos_definicion` WHERE columna_legacy IS NOT NULL") === 14,
    'hay: ' . (string)una($conn, "SELECT COUNT(*) FROM `campos_definicion`"));

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
$conn->query("UPDATE `productos` SET categoria='Redes', subcategoria='Patchcord',
              patchcord_tipo='UTP', patchcord_longitud='3' WHERE id = {$prodId}");
$fila = $conn->query("SELECT * FROM `productos` WHERE id = {$prodId}")->fetch_assoc();
$vals = campos_valores_de($conn, $prodId, $fila);
chequear('Un campo heredado se lee de su columna de productos',
    ($vals['patchcord_tipo'] ?? '') === 'UTP', 'leyó: ' . ($vals['patchcord_tipo'] ?? '(nada)'));
chequear('Los datos heredados no se movieron de lugar',
    (int)una($conn, "SELECT COUNT(*) FROM `campos_valor` WHERE producto_id = {$prodId}") === 0);

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
$fila = $conn->query("SELECT * FROM `productos` WHERE id = {$prodId}")->fetch_assoc();
$vals = campos_valores_de($conn, $prodId, $fila);
chequear('El valor nuevo se lee junto con los heredados',
    ($vals['color_capuchon'] ?? '') === 'Rojo' && ($vals['patchcord_tipo'] ?? '') === 'UTP');

chequear('Un valor fuera de la lista no se guarda',
    campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => 'Fucsia'])
 && (int)una($conn, "SELECT COUNT(*) FROM `campos_valor` WHERE producto_id={$prodId} AND clave='color_capuchon'") === 0);

// Reglas de borrado.
campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => 'Azul']);
chequear('No se borra una definición en uso', campo_definicion_eliminar($conn, $nuevoId) === false);
$legacyDef = null;
foreach (campos_definiciones($conn) as $d) { if ($d['clave'] === 'patchcord_tipo') { $legacyDef = $d; } }
chequear('No se borra una definición heredada',
    campo_definicion_eliminar($conn, (int)$legacyDef['id']) === false);

campos_guardar($conn, $prodId, 'Redes', 'Patchcord', ['color_capuchon' => '']);
chequear('Vaciar el valor lo borra de la tabla',
    (int)una($conn, "SELECT COUNT(*) FROM `campos_valor` WHERE producto_id={$prodId} AND clave='color_capuchon'") === 0);
chequear('Ahora sí se puede borrar la definición', campo_definicion_eliminar($conn, $nuevoId));

// Una clave que pisaría una columna real de productos no se admite.
chequear('No se puede crear un campo que pise una columna de productos',
    campo_definicion_agregar($conn, ['clave' => 'marca', 'etiqueta' => 'Marca', 'tipo' => 'texto']) === 0);

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
$P2 = 'up_';

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
chequear('Sólo se aplicaron las pendientes', $aplUp === [6, 7], 'aplicadas: ' . implode(',', $aplUp));

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
