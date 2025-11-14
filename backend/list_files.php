<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';

// Predefinimos user_id por si algo explota antes del try
$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

try {

    // --- Autorización mínima: tiene que haber sesión válida
    if (empty($_SESSION['user_id'])) {
        log_event($user_id, 'ACCESO_DENEGADO', 'Intento de acceso a list_files.php sin sesión', 'seguridad');
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'message' => 'No autorizado'
        ]);
        exit();
    }

    // Multiempresa (futuro)
    $tenant_id = $_SESSION['tenant_id'] ?? null;

    /**
     * IMPORTANTE:
     * - `archivos` es un campo legacy (serialize) que guardaba nombres
     *   de archivos subidos localmente.
     * - Esto NO lista archivos de Google Drive / OneDrive. Eso se maneja
     *   por carpetas y APIs en otros endpoints.
     */

    // Si tu tabla `productos` ya tiene columna tenant_id:
    // $sql = "SELECT id, archivos, fecha_modificacion
    //         FROM productos
    //         WHERE tenant_id = ?
    //         ORDER BY id ASC";

    // Por ahora sin filtro tenant_id:
    $sql = "SELECT id, archivos, fecha_modificacion FROM productos ORDER BY id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        log_event($user_id, 'ERROR', 'Fallo prepare() en list_files.php: ' . $conn->error, 'transaccion');
        throw new Exception("Error al preparar la consulta: " . $conn->error);
    }

    // Si más adelante aplicás tenant_id:
    // if ($tenant_id !== null) {
    //     $stmt->bind_param('i', $tenant_id);
    // }

    if (!$stmt->execute()) {
        log_event($user_id, 'ERROR', 'Fallo execute() en list_files.php: ' . $stmt->error, 'transaccion');
        throw new Exception("Error al ejecutar la consulta: " . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result) {
        log_event($user_id, 'ERROR', 'Fallo get_result() en list_files.php: ' . $stmt->error, 'transaccion');
        throw new Exception("Error al obtener resultados: " . $stmt->error);
    }

    $files = [];

    while ($row = $result->fetch_assoc()) {
        /**
         * $row['archivos'] históricamente es un serialize() de un array PHP:
         *   ['factura.pdf', 'inventario.jpg', ...]
         */
        $archivosLegacy = @unserialize($row['archivos']);

        if (is_array($archivosLegacy)) {
            foreach ($archivosLegacy as $archivoNombre) {

                // Sanitizamos el nombre antes de exponerlo
                $files[] = [
                    'producto_id' => (int)$row['id'],
                    'archivo'     => htmlspecialchars((string)$archivoNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'fecha'       => $row['fecha_modificacion']
                ];
            }
        }
    }

    $stmt->close();

    log_event(
        $user_id,
        'CONSULTA ARCHIVOS LEGACY',
        'list_files.php devolvió ' . count($files) . ' archivos.',
        'transaccion'
    );

    echo json_encode([
        'status' => 'success',
        'data'   => $files
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {

    // Error genérico controlado
    http_response_code(500);

    log_event(
        $user_id,
        'ERROR',
        'Excepción en list_files.php: ' . $e->getMessage(),
        'transaccion'
    );

    echo json_encode([
        'status'  => 'error',
        'message' => 'Error al obtener archivos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

} finally {

    if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
        $conn->close();
    }
}
