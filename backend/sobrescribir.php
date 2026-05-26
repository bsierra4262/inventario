<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Solo aceptamos POST. Si entran por GET, bloqueo.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit();
}

// Carga de dependencias críticas
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';

// Validar CSRF del formulario "confirmar_accion"
// Scope debe coincidir con el que usamos en confirmar_accion.php
csrf_or_fail('confirmar_accion', true); // true = el token se rota, un solo uso

// Identificar usuario responsable
$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

// =========================
// Autenticación Google Drive — plataforma primero, cloud_config como override
// =========================
$googleClientId     = GOOGLE_CLIENT_ID;
$googleClientSecret = GOOGLE_CLIENT_SECRET;
$googleRedirectUri  = GOOGLE_REDIRECT_URI ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/auth/superadmin_callback.php');

try {
    $cfgTable = tn('cloud_config');
    $cfgRes = $conn->query("SELECT config_key, config_value FROM `{$cfgTable}` WHERE provider = 'google'");
    if ($cfgRes) {
        while ($cfgRow = $cfgRes->fetch_assoc()) {
            match ($cfgRow['config_key']) {
                'client_id'    => $googleClientId     = $cfgRow['config_value'],
                'client_secret'=> $googleClientSecret = $cfgRow['config_value'],
                'redirect_uri' => $googleRedirectUri  = $cfgRow['config_value'],
                default        => null,
            };
        }
    }
} catch (Throwable $e) {
    log_event($user_id, 'ERROR', 'No se pudo leer cloud_config: ' . $e->getMessage(), 'transaccion');
}

if (!$googleClientId || !$googleClientSecret || !isset($_SESSION['access_token'])) {
    log_event($user_id, 'ERROR', 'Sin credenciales Google o sin token de sesión en sobrescribir.php', 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Error de autenticación. Por favor, inicia sesión de nuevo.'));
    exit();
}

$client = new Google\Client();
$client->setClientId($googleClientId);
$client->setClientSecret($googleClientSecret);
$client->setRedirectUri($googleRedirectUri);
$client->addScope(Google\Service\Drive::DRIVE);
$client->setAccessType('offline');
$client->setAccessToken($_SESSION['access_token']);

if ($client->isAccessTokenExpired()) {
    $refresh = $client->getRefreshToken();
    if ($refresh) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($refresh);
        $_SESSION['access_token'] = $newToken;
    } else {
        unset($_SESSION['access_token'], $_SESSION['google_authenticated']);
        $cid_r = (int)($_SESSION['company_id'] ?? 0);
        header('Location: /auth/login.php' . ($cid_r ? '?company=' . $cid_r : ''));
        exit();
    }
}

$service = new Google\Service\Drive($client);

// =========================
// Validaciones de sesión y datos previos
// =========================

