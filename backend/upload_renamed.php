<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../logs/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_event('usuario_desconocido', 'ERROR', 'Solicitud inválida a upload_renamed', 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Solicitud inválida.'));
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit();
}

if (!isset($_POST['new_name'], $_FILES['archivo']['tmp_name'], $_POST['folder_id'])) {
    log_event((string)$user_id, 'ERROR', 'Datos insuficientes para renombrar y subir el archivo', 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: Datos insuficientes para renombrar y subir el archivo.'));
    exit();
}

$newName  = htmlspecialchars((string)$_POST['new_name'], ENT_QUOTES, 'UTF-8');
$tempPath = $_FILES['archivo']['tmp_name'];
$folderId = htmlspecialchars((string)$_POST['folder_id'], ENT_QUOTES, 'UTF-8');

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

if (!file_exists($tempPath) || filesize($tempPath) === 0) {
    log_event((string)$user_id, 'ERROR', 'Archivo no existe o está vacío en upload_renamed', 'transaccion');
    header('Location: resultado.php?status=error&msg=' . urlencode('Error: El archivo no existe o está vacío.'));
    exit();
}

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
