<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

// --- Guardia de sesión ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    header('Location: /auth/login.php');
    exit();
}

// --- Guardia CSRF ---
if (!verifyCsrfToken(null, 'upload_producto')) {
    log_event((string)$user_id, 'CSRF_BLOCK', 'Token CSRF inválido en upload_to_drive.php', 'transaccion');
    header('Location: /inventario/agregar.php?err=' . urlencode('Formulario expirado. Por favor intentá de nuevo.'));
    exit();
}

// --- Guardia de autorización ---
// Este es el alta de activos (lo postea inventario/agregar.php), así que pide
// `asset.create`. Antes sólo verificaba que hubiera sesión: un rol `viewer`
// podía dar de alta activos y subir archivos al Drive de la empresa.
require_once __DIR__ . '/../core/security/permissions.php';
require_tenant_permission('asset.create');

// Función helper para errores de agregar — vuelve al form con el error visible
function agregar_error(string $msg): never {
    $_SESSION['form_data'] = $_SESSION['form_data'] ?? [];
    header('Location: /inventario/agregar.php?err=' . urlencode($msg));
    exit();
}

// --- Credenciales: plataforma como primario, tenant override vía cloud_config ---
require_once __DIR__ . '/../core/config/platform.php';
$googleClientId     = GOOGLE_CLIENT_ID;
$googleClientSecret = GOOGLE_CLIENT_SECRET;
$googleRedirectUri  = GOOGLE_REDIRECT_URI ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php');
$parentFolderId     = '';

try {
    $cfgTable = tn('cloud_config');
    $cfgRes = $conn->query("SELECT config_key, config_value FROM `{$cfgTable}` WHERE provider = 'google'");
    if ($cfgRes) {
        while ($cfgRow = $cfgRes->fetch_assoc()) {
            match ($cfgRow['config_key']) {
                'client_id'      => $googleClientId     = $cfgRow['config_value'],
                'client_secret'  => $googleClientSecret = $cfgRow['config_value'],
                'redirect_uri'   => $googleRedirectUri  = $cfgRow['config_value'],
                'root_folder_id' => $parentFolderId     = $cfgRow['config_value'],
                default          => null,
            };
        }
    }
} catch (Throwable $e) {
    log_event((string)$user_id, 'WARN', 'cloud_config no disponible: ' . $e->getMessage(), 'transaccion');
}

// Drive habilitado si hay credenciales Y token de sesión activo
$driveEnabled    = $googleClientId !== '' && $googleClientSecret !== '' && isset($_SESSION['access_token']);
$service         = null;
$subcarpeta_drive_id = null;

if ($driveEnabled) {
    try {
        $client = new Google\Client();
        $client->setClientId($googleClientId);
        $client->setClientSecret($googleClientSecret);
        $client->setRedirectUri($googleRedirectUri);
        $client->addScope([
            Google\Service\Drive::DRIVE,
            Google\Service\Drive::DRIVE_FILE,
            Google\Service\Drive::DRIVE_METADATA_READONLY,
            Google\Service\Drive::DRIVE_APPDATA,
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setAccessToken($_SESSION['access_token']);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if ($refreshToken) {
                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $_SESSION['access_token'] = $newToken;
                log_event((string)$user_id, 'AUTENTICACIÓN', 'Token Drive refrescado', 'transaccion');
            } else {
                // Token expirado sin refresh → guardar sin Drive
                unset($_SESSION['access_token'], $_SESSION['google_authenticated']);
                $driveEnabled = false;
                log_event((string)$user_id, 'WARN', 'Token Drive expirado, guardando activo sin Drive', 'transaccion');
            }
        }

        if ($driveEnabled) {
            $service = new Google\Service\Drive($client);
            // Fuera del web root: secure/ está bloqueado por su propio .htaccess.
            $tempDir = __DIR__ . '/../secure/tmp';
            if (!is_dir($tempDir)) { @mkdir($tempDir, 0700, true); }
        }
    } catch (Throwable $e) {
        $driveEnabled = false;
        log_event((string)$user_id, 'WARN', 'Error al inicializar Drive, guardando sin Drive: ' . $e->getMessage(), 'transaccion');
    }
} else {
    log_event((string)$user_id, 'INFO', 'Drive no configurado o sin token — activo se guardará solo en DB', 'transaccion');
}

// --- Solo procesar POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inventario/index.php');
    exit();
}

