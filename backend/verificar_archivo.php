<?php
// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
include __DIR__ . '/db.php';

$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

$client = new Google\Client();
$client->setAuthConfig(__DIR__ . '/client_secret.json');
$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/backend/verificar_archivo.php');
$client->addScope(Google\Service\Drive::DRIVE);
$client->setAccessType('offline');
$client->setPrompt('select_account');

// Validar token de acceso
if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $_SESSION['access_token'] = $client->getAccessToken();
        log_event($user_id, "AUTENTICACIÓN", "Token refrescado correctamente", "transaccion");
    }
} else {
    log_event($user_id, "ERROR", "Intento de acceso sin token activo", "transaccion");
    header('Location: ' . $client->createAuthUrl());
    exit();
}

$service = new Google\Service\Drive($client);

$fileName = $_GET['file_name'] ?? '';
$folderId = $_GET['folder_id'] ?? '';
$productoId = intval($_GET['id'] ?? 0);

log_event($user_id, "VERIFICAR", "Verificando archivo '$fileName' en carpeta '$folderId' para producto ID $productoId", "transaccion");

if (empty($fileName) || empty($folderId) || empty($productoId)) {
    log_event($user_id, "ERROR", "Datos insuficientes para verificar archivo", "transaccion");
    echo "<script>
            alert('Error: Datos insuficientes para verificar el archivo.');
            window.location.href = 'resultado.php?msg=' + encodeURIComponent('Error: Datos insuficientes para verificar el archivo.');
          </script>";
    exit();
}

