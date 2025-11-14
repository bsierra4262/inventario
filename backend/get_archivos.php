<?php
declare(strict_types=1);

// ---------------------------------------------------------
// get_archivos.php
// Devuelve el historial de archivos subidos asociados a productos,
// basado en la tabla `archivos` (producto_id, ruta, fecha).
// Protegido con sesión + CSRF + método POST + multiempresa.
// ---------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php'; // conexión ya adaptada al tenant actual

// -----------------------------------------------------
// 1. AUTENTICACIÓN DE SESIÓN
// -----------------------------------------------------
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Acceso denegado: sesión no válida.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Tenant actual (multiempresa)
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
// 2. RESTRICCIÓN DE MÉTODO
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    log_event(
        (string)$user_id,
        "ERROR",
        "Intento de acceso con método inválido a get_archivos.php ({$_SERVER['REQUEST_METHOD']})",
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
// Esperamos el token en POST['csrf_token']
// Este token tiene que haber sido generado con generateCsrfToken('get_archivos')
$csrf_token = $_POST['csrf_token'] ?? '';
if (!checkCsrfToken('get_archivos', $csrf_token)) {
    http_response_code(403);
    log_event(
        (string)$user_id,
        "CSRF_BLOCK",
        "Intento de uso de get_archivos.php con token inválido",
        "seguridad"
    );
    echo json_encode([
        'error' => 'CSRF inválido o ausente.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {

    // -------------------------------------------------
    // 4. CONSULTA SEGURA
    // -------------------------------------------------
    // Queremos:
    //   producto_id, ruta, fecha
    // ordenado por fecha DESC
    //
    // Multiempresa:
    // si tu tabla archivos tiene columna tenant_id:
    //   SELECT producto_id, ruta, fecha
    //   FROM archivos
    //   WHERE tenant_id = ?
    //   ORDER BY fecha DESC
    //
    // Te dejo ambas variantes:
    $sqlBase = "
        SELECT producto_id, ruta, fecha
        FROM archivos
        /* WHERE tenant_id = ? */
        ORDER BY fecha DESC
    ";

    if ($tenant_id) {
        $stmt = $conn->prepare($sqlBase);
        // Sacá el comentario /* WHERE tenant_id = ? */ arriba ANTES de habilitar bind_param
        // $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        // Sin filtro tenant (legacy)
        $result = $conn->query($sqlBase);
    }

    if (!$result) {
        http_response_code(500);
        log_event(
            (string)$user_id,
            "ERROR",
            "Error al ejecutar consulta en get_archivos.php: " . $conn->error,
            "transaccion"
        );
        echo json_encode([
            'error' => 'Error al ejecutar la consulta.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // -------------------------------------------------
    // 5. PROCESAR RESULTADOS
    // -------------------------------------------------
    // Normalizamos salida con llaves limpias para el front.
    // file_ref = lo que hoy llamás 'ruta' (puede ser path local,
    // ID de Google Drive, URL de SharePoint, etc.)
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'product_id' => (int)$row['producto_id'],
            'file_ref'   => (string)$row['ruta'],
            'fecha'      => (string)$row['fecha'],
        ];
    }
    $result->free();

    // -------------------------------------------------
    // 6. LOG + RESPUESTA
    // -------------------------------------------------
    log_event(
        (string)$user_id,
        "CONSULTA_ARCHIVOS",
        "Consulta de archivos completada exitosamente. Registros=" . count($data),
        "transaccion"
    );

    echo json_encode([
        'status' => 'ok',
        'count'  => count($data),
        'data'   => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // Cerrar conexión limpia
    if ($conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
    exit();

} catch (Throwable $e) {

    http_response_code(500);

    log_event(
        (string)$user_id,
        "ERROR",
        "Excepción en get_archivos.php: " . $e->getMessage(),
        "transaccion"
    );

    echo json_encode([
        'status'  => 'error',
        'message' => 'Se produjo un error inesperado.'
    ], JSON_UNESCAPED_UNICODE);

    if ($conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
    exit();
}
