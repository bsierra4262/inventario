<?php
/**
 * Proveedores y contratos.
 *
 * Hasta acá, «¿hasta cuándo tenemos soporte de este servidor?» no tenía dónde
 * contestarse: el proveedor era un texto suelto en Infocom y el contrato no
 * existía. Los campos salen de glpi_contracts —num, begin_date, duration,
 * notice, periodicity, billing, accounting_number, renewal—.
 *
 * `fin` se guarda calculada en vez de derivarla en cada consulta. Es
 * desnormalizar a propósito: la pantalla que importa es «qué se vence en los
 * próximos noventa días», y con la fecha guardada eso es un índice.
 */

declare(strict_types=1);

if (!defined('CONTRATO_TIPOS')) {
    define('CONTRATO_TIPOS', [
        'soporte'     => 'Soporte técnico',
        'garantia'    => 'Garantía extendida',
        'mantenimiento' => 'Mantenimiento',
        'alquiler'    => 'Alquiler / leasing',
        'licencia'    => 'Licencia de software',
        'conectividad'=> 'Conectividad',
        'seguro'      => 'Seguro',
        'otro'        => 'Otro',
    ]);
}

if (!defined('CONTRATO_RENOVACIONES')) {
    define('CONTRATO_RENOVACIONES', [
        'ninguna'    => 'No se renueva',
        'tacita'     => 'Tácita (se renueva solo)',
        'expresa'    => 'Expresa (hay que avisar)',
    ]);
}

if (!function_exists('contratos_disponible')) {
/** ¿Está aplicada la migración 9? */
function contratos_disponible(mysqli $conn): bool {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $t = $conn->real_escape_string(tn('contratos'));
        $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
        $cache = (bool)($r && $r->num_rows > 0);
    } catch (Throwable) { $cache = false; }
    return $cache;
}
}

// ═══════════════════════════════════════════════════════════════════════════
//  Proveedores
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('proveedores_listar')) {
function proveedores_listar(mysqli $conn, bool $incluirBajas = false): array {
    if (!contratos_disponible($conn)) { return []; }
    try {
        $donde = $incluirBajas ? '' : ' WHERE activo = 1';
        $r = $conn->query('SELECT * FROM `' . tn('proveedores') . '`' . $donde . ' ORDER BY nombre');
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('proveedor_guardar')) {
/**
 * Alta o edición de un proveedor. Devuelve ['ok','id','error'].
 *
 * @param int $id 0 para alta.
 */
function proveedor_guardar(mysqli $conn, int $id, array $d): array {
    if (!contratos_disponible($conn)) {
        return ['ok' => false, 'error' => 'La base todavía no tiene el esquema de proveedores.'];
    }
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($nombre === '') { return ['ok' => false, 'error' => 'El nombre es obligatorio.']; }

    $campos = ['cuit', 'contacto', 'telefono', 'email', 'web', 'direccion', 'nota'];
    $vals = [$nombre];
    $tipos = 's';
    foreach ($campos as $c) {
        $vals[] = trim((string)($d[$c] ?? '')) ?: null;
        $tipos .= 's';
    }

    try {
        $t = tn('proveedores');
        if ($id > 0) {
            $sets = 'nombre=?, ' . implode('=?, ', $campos) . '=?';
            $st = $conn->prepare("UPDATE `{$t}` SET {$sets} WHERE id=?");
            if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
            $vals[] = $id; $tipos .= 'i';
            $st->bind_param($tipos, ...$vals);
            $st->execute();
            $st->close();
            return ['ok' => true, 'id' => $id];
        }
        $cols = 'nombre, ' . implode(', ', $campos);
        $marcas = implode(',', array_fill(0, count($campos) + 1, '?'));
        $st = $conn->prepare("INSERT INTO `{$t}` ({$cols}) VALUES ({$marcas})");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
        $st->bind_param($tipos, ...$vals);
        $st->execute();
        $nuevo = (int)$st->insert_id;
        $st->close();
        return ['ok' => true, 'id' => $nuevo];
    } catch (mysqli_sql_exception $e) {
        // 1062 = clave duplicada. El nombre es único a propósito: dos fichas del
        // mismo proveedor dejan los contratos repartidos entre las dos.
        if ($e->getCode() === 1062) { return ['ok' => false, 'error' => 'Ya hay un proveedor con ese nombre.']; }
        return ['ok' => false, 'error' => 'No se pudo guardar el proveedor.'];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'No se pudo guardar el proveedor.'];
    }
}
}

if (!function_exists('proveedor_baja')) {
/** Da de baja un proveedor. No se borra si tiene contratos: se desactiva. */
function proveedor_baja(mysqli $conn, int $id): array {
    if (!contratos_disponible($conn) || $id <= 0) { return ['ok' => false, 'error' => 'Proveedor no válido.']; }
    try {
        $st = $conn->prepare('SELECT COUNT(*) c FROM `' . tn('contratos') . '` WHERE proveedor_id = ?');
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo verificar.']; }
        $st->bind_param('i', $id);
        $st->execute();
        $usos = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();

        $t = tn('proveedores');
        $sql = $usos > 0 ? "UPDATE `{$t}` SET activo = 0 WHERE id = ?" : "DELETE FROM `{$t}` WHERE id = ?";
        $st = $conn->prepare($sql);
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo completar.']; }
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        return ['ok' => true, 'desactivado' => $usos > 0, 'contratos' => $usos];
    } catch (Throwable) { return ['ok' => false, 'error' => 'No se pudo completar la baja.']; }
}
}

// ═══════════════════════════════════════════════════════════════════════════
//  Contratos
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('contrato_fecha_fin')) {
/**
 * Calcula la fecha de fin: el último día cubierto por el contrato.
 *
 * Se razona sobre cuándo empezaría el período SIGUIENTE y se le resta un día.
 * Así un contrato de doce meses que arranca el 1 de enero termina el 31 de
 * diciembre, y no el 1 de enero del año siguiente.
 *
 * El caso que obliga a no usar `DateTime::add()` a secas: sumarle un mes al 31
 * de enero da el 3 de marzo, porque PHP desborda el 31 de febrero hacia marzo.
 * Cuando el día no existe en el mes destino, el período siguiente arranca el
 * primero del mes que viene —31 de enero + 1 mes termina el 28 de febrero—.
 *
 * Devuelve cadena vacía si falta el inicio o la duración.
 */
function contrato_fecha_fin(string $inicio, int $meses): string {
    if ($meses <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) { return ''; }
    try {
        $d = new DateTimeImmutable($inicio);
        $dia = (int)$d->format('j');

        // Mes destino, contado a mano para no depender del desborde de PHP.
        $total = ((int)$d->format('n') - 1) + $meses;
        $anio  = (int)$d->format('Y') + intdiv($total, 12);
        $mes   = ($total % 12) + 1;
        $ultimo = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))
                       ->format('t');

        if ($dia > $ultimo) {
            // El día no existe en el mes destino: el período siguiente arranca
            // el primero del mes de después, así que el contrato termina el
            // último día del mes destino.
            return sprintf('%04d-%02d-%02d', $anio, $mes, $ultimo);
        }
        return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia)))
                   ->sub(new DateInterval('P1D'))
                   ->format('Y-m-d');
    } catch (Throwable) { return ''; }
}
}