$modelo             = trim(htmlspecialchars($_POST['nombre']      ?? ''));
if ($modelo === '') {
    agregar_error('El nombre/modelo es obligatorio.');
}

$marca              = trim(htmlspecialchars($_POST['marca']               ?? ''));
$descripcion        = isset($_POST['descripcion']) && trim($_POST['descripcion']) !== '' ? htmlspecialchars($_POST['descripcion']) : null;
$asignado_a         = trim(htmlspecialchars($_POST['asignado_a']          ?? ''));
$servis_tag         = trim(htmlspecialchars($_POST['servis_tag']          ?? ''));
$cantidad           = $servis_tag !== '' ? 1 : max(1, (int)($_POST['cantidad'] ?? 1));
$estado             = htmlspecialchars($_POST['estado']                   ?? '');
$categoria          = htmlspecialchars($_POST['categoria']                ?? '');
$subcategoria       = htmlspecialchars($_POST['subcategoria']             ?? '');
$periodo_garantia   = (int)($_POST['periodo_garantia']                    ?? 0);
$fecha_garantia     = htmlspecialchars($_POST['fecha_garantia']           ?? '');
$ubicacion          = trim(htmlspecialchars($_POST['ubicacion']           ?? ''));
$fecha_ingreso      = htmlspecialchars($_POST['fecha_ingreso']            ?? '');
$fecha_compra       = htmlspecialchars($_POST['fecha_compra']             ?? '');
$valor_usd          = is_numeric($_POST['valor_usd'] ?? '') ? (float)$_POST['valor_usd'] : 0.00;

if ($fecha_ingreso === '' || $fecha_ingreso === '0000-00-00') $fecha_ingreso = '';
if ($fecha_compra  === '' || $fecha_compra  === '0000-00-00') $fecha_compra  = '';

// "Asignado a" y "Ubicación" vienen de un catálogo. El <select> del formulario
// es sólo comodidad visual: la validación real es acá, porque cualquiera puede
// mandar otro valor por POST. Si no está en la lista, se descarta.
require_once __DIR__ . '/../core/tenants/catalogos.php';
$asignado_a = catalogo_normalizar($conn, 'asignado_a', $asignado_a);
$ubicacion  = catalogo_normalizar($conn, 'ubicacion',  $ubicacion);
// `estado` pasó a ser catálogo: se valida en el servidor igual que los otros
// dos. El <select> del formulario es comodidad visual, no el control.
$estado     = catalogo_normalizar($conn, 'estado',     $estado);

$fi = $fecha_ingreso ?: null;
$fc = $fecha_compra  ?: null;

// Campos técnicos
$patchcord_tipo     = $_POST['patchcord_tipo']     ?? null;
$patchcord_longitud = $_POST['patchcord_longitud'] ?? null;
$switch_gestion     = $_POST['switch_gestion']     ?? null;
$antenas_tipo       = $_POST['antenas_tipo']       ?? null;
$bobina_categoria   = $_POST['bobina_categoria']   ?? null;
$bobina_ubicacion   = $_POST['bobina_ubicacion']   ?? null;
$bobina_longitud    = $_POST['bobina_longitud']     ?? null;
$conectores_tipo    = $_POST['conectores_tipo']    ?? null;
$detalle_conectores = $_POST['detalle_conectores'] ?? null;
$cintas_generacion  = $_POST['cintas_generacion']  ?? null;
$detalle_repuesto   = $_POST['detalle_repuesto']   ?? null;
$detalle_otros      = $_POST['detalle_otros']      ?? null;
$subsoftware        = $_POST['subsoftware']        ?? null;
$subsoftware_otros  = $_POST['subsoftware_otros']  ?? null;
$detalle_tecnico    = $_POST['detalle_tecnico']    ?? null;

