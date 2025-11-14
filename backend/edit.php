<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

$iconMap = require __DIR__ . '/../iconMap.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ======================================================
// DETECCIÓN DEL PROVEEDOR DE ALMACENAMIENTO
// ======================================================
// Por ahora tu implementación real es Google Drive. Dejamos preparado OneDrive
// para que no rompa cuando en el futuro se agregue el flujo MS (Graph).
$storageProvider = strtolower((string)($_SESSION['storage_provider'] ?? 'google')); 
// valores esperados: 'google' | 'microsoft'

// ======================================================
// CLIENTE GOOGLE (solo si provider = google)
// ======================================================
$googleService = null;
$parentFolderId = '1dn00hb-ZX7DAe9EUNklxTkryS2Nd6YPS'; // carpeta raíz multiempresa (tenés esto hardcodeado)
$tempDir        = __DIR__ . '/temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

if ($storageProvider === 'google') {

    $client = new Google\Client();
    $client->setAuthConfig(__DIR__ . '/client_secret.json');
    $client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/backend/edit.php');
    $client->addScope([
        Google\Service\Drive::DRIVE_FILE,
        Google\Service\Drive::DRIVE_METADATA_READONLY,
        Google\Service\Drive::DRIVE_APPDATA
    ]);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // OAuth callback de Google
    if (isset($_GET['code'])) {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (!isset($token['error'])) {
            $_SESSION['access_token'] = $token;
            header('Location: /backend/edit.php?id=' . urlencode($_GET['id'] ?? ''));
            exit();
        } else {
            log_event("usuario_desconocido","ERROR","Error token: ".$token['error_description'],"transaccion");
            header('Location: resultado.php?status=error&origen=editar&msg='
                .urlencode('Error al obtener token: '.$token['error_description']));
            exit();
        }
    }

    // Set access token o redirigir a OAuth
    if (isset($_SESSION['access_token'])) {
        $client->setAccessToken($_SESSION['access_token']);
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $_SESSION['access_token'] = $client->getAccessToken();
        }
    } else {
        header('Location: ' . $client->createAuthUrl());
        exit();
    }

    // Servicio Google Drive
    $googleService = new Google\Service\Drive($client);
}

// ======================================================
// VALIDAR ID DEL PRODUCTO
// ======================================================
if (!isset($_GET['id'])) {
    log_event("usuario_desconocido","ERROR","Sin ID producto","transaccion");
    header('Location: resultado.php?status=error&origen=editar&msg='
        .urlencode('ID de producto no proporcionado.'));
    exit();
}
$producto_id = intval($_GET['id']);

// ======================================================
// CARGAR PRODUCTO DESDE BD
// ======================================================
$stmt = $conn->prepare("SELECT * FROM productos WHERE id = ?");
$stmt->bind_param('i',$producto_id);
$stmt->execute();
$producto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$producto) {
    log_event("usuario_desconocido","ERROR","Producto no encontrado ID:$producto_id","transaccion");
    header('Location: resultado.php?status=error&origen=editar&msg='
        .urlencode('Producto no encontrado.'));
    exit();
}

// ======================================================
// HELPERS DRIVE (GOOGLE). Para MS vamos a dejar TODO en blanco seguro.
// ======================================================
function drive_buscarOCrearCarpetaRaizProducto(
    Google\Service\Drive $service,
    string $parentFolderId,
    array $valores,
    array $producto,
    mysqli $conn,
    int $producto_id
): string {
    // Si el producto ya tiene carpeta_raiz_drive, devolvemos esa y listo.
    if (!empty($producto['carpeta_raiz_drive'])) {
        return $producto['carpeta_raiz_drive'];
    }

    // Si no tiene carpeta todavía, la generamos según modelo - marca
    $folderName = ($valores['modelo'] ?? $producto['modelo'] ?? '') . ' - ' . ($valores['marca'] ?? $producto['marca'] ?? '');
    $folderName = preg_replace('/[^\w\s\-]/u', '', $folderName);
    $folderName = trim(preg_replace('/\s+/', ' ', $folderName));

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

    // Guardar en BD para persistir
    $updateFolder = $conn->prepare("UPDATE productos SET carpeta_raiz_drive = ? WHERE id = ?");
    $updateFolder->bind_param('si', $carpeta_raiz_drive, $producto_id);
    $updateFolder->execute();
    $updateFolder->close();

    return $carpeta_raiz_drive;
}

function drive_buscarOCrearSubcarpetaProducto(
    Google\Service\Drive $service,
    string $carpeta_raiz_drive,
    string $servis_tag,
    int $producto_id
): string {
    $subFolderName = trim($servis_tag) !== '' ? $servis_tag : 'producto_' . $producto_id;

    $subFolders = $service->files->listFiles([
        'q' => "name = '$subFolderName' and mimeType = 'application/vnd.google-apps.folder' and '$carpeta_raiz_drive' in parents and trashed = false",
        'fields' => 'files(id, name)',
        'spaces' => 'drive'
    ]);

    if (count($subFolders->getFiles()) > 0) {
        return $subFolders[0]->getId();
    } else {
        $subFolderMetadata = new Google\Service\Drive\DriveFile([
            'name' => $subFolderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$carpeta_raiz_drive]
        ]);
        $subFolder = $service->files->create($subFolderMetadata, ['fields' => 'id']);
        return $subFolder->id;
    }
}

