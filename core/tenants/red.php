<?php
/**
 * Puertos de red y direcciones IP de un activo.
 *
 * Es lo que GLPI guarda en glpi_networkports (número, nombre, MAC, tipo,
 * velocidad, MTU) y glpi_ipaddresses. Un activo puede tener varios puertos —un
 * switch tiene veinticuatro— y un puerto varias direcciones: IPv4 y IPv6, o
 * dos IPv4 en la misma interfaz.
 *
 * La pregunta que destraba: «qué equipo tiene la IP 10.0.0.47», que hasta ahora
 * se contestaba con una planilla aparte, cuando había planilla.
 */

declare(strict_types=1);

if (!defined('RED_TIPOS_PUERTO')) {
    define('RED_TIPOS_PUERTO', [
        'ethernet' => 'Ethernet',
        'wifi'     => 'Wi-Fi',
        'fibra'    => 'Fibra',
        'agregado' => 'Agregado (LACP)',
        'virtual'  => 'Virtual',
        'consola'  => 'Consola',
        'otro'     => 'Otro',
    ]);
}

if (!defined('RED_ESTADOS_PUERTO')) {
    define('RED_ESTADOS_PUERTO', [
        'activo'   => 'Activo',
        'inactivo' => 'Inactivo',
        'reservado'=> 'Reservado',
    ]);
}

if (!defined('RED_ASIGNACIONES_IP')) {
    define('RED_ASIGNACIONES_IP', [
        'fija'      => 'Fija',
        'dhcp'      => 'DHCP',
        'reservada' => 'Reserva DHCP',
    ]);
}

if (!function_exists('red_disponible')) {
/** ¿Está aplicada la migración 8? */
function red_disponible(mysqli $conn): bool {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $t = $conn->real_escape_string(tn('puertos_red'));
        $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
        $cache = (bool)($r && $r->num_rows > 0);
    } catch (Throwable) { $cache = false; }
    return $cache;
}
}

if (!function_exists('red_normalizar_mac')) {
/**
 * Deja la MAC en un solo formato: AA:BB:CC:DD:EE:FF.
 *
 * Llegan escritas de cuatro maneras —con guiones, con puntos, pegadas, en
 * minúscula— y si se guardan tal cual, buscar por MAC no encuentra nada.
 * Devuelve cadena vacía si no son doce dígitos hexadecimales.
 */
function red_normalizar_mac(string $mac): string {
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
    if (strlen($hex) !== 12) { return ''; }
    return implode(':', str_split($hex, 2));
}
}

if (!function_exists('red_version_ip')) {
/** 4, 6 o 0 si no es una dirección válida. */
function red_version_ip(string $ip): int {
    $ip = trim($ip);
    if ($ip === '') { return 0; }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) { return 4; }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) { return 6; }
    return 0;
}
}

