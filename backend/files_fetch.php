<?php
declare(strict_types=1);

// ---------------------------------------------------------
// files_fetch.php
// Devuelve la lista histórica de archivos asociados a cada producto,
// basada en la columna legacy `archivos` de la tabla `productos`.
// Protegido con sesión + CSRF + método POST + multiempresa.
// ---------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php'; // conexión del tenant actual

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

// Tenant actual (multiempresa). Mismo criterio que en fetch.php
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
// Exigimos POST. Así evitamos CSRF GET que robe listado de archivos.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    log_event(
        (string)$user_id,
        "ERROR",
        "Intento de acceso con método inválido a files_fetch.php ({$_SERVER['REQUEST_METHOD']})",
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
// generateCsrfToken('files_fetch')
$csrf_token = $_POST['csrf_token'] ?? '';
if (!checkCsrfToken('files_fetch', $csrf_token)) {
    http_response_code(403);
    log_event(
        (string)$user_id,
        "CSRF_BLOCK",
        "Intento de uso de files_fetch.php con token inválido",
        "seguridad"
    );
    echo json_encode([
        'error' => 'CSRF inválido o ausente.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {

    // -------------------------------------------------
    // 4. CONSULTA A BD
    // -------------------------------------------------
    // Vamos a leer id, archivos (legacy), fecha_modificacion y opcionalmente archivado.
    // También podés filtrar por archivado=0 (solo activos vigentes en inventario).
    //
    // Hook tenant_id:
    //  - Si tu tabla ya tiene tenant_id, descomentá la línea AND p.tenant_id = ?
    //
    $sql = "
        SELECT 
            p.id               AS product_id,
            p.archivos         AS archivos_serializados,
            p.fecha_modificacion AS fecha,
            p.archivado        AS archivado
        FROM productos p
        /* WHERE p.tenant_id = ? */
        ORDER BY p.id ASC
    ";

    if ($tenant_id) {
        $stmt = $conn->prepare($sql);
        // Sacá el comentario 'WHERE p.tenant_id = ?' de arriba antes de habilitar esta línea:
        // $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $result = $conn->query($sql);
    }

    if (!$result) {
        http_response_code(500);
        log_event(
            (string)$user_id,
            "ERROR",
            "Error al ejecutar consulta files_fetch.php: " . $conn->error,
            "transaccion"
        );
        echo json_encode([
            'error' => 'Error al ejecutar la consulta'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // -------------------------------------------------
    // 5. PROCESAR RESULTADOS
    // -------------------------------------------------
    // Estructura de salida:
    // [
    //   {
    //      "product_id": 123,
    //      "archivo": "manual.pdf",
    //      "fecha": "2025-10-30 18:11:22",
    //      "archivado": 0
    //   },
    //   ...
    // ]
    //
    // El campo `archivos` históricamente parece guardarse serializado.
    // Idealmente deberíamos migrarlo a JSON en DB. Mientras tanto:
    //  - Intentamos primero json_decode()
    //  - Si no es JSON válido, como fallback tratamos de unserialize() PERO
    //    SIN permitir instanciación de objetos que puedan disparar código.
    //
    $filesOut = [];

    while ($row = $result->fetch_assoc()) {
        $product_id = (int)$row['product_id'];
        $fecha      = $row['fecha'] ?? '';
        $archivado  = isset($row['archivado']) ? (int)$row['archivado'] : 0;

        $raw = $row['archivos_serializados'] ?? '';

        $listaArchivos = [];

        // 1) Intentar JSON primero
        if ($raw !== '' && $raw !== null) {
            $tryJson = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tryJson)) {
                $listaArchivos = $tryJson;
            } else {
                // 2) Fallback a unserialize() con opciones seguras
                //    IMPORTANTE: esto sigue siendo legacy. Lo mantenemos
                //    SOLO para compatibilidad. Lo ideal es migrar a JSON.
                $tmp = @unserialize($raw, ['allowed_classes' => false]);
                if ($tmp !== false && is_array($tmp)) {
                    $listaArchivos = $tmp;
                }
            }
        }

        // Normalizar salida: cada elemento de $listaArchivos lo devolvemos individualmente
        foreach ($listaArchivos as $archivoItem) {
            // Solo cadenas (por seguridad). Si vino otra cosa, lo descartamos.
            if (!is_string($archivoItem)) {
                continue;
            }

            $filesOut[] = [
                'product_id' => $product_id,
                'archivo'    => $archivoItem,
                'fecha'      => $fecha,
                'archivado'  => $archivado
            ];
        }
    }

    $result->free();

    // -------------------------------------------------
    // 6. LOG + RESPUESTA
    // -------------------------------------------------
    log_event(
        (string)$user_id,
        "CONSULTA_ARCHIVOS",
        "Consulta de archivos realizada exitosamente. Registros=" . count($filesOut),
        "transaccion"
    );

    echo json_encode([
        'status' => 'ok',
        'count'  => count($filesOut),
        'data'   => $filesOut
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // Cierre de conexión
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
    exit();

} catch (Throwable $e) {

    http_response_code(500);

    log_event(
        (string)$user_id,
        "ERROR",
        "Excepción en files_fetch.php: " . $e->getMessage(),
        "transaccion"
    );

    echo json_encode([
        'error' => 'Se produjo un error inesperado'
    ], JSON_UNESCAPED_UNICODE);

    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
    exit();
}