if (!function_exists('contrato_guardar')) {
/**
 * Alta o edición de un contrato. Devuelve ['ok','id','error'].
 *
 * La fecha de fin NO se recibe del formulario: se calcula. Si se pudiera
 * escribir a mano, terminaría no coincidiendo con el inicio más la duración, y
 * entonces no se sabría cuál de los dos creer.
 */
function contrato_guardar(mysqli $conn, int $id, array $d): array {
    if (!contratos_disponible($conn)) {
        return ['ok' => false, 'error' => 'La base todavía no tiene el esquema de contratos.'];
    }
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($nombre === '') { return ['ok' => false, 'error' => 'El nombre del contrato es obligatorio.']; }

    $tipo = (string)($d['tipo'] ?? 'otro');
    if (!isset(CONTRATO_TIPOS[$tipo])) { $tipo = 'otro'; }
    $renov = (string)($d['renovacion'] ?? 'ninguna');
    if (!isset(CONTRATO_RENOVACIONES[$renov])) { $renov = 'ninguna'; }

    $numero = trim((string)($d['numero'] ?? '')) ?: null;
    $provId = (int)($d['proveedor_id'] ?? 0);
    $provId = $provId > 0 ? $provId : null;

    $inicio = trim((string)($d['inicio'] ?? ''));
    $inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) ? $inicio : null;
    $meses  = max(0, (int)($d['duracion_meses'] ?? 0));
    $fin    = ($inicio !== null) ? (contrato_fecha_fin($inicio, $meses) ?: null) : null;

    $preaviso = max(0, (int)($d['preaviso_dias'] ?? 0));
    $period   = max(0, (int)($d['periodicidad_meses'] ?? 0));
    $factur   = max(0, (int)($d['facturacion_meses'] ?? 0));
    $contable = trim((string)($d['numero_contable'] ?? '')) ?: null;
    $costoRaw = trim((string)($d['costo'] ?? ''));
    $costo    = $costoRaw === '' ? null : (float)str_replace(',', '.', $costoRaw);
    $moneda   = strtoupper(trim((string)($d['moneda'] ?? 'USD'))) ?: 'USD';
    $nota     = trim((string)($d['nota'] ?? '')) ?: null;

    // 14 columnas, 14 tipos. La cadena se escribe junto a la lista y se cuenta:
    // un desajuste es un ArgumentCountError fatal en PHP 8.
    $cols = ['numero', 'nombre', 'tipo', 'proveedor_id', 'inicio', 'duracion_meses', 'fin',
             'preaviso_dias', 'periodicidad_meses', 'facturacion_meses', 'renovacion',
             'numero_contable', 'costo', 'moneda', 'nota'];
    $tipos = 'sssi' . 's' . 'i' . 's' . 'iii' . 's' . 's' . 'd' . 's' . 's';
    $vals  = [$numero, $nombre, $tipo, $provId, $inicio, $meses, $fin,
              $preaviso, $period, $factur, $renov, $contable, $costo, $moneda, $nota];

    if (strlen($tipos) !== count($cols) || count($vals) !== count($cols)) {
        return ['ok' => false, 'error' => 'Error interno: la lista de campos no coincide.'];
    }

    try {
        $t = tn('contratos');
        if ($id > 0) {
            $sets = implode(' = ?, ', $cols) . ' = ?';
            $st = $conn->prepare("UPDATE `{$t}` SET {$sets} WHERE id = ?");
            if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
            $vals[] = $id;
            $st->bind_param($tipos . 'i', ...$vals);
            $st->execute();
            $st->close();
            return ['ok' => true, 'id' => $id];
        }
        $marcas = implode(',', array_fill(0, count($cols), '?'));
        $st = $conn->prepare("INSERT INTO `{$t}` (`" . implode('`,`', $cols) . "`) VALUES ({$marcas})");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
        $st->bind_param($tipos, ...$vals);
        $st->execute();
        $nuevo = (int)$st->insert_id;
        $st->close();
        return ['ok' => true, 'id' => $nuevo];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'No se pudo guardar el contrato.'];
    }
}
}

