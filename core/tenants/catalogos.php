<?php
/**
 * Catálogos del tenant: listas gestionadas para campos que antes eran texto libre.
 *
 * Motivo: "Asignado a" y "Ubicación" se cargaban a mano en cada alta. El
 * resultado era el esperado — "Depósito", "deposito", "Dep.", "DEPOSITO" — y
 * los reportes por ubicación o por responsable no agrupaban nada. Ahora son
 * desplegables alimentados por esta tabla; el admin (y sólo el admin) da de
 * alta valores nuevos.
 *
 * Agregar un tipo nuevo es sumarlo a CATALOGO_TIPOS. Cada entrada declara en
 * qué tabla y columna se usa el valor, porque de eso dependen "cuántos activos
 * lo usan" y el arrastre al renombrar. Antes se asumía que el tipo *era* el
 * nombre de la columna en `productos`; sigue siendo así para la mayoría, pero
 * los catálogos de Infocom viven en otra tabla.
 *
 * `ubicacion` es el caso aparte: dejó de ser una lista plana y pasó a ser un
 * árbol con dirección y coordenadas (core/tenants/ubicaciones.php). Se mantiene
 * acá para que todo el código que ya llamaba a catalogo_valores($conn,
 * 'ubicacion') o catalogo_normalizar(...) siga funcionando sin cambios: estas
 * funciones delegan y devuelven la lista de rutas completas.
 */

declare(strict_types=1);

require_once __DIR__ . '/ubicaciones.php';

if (!defined('CATALOGO_TIPOS')) {
    define('CATALOGO_TIPOS', [
        // ── Del activo ──────────────────────────────────────────────────────
        'asignado_a'   => ['label' => 'Asignado a',   'tabla' => 'productos', 'columna' => 'asignado_a',
                           'ayuda' => 'Personas o departamentos a los que se asigna un activo'],
        'ubicacion'    => ['label' => 'Ubicación',    'tabla' => 'productos', 'columna' => 'ubicacion', 'arbol' => true,
                           'ayuda' => 'Sedes, oficinas, depósitos o racks. Es un árbol: una sede contiene pisos, y un piso depósitos'],
        'marca'        => ['label' => 'Marca',        'tabla' => 'productos', 'columna' => 'marca',
                           'ayuda' => 'Fabricantes de los activos'],
        'modelo'       => ['label' => 'Modelo',       'tabla' => 'productos', 'columna' => 'modelo',
                           'ayuda' => 'Modelos de equipo'],
        'categoria'    => ['label' => 'Categoría',    'tabla' => 'productos', 'columna' => 'categoria',
                           'ayuda' => 'Tipo de activo: notebook, switch, impresora…'],
        'subcategoria' => ['label' => 'Subcategoría', 'tabla' => 'productos', 'columna' => 'subcategoria',
                           'ayuda' => 'Detalle dentro de la categoría'],
        'estado'       => ['label' => 'Estado',       'tabla' => 'productos', 'columna' => 'estado',
                           'ayuda' => 'En uso, en depósito, en reparación, de baja…'],

        // ── Sistema operativo ───────────────────────────────────────────────
        //    Eran un <input list> con diez sugerencias escritas en el HTML:
        //    convivían «Windows 11», «windows 11», «Win11» y «W11», y la
        //    pregunta que motivó la fase 4 —cuántas máquinas siguen en
        //    Windows 10— volvía a no poder contestarse. La migración 10 siembra
        //    una lista completa, al estilo de GLPI.
        'so'              => ['label' => 'Sistema operativo', 'tabla' => 'activo_so', 'columna' => 'so',
                              'ayuda' => 'Windows, Linux, macOS, hipervisores y sistemas de equipos de red'],
        'so_version'      => ['label' => 'Versión del sistema', 'tabla' => 'activo_so', 'columna' => 'version',
                              'ayuda' => 'La versión concreta: 22.04 LTS, 23H2, Sonoma'],
        'so_edicion'      => ['label' => 'Edición del sistema', 'tabla' => 'activo_so', 'columna' => 'edicion',
                              'ayuda' => 'Pro, Home, Enterprise, Datacenter'],
        'so_arquitectura' => ['label' => 'Arquitectura',        'tabla' => 'activo_so', 'columna' => 'arquitectura',
                              'ayuda' => 'x86_64, ARM64, RISC-V'],

        // ── Financiero y administrativo (Infocom) ───────────────────────────
        'proveedor'    => ['label' => 'Proveedor',    'tabla' => 'activo_infocom', 'columna' => 'proveedor',
                           'ayuda' => 'A quién se le compró el activo'],
        'presupuesto'  => ['label' => 'Presupuesto',  'tabla' => 'activo_infocom', 'columna' => 'presupuesto',
                           'ayuda' => 'Partida presupuestaria con la que se compró'],
        'criticidad'   => ['label' => 'Criticidad',   'tabla' => 'activo_infocom', 'columna' => 'criticidad',
                           'ayuda' => 'Qué tan crítico es el activo para la operación'],
    ]);
}

