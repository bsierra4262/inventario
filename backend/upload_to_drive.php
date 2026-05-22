<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
include __DIR__ . '/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

$client = new Google\Client();
$client->setAuthConfig(__DIR__ . '/client_secret.json');
$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/backend/upload_to_drive.php');
$client->addScope([
    Google\Service\Drive::DRIVE_FILE,
    Google\Service\Drive::DRIVE_METADATA_READONLY,
    Google\Service\Drive::DRIVE_APPDATA
]);
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (!isset($token['error'])) {
        $_SESSION['access_token'] = $token;
        log_event($user_id, "AUTENTICACIÓN", "Token obtenido correctamente", "transaccion");
        header('Location: /backend/upload_to_drive.php');
        exit();
    } else {
        log_event($user_id, "ERROR", "Error al obtener token: " . $token['error_description'], "transaccion");
        header('Location: resultado.php?status=error&msg=' . urlencode('Error al obtener el token de acceso: ' . $token['error_description']) . '&origen=agregar');
        exit();
    }
}

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
$parentFolderId = '1dn00hb-ZX7DAe9EUNklxTkryS2Nd6YPS';
$tempDir = __DIR__ . '/temp';
if (!file_exists($tempDir)) mkdir($tempDir, 0777, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(__DIR__ . '/debug.log', date('Y-m-d H:i:s') . " - Se llamó al POST en upload_to_drive.php\n", FILE_APPEND);
    $modelo = trim(htmlspecialchars($_POST['nombre'] ?? ''));
    if ($modelo === '') {
        header('Location: resultado.php?status=error&msg=Nombre/modelo%20obligatorio.&origen=agregar');
        exit();
    }
    $marca = trim(htmlspecialchars($_POST['marca'] ?? ''));
    $descripcion = isset($_POST['descripcion']) && trim($_POST['descripcion']) !== '' ? htmlspecialchars($_POST['descripcion']) : null;
    $asignado_a = trim(htmlspecialchars($_POST['asignado_a'] ?? ''));
    $servis_tag = trim(htmlspecialchars($_POST['servis_tag'] ?? ''));
    if ($servis_tag !== '') {
        $cantidad = 1;
    } else {
        $cantidad = isset($_POST['cantidad']) ? max(1, intval($_POST['cantidad'])) : 1;
    }
    $estado = htmlspecialchars($_POST['estado'] ?? '');
    $categoria = htmlspecialchars($_POST['categoria'] ?? '');
    $subcategoria  = htmlspecialchars($_POST['subcategoria'] ?? '');
    $periodo_garantia = intval($_POST['periodo_garantia'] ?? 0);
    $fecha_garantia = htmlspecialchars($_POST['fecha_garantia'] ?? '');
    $ubicacion = trim(htmlspecialchars($_POST['ubicacion'] ?? ''));
    $fecha_ingreso = htmlspecialchars($_POST['fecha_ingreso'] ?? '');
    $fecha_compra  = htmlspecialchars($_POST['fecha_compra'] ?? '');
    // Normalizar fechas vacías a NULL-safe empty string
    if ($fecha_ingreso === '' || $fecha_ingreso === '0000-00-00') $fecha_ingreso = '';
    if ($fecha_compra  === '' || $fecha_compra  === '0000-00-00') $fecha_compra  = '';

   $_SESSION['form_data'] = compact(
       'modelo','marca','descripcion','servis_tag','estado','categoria','subcategoria',
       'periodo_garantia','fecha_garantia','ubicacion','fecha_ingreso',
       'fecha_compra','asignado_a','cantidad'
   );

    try {
        $conn->begin_transaction();

        // Insertar producto
        $valor_usd = isset($_POST['valor_usd']) && is_numeric($_POST['valor_usd']) ? floatval($_POST['valor_usd']) : 0.00;
        // ——— CAMPOS TÉCNICOS (agregar todos los que estén en la tabla productos) ———
        $patchcord_tipo      = $_POST['patchcord_tipo']      ?? null;
        $patchcord_longitud  = $_POST['patchcord_longitud']  ?? null;
        $switch_gestion      = $_POST['switch_gestion']      ?? null;
        $antenas_tipo        = $_POST['antenas_tipo']        ?? null;
        $bobina_categoria    = $_POST['bobina_categoria']    ?? null;
        $bobina_ubicacion    = $_POST['bobina_ubicacion']    ?? null;
        $bobina_longitud     = $_POST['bobina_longitud']     ?? null;
        $conectores_tipo     = $_POST['conectores_tipo']     ?? null;
        $detalle_conectores  = $_POST['detalle_conectores']  ?? null;
        $cintas_generacion   = $_POST['cintas_generacion']   ?? null;
        $detalle_repuesto    = $_POST['detalle_repuesto']    ?? null;
        $detalle_otros       = $_POST['detalle_otros']       ?? null;
        $subsoftware         = $_POST['subsoftware']         ?? null;
        $subsoftware_otros   = $_POST['subsoftware_otros']   ?? null;
        $detalle_tecnico = $_POST['detalle_tecnico'] ?? null;

        $stmt = $conn->prepare(
            "INSERT INTO productos (
                modelo, marca, descripcion, servis_tag, estado, categoria, subcategoria,
                periodo_garantia, fecha_garantia, ubicacion, carpeta_raiz_drive, fecha_ingreso,
                fecha_compra, asignado_a, cantidad, valor_usd,
                patchcord_tipo, patchcord_longitud, switch_gestion, antenas_tipo,
                bobina_categoria, bobina_ubicacion, bobina_longitud,
                conectores_tipo, detalle_conectores, cintas_generacion,
                detalle_repuesto, detalle_otros, subsoftware, subsoftware_otros
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $fi = $fecha_ingreso ?: null;
        $fc = $fecha_compra  ?: null;

        $stmt->bind_param(
            'sssssssissssssdsssssssssssssss',
            $modelo, $marca, $descripcion, $servis_tag, $estado, $categoria, $subcategoria,
            $periodo_garantia, $fecha_garantia, $ubicacion, $carpeta_raiz_drive,
            $fi, $fc, $asignado_a, $cantidad, $valor_usd,
            $patchcord_tipo, $patchcord_longitud, $switch_gestion, $antenas_tipo,
            $bobina_categoria, $bobina_ubicacion, $bobina_longitud,
            $conectores_tipo, $detalle_conectores, $cintas_generacion,
            $detalle_repuesto, $detalle_otros, $subsoftware, $subsoftware_otros
        );

        if (!$stmt->execute()) {
            throw new Exception('Error al insertar producto: ' . $stmt->error);
        }

        $producto_id = $stmt->insert_id;
        
        file_put_contents(__DIR__ . '/debug.log', date('Y-m-d H:i:s') . " - Producto insertado correctamente. ID: $producto_id\n", FILE_APPEND);

// 1. Crear carpeta raíz por producto (si no existe)
$folderName = "$modelo - $marca";
$folderName = preg_replace('/[^\w\s\-]/u', '', $folderName);
$folderName = trim(preg_replace('/\s+/', ' ', $folderName));

// Buscar o crear la carpeta raíz bajo el parentFolderId
$existingFolders = $service->files->listFiles([
    'q' => "name = '$folderName' and mimeType = 'application/vnd.google-apps.folder' and '$parentFolderId' in parents and trashed = false",
    'fields' => 'files(id, name)',
    'spaces' => 'drive'
]);
if (count($existingFolders->getFiles()) > 0) {
    $carpeta_raiz_drive = $existingFolders[0]->getId();
} else {
    $folderMetadata = new Google\Service\Drive\DriveFile([
        'name' => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentFolderId]
    ]);
    $rootFolder = $service->files->create($folderMetadata, ['fields' => 'id']);
    $carpeta_raiz_drive = $rootFolder->id;
}

// Guardar la carpeta raíz en el producto (por si luego editás)
$stmt_upd = $conn->prepare("UPDATE productos SET carpeta_raiz_drive = ? WHERE id = ?");
$stmt_upd->bind_param('si', $carpeta_raiz_drive, $producto_id);
$stmt_upd->execute();
$stmt_upd->close();

// 2. Crear subcarpeta por ServiceTag o por ID de producto (exactamente igual a edit.php)
$subFolderName = !empty($servis_tag) ? $servis_tag : 'producto_' . $producto_id;
$subFolders = $service->files->listFiles([
    'q' => "name = '$subFolderName' and mimeType = 'application/vnd.google-apps.folder' and '$carpeta_raiz_drive' in parents and trashed = false",
    'fields' => 'files(id, name)',
    'spaces' => 'drive'
]);
if (count($subFolders->getFiles()) > 0) {
    $subcarpeta_drive_id = $subFolders[0]->getId();
} else {
    $subFolderMetadata = new Google\Service\Drive\DriveFile([
        'name' => $subFolderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$carpeta_raiz_drive]
    ]);
    $subFolder = $service->files->create($subFolderMetadata, ['fields' => 'id']);
    $subcarpeta_drive_id = $subFolder->id;
}


        // Insertar movimiento
        $stmt_mov = $conn->prepare("
            INSERT INTO producto_movimientos (
                producto_id, accion, descripcion, realizado_por,
                modelo, marca, detalle_tecnico, asignado_a, categoria, subcategoria,
                servis_tag, cantidad, valor_usd, estado, carpeta_raiz_drive,
                fecha_ingreso, ubicacion, fecha_garantia, periodo_garantia,
                patchcord_tipo, patchcord_longitud, switch_gestion, antenas_tipo,
                bobina_categoria, bobina_ubicacion, bobina_longitud,
                conectores_tipo, detalle_conectores, cintas_generacion,
                detalle_repuesto, detalle_otros, subsoftware, subsoftware_otros
            ) VALUES (
                ?, 'Agregado', ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?
            )
        ");

        $stmt_mov->bind_param(
            'isisssssssssssssssssssssssssssss',
            $producto_id,
            $descripcion,
            $user_id,
            $modelo,
            $marca,
            $detalle_tecnico,
            $asignado_a,
            $categoria,
            $subcategoria,
            $servis_tag,
            $cantidad,
            $valor_usd,
            $estado,
            $carpeta_raiz_drive,
            $fi,
            $ubicacion,
            $fecha_garantia,
            $periodo_garantia,
            $patchcord_tipo,
            $patchcord_longitud,
            $switch_gestion,
            $antenas_tipo,
            $bobina_categoria,
            $bobina_ubicacion,
            $bobina_longitud,
            $conectores_tipo,
            $detalle_conectores,
            $cintas_generacion,
            $detalle_repuesto,
            $detalle_otros,
            $subsoftware,
            $subsoftware_otros
        );

        if (!$stmt_mov->execute()) {
            throw new Exception('Error al registrar movimiento: ' . $stmt_mov->error);
        }

        if (!$conn->commit()) {
            log_event($user_id, "ERROR", "Fallo al hacer commit: " . $conn->error, "transaccion");
            throw new Exception('Error al confirmar la transacción.');
        }

        log_event($user_id, "PRODUCTO", "Producto '$modelo' agregado (ID: $producto_id)", "transaccion");
    $_SESSION['form_data']['producto_id'] = $producto_id;

    $archivos_duplicados = [];
    $archivos_rechazados = [];
    $formatos_permitidos = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];

    if (!empty($_FILES['archivo']['name'][0])) {
        foreach ($_FILES['archivo']['name'] as $index => $fileName) {
            $tmpPath = $_FILES['archivo']['tmp_name'][$index];
            $fileSize = @filesize($tmpPath);
            $mimeType = mime_content_type($tmpPath);
    
            if (!$fileSize || $fileSize > 5 * 1024 * 1024) {
                $archivos_rechazados[] = "$fileName (excede 5MB o error al leer archivo)";
                log_event($user_id, "ARCHIVO RECHAZADO", "$fileName (motivo: excede 5MB o error al leer archivo)", "transaccion");
                continue;
            }
    
            if (!in_array($mimeType, $formatos_permitidos)) {
                $archivos_rechazados[] = "$fileName (formato no permitido)";
                log_event($user_id, "ARCHIVO RECHAZADO", "$fileName (motivo: formato no permitido)", "transaccion");
                continue;
            }
    
            $filesList = $service->files->listFiles([
                'q' => "name = '$fileName' and '$subcarpeta_drive_id' in parents and trashed = false",
                'fields' => 'files(id, name)',
                'spaces' => 'drive'
            ]);
    
            if (count($filesList->getFiles()) > 0) {
                $uniqueName = uniqid('dup_') . '_' . basename($fileName);
                $destPath = $tempDir . '/' . $uniqueName;
                move_uploaded_file($tmpPath, $destPath);
    
                $archivos_duplicados[] = [
                    'file_name' => $fileName,
                    'file_id' => $filesList[0]->getId(),
                    'tmpPath' => $destPath,
                    'mimeType' => $mimeType,
                    'folder_id' => $subcarpeta_drive_id,
                    'producto_id' => $producto_id
                ];
            } else {
                $fileMetadata = new Google\Service\Drive\DriveFile([
                    'name' => $fileName,
                    'parents' => [$subcarpeta_drive_id]
                ]);
                $uploadedFile = $service->files->create($fileMetadata, [
                    'data' => file_get_contents($tmpPath),
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id'
                ]);
    
                if ($uploadedFile && $uploadedFile->id) {
                    log_event($user_id, "ARCHIVO SUBIDO", "$fileName subido correctamente (ID: {$uploadedFile->id})", "transaccion");
                } else {
                    log_event($user_id, "ERROR", "$fileName subido pero sin ID devuelto", "transaccion");
                }
            }
        } // Cierre del foreach
    } // Cierre del if !empty $_FILES
    if (!empty($archivos_duplicados)) {
        $_SESSION['archivos_duplicados'] = $archivos_duplicados;
        $_SESSION['archivo_actual'] = $archivos_duplicados[0];
        header('Location: confirmar_accion.php?file_name=' . urlencode($archivos_duplicados[0]['file_name']) .
               '&folder_id=' . urlencode($archivos_duplicados[0]['folder_id']) .
               '&file_id=' . urlencode($archivos_duplicados[0]['file_id']) .
               '&id=' . $producto_id);
        exit();
    }

    if (!empty($archivos_rechazados)) {
        $msg = "<div style='text-align:left;'><strong>Algunos archivos fueron rechazados:</strong><ul>";
        foreach ($archivos_rechazados as $rechazado) {
            $msg .= "<li>" . htmlspecialchars($rechazado) . "</li>";
        }
        $msg .= "</ul><p style='margin-top:10px;'>Por favor verifica el formato y que el tama\u00f1o no exceda los 5MB.</p></div>";
        echo "<script>location.href='resultado.php?status=error&origen=agregar&msg=" . rawurlencode($msg) . "';</script>";
        exit();
    }
    log_event($user_id, "PRODUCTO", "Producto '$modelo' procesado completamente con archivos", "transaccion");
    // Mensaje final
        unset($_SESSION['form_data']);
        header('Location: resultado.php?status=success&msg=' . urlencode('Producto agregado correctamente.') . '&origen=agregar');
        exit();

    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/debug.log', date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        $conn->rollback();
        log_event($user_id, "ERROR", $e->getMessage(), "transaccion");
        header('Location: resultado.php?status=error&msg=' . urlencode('Error al agregar producto: ' . $e->getMessage()) . '&origen=agregar');
        exit();
    }
}