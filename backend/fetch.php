<?php
declare(strict_types=1);

// ---------------------------------------------------------
// fetch.php
// Devuelve (en JSON) el inventario visible para el tenant actual,
// excluyendo productos archivados.
// Protegido con sesión + CSRF + control de método.
// ---------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php'; // <- usa la conexión del tenant actual

// -----------------------------------------------------
// 1. AUTENTICACIÓN
// -----------------------------------------------------
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Acceso denegado: sesión no válida.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Tenant actual (multiempresa). Esto debería estar seteado en el login.
// Si ya tenés multiempresa activa, descomentá el bloqueo.
// Si todavía no lo tenés, podés dejar que pase pero mantené la variable.
$tenant_id = $_SESSION['tenant_id'] ?? null;
/*
if (!$tenant_id) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Acceso denegado: tenant no asociado.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
*/

// -----------------------------------------------------
// 2. MÉTODO HTTP
// -----------------------------------------------------
// Igual que en fetch-uploads.php y export_csv.php, vamos a exigir POST.
// Motivo: esto devuelve inventario completo (datos sensibles).
// Si dejás GET, un atacante puede hacer CSRF GET fácil.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    log_event(
        (string)$user_id,
        "ERROR",
        "Intento de acceso con método inválido a fetch.php ({$_SERVER['REQUEST_METHOD']})",
        "seguridad"
    );
    echo json_encode([
        'error' => 'Método no permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -----------------------------------------------------
// 3. CSRF
// -----------------------------------------------------
// Esperamos un token válido en POST['csrf_token'] generado con:
// generateCsrfToken('fetch_productos')
$csrf_token = $_POST['csrf_token'] ?? '';
if (!checkCsrfToken('fetch_productos', $csrf_token)) {
    http_response_code(403);
    log_event(
        (string)$user_id,
        "CSRF_BLOCK",
        "Intento de uso de fetch.php con token inválido",
        "seguridad"
    );
    echo json_encode([
        'error' => 'CSRF inválido o ausente.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // -----------------------------------------------------------------
    // 4. CONSULTA A BD
    // -----------------------------------------------------------------
    // Usamos la conexión $conn que expone db.php ya con el contexto correcto.
    // Vamos a devolver SOLO campos relevantes para la UI normal del inventario.
    //
    // MUY IMPORTANTE:
    //  - Excluimos archivados=1 porque eso va a otra pestaña/reportes especiales.
    //  - Hook opcional tenant_id (multiempresa). Si tu tabla productos ya tiene tenant_id,
    //    descomentá la parte del WHERE que filtra por tenant.
    //
    $sql = "
        SELECT 
            p.id,
            p.modelo,
            p.marca,
            p.servis_tag,
            p.cantidad,
            p.valor_usd,
            p.estado,
            p.categoria,
            p.subcategoria,
            p.asignado_a,
            p.ubicacion,
            p.fecha_ingreso,
            p.fecha_egreso,
            p.periodo_garantia,
            p.fecha_garantia,
            p.detalle_tecnico,
            p.patchcord_tipo,
            p.patchcord_longitud,
            p.switch_gestion,
            p.antenas_tipo,
            p.bobina_categoria,
            p.bobina_ubicacion,
            p.bobina_longitud,
            p.conectores_tipo,
            p.detalle_conectores,
            p.cintas_generacion,
            p.detalle_repuesto,
            p.detalle_otros,
            p.subsoftware,
            p.subsoftware_otros
        FROM productos p
        WHERE p.archivado = 0
        /* AND p.tenant_id = ? */
        ORDER BY p.id ASC
    ";

    // Si ya tenés tenant_id en la tabla productos, usamos prepare + bind:
    if ($tenant_id) {
        $stmt = $conn->prepare($sql);
        // Sacá el comentario de "AND p.tenant_id = ?" de arriba antes de habilitar esta línea:
        // $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        // modo legacy (sin multiempresa filtrada)
        $result = $conn->query($sql);
    }

    if (!$result) {
        http_response_code(500);
        log_event(
            (string)$user_id,
            "ERROR",
            "Error de consulta en fetch.php: " . $conn->error,
            "transaccion"
        );
        echo json_encode([
            'error' => 'Error al ejecutar la consulta'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // -----------------------------------------------------------------
    // 5. FORMATEO DE SALIDA
    // -----------------------------------------------------------------
    // Normalizamos algunos campos antes de mandarlos al front:
    // - Fechas a formato YYYY-MM-DD siempre
    // - Nulls -> string vacío
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $row['fecha_ingreso']    = normalizar_fecha($row['fecha_ingreso'] ?? null);
        $row['fecha_egreso']     = normalizar_fecha($row['fecha_egreso'] ?? null);
        $row['fecha_garantia']   = normalizar_fecha($row['fecha_garantia'] ?? null);

        foreach ($row as $k => $v) {
            if ($v === null) {
                $row[$k] = '';
            }
        }

        $productos[] = $row;
    }

    $result->free();

    // -----------------------------------------------------------------
    // 6. LOG Y RESPUESTA
    // -----------------------------------------------------------------
    log_event(
        (string)$user_id,
        "CONSULTA_PRODUCTOS",
        "Fetch exitoso de " . count($productos) . " productos visibles (archivado=0)",
        "transaccion"
    );

    echo json_encode([
        'status' => 'ok',
        'count'  => count($productos),
        'data'   => $productos
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();

} catch (Throwable $e) {
    http_response_code(500);

    log_event(
        (string)$user_id,
        "ERROR",
        "Excepción en fetch.php: " . $e->getMessage(),
        "transaccion"
    );

    echo json_encode([
        'error' => 'Se produjo un error inesperado'
    ], JSON_UNESCAPED_UNICODE);
    exit();
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}


// -----------------------------------------------------------------
// 7. FUNCIÓN AUXILIAR PARA FECHAS
// -----------------------------------------------------------------
function normalizar_fecha(?string $fecha): string {
    if ($fecha === null || $fecha === '' || $fecha === '0000-00-00') {
        return '';
    }

    // Si ya viene en formato YYYY-MM-DD, la dejamos igual
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return $fecha;
    }

    // dd/mm/yyyy -> yyyy-mm-dd
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
        $y = (int)$m[3];
        $mo = (int)$m[2];
        $d = (int)$m[1];
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    // Si viene en otro formato raro, la devuelvo cruda para no romper front
    return $fecha;
}