$_SESSION['form_data'] = compact(
    'modelo', 'marca', 'descripcion', 'servis_tag', 'estado', 'categoria', 'subcategoria',
    'periodo_garantia', 'fecha_garantia', 'ubicacion', 'fecha_ingreso',
    'fecha_compra', 'asignado_a', 'cantidad'
);

$carpeta_raiz_drive  = null;

try {
    $conn->begin_transaction();

    $productosTable     = tn('productos');
    $movimientosTable   = tn('producto_movimientos');

    $stmt = $conn->prepare(
        "INSERT INTO `{$productosTable}` (
            modelo, marca, descripcion, servis_tag, estado, categoria, subcategoria,
            periodo_garantia, fecha_garantia, ubicacion, carpeta_raiz_drive, fecha_ingreso,
            fecha_compra, asignado_a, cantidad, valor_usd,
            patchcord_tipo, patchcord_longitud, switch_gestion, antenas_tipo,
            bobina_categoria, bobina_ubicacion, bobina_longitud,
            conectores_tipo, detalle_conectores, cintas_generacion,
            detalle_repuesto, detalle_otros, subsoftware, subsoftware_otros
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        'sssssssissssssidssssssssssssss',
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

    // Enlace al nodo del árbol de ubicaciones. `ubicacion` guarda la ruta como
    // texto (y así la siguen leyendo listado, reportes y exportaciones);
    // `ubicacion_id` es lo que permite filtrar por rama.
    ubicacion_sincronizar_id($conn, (int)$producto_id, (string)$ubicacion);

    // Datos financieros y administrativos. Viven en su propia tabla, asi que
    // se guardan aparte del INSERT del activo. Todos opcionales: si el alta
    // no los trae, queda la fila vacia y se completan desde la edicion.
    require_once __DIR__ . '/../core/tenants/infocom.php';
    infocom_guardar($conn, (int)$producto_id, $_POST);

    // Campos especificos de la categoria definidos en datos. Los catorce
    // heredados siguen viajando en el INSERT de `productos`; aca se guardan
    // los configurables, que viven en `campos_valor`.
    require_once __DIR__ . '/../core/tenants/campos.php';
    campos_guardar($conn, (int)$producto_id, $categoria, $subcategoria, $_POST);

    // El sistema operativo también: es una fila por activo, así que entra en
    // la misma transacción. Lo que necesita el id del activo —componentes,
    // puertos, relaciones— se carga después, en la ficha técnica, y a eso se
    // redirige al terminar.
    require_once __DIR__ . '/../core/tenants/sistema_operativo.php';
    if (so_disponible($conn)) {
        so_asegurar_fila($conn, (int)$producto_id);
        $datosSo = [];
        foreach (array_merge(SO_CAMPOS_TEXTO, SO_CAMPOS_FECHA) as $cSo) {
            if (isset($_POST[$cSo])) { $datosSo[$cSo] = (string)$_POST[$cSo]; }
        }
        if ($datosSo !== []) { so_guardar($conn, (int)$producto_id, $datosSo); }
    }
    $stmt->close();

    // Crear estructura de carpetas en Drive (solo si Drive está habilitado y hay carpeta raíz configurada)
    if ($driveEnabled && $service && $parentFolderId) {
        // Nivel 1: carpeta del modelo/marca dentro del root del tenant
        $folderName = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s\-]/u', '', "$modelo - $marca")));
        if ($folderName === '') $folderName = 'Activos';
        $existing = $service->files->listFiles([
            'q'      => "name = " . json_encode($folderName) . " and mimeType = 'application/vnd.google-apps.folder' and '{$parentFolderId}' in parents and trashed = false",
            'fields' => 'files(id)',
            'spaces' => 'drive',
        ]);
        if (count($existing->getFiles()) > 0) {
            $modeloFolderId = $existing->getFiles()[0]->getId();
        } else {
            $meta = new Google\Service\Drive\DriveFile([
                'name'     => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents'  => [$parentFolderId],
            ]);
            $modeloFolderId = $service->files->create($meta, ['fields' => 'id'])->id;
        }

        // Nivel 2: subcarpeta por SerialTag o ID de producto
        // Esta es la carpeta que se guarda en DB y a la que se vincula el activo
        $subName = $servis_tag !== '' ? $servis_tag : 'activo_' . $producto_id;
        $subExisting = $service->files->listFiles([
            'q'      => "name = " . json_encode($subName) . " and mimeType = 'application/vnd.google-apps.folder' and '{$modeloFolderId}' in parents and trashed = false",
            'fields' => 'files(id)',
            'spaces' => 'drive',
        ]);
        if (count($subExisting->getFiles()) > 0) {
            $subcarpeta_drive_id = $subExisting->getFiles()[0]->getId();
        } else {
            $subMeta = new Google\Service\Drive\DriveFile([
                'name'     => $subName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents'  => [$modeloFolderId],
            ]);
            $subcarpeta_drive_id = $service->files->create($subMeta, ['fields' => 'id'])->id;
        }

        // Guardamos el ID de la SUBCARPETA específica del activo (no la del modelo)
        // Así el link en "Ver activo" va directo a los archivos de ESE serial/activo
        $carpeta_raiz_drive = $subcarpeta_drive_id;

        $stmt_upd = $conn->prepare("UPDATE `{$productosTable}` SET carpeta_raiz_drive = ? WHERE id = ?");
        $stmt_upd->bind_param('si', $carpeta_raiz_drive, $producto_id);
        $stmt_upd->execute();
        $stmt_upd->close();
    }

    // Movimiento de auditoría
    $stmt_mov = $conn->prepare(
        "INSERT INTO `{$movimientosTable}` (
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
        )"
    );
    $stmt_mov->bind_param(
        'isisssssssidsssssissssssssssssss',
        $producto_id, $descripcion, $user_id,
        $modelo, $marca, $detalle_tecnico, $asignado_a, $categoria, $subcategoria,
        $servis_tag, $cantidad, $valor_usd, $estado, $carpeta_raiz_drive,
        $fi, $ubicacion, $fecha_garantia, $periodo_garantia,
        $patchcord_tipo, $patchcord_longitud, $switch_gestion, $antenas_tipo,
        $bobina_categoria, $bobina_ubicacion, $bobina_longitud,
        $conectores_tipo, $detalle_conectores, $cintas_generacion,
        $detalle_repuesto, $detalle_otros, $subsoftware, $subsoftware_otros
    );
    if (!$stmt_mov->execute()) {
        throw new Exception('Error al registrar movimiento: ' . $stmt_mov->error);
    }
    $stmt_mov->close();

    $conn->commit();
    log_event((string)$user_id, 'PRODUCTO', "Producto '$modelo' agregado (ID: $producto_id)", 'transaccion');

    $_SESSION['form_data']['producto_id'] = $producto_id;

    // Procesar archivos adjuntos
    $archivos_duplicados = [];
    $archivos_rechazados = [];
    $formatos_permitidos = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    if (!empty($_FILES['archivo']['name'][0]) && $subcarpeta_drive_id !== null) {
        foreach ($_FILES['archivo']['name'] as $index => $fileName) {
            $tmpPath  = $_FILES['archivo']['tmp_name'][$index];
            $fileSize = @filesize($tmpPath);
            $mimeType = mime_content_type($tmpPath);

            if (!$fileSize || $fileSize > 5 * 1024 * 1024) {
                $archivos_rechazados[] = "$fileName (excede 5MB o error al leer)";
                log_event((string)$user_id, 'ARCHIVO RECHAZADO', "$fileName: excede 5MB o ilegible", 'transaccion');
                continue;
            }
            if (!in_array($mimeType, $formatos_permitidos, true)) {
                $archivos_rechazados[] = "$fileName (formato no permitido)";
                log_event((string)$user_id, 'ARCHIVO RECHAZADO', "$fileName: formato no permitido", 'transaccion');
                continue;
            }

            $existingFile = $service->files->listFiles([
                'q'      => "name = " . json_encode($fileName) . " and '{$subcarpeta_drive_id}' in parents and trashed = false",
                'fields' => 'files(id)',
                'spaces' => 'drive',
            ]);

            if (count($existingFile->getFiles()) > 0) {
                // Nombre en disco sin la extensión que eligió el usuario: un
                // polyglot (imagen válida + código PHP) llamado .php pasaba el
                // chequeo de MIME y quedaba escrito con esa extensión.
                $uniqueName = bin2hex(random_bytes(16)) . '.bin';
                $destPath   = $tempDir . '/' . $uniqueName;
                move_uploaded_file($tmpPath, $destPath);
                $archivos_duplicados[] = [
                    'file_name'  => $fileName,
                    'file_id'    => $existingFile->getFiles()[0]->getId(),
                    'tmpPath'    => $destPath,
                    'mimeType'   => $mimeType,
                    'folder_id'  => $subcarpeta_drive_id,
                    'producto_id' => $producto_id,
                ];
            } else {
                $fileMeta = new Google\Service\Drive\DriveFile([
                    'name'    => $fileName,
                    'parents' => [$subcarpeta_drive_id],
                ]);
                $uploaded = $service->files->create($fileMeta, [
                    'data'       => file_get_contents($tmpPath),
                    'mimeType'   => $mimeType,
                    'uploadType' => 'multipart',
                    'fields'     => 'id',
                ]);
                if ($uploaded && $uploaded->id) {
                    log_event((string)$user_id, 'ARCHIVO SUBIDO', "$fileName (ID: {$uploaded->id})", 'transaccion');
                }
            }
        }
    }

    if (!empty($archivos_duplicados)) {
        $_SESSION['archivos_duplicados'] = $archivos_duplicados;
        $_SESSION['archivo_actual']      = $archivos_duplicados[0];
        header('Location: confirmar_accion.php?file_name=' . urlencode($archivos_duplicados[0]['file_name'])
            . '&folder_id=' . urlencode($archivos_duplicados[0]['folder_id'])
            . '&file_id='   . urlencode($archivos_duplicados[0]['file_id'])
            . '&id=' . $producto_id);
        exit();
    }

    if (!empty($archivos_rechazados)) {
        $rechazadosMsg = implode(', ', $archivos_rechazados);
        header('Location: /inventario/index.php?ok=1&warn=' . urlencode("Activo guardado. Archivos rechazados: {$rechazadosMsg}"));
        exit();
    }

    unset($_SESSION['form_data']);
    $okMsg = "Activo '{$modelo}' agregado correctamente.";
    if (!$driveEnabled) {
        $okMsg .= ' Drive no configurado — podés configurarlo en Integración.';
    } elseif (!$parentFolderId) {
        $okMsg .= ' Sin carpeta Drive asignada — configurá el ID de carpeta en Integración.';
    }
    // A la ficha técnica, no al listado: el alta recién empieza. Componentes,
    // red, contratos y documentos necesitan el id del activo, que recién
    // existe ahora. Antes había que volver al listado, buscarlo y entrar.
    header('Location: /inventario/ficha.php?id=' . (int)$producto_id . '&nuevo=1&ok=' . urlencode($okMsg));
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    log_event((string)$user_id, 'ERROR', $e->getMessage(), 'transaccion');
    agregar_error('Error al guardar el activo: ' . $e->getMessage());
}