if (!function_exists('red_puertos_de')) {
/** Los puertos de un activo, cada uno con sus direcciones. */
function red_puertos_de(mysqli $conn, int $productoId): array {
    if (!red_disponible($conn) || $productoId <= 0) { return []; }
    try {
        $st = $conn->prepare('SELECT * FROM `' . tn('puertos_red') . '`
                               WHERE producto_id = ? ORDER BY numero IS NULL, numero, id');
        if (!$st) { return []; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $puertos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        if ($puertos === []) { return []; }

        // Las direcciones de todos los puertos en una sola consulta, no una por
        // puerto: un switch de 24 bocas serían 24 consultas.
        $ids = array_column($puertos, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $conn->prepare('SELECT * FROM `' . tn('direcciones_ip') . '`
                                WHERE puerto_id IN (' . $marcas . ') ORDER BY version_ip, ip');
        if ($st2) {
            $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
            $st2->execute();
            $ips = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
            $st2->close();
            $porPuerto = [];
            foreach ($ips as $ip) { $porPuerto[(int)$ip['puerto_id']][] = $ip; }
            foreach ($puertos as &$p) { $p['ips'] = $porPuerto[(int)$p['id']] ?? []; }
            unset($p);
        }
        return $puertos;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('red_puerto_guardar')) {
/**
 * Alta o edición de un puerto. Devuelve el id, o 0 si falló.
 *
 * @param int $id 0 para alta.
 */
function red_puerto_guardar(mysqli $conn, int $productoId, int $id, array $d): int {
    if (!red_disponible($conn) || $productoId <= 0) { return 0; }

    $tipo = (string)($d['tipo'] ?? 'ethernet');
    if (!isset(RED_TIPOS_PUERTO[$tipo])) { $tipo = 'otro'; }
    $estado = (string)($d['estado'] ?? 'activo');
    if (!isset(RED_ESTADOS_PUERTO[$estado])) { $estado = 'activo'; }

    $numero = isset($d['numero']) && trim((string)$d['numero']) !== '' ? (int)$d['numero'] : null;
    $nombre = trim((string)($d['nombre'] ?? '')) ?: null;
    $mac    = red_normalizar_mac((string)($d['mac'] ?? '')) ?: null;
    $vel    = isset($d['velocidad_mbps']) && trim((string)$d['velocidad_mbps']) !== '' ? (int)$d['velocidad_mbps'] : null;
    $mtu    = isset($d['mtu']) && trim((string)$d['mtu']) !== '' ? (int)$d['mtu'] : null;
    $vlan   = trim((string)($d['vlan'] ?? '')) ?: null;
    $nota   = trim((string)($d['nota'] ?? '')) ?: null;

    try {
        $t = tn('puertos_red');
        if ($id > 0) {
            $sql = "UPDATE `{$t}` SET numero=?, nombre=?, tipo=?, mac=?, velocidad_mbps=?, mtu=?,
                    vlan=?, estado=?, nota=? WHERE id=? AND producto_id=?";
            $st = $conn->prepare($sql);
            if (!$st) { return 0; }
            // 11 tipos, uno por marcador: i s s s i i s s s i i
            $st->bind_param('isssiisssii', $numero, $nombre, $tipo, $mac, $vel, $mtu,
                            $vlan, $estado, $nota, $id, $productoId);
            $ok = $st->execute();
            $st->close();
            return $ok ? $id : 0;
        }

        $sql = "INSERT INTO `{$t}` (producto_id, numero, nombre, tipo, mac, velocidad_mbps, mtu, vlan, estado, nota)
                VALUES (?,?,?,?,?,?,?,?,?,?)";
        $st = $conn->prepare($sql);
        if (!$st) { return 0; }
        $st->bind_param('iisssiisss', $productoId, $numero, $nombre, $tipo, $mac, $vel, $mtu, $vlan, $estado, $nota);
        $st->execute();
        $nuevo = (int)$st->insert_id;
        $st->close();
        return $nuevo;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('red_puerto_borrar')) {
/** Borra un puerto y, con él, sus direcciones. */
function red_puerto_borrar(mysqli $conn, int $productoId, int $id): bool {
    if (!red_disponible($conn) || $id <= 0) { return false; }
    try {
        // Primero las direcciones: no hay clave foránea con ON DELETE CASCADE
        // porque las tablas viejas del esquema son MyISAM en algunos tenants y
        // una foránea entre motores distintos no se puede crear.
        $st = $conn->prepare('DELETE FROM `' . tn('direcciones_ip') . '` WHERE puerto_id = ?');
        if ($st) { $st->bind_param('i', $id); $st->execute(); $st->close(); }

        $st = $conn->prepare('DELETE FROM `' . tn('puertos_red') . '` WHERE id = ? AND producto_id = ?');
        if (!$st) { return false; }
        $st->bind_param('ii', $id, $productoId);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('red_ip_guardar')) {
/** Agrega una dirección a un puerto. Devuelve el id o 0. */
function red_ip_guardar(mysqli $conn, int $puertoId, array $d): int {
    if (!red_disponible($conn) || $puertoId <= 0) { return 0; }

    $ip = trim((string)($d['ip'] ?? ''));
    $version = red_version_ip($ip);
    if ($version === 0) { return 0; }

    $asig = (string)($d['asignacion'] ?? 'fija');
    if (!isset(RED_ASIGNACIONES_IP[$asig])) { $asig = 'fija'; }

    $mascara = trim((string)($d['mascara'] ?? '')) ?: null;
    $gateway = trim((string)($d['gateway'] ?? '')) ?: null;
    $dns     = trim((string)($d['nombre_dns'] ?? '')) ?: null;

    try {
        $st = $conn->prepare('INSERT INTO `' . tn('direcciones_ip') . '`
              (puerto_id, ip, version_ip, mascara, gateway, asignacion, nombre_dns) VALUES (?,?,?,?,?,?,?)');
        if (!$st) { return 0; }
        $st->bind_param('isissss', $puertoId, $ip, $version, $mascara, $gateway, $asig, $dns);
        $st->execute();
        $id = (int)$st->insert_id;
        $st->close();
        return $id;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('red_ip_borrar')) {
function red_ip_borrar(mysqli $conn, int $id): bool {
    if (!red_disponible($conn) || $id <= 0) { return false; }
    try {
        $st = $conn->prepare('DELETE FROM `' . tn('direcciones_ip') . '` WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('red_buscar')) {
/**
 * Qué activo tiene una IP o una MAC.
 *
 * Acepta la MAC en cualquier formato: se normaliza antes de buscar, que es para
 * lo que existe red_normalizar_mac().
 */
function red_buscar(mysqli $conn, string $texto): array {
    if (!red_disponible($conn)) { return []; }
    $texto = trim($texto);
    if ($texto === '') { return []; }

    $mac = red_normalizar_mac($texto);
    try {
        $pu = tn('puertos_red');
        $ip = tn('direcciones_ip');
        $pr = tn('productos');
        // Una fila por PUERTO, con sus direcciones juntas.
        //
        // Con DISTINCT y la IP en el SELECT, buscar por MAC devolvía una fila
        // por cada dirección del puerto: un puerto con IPv4 e IPv6 aparecía dos
        // veces, como si fueran dos equipos.
        $sql = "SELECT p.id, p.modelo, p.marca, p.asignado_a, p.ubicacion,
                       pu.id AS puerto_id, pu.nombre AS puerto, pu.mac,
                       GROUP_CONCAT(i.ip ORDER BY i.version_ip, i.ip SEPARATOR ', ') AS ips
                  FROM `{$pu}` pu
                  JOIN `{$pr}` p ON p.id = pu.producto_id
             LEFT JOIN `{$ip}` i ON i.puerto_id = pu.id
                 WHERE (? <> '' AND pu.mac = ?) OR i.ip = ?
              GROUP BY pu.id, p.id, p.modelo, p.marca, p.asignado_a, p.ubicacion, pu.nombre, pu.mac
              ORDER BY p.modelo
                 LIMIT 50";
        $st = $conn->prepare($sql);
        if (!$st) { return []; }
        $st->bind_param('sss', $mac, $mac, $texto);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) { return []; }
}
}
