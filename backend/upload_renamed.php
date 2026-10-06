<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/integraciones/drive.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_event('usuario_desconocido', 'ERROR', 'Solicitud inválida a upload_renamed', 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Solicitud inválida.'));
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// Guardias
//
// Este archivo no tenía NINGUNA: ni token CSRF (no incluía csrf.php siquiera),
// ni chequeo de permiso, ni validación del archivo. Cualquier usuario con
// sesión —incluido un `viewer`— podía subir un archivo de cualquier tipo y
// cualquier tamaño a una carpeta arbitraria del Drive de la empresa, con sólo
// pasar un folder_id.
//
// Además está huérfano: ningún formulario, link ni redirect del proyecto apunta
// acá. El flujo vivo de duplicados es confirmar_accion.php → renombrar.php /
// sobrescribir.php, y renombrar.php ya hace el renombrar-y-subir. Quedó de un
// diseño anterior. Se blinda igual en vez de borrarlo, pero conviene darlo de
// baja: código muerto alcanzable por HTTP es superficie sin dueño.
// ─────────────────────────────────────────────────────────────────────────────
csrf_or_fail('confirmar_accion', true);
$_me     = require_tenant_permission('asset.edit');
$user_id = (int)$_me['id'];

if (!isset($_POST['new_name'], $_FILES['archivo']['tmp_name'], $_POST['folder_id'])) {
    log_event((string)$user_id, 'ERROR', 'Datos insuficientes para renombrar y subir el archivo', 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Datos insuficientes para renombrar y subir el archivo.'));
    exit();
}

$newName  = drive_nombre_archivo_seguro((string)$_POST['new_name']);
$tempPath = (string)$_FILES['archivo']['tmp_name'];
$folderId = (string)$_POST['folder_id'];

// El folder_id va dentro de un pedido a la API de Drive: se valida con el mismo
// patrón que confirmar_accion.php, no con htmlspecialchars() —que escapa para
// HTML, un contexto que acá no existe.
if (!preg_match('/^[A-Za-z0-9_\-\.!]+$/', $folderId)) {
    log_event((string)$user_id, 'ERROR', "ID de carpeta no válido: $folderId", 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: ID de carpeta no válido.'));
    exit();
}

// Tipo MIME real y tamaño, con el mismo criterio que el alta de activos.
if (!drive_adjunto_valido($tempPath, $motivoRechazo)) {
    log_event((string)$user_id, 'ARCHIVO RECHAZADO', "$newName: $motivoRechazo", 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode("Archivo rechazado: $motivoRechazo."));
    exit();
}

// Credenciales Google — plataforma primero, cloud_config como override
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
    log_event((string)$user_id, 'ERROR', 'No se pudo leer cloud_config: ' . $e->getMessage(), 'transaccion');
}

if (!$googleClientId || !$googleClientSecret || !isset($_SESSION['access_token'])) {
    log_event((string)$user_id, 'ERROR', 'Sin credenciales Google o sin token de sesión en upload_renamed.php', 'transaccion');
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
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
        require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
        header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
        exit();
    }
}

$service = new Google\Service\Drive($client);

// La existencia y el tamaño ya se verificaron arriba con drive_adjunto_valido(),
// antes de armar el cliente de Drive.

try {
    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $newName,
        'parents' => [$folderId],
    ]);
    $content = file_get_contents($tempPath);
    if ($content === false) {
        throw new Exception('Error al leer el contenido del archivo.');
    }

    $service->files->create($fileMetadata, [
        'data'       => $content,
        'mimeType'   => mime_content_type($tempPath) ?: 'application/octet-stream',
        'uploadType' => 'multipart',
        'fields'     => 'id',
    ]);

    log_event((string)$user_id, 'SUBIR RENOMBRADO', "Archivo '$newName' subido y renombrado correctamente en Drive", 'transaccion');
    header('Location: resultado.php?status=success&msg=' . urlencode('Archivo subido y renombrado correctamente.'));
    exit();

} catch (Throwable $e) {
    log_event((string)$user_id, 'ERROR', 'Error al subir archivo renombrado: ' . $e->getMessage(), 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Error al subir archivo: ' . $e->getMessage()));
    exit();
}