try {
    $filesList = $service->files->listFiles([
        'q' => "'$folderId' in parents and name = '$fileName' and trashed = false",
        'fields' => 'files(id, name)'
    ]);

    if (count($filesList->getFiles()) > 0) {
        $fileId = $filesList->getFiles()[0]->getId();
        log_event($user_id, "ARCHIVO ENCONTRADO", "Archivo '$fileName' ya existe en Drive (ID: $fileId)", "transaccion");

        // Trazabilidad: registrar verificación archivo existente
        $stmt_mov = $conn->prepare("
            INSERT INTO producto_movimientos (producto_id, accion, detalle, realizado_por)
            VALUES (?, 'Verificación archivo', ?, ?)
        ");
        $detalle = "El archivo '$fileName' ya existe en Drive (ID: $fileId)";
        $stmt_mov->bind_param('isi', $productoId, $detalle, $_SESSION['user_id']);
        $stmt_mov->execute();
        $stmt_mov->close();

        // Formulario sobrescribir / renombrar
        $formData = $_SESSION['form_data'] ?? null;
        if (!$formData) {
            log_event($user_id, "ERROR", "No se encontraron datos del formulario para el producto", "transaccion");
            echo "<script>
                    alert('Error: No se encontraron datos del formulario. Por favor, vuelve a intentar.');
                    window.location.href = 'resultado.php?msg=' + encodeURIComponent('Error: No se encontraron datos del formulario.');
                  </script>";
            exit();
        }

        $modelo = htmlspecialchars($formData['modelo']);
        $marca = htmlspecialchars($formData['marca']);
        $descripcion = htmlspecialchars($formData['descripcion'] ?? '');
        $servis_tag = htmlspecialchars($formData['servis_tag']);
        $estado = htmlspecialchars($formData['estado']);
        $fileNameHtml = htmlspecialchars($fileName);
        $folderIdHtml = htmlspecialchars($folderId);
        $fileIdHtml = htmlspecialchars($fileId);
        $productoIdHtml = htmlspecialchars($productoId);

        echo "<div style='text-align: center; margin-top: 20px;'>
                <h2>El archivo ya existe en Google Drive</h2>
                <p>¿Deseas sobrescribir el archivo existente o guardar uno nuevo con un nombre diferente?</p>
                <form action='sobrescribir.php' method='POST' style='display: inline;' enctype='multipart/form-data'>
                    <input type='hidden' name='file_id' value='$fileIdHtml'>
                    <input type='hidden' name='folder_id' value='$folderIdHtml'>
                    <input type='hidden' name='file_name' value='$fileNameHtml'>
                    <input type='hidden' name='producto_id' value='$productoIdHtml'>
                    <input type='hidden' name='modelo' value='$modelo'>
                    <input type='hidden' name='marca' value='$marca'>
                    <input type='hidden' name='descripcion' value='$descripcion'>
                    <input type='hidden' name='servis_tag' value='$servis_tag'>
                    <input type='hidden' name='estado' value='$estado'>
                    <label for='archivo' class='form-label'>Selecciona el archivo a sobrescribir:</label>
                    <input type='file' class='form-control mb-3' name='archivo' id='archivo' required>
                    <button type='submit' class='btn btn-danger'>Sobrescribir</button>
                </form>
                <form action='renombrar.php' method='POST' style='display: inline;' enctype='multipart/form-data'>
                    <input type='hidden' name='folder_id' value='$folderIdHtml'>
                    <input type='hidden' name='producto_id' value='$productoIdHtml'>
                    <input type='hidden' name='modelo' value='$modelo'>
                    <input type='hidden' name='marca' value='$marca'>
                    <input type='hidden' name='descripcion' value='$descripcion'>
                    <input type='hidden' name='servis_tag' value='$servis_tag'>
                    <input type='hidden' name='estado' value='$estado'>
                    <label for='archivo_renombrado' class='form-label'>Selecciona el archivo y define un nombre:</label>
                    <input type='file' class='form-control mb-2' name='archivo' id='archivo_renombrado' required>
                    <div class='input-group mt-2'>
                        <input type='text' class='form-control' name='new_name' placeholder='Nuevo nombre' required>
                        <button type='submit' class='btn btn-primary'>Renombrar</button>
                    </div>
                </form>
              </div>";
    } else {
        $fileMetadata = new Google\Service\Drive\DriveFile([
            'name' => $fileName,
            'parents' => [$folderId]
        ]);

        $uploadedFile = $service->files->create($fileMetadata, [
            'data' => '',
            'mimeType' => 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields' => 'id'
        ]);

        log_event($user_id, "ARCHIVO SUBIDO", "Archivo '$fileName' subido directamente (ID: {$uploadedFile->id})", "transaccion");

        // Trazabilidad: registrar alta de archivo
        $stmt_mov = $conn->prepare("
            INSERT INTO producto_movimientos (producto_id, accion, detalle, realizado_por)
            VALUES (?, 'Alta archivo', ?, ?)
        ");
        $detalle = "Archivo '$fileName' subido directamente a Drive (ID: {$uploadedFile->id})";
        $stmt_mov->bind_param('isi', $productoId, $detalle, $_SESSION['user_id']);
        $stmt_mov->execute();
        $stmt_mov->close();

        echo "<script>
                alert('Archivo subido correctamente con ID: " . htmlspecialchars($uploadedFile->id) . "');
                window.location.href = 'resultado.php?status=success&msg=' + encodeURIComponent('Archivo subido correctamente.');
              </script>";
    }
} catch (Exception $e) {
    log_event($user_id, "ERROR", "Error al procesar archivo: " . $e->getMessage(), "transaccion");

    // Trazabilidad: registrar error
    $stmt_mov = $conn->prepare("
        INSERT INTO producto_movimientos (producto_id, accion, detalle, realizado_por)
        VALUES (?, 'Error verificar archivo', ?, ?)
    ");
    $detalle = "Error al verificar o subir archivo: " . $e->getMessage();
    $stmt_mov->bind_param('isi', $productoId, $detalle, $_SESSION['user_id']);
    $stmt_mov->execute();
    $stmt_mov->close();

    error_log("Error al procesar archivo: " . $e->getMessage());
    echo "<script>
            alert('Error al procesar archivo: " . htmlspecialchars($e->getMessage()) . "');
            window.location.href = 'resultado.php?msg=' + encodeURIComponent('Error al procesar archivo: " . addslashes($e->getMessage()) . "');
          </script>";
    exit();
}
?>
