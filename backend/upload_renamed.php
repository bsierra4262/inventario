<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../logs/logger.php';
    session_start();

    $user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

    if (!isset($_POST['new_name'], $_FILES['archivo']['tmp_name'], $_POST['folder_id'])) {
        log_event($user_id, "ERROR", "Datos insuficientes para renombrar y subir el archivo", "transaccion");
        header('Location: resultado.php?status=error&msg=' . urlencode('Error: Datos insuficientes para renombrar y subir el archivo.'));
        exit();
    }

    $newName = htmlspecialchars($_POST['new_name']);
    $tempPath = $_FILES['archivo']['tmp_name'];
    $folderId = htmlspecialchars($_POST['folder_id']);

    $client = new Google\Client();
    $client->setAuthConfig(__DIR__ . '/client_secret.json');
    $client->addScope(Google\Service\Drive::DRIVE);
    $client->setAccessType('offline');

    if (!isset($_SESSION['access_token'])) {
        log_event($user_id, "ERROR", "Intento de subir sin autenticación", "transaccion");
        header('Location: ../login.php');
        exit();
    }

    $client->setAccessToken($_SESSION['access_token']);

    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $_SESSION['access_token'] = $client->getAccessToken();
    }

    $service = new Google\Service\Drive($client);

    if (!file_exists($tempPath) || filesize($tempPath) === 0) {
        log_event($user_id, "ERROR", "Archivo no existe o está vacío en upload_renamed", "transaccion");
        header('Location: resultado.php?status=error&msg=' . urlencode('Error: El archivo no existe o está vacío.'));
        exit();
    }

    try {
        $fileMetadata = new Google\Service\Drive\DriveFile([
            'name' => $newName,
            'parents' => [$folderId]
        ]);
        $content = file_get_contents($tempPath);

        if ($content === false) {
            throw new Exception('Error al leer el contenido del archivo.');
        }

        $service->files->create(
            $fileMetadata,
            [
                'data' => $content,
                'mimeType' => mime_content_type($tempPath) ?: 'application/octet-stream',
                'uploadType' => 'multipart',
                'fields' => 'id'
            ]
        );

        log_event($user_id, "SUBIR RENOMBRADO", "Archivo '$newName' subido y renombrado correctamente en Drive", "transaccion");
        header('Location: resultado.php?status=success&msg=' . urlencode('Archivo sobrescrito correctamente.'));
        exit();

    } catch (Exception $e) {
        log_event($user_id, "ERROR", "Error al sobrescribir archivo: " . $e->getMessage(), "transaccion");
        header('Location: resultado.php?status=error&msg=' . urlencode('Error al sobrescribir archivo: ' . $e->getMessage()));
        exit();
    }
} else {
    log_event('usuario_desconocido', "ERROR", "Solicitud inválida a upload_renamed", "transaccion");
    header('Location: resultado.php?status=error&msg=' . urlencode('Solicitud inválida.'));
    exit();
}