// Archivo que el usuario eligió sobrescribir
$actual = $_SESSION['archivo_actual'] ?? null;
if (!$actual || !isset($actual['tmpPath']) || !is_string($actual['tmpPath']) || !file_exists($actual['tmpPath'])) {
    log_event(
        $user_id,
        "ERROR",
        "Archivo temporal no disponible para sobrescribir",
        "transaccion"
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Archivo temporal no disponible para sobrescribir.'));
    exit();
}

// Reparar producto_id si faltaba
if (
    (empty($actual['producto_id']) || intval($actual['producto_id']) <= 0)
    && !empty($_SESSION['form_data']['producto_id'])
) {
    $actual['producto_id'] = $_SESSION['form_data']['producto_id'];
    $_SESSION['archivo_actual']['producto_id'] = $actual['producto_id'];

    log_event(
        $user_id,
        "DEBUG",
        "Producto ID reparado desde form_data: " . $_SESSION['archivo_actual']['producto_id'],
        "transaccion"
    );
}

// Datos necesarios
$fileId      = $actual['file_id']      ?? '';
$fileName    = $actual['file_name']    ?? '';
$productoId  = intval($actual['producto_id'] ?? 0);
$tmpPath     = $actual['tmpPath'];
$mimeType    = $actual['mimeType'] ?? (mime_content_type($tmpPath) ?: 'application/octet-stream');

// Log de control
log_event(
    $user_id,
    "DEBUG",
    "Producto ID recibido en sobrescribir.php: " . $productoId,
    "transaccion"
);

// Producto ID válido?
if ($productoId <= 0) {
    log_event(
        $user_id,
        "ERROR",
        "Producto ID inválido recibido en sobrescribir.php: " . $productoId,
        "transaccion"
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Producto ID inválido recibido.'));
    exit();
}

// Datos del formulario original (para actualizar metadata en DB y loguear movimiento)
$formData = $_SESSION['form_data'] ?? null;
if (!$formData) {
    log_event(
        $user_id,
        "ERROR",
        "Datos del formulario no disponibles durante sobrescribir",
        "transaccion"
    );
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: No se encontraron datos del formulario.'));
    exit();
}

// Sanitizamos para DB / logs
$modelo            = htmlspecialchars($formData['modelo']            ?? '', ENT_QUOTES, 'UTF-8');
$marca             = htmlspecialchars($formData['marca']             ?? '', ENT_QUOTES, 'UTF-8');
$descripcion       = isset($formData['descripcion']) && $formData['descripcion'] !== ''
                     ? htmlspecialchars($formData['descripcion'], ENT_QUOTES, 'UTF-8')
                     : '';
$servis_tag        = htmlspecialchars($formData['servis_tag']        ?? '', ENT_QUOTES, 'UTF-8');
$estado            = htmlspecialchars($formData['estado']            ?? '', ENT_QUOTES, 'UTF-8');
$categoria         = htmlspecialchars($formData['categoria']         ?? '', ENT_QUOTES, 'UTF-8');
$periodo_garantia  = intval($formData['periodo_garantia']            ?? 0);
$fecha_garantia    = htmlspecialchars($formData['fecha_garantia']    ?? '', ENT_QUOTES, 'UTF-8');
$ubicacion         = htmlspecialchars($formData['ubicacion']         ?? '', ENT_QUOTES, 'UTF-8');
$fecha_ingreso     = htmlspecialchars($formData['fecha_ingreso']     ?? '', ENT_QUOTES, 'UTF-8');
$fecha_egreso      = htmlspecialchars($formData['fecha_egreso']      ?? '', ENT_QUOTES, 'UTF-8');
$asignado_a        = htmlspecialchars($formData['asignado_a']        ?? '', ENT_QUOTES, 'UTF-8');

try {
    // =========================
    // 1. Sobrescribir el archivo en Drive
    // =========================
    $fileInfo = $service->files->get($fileId, ['fields' => 'mimeType']);
    if ($fileInfo->mimeType === 'application/vnd.google-apps.folder') {
        throw new Exception('No se puede sobrescribir una carpeta.');
    }

    $updatedFile = new Google\Service\Drive\DriveFile([
        'name' => $fileName
    ]);

    $service->files->update(
        $fileId,
        $updatedFile,
        [
            'data'       => file_get_contents($tmpPath),
            'mimeType'   => $mimeType,
            'uploadType' => 'multipart',
            'fields'     => 'id'
        ]
    );

    // Borramos el temporal local
    if (file_exists($tmpPath)) {
        unlink($tmpPath);
    }

    // =========================
    // 2. Confirmar que el producto existe
    // =========================
    $check = $conn->prepare("SELECT id FROM `" . tn('productos') . "` WHERE id = ?");
    $check->bind_param("i", $productoId);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows === 0) {
        log_event(
            $user_id,
            "ERROR",
            "Producto ID $productoId no existe al sobrescribir",
            "transaccion"
        );
        $check->close();
        header('Location: resultado.php?status=error&msg=' . urlencode('Error: El producto no existe.'));
        exit();
    }
    $check->close();

    // =========================
    // 3. Actualizar metadata del producto con los últimos datos del form
    //    (esto replica tu lógica existente)
    // =========================
    $stmt = $conn->prepare("
        UPDATE `" . tn('productos') . "`
        SET modelo = ?, marca = ?, descripcion = ?, servis_tag = ?, estado = ?, categoria = ?,
            periodo_garantia = ?, fecha_garantia = ?, ubicacion = ?, fecha_ingreso = ?,
            fecha_egreso = ?, asignado_a = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        'ssssssisssssi',
        $modelo,
        $marca,
        $descripcion,
        $servis_tag,
        $estado,
        $categoria,
        $periodo_garantia,
        $fecha_garantia,
        $ubicacion,
        $fecha_ingreso,
        $fecha_egreso,
        $asignado_a,
        $productoId
    );

    if (!$stmt->execute()) {
        log_event(
            $user_id,
            "ERROR",
            "Error al actualizar producto ID $productoId: " . $stmt->error,
            "transaccion"
        );
        // Nota: no abortamos acá porque la sobreescritura de archivo ya ocurrió.
    }

    $stmt->close();

    // =========================
    // 4. Registrar movimiento para trazabilidad
    // =========================
    // IMPORTANTE:
    // la tabla producto_movimientos en tu versión actual guarda:
    //   producto_id, accion, detalle_otros, realizado_por
    // Le dejamos la misma estructura y mensaje que vos ya estabas usando.
    $stmt_mov = $conn->prepare("
        INSERT INTO `" . tn('producto_movimientos') . "` (
            producto_id,
            accion,
            detalle_otros,
            realizado_por
        ) VALUES (
            ?,
            'Sobrescribir archivo',
            ?,
            ?
        )
    ");

    $detalle = "El archivo " . $fileName . " fue sobrescrito en Google Drive";

    if ($stmt_mov) {
        $stmt_mov->bind_param(
            'iss',
            $productoId,
            $detalle,
            $_SESSION['user_id']
        );

        if (!$stmt_mov->execute()) {
            log_event(
                $user_id,
                "ERROR",
                "Error al registrar movimiento: " . $stmt_mov->error,
                "transaccion"
            );
        }

        $stmt_mov->close();
    } else {
        log_event(
            $user_id,
            "ERROR",
            "Error al preparar insert movimiento: " . $conn->error,
            "transaccion"
        );
    }

    log_event(
        $user_id,
        "SOBRESCRIBIR",
        "Archivo $fileName sobrescrito y producto ID $productoId actualizado correctamente",
        "transaccion"
    );

    // =========================
    // 5. Manejo de lote (si había más duplicados en cola)
    // =========================
    unset($_SESSION['archivo_actual']);

    if (!empty($_SESSION['archivos_duplicados'])) {
        $siguiente = array_shift($_SESSION['archivos_duplicados']);
        $_SESSION['archivo_actual'] = $siguiente;

        header(
            "Location: confirmar_accion.php?file_name=" . urlencode($siguiente['file_name']) .
            "&folder_id=" . urlencode($siguiente['folder_id']) .
            "&file_id=" . urlencode($siguiente['file_id']) .
            "&id=" . urlencode((string)$productoId)
        );
        exit();
    }

    // Si ya procesamos todo el lote, limpiamos sesión
    unset($_SESSION['form_data'], $_SESSION['archivos_duplicados']);

    header('Location: resultado.php?status=success&msg=' . urlencode('Archivo sobrescrito correctamente.'));
    exit();

} catch (Throwable $e) {
    log_event(
        $user_id,
        "ERROR",
        "Error al sobrescribir archivo: " . $e->getMessage(),
        "transaccion"
    );

    header('Location: resultado.php?status=error&msg=' . urlencode('Error al sobrescribir archivo: ' . $e->getMessage()));
    exit();
}