if (!function_exists('catalogo_tipo_valido')) {
function catalogo_tipo_valido(string $tipo): bool {
    return array_key_exists($tipo, CATALOGO_TIPOS);
}
}

if (!function_exists('catalogo_es_arbol')) {
/** True si el tipo se gestiona como árbol (hoy sólo 'ubicacion'). */
function catalogo_es_arbol(string $tipo): bool {
    return !empty(CATALOGO_TIPOS[$tipo]['arbol']);
}
}

if (!function_exists('catalogo_destino')) {
/**
 * Tabla y columna donde se usa el valor de este catálogo.
 * Salen de CATALOGO_TIPOS, nunca del request: se interpolan en el SQL.
 *
 * @return array{0:string,1:string}|null
 */
function catalogo_destino(string $tipo): ?array {
    $def = CATALOGO_TIPOS[$tipo] ?? null;
    if (!$def || empty($def['tabla']) || empty($def['columna'])) { return null; }
    return [(string)$def['tabla'], (string)$def['columna']];
}
}

/**
 * Valores activos de un catálogo, ordenados para mostrar en un <select>.
 * Cachea por request: la misma página puede pedir el catálogo varias veces.
 *
 * @return string[]
 */
if (!function_exists('catalogo_valores')) {
function catalogo_valores(mysqli $conn, string $tipo, bool $incluirInactivos = false): array {
    if (!catalogo_tipo_valido($tipo)) { return []; }

    // Los tipos en árbol no viven en `catalogos`: devuelven sus rutas completas.
    // Así el <select> de alta y edición sigue recibiendo una lista de strings.
    //
    // Salvo que todavía no se haya migrado: entre el despliegue del código y el
    // `php bin/migrar.php` hay una ventana con código nuevo y esquema viejo. Ahí
    // se sigue leyendo la lista plana de `catalogos` —lo que había antes— en vez
    // de devolver vacío y dejar el desplegable de Ubicación sin opciones.
    if (catalogo_es_arbol($tipo) && ubicaciones_disponible($conn)) {
        return ubicaciones_rutas($conn, $incluirInactivos);
    }

    static $cache = [];
    $clave = $tipo . ($incluirInactivos ? ':all' : ':activos');
    if (isset($cache[$clave])) { return $cache[$clave]; }

    $out = [];
    try {
        $tabla = tn('catalogos');
        $sql   = "SELECT valor FROM `{$tabla}` WHERE tipo = ?"
               . ($incluirInactivos ? '' : ' AND activo = 1')
               . ' ORDER BY orden ASC, valor ASC';
        $st = $conn->prepare($sql);
        $st->bind_param('s', $tipo);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) { $out[] = (string)$r['valor']; }
        $st->close();
    } catch (Throwable) { /* tabla todavía no migrada */ }

    $cache[$clave] = $out;
    return $out;
}
}

/** Filas completas (para la pantalla de gestión). */
if (!function_exists('catalogo_filas')) {
function catalogo_filas(mysqli $conn, string $tipo): array {
    if (!catalogo_tipo_valido($tipo)) { return []; }
    if (catalogo_es_arbol($tipo) && ubicaciones_disponible($conn)) {
        $filas = ubicaciones_arbol_plano($conn, false);
        // La pantalla de gestión espera una clave 'valor'. En un árbol lo
        // editable es el NOMBRE del nodo, no la ruta: renombrar "Depósito" no
        // puede obligar a reescribir "Sede Centro > Piso 2 > Depósito". La ruta
        // viaja aparte, en 'ruta', que es lo que guardan los activos.
        foreach ($filas as &$f) { $f['valor'] = $f['nombre']; }
        unset($f);
        return $filas;
    }
    $out = [];
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("SELECT id, valor, activo, orden FROM `{$tabla}` WHERE tipo = ? ORDER BY orden ASC, valor ASC");
        $st->bind_param('s', $tipo);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) { $out[] = $r; }
        $st->close();
    } catch (Throwable) {}
    return $out;
}
}