if (!function_exists('contratos_listar')) {
/** Los contratos, con el nombre del proveedor y cuántos activos cubren. */
function contratos_listar(mysqli $conn, bool $incluirBajas = false): array {
    if (!contratos_disponible($conn)) { return []; }
    try {
        $co = tn('contratos');
        $pv = tn('proveedores');
        $ca = tn('contrato_activo');
        $donde = $incluirBajas ? '' : ' WHERE c.activo = 1';
        $sql = "SELECT c.*, p.nombre AS proveedor,
                       (SELECT COUNT(*) FROM `{$ca}` x WHERE x.contrato_id = c.id) AS activos
                  FROM `{$co}` c
             LEFT JOIN `{$pv}` p ON p.id = c.proveedor_id
                       {$donde}
              ORDER BY c.fin IS NULL, c.fin, c.nombre";
        $r = $conn->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('contratos_por_vencer')) {
/**
 * Contratos que vencen dentro de N días, o que ya vencieron.
 *
 * Es la consulta para la que se guarda `fin`: sin ella habría que calcular el
 * vencimiento de cada contrato en PHP y no se podría usar índice.
 */
function contratos_por_vencer(mysqli $conn, int $dias = 90): array {
    if (!contratos_disponible($conn)) { return []; }
    try {
        $co = tn('contratos');
        $pv = tn('proveedores');
        $sql = "SELECT c.*, p.nombre AS proveedor,
                       DATEDIFF(c.fin, CURDATE()) AS dias_restantes
                  FROM `{$co}` c
             LEFT JOIN `{$pv}` p ON p.id = c.proveedor_id
                 WHERE c.activo = 1 AND c.fin IS NOT NULL
                   AND c.fin <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              ORDER BY c.fin";
        $st = $conn->prepare($sql);
        if (!$st) { return []; }
        $st->bind_param('i', $dias);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('contratos_de_activo')) {
/** Los contratos que cubren un activo. */
function contratos_de_activo(mysqli $conn, int $productoId): array {
    if (!contratos_disponible($conn) || $productoId <= 0) { return []; }
    try {
        $co = tn('contratos');
        $pv = tn('proveedores');
        $ca = tn('contrato_activo');
        $sql = "SELECT c.*, p.nombre AS proveedor, DATEDIFF(c.fin, CURDATE()) AS dias_restantes
                  FROM `{$ca}` x
                  JOIN `{$co}` c ON c.id = x.contrato_id
             LEFT JOIN `{$pv}` p ON p.id = c.proveedor_id
                 WHERE x.producto_id = ?
              ORDER BY c.fin IS NULL, c.fin";
        $st = $conn->prepare($sql);
        if (!$st) { return []; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('contrato_vincular')) {
/** Suma un activo a un contrato. Repetirlo no falla ni duplica. */
function contrato_vincular(mysqli $conn, int $contratoId, int $productoId): bool {
    if (!contratos_disponible($conn) || $contratoId <= 0 || $productoId <= 0) { return false; }
    try {
        $st = $conn->prepare('INSERT IGNORE INTO `' . tn('contrato_activo') . '`
                              (contrato_id, producto_id) VALUES (?,?)');
        if (!$st) { return false; }
        $st->bind_param('ii', $contratoId, $productoId);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('contrato_desvincular')) {
function contrato_desvincular(mysqli $conn, int $contratoId, int $productoId): bool {
    if (!contratos_disponible($conn)) { return false; }
    try {
        $st = $conn->prepare('DELETE FROM `' . tn('contrato_activo') . '`
                               WHERE contrato_id = ? AND producto_id = ?');
        if (!$st) { return false; }
        $st->bind_param('ii', $contratoId, $productoId);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('contrato_baja')) {
/** Da de baja un contrato. No se borra: queda desactivado con sus vínculos. */
function contrato_baja(mysqli $conn, int $id): bool {
    if (!contratos_disponible($conn) || $id <= 0) { return false; }
    try {
        $st = $conn->prepare('UPDATE `' . tn('contratos') . '` SET activo = 0 WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}