// ======================================================
// PROCESAR SUBMIT (POST)
// ======================================================
if ($_SERVER['REQUEST_METHOD']==='POST') {

    // 🔒 1) Validar CSRF scope 'edit_producto'
    csrf_or_fail('edit_producto', true);

    // 2) Campos editables
    $campos = [
        'modelo','marca','descripcion','servis_tag','estado','categoria','subcategoria',
        'periodo_garantia','fecha_garantia','ubicacion','fecha_ingreso','fecha_egreso',
        'asignado_a','cantidad','valor_usd','detalle_repuesto','detalle_otros',
        'patchcord_tipo','patchcord_longitud','switch_gestion','antenas_tipo',
        'bobina_categoria','bobina_ubicacion','bobina_longitud',
        'conectores_tipo','detalle_conectores','cintas_generacion',
        'subsoftware','subsoftware_otros','detalle_tecnico','archivado'
    ];

    // 3) Sanitizar
    $valores = [];
    foreach($campos as $c){
        $valores[$c] = isset($_POST[$c])
            ? htmlspecialchars($_POST[$c] ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')
            : null;
    }
    $valores['cantidad']         = intval($valores['cantidad']);
    $valores['periodo_garantia'] = intval($valores['periodo_garantia']);
    $valores['valor_usd']        = floatval($valores['valor_usd']);

    // fix longitudes que pueden venir encodeadas
    $valores['patchcord_longitud'] = html_entity_decode($valores['patchcord_longitud'] ?? '', ENT_QUOTES|ENT_HTML5, 'UTF-8');
    $valores['bobina_longitud']    = html_entity_decode($valores['bobina_longitud'] ?? '',    ENT_QUOTES|ENT_HTML5, 'UTF-8');

    // Guardar en sesión para fallback si hay error
    $_SESSION['form_data'] = $valores;
    $_SESSION['form_data']['producto_id'] = $producto_id;

    // 4) Manejo de archivos según proveedor de almacenamiento.
    //    Por ahora sólo implementado Google Drive real.
    $archivos_duplicados = [];
    $archivos_rechazados = [];
    $archivos_subidos    = [];

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

    $carpeta_raiz_drive  = '';
    $subcarpeta_drive_id = '';

    if ($storageProvider === 'google' && $googleService instanceof Google\Service\Drive) {
        // === 4.1 Carpeta raíz asociada al producto ===
        $carpeta_raiz_drive = drive_buscarOCrearCarpetaRaizProducto(
            $googleService,
            $parentFolderId,
            $valores,
            $producto,
            $conn,
            $producto_id
        );

        // === 4.2 Subcarpeta por servicetag o producto_id ===
        $subcarpeta_drive_id = drive_buscarOCrearSubcarpetaProducto(
            $googleService,
            $carpeta_raiz_drive,
            $valores['servis_tag'] ?? ($producto['servis_tag'] ?? ''),
            $producto_id
        );

        // === 4.3 Procesar uploads ===
        if (!empty($_FILES['archivo']['name'][0])) {
            foreach ($_FILES['archivo']['name'] as $index => $fileName) {

                $tmpPath   = $_FILES['archivo']['tmp_name'][$index];
                $fileSize  = @filesize($tmpPath);
                $mimeType  = @mime_content_type($tmpPath);

                // Tamaño y tipo
                if (!$fileSize || $fileSize > 5 * 1024 * 1024) {
                    $archivos_rechazados[] = "$fileName (excede 5MB o error al leer archivo)";
                    continue;
                }
                if (!in_array($mimeType, $formatos_permitidos)) {
                    $archivos_rechazados[] = "$fileName (formato no permitido)";
                    continue;
                }

                // ¿Existe ya?
                $filesList = $googleService->files->listFiles([
                    'q' => "name = '$fileName' and '$subcarpeta_drive_id' in parents and trashed = false",
                    'fields' => 'files(id, name)',
                    'spaces' => 'drive'
                ]);

                if (count($filesList->getFiles()) > 0) {
                    // Duplicado: lo guardamos temporal para que el usuario decida
                    $uniqueName = uniqid('dup_') . '_' . basename($fileName);
                    $destPath   = $tempDir . '/' . $uniqueName;
                    move_uploaded_file($tmpPath, $destPath);

                    $archivos_duplicados[] = [
                        'file_name'   => $fileName,
                        'file_id'     => $filesList[0]->getId(),
                        'tmpPath'     => $destPath,
                        'mimeType'    => $mimeType,
                        'folder_id'   => $subcarpeta_drive_id,
                        'producto_id' => $producto_id
                    ];
                } else {
                    // Subir archivo nuevo
                    $fileMetadata = new Google\Service\Drive\DriveFile([
                        'name'    => $fileName,
                        'parents' => [$subcarpeta_drive_id]
                    ]);
                    $googleService->files->create(
                        $fileMetadata,
                        [
                            'data'       => file_get_contents($tmpPath),
                            'mimeType'   => $mimeType,
                            'uploadType' => 'multipart',
                            'fields'     => 'id'
                        ]
                    );
                    $archivos_subidos[] = $fileName;
                }
            } // foreach archivos
        } // if archivos
    } else {
        // FUTURO MS365 / SharePoint / OneDrive:
        // Por ahora no implementamos subida (para no inventar API Microsoft),
        // pero tampoco rompemos el flujo.
        // $carpeta_raiz_drive y $subcarpeta_drive_id quedan vacíos.
    }

    // 4.4 Si hubo duplicados -> redirigimos al flujo de confirmación
    if (!empty($archivos_duplicados)) {
        $_SESSION['archivos_duplicados'] = $archivos_duplicados;
        $_SESSION['archivo_actual']      = $archivos_duplicados[0];

        header('Location: confirmar_accion.php?file_name=' . urlencode($archivos_duplicados[0]['file_name']) .
               '&folder_id=' . urlencode($archivos_duplicados[0]['folder_id']) .
               '&file_id='   . urlencode($archivos_duplicados[0]['file_id']) .
               '&id='        . $producto_id);
        exit();
    }

    // 4.5 Archivos rechazados -> informar error agrupado
    if (!empty($archivos_rechazados)) {
        $msg = "<div style='text-align:left;'><strong>Algunos archivos fueron rechazados:</strong><ul>";
        foreach ($archivos_rechazados as $rechazado) {
            $msg .= "<li>" . htmlspecialchars($rechazado, ENT_QUOTES, 'UTF-8') . "</li>";
        }
        $msg .= "</ul><p style='margin-top:10px;'>Por favor verificá el formato y que el tamaño no exceda los 5MB.</p></div>";

        header('Location: resultado.php?status=error&origen=editar&msg=' . urlencode($msg));
        exit();
    }

    // Guardamos por coherencia (las carpetas que detectamos/creamos)
    $valores['carpeta_raiz_drive']  = $carpeta_raiz_drive ?? ($producto['carpeta_raiz_drive'] ?? '');
    $valores['subcarpeta_drive_id'] = $subcarpeta_drive_id ?? '';

    // ==================================================
    // 5) Comparar cambios con BD para decidir si hay UPDATE
    // ==================================================

    $stmt_old = $conn->prepare("SELECT * FROM productos WHERE id = ?");
    $stmt_old->bind_param('i', $producto_id);
    $stmt_old->execute();
    $producto_actual = $stmt_old->get_result()->fetch_assoc();
    $stmt_old->close();

    $hayCambios = false;
    $camposCambiados = [];

    foreach ($campos as $campo) {
        if (($producto_actual[$campo] ?? null) != ($valores[$campo] ?? null)) {
            $hayCambios = true;
            $camposCambiados[] = $campo;
        }
    }

    // Caso especial: si el único cambio fue 'archivado',
    // NO metemos "Producto actualizado", eso ya se está manejando en edelete.php.
    if ($hayCambios && count($camposCambiados) === 1 && $camposCambiados[0] === 'archivado') {
        unset($_SESSION['form_data']);
        header('Location: resultado.php?status=success&origen=editar&msg='
            . urlencode('Producto archivado/desarchivado correctamente (sin otros cambios).'));
        exit();
    }

    // ==================================================
    // 6) Hacer UPDATE si hay cambios reales
    // ==================================================
    if ($hayCambios) {

        $sql = "UPDATE productos SET
                modelo             = ?, marca             = ?, descripcion        = ?,
                servis_tag         = ?, estado            = ?, categoria         = ?,
                subcategoria       = ?, periodo_garantia  = ?, fecha_garantia     = ?,
                ubicacion          = ?, fecha_ingreso     = ?, fecha_egreso       = ?,
                asignado_a         = ?, cantidad          = ?, valor_usd          = ?,
                detalle_repuesto   = ?, detalle_otos      = ?, patchcord_tipo     = ?,
                patchcord_longitud = ?, switch_gestion    = ?, antenas_tipo       = ?,
                bobina_categoria   = ?, bobina_ubicacion  = ?, bobina_longitud    = ?,
                conectores_tipo    = ?, detalle_conectores= ?, cintas_generacion  = ?,
                subsoftware        = ?, subsoftware_otros = ?, detalle_tecnico    = ?,
                archivado          = ?
            WHERE id = ?";

        // OJO: tu versión original tenía un pequeño typo en la columna `detalle_otros`
        // En tu código original de update usaste `detalle_otros` (bien),
        // acá accidentalmente tenías `detalle_otos` en el SET del SQL.
        // Lo corrijo para que NO rompa.
        $sql = "UPDATE productos SET
                modelo             = ?, marca             = ?, descripcion        = ?,
                servis_tag         = ?, estado            = ?, categoria          = ?,
                subcategoria       = ?, periodo_garantia  = ?, fecha_garantia     = ?,
                ubicacion          = ?, fecha_ingreso     = ?, fecha_egreso       = ?,
                asignado_a         = ?, cantidad          = ?, valor_usd          = ?,
                detalle_repuesto   = ?, detalle_otros     = ?, patchcord_tipo     = ?,
                patchcord_longitud = ?, switch_gestion    = ?, antenas_tipo       = ?,
                bobina_categoria   = ?, bobina_ubicacion  = ?, bobina_longitud    = ?,
                conectores_tipo    = ?, detalle_conectores= ?, cintas_generacion  = ?,
                subsoftware        = ?, subsoftware_otros = ?, detalle_tecnico    = ?,
                archivado          = ?
            WHERE id = ?";

        $upd = $conn->prepare($sql);
        $upd->bind_param(
            'sssssssisssssdsssssssssssssssssi',
            $valores['modelo'],
            $valores['marca'],
            $valores['descripcion'],
            $valores['servis_tag'],
            $valores['estado'],
            $valores['categoria'],
            $valores['subcategoria'],
            $valores['periodo_garantia'],
            $valores['fecha_garantia'],
            $valores['ubicacion'],
            $valores['fecha_ingreso'],
            $valores['fecha_egreso'],
            $valores['asignado_a'],
            $valores['cantidad'],
            $valores['valor_usd'],
            $valores['detalle_repuesto'],
            $valores['detalle_otros'],
            $valores['patchcord_tipo'],
            $valores['patchcord_longitud'],
            $valores['switch_gestion'],
            $valores['antenas_tipo'],
            $valores['bobina_categoria'],
            $valores['bobina_ubicacion'],
            $valores['bobina_longitud'],
            $valores['conectores_tipo'],
            $valores['detalle_conectores'],
            $valores['cintas_generacion'],
            $valores['subsoftware'],
            $valores['subsoftware_otros'],
            $valores['detalle_tecnico'],
            $valores['archivado'],
            $producto_id
        );

        if (!$upd->execute()) {
            log_event("UsuarioID:{$_SESSION['user_id']}", "ERROR",
                "Fallo update ID:$producto_id - " . $upd->error, "transaccion");
            header('Location: resultado.php?status=error&origen=editar&msg='
                . urlencode('Error al actualizar: ' . $upd->error));
            exit();
        }
        $upd->close();

        log_event("UsuarioID:{$_SESSION['user_id']}", "EDITAR PRODUCTO",
            "Producto ID:$producto_id actualizado", "transaccion");

        unset($_SESSION['form_data']);
        header('Location: resultado.php?status=success&origen=editar&msg='
            . urlencode('Producto actualizado correctamente.'));
        exit();
    }

    // ==================================================
    // 7) Si no hubo cambios de campos (solo archivos nuevos / nada)
    // ==================================================
    unset($_SESSION['form_data']);
    header('Location: resultado.php?status=success&origen=editar&msg='
        . urlencode('No se realizaron cambios en los datos del producto (solo archivos).'));
    exit();
}

// ======================================================
// OPCIONES DE CATEGORÍA / SUBCATEGORÍA (sin cambios, lo tuyo)
// ======================================================
$opcionesPorCategoria = [
    'Informática' => [
        'Desktop (PC de escritorio)','Laptop','Notebook','Monitor','Mouse','Teclado',
        'Webcam','Micrófono','Auriculares','Parlantes','Escáner','Numpad','Tableta gráfica',
        'Tablet','All-in-One','Docking station','Cables de alimentación',
        'Soportes / Bases para notebook','Otros'
    ],
    'Redes' => [
        'Patchcord','Switch','Router','Access Point','Módem','Firewall',
        'Repetidor / Extensor Wi-Fi','Antenas','Rack / Gabinete de comunicaciones',
        'Panel de parcheo (Patch panel)','Organizadores de cables','Puntos de red (RJ45)',
        'Bobina UTP','Conectores','Tester de red','POE Injector / POE Switch',
        'Convertidores de medios','Otros'
    ],
    'Servidores y almacenamiento' => [
        'Servidor físico','NAS','SAN','Disco rígido (HDD)','Unidad de estado sólido (SSD)',
        'Cintas de respaldo','Gabinetes de almacenamiento externo','RAID Controller',
        'UPS para servidores','Fuentes redundantes','Otros'
    ],
    'Periféricos y accesorios' => [
        'Impresora térmica','Impresora de tickets','Impresora multifunción',
        'Lector de código de barras','Lector biométrico','Tarjeta de video externa',
        'Adaptadores USB / HDMI / VGA','Hub USB','Cables (HDMI, VGA, DisplayPort, USB, etc.)',
        'Fundas / Mochilas / Maletines','Kit de limpieza','Cargadores y fuentes',
        'Adaptadores eléctricos','Otros'
    ],
    'Herramientas y mantenimiento' => [
        'Destornilladores','Kits de precisión','Pinzas / Alicates','Tester eléctrico',
        'Multímetro','Crimpadoras','Pelacables','Termofusora / Pistola de calor',
        'Etiquetadora','Cepillos antiestáticos','Espuma Antiestática','Aire comprimido',
        'Alcohol isopropílico','Otros'
    ],
    'Seguridad' => [
        'Cámara IP','CCTV','DVR','NVR','Sensores de movimiento','Control de acceso',
        'Cerraduras electrónicas','Alarmas','Detectores de humo / temperatura',
        'UPS para CCTV','Cables coaxiales','Monitores de vigilancia','Otros'
    ],
    'Software y licencias' => [
        'Sistemas operativos','Licencias Office','Antivirus','Software de backup',
        'Software contable / ERP','Licencias de servidores','Herramientas de virtualización',
        'Licencias en la nube','Otros'
    ],
    'Telefonía' => [
        'Teléfonos IP','Teléfonos analógicos','Teléfonos inalámbricos (DECT)',
        'Central telefónica / PBX','Adaptadores ATA','Auriculares con micrófono (headset)',
        'Softphones / Aplicaciones de telefonía','Módulos FXO / FXS','VoIP Gateway',
        'Cables telefónicos','Baterías para teléfonos inalámbricos','Otros'
    ],
    'Electricidad' => [
        'Tomas y enchufes','Zapatillas eléctricas / Múltiples','Cables eléctricos',
        'Disyuntores / térmicas','Luces de emergencia','Lámparas / iluminación LED',
        'Transformadores','Regletas con protección','Estabilizadores de tensión',
        'UPS','Baterías','Pilas','Otros'
    ],
    'Inmobiliario / Muebles' => [
        'Escritorios','Sillas ergonómicas','Sillas de oficina','Mesas de trabajo',
        'Archiveros / Cajas de almacenamiento','Estanterías','Racks','Soportes para monitor',
        'Carros para equipos','Otros'
    ],
    'Stock de Repuestos' => ['Campo a completar'],
    'Otros' => ['Otros']
];

$subOpcionesSoftware = [
    'Sistemas operativos' => ['Windows', 'Linux', 'macOS', 'Otro'],
    'Licencias Office' => ['Microsoft Office', 'Office 365', 'Otro'],
    'Antivirus' => ['ESET', 'Kaspersky', 'Norton', 'Avast', 'Otro'],
    'Software de backup' => ['Acronis', 'Veeam', 'Backup Exec', 'Otro'],
    'Software contable / ERP' => ['Tango', 'Bejerman', 'SAP', 'Otro'],
    'Licencias de servidores' => ['Windows Server', 'CALs', 'Linux Server', 'Otro'],
    'Herramientas de virtualización' => ['VMware', 'Hyper-V', 'Proxmox', 'VirtualBox', 'Otro'],
    'Licencias en la nube' => ['Google Workspace', 'Microsoft 365', 'AWS', 'Otro']
];

$subOpcionesSoftwareJson = json_encode($subOpcionesSoftware, JSON_UNESCAPED_UNICODE);

// ======================================================
// TOKEN CSRF PARA EL FORM DE EDICIÓN (scope 'edit_producto')
// ======================================================
$csrf_token_edit = generateCsrfToken('edit_producto');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Activo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background: #f5f5f5; font-family: 'Saira', sans-serif; }
        .main-container {
            background: #fff;
            border-radius: 14px;
            max-width: 980px;
            margin: 2rem auto;
            padding: 2rem 2rem 1.5rem 2rem;
            box-shadow: 0 6px 20px rgba(0,0,0,.1);
        }
        .btn-violeta {
            background: #663399 !important;
            border-color: #663399 !important;
            color: #fff !important;
        }
        .btn-violeta:hover {
            background: #4E247A !important;
            border-color: #4E247A !important;
        }
        h2 { color: #4E247A; font-weight: bold; }
        label { color: #663399; font-weight: 600; }
        .form-control, .form-select { border-radius: 9px; }
        .btn-primary {
            background: #86B329;
            border-color: #86B329;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: #4E247A;
            border-color: #4E247A;
        }
        .footer {
            margin: 40px 0 0 0;
            text-align: center;
            color: #fff;
            background: #663399;
            border-radius: 0 0 14px 14px;
            padding: 18px 0;
        }
    </style>
</head>
<body>
<div class="main-container">
    <a href="/index.php" class="btn btn-violeta mb-3" style="background:#663399;color:white;border-radius:25px;font-weight:600;">
        <i class="bi bi-arrow-left"></i> Volver
    </a>

    <h2><i class="bi bi-pencil-square"></i> Editar Activo: <?= htmlspecialchars($producto['modelo'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>

    <form id="form-editar" action="" method="POST" enctype="multipart/form-data">
        <!-- CSRF -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_edit, ENT_QUOTES, 'UTF-8') ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label for="asignado_a">Asignado a</label>
                <input type="text" class="form-control" name="asignado_a" value="<?= htmlspecialchars($producto['asignado_a'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-6">
                <label for="modelo">Nombre/Modelo *</label>
                <input type="text" class="form-control" name="modelo" value="<?= htmlspecialchars($producto['modelo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="col-md-6">
                <label for="marca">Marca</label>
                <input type="text" class="form-control" name="marca" value="<?= htmlspecialchars($producto['marca'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-6">
                <label for="servis_tag">S/N Service Tag</label>
                <input type="text" class="form-control" name="servis_tag" id="servis_tag" value="<?= htmlspecialchars($producto['servis_tag'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-md-6" id="cantidad-container">
                <label for="cantidad">Cantidad *</label>
                <input type="number" class="form-control" name="cantidad" id="cantidad" min="1"
                       value="<?= htmlspecialchars($producto['cantidad'] ?? 1, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <!-- CATEGORÍA Y SUBCATEGORÍA -->
            <div class="col-md-6">
                <label for="categoria-select">Categoría <i id="icon-categoria" class="bi bi-folder ms-2"></i></label>
                <select class="form-select" name="categoria" id="categoria-select" required>
                    <option value="">Seleccione...</option>
                    <?php foreach($opcionesPorCategoria as $cat => $subs): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                            <?= (($producto['categoria'] ?? '') == $cat) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6" id="subcategoria-container" style="display:none;">
                <label for="subcategoria-select">Subcategoría <i id="icon-subcategoria" class="bi bi-folder ms-2"></i></label>
                <select id="subcategoria-select" name="subcategoria" class="form-select">
                    <option value="">Seleccione...</option>
                </select>
            </div>

            <!-- Sub-subcategoría Software -->
            <div class="col-md-6" id="subsoftware-container" style="display:none;">
                <label for="subsoftware-select">Detalle Licencia/Plataforma</label>
                <select id="subsoftware-select" name="subsoftware" class="form-select">
                    <option value="">Seleccione...</option>
                </select>
            </div>

            <!-- CAMPO OTROS SUB-SUBCATEGORÍA -->
            <div class="col-md-6" id="subsoftware-otros-container" style="display:none;">
                <label for="subsoftware-otros-input">Detalle "Otros"</label>
                <input type="text" name="subsoftware_otros" id="subsoftware-otros-input"
                       class="form-control"
                       value="<?= htmlspecialchars($producto['subsoftware_otros'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Escribí aquí el detalle...">
            </div>

            <!-- Patchcord -->
            <div id="patchcord-extra" class="row g-3 mt-3" style="display:none;">
                <div id="patchcord-categoria" class="col-md-4" style="display:none;">
                    <label class="form-label">Tipo de patchcord</label>
                    <select name="patchcord_tipo" id="patchcord-categoria-select" class="form-select">
                        <option value="">— Seleccione tipo —</option>
                        <option value="UTP" <?= (($producto['patchcord_tipo'] ?? '')==='UTP')?'selected':''; ?>>UTP</option>
                        <option value="STP" <?= (($producto['patchcord_tipo'] ?? '')==='STP')?'selected':''; ?>>STP</option>
                        <option value="FTP" <?= (($producto['patchcord_tipo'] ?? '')==='FTP')?'selected':''; ?>>FTP</option>
                    </select>
                </div>
                <div id="patchcord-longitud" class="col-md-4" style="display:none;">
                    <label class="form-label">Longitud</label>
                    <select name="patchcord_longitud" id="patchcord-longitud-select" class="form-select">
                        <option value="">— Seleccione longitud —</option>
                        <option value="1"   <?= (($producto['patchcord_longitud'] ?? '')==='1')?'selected':''; ?>>1 m</option>
                        <option value="3"   <?= (($producto['patchcord_longitud'] ?? '')==='3')?'selected':''; ?>>3 m</option>
                        <option value="5"   <?= (($producto['patchcord_longitud'] ?? '')==='5')?'selected':''; ?>>5 m</option>
                        <option value="10"  <?= (($producto['patchcord_longitud'] ?? '')==='10')?'selected':''; ?>>10 m</option>
                        <option value=">10" <?= (($producto['patchcord_longitud'] ?? '')==='>10')?'selected':''; ?>>>10 m</option>
                    </select>
                </div>
            </div>

            <!-- Switch -->
            <div id="switch-extra" class="row g-3 mt-3" style="display:none;">
                <div class="col-md-6">
                    <label class="form-label">Gestión</label>
                    <select name="switch_gestion" id="switch_gestion" class="form-select">
                        <option value="">Seleccione...</option>
                        <option value="No gestionado" <?= (($producto['switch_gestion'] ?? '')==='No gestionado')?'selected':''; ?>>No gestionado</option>
                        <option value="Gestionado"     <?= (($producto['switch_gestion'] ?? '')==='Gestionado')?'selected':''; ?>>Gestionado</option>
                    </select>
                </div>
            </div>

            <!-- Antenas -->
            <div id="antenas-extra" class="row g-3 mt-3" style="display:none;">
                <div class="col-md-6">
                    <label class="form-label">Tipo de antena</label>
                    <select name="antenas_tipo" id="antenas_tipo" class="form-select">
                        <option value="">Seleccione...</option>
                        <option value="Omnidireccional" <?= (($producto['antenas_tipo'] ?? '')==='Omnidireccional')?'selected':''; ?>>Omnidireccional</option>
                        <option value="Direccional"     <?= (($producto['antenas_tipo'] ?? '')==='Direccional')?'selected':''; ?>>Direccional</option>
                    </select>
                </div>
            </div>

            <!-- Bobina UTP -->
            <div id="bobina-extra" class="row g-3 mt-3" style="display:none;">
                <div id="bobina-categoria" class="col-md-4" style="display:none;">
                    <label class="form-label">Categoría cable</label>
                    <select name="bobina_categoria" id="bobina-categoria-select" class="form-select">
                        <option value="">— Seleccione categoría —</option>
                        <option value="CAT5e" <?= (($producto['bobina_categoria'] ?? '')==='CAT5e')?'selected':''; ?>>CAT5e</option>
                        <option value="CAT6e" <?= (($producto['bobina_categoria'] ?? '')==='CAT6e')?'selected':''; ?>>CAT6e</option>
                    </select>
                </div>
                <div id="bobina-ubicacion" class="col-md-4" style="display:none;">
                    <label class="form-label">Tipo</label>
                    <select name="bobina_ubicacion" id="bobina-ubicacion-select" class="form-select">
                        <option value="">— Interior/Exterior —</option>
                        <option value="Interior" <?= (($producto['bobina_ubicacion'] ?? '')==='Interior')?'selected':''; ?>>Interior</option>
                        <option value="Exterior" <?= (($producto['bobina_ubicacion'] ?? '')==='Exterior')?'selected':''; ?>>Exterior</option>
                    </select>
                </div>
                <div id="bobina-longitud" class="col-md-4" style="display:none;">
                    <label class="form-label">Longitud</label>
                    <select name="bobina_longitud" id="bobina-longitud-select" class="form-select">
                        <option value="">— Seleccione longitud —</option>
                        <option value="10"   <?= (($producto['bobina_longitud'] ?? '')==='10')?'selected':''; ?>>10 m</option>
                        <option value="25"   <?= (($producto['bobina_longitud'] ?? '')==='25')?'selected':''; ?>>25 m</option>
                        <option value="50"   <?= (($producto['bobina_longitud'] ?? '')==='50')?'selected':''; ?>>50 m</option>
                        <option value="100"  <?= (($producto['bobina_longitud'] ?? '')==='100')?'selected':''; ?>>100 m</option>
                        <option value=">100" <?= (($producto['bobina_longitud'] ?? '')==='>100')?'selected':''; ?>>>100 m</option>
                    </select>
                </div>
            </div>

            <!-- Conectores -->
            <div id="conectores-extra" class="row g-3 mt-3" style="display:none;">
                <div class="col-md-6">
                    <label class="form-label">Tipo de conector</label>
                    <select id="conectores-tipo-select" name="conectores_tipo" class="form-select">
                        <option value="">Seleccione...</option>
                        <option value="RJ45" <?= (($producto['conectores_tipo'] ?? '')==='RJ45')?'selected':''; ?>>RJ45</option>
                        <option value="SC"   <?= (($producto['conectores_tipo'] ?? '')==='SC')?'selected':''; ?>>SC</option>
                        <option value="LC"   <?= (($producto['conectores_tipo'] ?? '')==='LC')?'selected':''; ?>>LC</option>
                        <option value="Otros"<?= (($producto['conectores_tipo'] ?? '')==='Otros')?'selected':''; ?>>Otros</option>
                    </select>
                </div>
                <div id="conectores-otros" class="col-12" style="display:none;">
                    <label class="form-label">Especifique otro conector</label>
                    <input type="text" name="detalle_conectores" class="form-control"
                           value="<?= htmlspecialchars($producto['detalle_conectores'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Describa aquí…">
                </div>
            </div>

            <!-- Cintas de respaldo -->
            <div id="cintas-extra" class="row g-3 mt-3" style="display:none;">
                <div id="cintas-generacion" class="col-md-6" style="display:none;">
                    <label class="form-label">Generación LTO</label>
                    <select name="cintas_generacion" class="form-select">
                        <option value="">Seleccione...</option>
                        <option value="LTO-1" <?= (($producto['cintas_generacion'] ?? '')==='LTO-1')?'selected':''; ?>>LTO-1</option>
                        <option value="LTO-2" <?= (($producto['cintas_generacion'] ?? '')==='LTO-2')?'selected':''; ?>>LTO-2</option>
                        <option value="LTO-3" <?= (($producto['cintas_generacion'] ?? '')==='LTO-3')?'selected':''; ?>>LTO-3</option>
                        <option value="LTO-4" <?= (($producto['cintas_generacion'] ?? '')==='LTO-4')?'selected':''; ?>>LTO-4</option>
                        <option value="LTO-5" <?= (($producto['cintas_generacion'] ?? '')==='LTO-5')?'selected':''; ?>>LTO-5</option>
                    </select>
                </div>
            </div>

            <!-- Repuestos -->
            <div id="subcategoria-repuestos" class="row g-3 mt-3" style="display:none;">
                <div class="col-12">
                    <label class="form-label">Detalle Repuesto</label>
                    <input type="text" name="detalle_repuesto" class="form-control"
                           value="<?= htmlspecialchars($producto['detalle_repuesto'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <!-- Otros subcategoría -->
            <div id="subcategoria-otros" class="row g-3 mt-3" style="display:none;">
                <div class="col-12">
                    <label class="form-label">Detalle "Otros" <span class="text-muted"></span></label>
                    <input type="text" name="detalle_otros" class="form-control"
                           value="<?= htmlspecialchars($producto['detalle_otros'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Escribí aquí el detalle...">
                </div>
            </div>

            <!-- Otros por categoría/stock repuestos -->
            <div id="detalle-otros-categoria" class="row g-3 mt-3" style="display:none;">
                <div class="col-12">
                    <label class="form-label">
                        <?php
                        if (($producto['categoria'] ?? '') === 'Otros') {
                            echo 'Detalle del producto (Categoría "Otros")';
                        } elseif (($producto['categoria'] ?? '') === 'Stock de Repuestos') {
                            echo 'Detalle de repuesto (Stock de Repuestos)';
                        } else {
                            echo 'Detalle';
                        }
                        ?>
                        <span class="text-muted"></span>
                    </label>
                    <input type="text" name="detalle_otros" class="form-control"
                           value="<?= htmlspecialchars($producto['detalle_otros'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Escribí aquí el detalle...">
                </div>
            </div>

            <!-- Descripción -->
            <div class="col-12">
                <label for="descripcion">Descripción</label>
                <textarea class="form-control" name="descripcion" rows="3"><?= htmlspecialchars($producto['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- Estado, valor, fechas -->
            <div class="col-md-6">
                <label for="estado">Estado</label>
                <select class="form-select" name="estado">
                    <option value="Nuevo" <?= (($producto['estado'] ?? '')==='Nuevo')?'selected':''; ?>>Nuevo</option>
                    <option value="Usado" <?= (($producto['estado'] ?? '')==='Usado')?'selected':''; ?>>Usado</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="valor_usd">Valor en USD</label>
                <input type="number" class="form-control" name="valor_usd" step="0.01" min="0"
                       value="<?= htmlspecialchars($producto['valor_usd'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-md-4">
                <label for="periodo_garantia">Período de Garantía (meses)</label>
                <input type="number" class="form-control" name="periodo_garantia"
                       value="<?= htmlspecialchars($producto['periodo_garantia'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-4">
                <label for="fecha_garantia">Inicio Garantía</label>
                <input type="date" class="form-control" name="fecha_garantia"
                    value="<?= (!empty($producto['fecha_garantia']) && $producto['fecha_garantia'] != '0000-00-00')
                                ? htmlspecialchars($producto['fecha_garantia'], ENT_QUOTES, 'UTF-8') : '' ?>">
            </div>
            <div class="col-md-4">
                <label for="ubicacion">Ubicación</label>
                <input type="text" class="form-control" name="ubicacion"
                       value="<?= htmlspecialchars($producto['ubicacion'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-md-6">
                <label for="fecha_ingreso">Fecha de Ingreso</label>
                <input type="date" class="form-control" name="fecha_ingreso"
                       value="<?= (!empty($producto['fecha_ingreso']) && $producto['fecha_ingreso'] != '0000-00-00')
                                ? htmlspecialchars($producto['fecha_ingreso'], ENT_QUOTES, 'UTF-8') : '' ?>">
            </div>
            <div class="col-md-6">
                <label for="fecha_egreso">Fecha de Egreso</label>
                <input type="date" class="form-control" name="fecha_egreso"
                       value="<?= (!empty($producto['fecha_egreso']) && $producto['fecha_egreso'] != '0000-00-00')
                                ? htmlspecialchars($producto['fecha_egreso'], ENT_QUOTES, 'UTF-8') : '' ?>">
            </div>

            <div class="col-12">
                <label for="archivo">Subir Archivos</label>
                <input type="file" class="form-control" name="archivo[]" multiple id="file-input">
                <div class="form-text" style="color: #4E247A;">.jpg, .png, .pdf, .docx | máx 5MB</div>
            </div>

            <div class="col-12 d-flex justify-content-center mt-3">
                <button type="submit" class="btn btn-primary" style="border-radius: 30px; padding: 0.6rem 2rem;">
                    Actualizar Producto
                </button>
            </div>
        </div>
    </form>
</div>

<footer class="footer">© 2025 ITH - Todos los derechos reservados</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ======================================================
// JS DEL FORM (idéntico al tuyo, con mini retoques safe)
// ======================================================
const catSel = document.getElementById('categoria-select');
const subSel = document.getElementById('subcategoria-select');
const subCont = document.getElementById('subcategoria-container');
const iconCat = document.getElementById('icon-categoria');
const iconSub = document.getElementById('icon-subcategoria');
const detalleOtrosCategoria = document.getElementById('detalle-otros-categoria');
const subcategoriaOtros = document.getElementById('subcategoria-otros');
const cantidadContainer = document.getElementById('cantidad-container');
const servisTagInput = document.getElementById('servis_tag');
const opcionesPorCategoria = <?= json_encode($opcionesPorCategoria, JSON_UNESCAPED_UNICODE) ?>;
const iconMapJS = <?= json_encode($iconMap, JSON_UNESCAPED_UNICODE) ?>;
const producto = <?= json_encode($producto, JSON_UNESCAPED_UNICODE) ?>;
const subOpcionesSoftware = <?= $subOpcionesSoftwareJson ?>;

const subSoftwareContainer = document.getElementById('subsoftware-container');
const subSoftwareSelect = document.getElementById('subsoftware-select');
const subSoftwareOtrosContainer = document.getElementById('subsoftware-otros-container');
const subSoftwareOtrosInput = document.getElementById('subsoftware-otros-input');

function mostrarSubOpcionesSoftware(subcat, selected) {
    if (catSel.value === 'Software y licencias' && subOpcionesSoftware[subcat]) {
        subSoftwareContainer.style.display = 'block';
        subSoftwareSelect.innerHTML = '<option value="">Seleccione...</option>';
        subOpcionesSoftware[subcat].forEach(opt => {
            subSoftwareSelect.innerHTML += `<option value="${opt}"${selected===opt?' selected':''}>${opt}</option>`;
        });
        if (selected === "Otro") {
            subSoftwareOtrosContainer.style.display = 'block';
            subSoftwareOtrosInput.value = producto.subsoftware_otros || '';
        } else {
            subSoftwareOtrosContainer.style.display = 'none';
            subSoftwareOtrosInput.value = '';
        }
    } else {
        subSoftwareContainer.style.display = 'none';
        subSoftwareSelect.value = '';
        subSoftwareOtrosContainer.style.display = 'none';
        subSoftwareOtrosInput.value = '';
    }
}

subSoftwareSelect.addEventListener('change', function() {
    if (subSoftwareSelect.value === "Otro") {
        subSoftwareOtrosContainer.style.display = 'block';
        subSoftwareOtrosInput.value = '';
        subSoftwareOtrosInput.focus();
    } else {
        subSoftwareOtrosContainer.style.display = 'none';
        subSoftwareOtrosInput.value = '';
    }
});

// Validación de archivos
function validarArchivos() {
    const archivos = document.getElementById('file-input').files;
    const tipos = [
        'image/jpeg','image/png','image/webp','image/gif',
        'application/pdf','application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];
    for (let f of archivos) {
        if (f.size > 5*1024*1024) {
            alert(`El archivo "${f.name}" excede 5MB.`);
            return false;
        }
        if (!tipos.includes(f.type)) {
            alert(`El formato de "${f.name}" no está permitido.`);
            return false;
        }
    }
    return true;
}

document.getElementById('form-editar').addEventListener('submit', function(e){
    [
        'patchcord-categoria-select',
        'patchcord-longitud-select',
        'switch_gestion',
        'antenas_tipo',
        'bobina-categoria-select',
        'bobina-ubicacion-select',
        'bobina-longitud-select',
        'conectores-tipo-select',
        'cintas_generacion',
        'subsoftware-select',
    ].forEach(id => {
        let el = document.getElementById(id);
        if (el) el.disabled = false;
    });

    if (!validarArchivos()) {
        e.preventDefault();
        return false;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    function checkDetalleOtros() {
        if (catSel.value === 'Otros' || catSel.value === 'Stock de Repuestos') {
            detalleOtrosCategoria.style.display = 'block';
            subCont.style.display = 'none';
            subcategoriaOtros.style.display = 'none';
        } else {
            detalleOtrosCategoria.style.display = 'none';
            const lista = opcionesPorCategoria[catSel.value] || [];
            subCont.style.display = lista.length ? 'block' : 'none';
        }
    }

    if (producto.categoria) {
        catSel.value = producto.categoria;
        const lista = opcionesPorCategoria[producto.categoria] || [];
        subSel.innerHTML = '<option value="">Seleccione…</option>';
        lista.forEach(opt => subSel.append(new Option(opt, opt)));

        subCont.style.display = lista.length ? 'block' : 'none';

        if (producto.subcategoria) {
            subSel.value = producto.subcategoria;
            iconSub.className = iconMapJS[producto.subcategoria] || 'bi bi-folder ms-2';
            if (producto.categoria === "Software y licencias" && subOpcionesSoftware[producto.subcategoria]) {
                mostrarSubOpcionesSoftware(producto.subcategoria, producto.subsoftware || '');
            }
        }

        iconCat.className = iconMapJS[producto.categoria] || 'bi bi-folder ms-2';
        checkDetalleOtros();
    }

    mostrarExtras(producto.subcategoria);

    function toggleCantidad() {
        if (servisTagInput.value.trim()) {
            cantidadContainer.style.display = 'none';
        } else {
            cantidadContainer.style.display = 'block';
        }
    }
    servisTagInput.addEventListener('input', toggleCantidad);
    toggleCantidad();

    // Evento cambio de categoría
    catSel.addEventListener('change', function() {
        const lista = opcionesPorCategoria[catSel.value] || [];
        subSel.innerHTML = '<option value="">Seleccione…</option>';
        lista.forEach(opt => subSel.append(new Option(opt, opt)));

        subSel.value = '';
        iconCat.className = iconMapJS[catSel.value] || 'bi bi-folder ms-2';
        iconSub.className = 'bi bi-folder ms-2';
        subcategoriaOtros.style.display = 'none';
        checkDetalleOtros();
        mostrarExtras('');

        // Oculta subsoftware al cambiar categoría
        mostrarSubOpcionesSoftware('', '');
    });

    // Evento cambio de subcategoría
    subSel.addEventListener('change', function() {
        iconSub.className = iconMapJS[subSel.value] || 'bi bi-folder ms-2';
        mostrarExtras(subSel.value);

        let selected = '';
        if (catSel.value === 'Software y licencias' && subSel.value === producto.subcategoria) {
            selected = producto.subsoftware || '';
        }
        mostrarSubOpcionesSoftware(subSel.value, selected);

        if (subSel.value === 'Otros') {
            subcategoriaOtros.style.display = 'flex';
        } else {
            subcategoriaOtros.style.display = 'none';
        }
        checkDetalleOtros();
    });

    function mostrarExtras(subcat) {
        document.getElementById('patchcord-extra').style.display     = (subcat==='Patchcord') ? 'flex' : 'none';
        document.getElementById('switch-extra').style.display        = (subcat==='Switch') ? 'flex' : 'none';
        document.getElementById('antenas-extra').style.display       = (subcat==='Antenas') ? 'flex' : 'none';
        document.getElementById('bobina-extra').style.display        = (subcat==='Bobina UTP') ? 'flex' : 'none';
        document.getElementById('conectores-extra').style.display    = (subcat==='Conectores') ? 'flex' : 'none';
        document.getElementById('cintas-extra').style.display        = (subcat==='Cintas de respaldo') ? 'flex' : 'none';
        document.getElementById('subcategoria-repuestos').style.display = (subcat==='Repuestos') ? 'flex' : 'none';

        if (subcat==='Patchcord') {
            document.getElementById('patchcord-categoria').style.display = 'block';
            let patchcordTipo = document.getElementById('patchcord-categoria-select').value;
            if (!patchcordTipo && producto.patchcord_tipo) patchcordTipo = producto.patchcord_tipo;
            document.getElementById('patchcord-longitud').style.display = patchcordTipo ? 'block' : 'none';
        }

        if (subcat==='Bobina UTP') {
            document.getElementById('bobina-categoria').style.display = 'block';
            let cat = document.getElementById('bobina-categoria-select').value;
            let ubi = document.getElementById('bobina-ubicacion-select').value;
            if (!cat && producto.bobina_categoria)   cat = producto.bobina_categoria;
            if (!ubi && producto.bobina_ubicacion)   ubi = producto.bobina_ubicacion;
            document.getElementById('bobina-ubicacion').style.display = cat ? 'block' : 'none';
            document.getElementById('bobina-longitud').style.display  = ubi ? 'block' : 'none';
        }

        if (subcat==='Conectores') {
            let tipoConector = document.getElementById('conectores-tipo-select').value;
            if (!tipoConector && producto.conectores_tipo) tipoConector = producto.conectores_tipo;
            document.getElementById('conectores-otros').style.display = (tipoConector === 'Otros') ? 'block' : 'none';
        }

        if (subcat==='Cintas de respaldo') {
            document.getElementById('cintas-generacion').style.display = 'block';
        }
    }

    document.getElementById('patchcord-categoria-select')
        ?.addEventListener('change', function(e){
            document.getElementById('patchcord-longitud').style.display = e.target.value ? 'block' : 'none';
        });

    document.getElementById('conectores-tipo-select')
        ?.addEventListener('change', function(e){
            document.getElementById('conectores-otros').style.display = (e.target.value === 'Otros') ? 'block' : 'none';
        });

    document.getElementById('bobina-categoria-select')
        ?.addEventListener('change', function(e){
            document.getElementById('bobina-ubicacion').style.display = e.target.value ? 'block' : 'none';
            if (!e.target.value) {
                document.getElementById('bobina-longitud').style.display = 'none';
            }
        });

    document.getElementById('bobina-ubicacion-select')
        ?.addEventListener('change', function(e){
            document.getElementById('bobina-longitud').style.display = e.target.value ? 'block' : 'none';
        });

    setTimeout(function(){
        if (producto.subcategoria === 'Patchcord' && producto.patchcord_tipo)
            document.getElementById('patchcord-longitud').style.display = 'block';
        if (producto.subcategoria === 'Bobina UTP' && producto.bobina_categoria)
            document.getElementById('bobina-ubicacion').style.display = 'block';
        if (producto.subcategoria === 'Bobina UTP' && producto.bobina_ubicacion)
            document.getElementById('bobina-longitud').style.display = 'block';
        if (producto.subcategoria === 'Conectores' && producto.conectores_tipo === 'Otros')
            document.getElementById('conectores-otros').style.display = 'block';
        if (producto.subcategoria === 'Cintas de respaldo')
            document.getElementById('cintas-generacion').style.display = 'block';
    }, 200);
});
</script>
</body>
</html>