/** Cuántos activos usan ese valor (para avisar antes de desactivar/borrar). */
if (!function_exists('catalogo_uso')) {
function catalogo_uso(mysqli $conn, string $tipo, string $valor): int {
    if (!catalogo_tipo_valido($tipo)) { return 0; }
    $destino = catalogo_destino($tipo);
    if ($destino === null) { return 0; }
    try {
        // Tabla y columna salen de CATALOGO_TIPOS, nunca del request. Antes se
        // asumía `productos` y que la columna se llamaba igual que el tipo; los
        // catálogos de Infocom (proveedor, presupuesto, criticidad) viven en
        // otra tabla y con eso habrían contado siempre cero.
        $tabla = tn($destino[0]);
        $col   = $destino[1];
        $st = $conn->prepare("SELECT COUNT(*) c FROM `{$tabla}` WHERE `{$col}` = ?");
        $st->bind_param('s', $valor);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['c'] ?? 0);
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('catalogo_agregar')) {
function catalogo_agregar(mysqli $conn, string $tipo, string $valor, ?int $padreId = null): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    // En árbol, $padreId dice de quién cuelga; null lo deja como raíz.
    if (catalogo_es_arbol($tipo) && ubicaciones_disponible($conn)) { return ubicacion_agregar($conn, $valor, $padreId) > 0; }
    $valor = trim(preg_replace('/\s+/u', ' ', $valor));
    if ($valor === '' || mb_strlen($valor) > 255) { return false; }
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("INSERT INTO `{$tabla}` (tipo, valor) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE activo = 1");
        $st->bind_param('ss', $tipo, $valor);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

/**
 * Renombra un valor y arrastra el cambio a los activos que lo usaban, para que
 * no queden huérfanos apuntando a un texto que ya no está en la lista.
 */
if (!function_exists('catalogo_renombrar')) {
function catalogo_renombrar(mysqli $conn, string $tipo, int $id, string $nuevo): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    // En árbol renombrar recalcula además la ruta de todo el subárbol.
    if (catalogo_es_arbol($tipo) && ubicaciones_disponible($conn)) { return ubicacion_renombrar($conn, $id, $nuevo); }
    $nuevo = trim(preg_replace('/\s+/u', ' ', $nuevo));
    if ($nuevo === '' || mb_strlen($nuevo) > 255) { return false; }
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("SELECT valor FROM `{$tabla}` WHERE id = ? AND tipo = ? LIMIT 1");
        $st->bind_param('is', $id, $tipo);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) { return false; }
        $viejo = (string)$row['valor'];
        if ($viejo === $nuevo) { return true; }

        $conn->begin_transaction();
        $up = $conn->prepare("UPDATE `{$tabla}` SET valor = ? WHERE id = ? AND tipo = ?");
        $up->bind_param('sis', $nuevo, $id, $tipo);
        $up->execute();
        $up->close();

        $destino = catalogo_destino($tipo);
        if ($destino === null) { $conn->rollback(); return false; }
        $prod = tn($destino[0]);
        $col  = $destino[1];
        $up2 = $conn->prepare("UPDATE `{$prod}` SET `{$col}` = ? WHERE `{$col}` = ?");
        $up2->bind_param('ss', $nuevo, $viejo);
        $up2->execute();
        $up2->close();

        $conn->commit();
        return true;
    } catch (Throwable) {
        try { $conn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

/** Baja lógica: el valor sale del desplegable pero los activos que lo usan lo conservan. */
if (!function_exists('catalogo_activar')) {
function catalogo_activar(mysqli $conn, string $tipo, int $id, bool $activo): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    if (catalogo_es_arbol($tipo) && ubicaciones_disponible($conn)) { return ubicacion_activar($conn, $id, $activo); }
    try {
        $tabla = tn('catalogos');
        $v = $activo ? 1 : 0;
        $st = $conn->prepare("UPDATE `{$tabla}` SET activo = ? WHERE id = ? AND tipo = ?");
        $st->bind_param('iis', $v, $id, $tipo);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

/** Borrado definitivo. Se rechaza si hay activos usando el valor. */
if (!function_exists('catalogo_eliminar')) {
function catalogo_eliminar(mysqli $conn, string $tipo, int $id): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    // En árbol se rechaza además si tiene hijos: borrar un padre dejaría
    // colgando a los descendientes.
    if (catalogo_es_arbol($tipo) && ubicaciones_disponible($conn)) { return ubicacion_eliminar($conn, $id); }
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("SELECT valor FROM `{$tabla}` WHERE id = ? AND tipo = ? LIMIT 1");
        $st->bind_param('is', $id, $tipo);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) { return false; }
        if (catalogo_uso($conn, $tipo, (string)$row['valor']) > 0) { return false; }

        $del = $conn->prepare("DELETE FROM `{$tabla}` WHERE id = ? AND tipo = ?");
        $del->bind_param('is', $id, $tipo);
        $ok = $del->execute();
        $del->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

/**
 * Valida que el valor recibido de un formulario pertenezca al catálogo.
 *
 * Un usuario común no puede inventar valores: si manda algo que no está en la
 * lista, se descarta. Se acepta el vacío (campo opcional) y se acepta el valor
 * que el activo ya tenía, para no romper la edición de activos viejos cargados
 * antes de que existiera el catálogo.
 */
if (!function_exists('catalogo_normalizar')) {
function catalogo_normalizar(mysqli $conn, string $tipo, ?string $valor, ?string $valorActual = null): string {
    $valor = trim((string)$valor);
    if ($valor === '') { return ''; }
    if ($valorActual !== null && $valor === trim($valorActual)) { return $valor; }
    $validos = catalogo_valores($conn, $tipo, true);
    return in_array($valor, $validos, true) ? $valor : '';
}
}
