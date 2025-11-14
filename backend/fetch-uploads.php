<?php
declare(strict_types=1);

// ---------------------------------------------
// fetch-uploads.php
// Devuelve (en JSON) la lista de archivos subidos
// en /uploads/, con nombre y fecha.
// Protegido con sesión + CSRF.
// ---------------------------------------------

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/csrf.php';

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

// -----------------------------------------------------
// 2. MÉTODO HTTP
// -----------------------------------------------------
// Este endpoint expone metadata interna (nombres de archivos subidos).
// No lo dejamos abierto con GET anónimo desde otra pestaña.
// Lo restringimos a POST para reducir superficie CSRF y
// lo validamos con token CSRF.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    log_event(
        (string)$user_id,
        "ERROR",
        "Intento de acceso con método inválido a fetch-uploads.php ({$_SERVER['REQUEST_METHOD']})",
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
//
// Esperamos que el frontend nos mande en el body:
//   csrf_token=....
//
// Ese token tiene que ser el que generamos con
// generateCsrfToken('fetch_uploads_list').
//
// Si no coincide => rechazamos.
$csrf_token = $_POST['csrf_token'] ?? '';
if (!checkCsrfToken('fetch_uploads_list', $csrf_token)) {
    http_response_code(403);
    log_event(
        (string)$user_id,
        "CSRF_BLOCK",
        "Intento de uso de fetch-uploads.php con token inválido",
        "seguridad"
    );
    echo json_encode([
        'error' => 'CSRF inválido o ausente.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -----------------------------------------------------
// 4. OBTENER LISTA DE ARCHIVOS
// -----------------------------------------------------
try {
    // Directorio físico donde guardás lo subido ANTES de enviarlo a Drive / OneDrive.
    $uploadDir = realpath(__DIR__ . '/../uploads/');

    if (
        !$uploadDir ||
        !is_dir($uploadDir) ||
        !is_readable($uploadDir)
    ) {
        http_response_code(400);

        log_event(
            (string)$user_id,
            "ERROR",
            "Acceso fallido a fetch-uploads: '$uploadDir' inválido o inaccesible",
            "transaccion"
        );

        echo json_encode([
            'error' => 'El directorio de subida no existe o no es accesible.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $files = [];
    $filesList = scandir($uploadDir);

    // scandir() devuelve también "." y "..". Los filtramos como ya hacías
    foreach ($filesList as $file) {
        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $file;

        // Sólo listamos archivos regulares legibles
        if (
            $file !== '.' &&
            $file !== '..' &&
            is_file($filePath) &&
            is_readable($filePath)
        ) {
            // Nota: NO exponemos tamaño, hash, ni ruta absoluta.
            // Sólo nombre y fecha. Menos superficie de fuga.
            $files[] = [
                'name'        => $file,
                'upload_date' => date("Y-m-d H:i:s", filemtime($filePath))
            ];
        }
    }

    // -------------------------------------------------
    // 5. RESPUESTA
    // -------------------------------------------------
    // Nunca devolvemos null, siempre array. Eso simplifica el JS del front.
    log_event(
        (string)$user_id,
        "CONSULTA_UPLOADS",
        "Listado de archivos enviado correctamente (" . count($files) . " archivos)",
        "transaccion"
    );

    echo json_encode(
        [
            'status' => 'ok',
            'count'  => count($files),
            'files'  => $files
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit();

} catch (Throwable $e) {
    // Cualquier excepción: 500 seguro.
    http_response_code(500);

    log_event(
        (string)$user_id,
        "ERROR",
        "Excepción en fetch-uploads: " . $e->getMessage(),
        "transaccion"
    );

    echo json_encode([
        'error' => 'Se produjo un error inesperado.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
