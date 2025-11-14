<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Solo aceptamos POST para evitar abusos por GET/CSRF clásico.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit();
}

// Dependencias
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';

// Validar CSRF del formulario "confirmar_accion"
csrf_or_fail('confirmar_accion', true); // true = un solo uso del token

// Usuario autenticado en la app
$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

// =========================
// Autenticación Google Drive
// (SharePoint/OneDrive se puede acoplar con la misma estructura más adelante)
// =========================
$client = new Google\Client();
$client->setAuthConfig(__DIR__ . '/client_secret.json');
$client->addScope(Google\Service\Drive::DRIVE);
$client->setAccessType('offline');

if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $_SESSION['access_token'] = $client->getAccessToken();
    }
} else {
    log_event(
        $user_id,
        'ERROR',
        'Acceso denegado: no hay token de Google.',
        'transaccion'
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error de autenticación. Por favor, inicia sesión de nuevo.'));
    exit();
}

$service = new Google\Service\Drive($client);

// =========================
// VALIDACIONES INICIALES
// =========================
$actual = $_SESSION['archivo_actual'] ?? null;
if (
    !$actual ||
    !isset($actual['tmpPath']) ||
    !is_string($actual['tmpPath']) ||
    !file_exists($actual['tmpPath'])
) {
    log_event(
        $user_id,
        'ERROR',
        'Archivo temporal no disponible para renombrar.',
        'transaccion'
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Archivo temporal no disponible para renombrar.'));
    exit();
}

// Reparar producto_id si se perdió en sesión,
// usando el form_data como fallback (igual que en sobrescribir.php)
if (
    (empty($actual['producto_id']) || intval($actual['producto_id']) <= 0)
    && !empty($_SESSION['form_data']['producto_id'])
) {
    $actual['producto_id'] = $_SESSION['form_data']['producto_id'];
    $_SESSION['archivo_actual']['producto_id'] = $actual['producto_id'];

    log_event(
        $user_id,
        'DEBUG',
        "Producto ID reparado desde form_data: " . $_SESSION['archivo_actual']['producto_id'],
        'transaccion'
    );
}

// Sanitizamos el nuevo nombre que viene por POST
$newName = trim((string)($_POST['new_name'] ?? ''));
$newName = htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');

$tmpPath    = $actual['tmpPath'];
$productoId = intval($actual['producto_id'] ?? 0);
$mimeType   = $actual['mimeType'] ?? (mime_content_type($tmpPath) ?: 'application/octet-stream');

// Validación fuerte de productoId
if ($productoId <= 0) {
    log_event(
        $user_id,
        'ERROR',
        "Producto ID inválido recibido en renombrar.php: $productoId",
        'transaccion'
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Producto ID inválido recibido.'));
    exit();
}

// Debe existir el form_data en sesión (igual que en sobrescribir.php)
$formData = $_SESSION['form_data'] ?? null;
if (!$formData) {
    log_event(
        $user_id,
        'ERROR',
        'No se encontraron datos del formulario para renombrar.',
        'transaccion'
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: No se encontraron datos del formulario.'));
    exit();
}

// =========================
// OBTENER DATOS DEL PRODUCTO Y SU CARPETA
// =========================
$stmt = $conn->prepare("
    SELECT servis_tag, carpeta_raiz_drive
    FROM productos
    WHERE id = ?
");
$stmt->bind_param('i', $productoId);
$stmt->execute();
$stmt->bind_result($servis_tag, $carpeta_raiz_drive);
$stmt->fetch();
$stmt->close();

if (!$carpeta_raiz_drive) {
    log_event(
        $user_id,
        'ERROR',
        "Producto ID $productoId sin carpeta_raiz_drive asociada.",
        'transaccion'
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Producto sin carpeta de Drive asociada.'));
    exit();
}

// Nombre que debe tener la subcarpeta: service tag o producto_ID
$subFolderName = !empty($servis_tag) ? $servis_tag : ('producto_' . $productoId);

// =========================
// BUSCAR / CREAR SUBCARPETA REAL EN DRIVE
// =========================
$subFolders = $service->files->listFiles([
    'q' => "name = '$subFolderName' and mimeType = 'application/vnd.google-apps.folder' and '$carpeta_raiz_drive' in parents and trashed = false",
    'fields' => 'files(id, name)',
    'spaces' => 'drive'
]);

if (count($subFolders->getFiles()) > 0) {
    $subcarpeta_drive_id = $subFolders[0]->getId();
} else {
    $subFolderMetadata = new Google\Service\Drive\DriveFile([
        'name'     => $subFolderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents'  => [$carpeta_raiz_drive]
    ]);

    $subFolder = $service->files->create(
        $subFolderMetadata,
        ['fields' => 'id']
    );

    $subcarpeta_drive_id = $subFolder->id;
}

// =========================
// SUBIR EL ARCHIVO RENOMBRADO
// =========================
try {
    $fileContent = file_get_contents($tmpPath);
    if ($fileContent === false) {
        throw new Exception('Error al leer el contenido del archivo.');
    }

    // Evitar doble duplicado con ese newName dentro de la misma subcarpeta:
    // Si ya existe, lo borramos antes de subir el nuevo.
    $archivosExistentes = $service->files->listFiles([
        'q' => "name = '$newName' and '$subcarpeta_drive_id' in parents and trashed = false",
        'fields' => 'files(id, name)',
        'spaces' => 'drive'
    ]);

    if (count($archivosExistentes->getFiles()) > 0) {
        $fileIdExistente = $archivosExistentes->getFiles()[0]->getId();
        $service->files->delete($fileIdExistente);
    }

    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $newName,
        'parents' => [$subcarpeta_drive_id]
    ]);

    $createdFile = $service->files->create(
        $fileMetadata,
        [
            'data'       => $fileContent,
            'mimeType'   => $mimeType,
            'uploadType' => 'multipart',
            'fields'     => 'id'
        ]
    );

    // Limpiamos el temporal local después de subir
    if (file_exists($tmpPath)) {
        unlink($tmpPath);
    }

    // =========================
    // REGISTRAR MOVIMIENTO EN producto_movimientos
    // =========================
    $stmt_mov = $conn->prepare("
        INSERT INTO producto_movimientos (
            producto_id,
            accion,
            detalle_otros,
            realizado_por
        ) VALUES (
            ?,
            'Renombrar archivo',
            ?,
            ?
        )
    ");

    $detalle = "Archivo renombrado a '$newName' y subido a Google Drive";

    if ($stmt_mov) {
        $stmt_mov->bind_param(
            'iss',
            $productoId,
            $detalle,
            $user_id
        );
        $stmt_mov->execute();
        $stmt_mov->close();
    }

    log_event(
        $user_id,
        'RENOMBRAR',
        "Archivo renombrado a '$newName' y subido correctamente a subcarpeta ($subFolderName). Producto ID: $productoId",
        'transaccion'
    );

    // =========================
    // PROCESAR SIGUIENTE ARCHIVO DUPLICADO O FINALIZAR
    // =========================
    unset($_SESSION['archivo_actual']);

    if (!empty($_SESSION['archivos_duplicados'])) {
        $archivoSiguiente = array_shift($_SESSION['archivos_duplicados']);
        $_SESSION['archivo_actual'] = $archivoSiguiente;

        header(
            'Location: confirmar_accion.php?file_name=' . urlencode($archivoSiguiente['file_name']) .
            '&folder_id=' . urlencode($archivoSiguiente['folder_id']) .
            '&file_id=' . urlencode($archivoSiguiente['file_id']) .
            '&id=' . $productoId
        );
        exit();
    }

    // Limpieza final si no quedan pendientes
    unset($_SESSION['form_data'], $_SESSION['archivos_duplicados']);

    header('Location: resultado.php?status=success&msg=' . urlencode('Archivo renombrado y subido correctamente.'));
    exit();

} catch (Exception $e) {
    log_event(
        $user_id,
        'ERROR',
        'Error al renombrar y subir archivo: ' . $e->getMessage(),
        'transaccion'
    );
    error_log("Error al renombrar y subir archivo: " . $e->getMessage());

    header('Location: resultado.php?status=error&msg=' . urlencode('Error al renombrar y subir archivo: ' . $e->getMessage()));
    exit();
}
