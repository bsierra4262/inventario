<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/catalogos.php';
require_once __DIR__ . '/../core/tenants/infocom.php';
require_once __DIR__ . '/../includes/campo_catalogo.php';
require_once __DIR__ . '/../includes/campos_dinamicos.php';

$iconMap = require __DIR__ . '/../includes/iconMap.php';
require_once __DIR__ . '/../includes/cloud_icons.php';
$cloudUploadOk = cloud_upload_disponible($conn);

// Verificar permiso de editar activos
$_edit_uid  = (int)($_SESSION['user_id'] ?? 0);
$_edit_user = null;
if ($_edit_uid > 0) {
    $_edit_us   = tn('users');
    $_edit_stmt = $conn->prepare("SELECT role FROM `{$_edit_us}` WHERE id = ? LIMIT 1");
    $_edit_stmt->bind_param('i', $_edit_uid);
    $_edit_stmt->execute();
    $_edit_user = $_edit_stmt->get_result()->fetch_assoc();
    $_edit_stmt->close();
    require_permission('asset.edit', $_edit_user);
}
// ¿Puede ampliar las listas de "Asignado a" y "Ubicación"?
$puedeGestionarCatalogos = $_edit_user ? user_can('catalog.manage', $_edit_user) : false;

// ======================================================
// DETECCIÓN DEL PROVEEDOR DE ALMACENAMIENTO
// ======================================================
$storageProvider = strtolower((string)($_SESSION['storage_provider'] ?? 'google'));
// valores esperados: 'google' | 'microsoft'

// ======================================================
// CLIENTE GOOGLE (solo si provider = google)
// Lee credenciales desde la DB cloud_config
// ======================================================
$googleService  = null;
$parentFolderId = '';
// Los temporales del flujo de duplicados van a secure/tmp, que ya está bloqueado
// por secure/.htaccess (Require all denied). Antes iban a backend/temp/, dentro
// del web root y sin ningún .htaccess propio.
$tempDir        = __DIR__ . '/../secure/tmp';
if (!is_dir($tempDir)) { @mkdir($tempDir, 0700, true); }

if ($storageProvider === 'google') {
    // Plataforma como primario, tenant override vía cloud_config
    require_once __DIR__ . '/../core/config/platform.php';
    $googleClientId     = GOOGLE_CLIENT_ID;
    $googleClientSecret = GOOGLE_CLIENT_SECRET;
    $googleRedirectUri  = GOOGLE_REDIRECT_URI ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php');

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
        log_event((string)($_SESSION['user_id'] ?? '?'), 'WARN', 'cloud_config no disponible: ' . $e->getMessage(), 'transaccion');
    }

    if ($googleClientId && $googleClientSecret && isset($_SESSION['access_token'])) {
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
            $client->setAccessToken($_SESSION['access_token']);

            if ($client->isAccessTokenExpired()) {
                $refresh = $client->getRefreshToken();
                if ($refresh) {
                    $newToken = $client->fetchAccessTokenWithRefreshToken($refresh);
                    $_SESSION['access_token'] = $newToken;
                } else {
                    unset($_SESSION['access_token'], $_SESSION['google_authenticated']);
                    // Token expirado sin refresh → continuar sin Drive
                    $googleClientId = '';
                }
            }
            if ($googleClientId) {
                $googleService = new Google\Service\Drive($client);
            }
        } catch (Throwable $e) {
            log_event((string)($_SESSION['user_id'] ?? '?'), 'WARN', 'Error inicializando Drive en edit: ' . $e->getMessage(), 'transaccion');
        }
    }
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
$stmt = $conn->prepare("SELECT * FROM `" . tn('productos') . "` WHERE id = ?");
$stmt->bind_param('i', $producto_id);
$stmt->execute();
$producto = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Datos financieros y administrativos (Infocom). Array vacío si el esquema
// todavía no se migró: la pantalla funciona igual, sólo sin esa sección.
$infocom = infocom_de($conn, $producto_id);

if (!$producto) {
    log_event("usuario_desconocido","ERROR","Producto no encontrado ID:$producto_id","transaccion");
    header('Location: resultado.php?status=error&origen=editar&msg='
        .urlencode('Producto no encontrado.'));
    exit();
}

// ======================================================
// HELPERS DRIVE (GOOGLE). Para MS vamos a dejar TODO en blanco seguro.
// ======================================================

/**
 * Escapa un valor para interpolarlo dentro de una query `q` de la API de Drive.
 *
 * Los nombres de archivo y de carpeta se venían metiendo crudos entre comillas
 * simples: un activo llamado  x' or trashed=false or '  cambiaba el sentido de
 * la consulta y podía hacer coincidir archivos de otras carpetas del Drive.
 * La API pide escapar la barra invertida y la comilla simple.
 */
if (!function_exists('drive_q_escape')) {
    function drive_q_escape(string $valor): string {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $valor);
    }
}

/**
 * Devuelve el primer archivo de un listado de Drive, o null si no hay ninguno.
 *
 * `Google\Service\Drive\FileList` extiende `Google\Collection`, que implementa
 * `Iterator` y `Countable` pero NO `ArrayAccess`. Escribir `$lista[0]` lanzaba
 * "Cannot use object of type ... as array" — un error fatal que el navegador
 * muestra como HTTP 500. Era exactamente lo que rompía al guardar una edición
 * cuando el activo no tenía todavía carpeta en Drive.
 */
if (!function_exists('drive_primer_archivo')) {
    function drive_primer_archivo($lista): ?Google\Service\Drive\DriveFile {
        $archivos = $lista->getFiles();
        return $archivos ? $archivos[0] : null;
    }
}

if (!function_exists('drive_buscarOCrearCarpetaRaizProducto')) {
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

    $qName   = drive_q_escape($folderName);
    $qParent = drive_q_escape($parentFolderId);
    $existingFolders = $service->files->listFiles([
        'q' => "name = '{$qName}' and mimeType = 'application/vnd.google-apps.folder' and '{$qParent}' in parents and trashed = false",
        'fields' => 'files(id, name)',
        'spaces' => 'drive'
    ]);
    $encontrada = drive_primer_archivo($existingFolders);
    if ($encontrada !== null) {
        $carpeta_raiz_drive = $encontrada->getId();
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
    $updateFolder = $conn->prepare("UPDATE `" . tn('productos') . "` SET carpeta_raiz_drive = ? WHERE id = ?");
    $updateFolder->bind_param('si', $carpeta_raiz_drive, $producto_id);
    $updateFolder->execute();
    $updateFolder->close();

    return $carpeta_raiz_drive;
}
} // end function_exists drive_buscarOCrearCarpetaRaizProducto

if (!function_exists('drive_buscarOCrearSubcarpetaProducto')) {
function drive_buscarOCrearSubcarpetaProducto(
    Google\Service\Drive $service,
    string $carpeta_raiz_drive,
    string $servis_tag,
    int $producto_id
): string {
    $subFolderName = trim($servis_tag) !== '' ? $servis_tag : 'producto_' . $producto_id;

    $qSub    = drive_q_escape($subFolderName);
    $qParent = drive_q_escape($carpeta_raiz_drive);
    $subFolders = $service->files->listFiles([
        'q' => "name = '{$qSub}' and mimeType = 'application/vnd.google-apps.folder' and '{$qParent}' in parents and trashed = false",
        'fields' => 'files(id, name)',
        'spaces' => 'drive'
    ]);

    $sub = drive_primer_archivo($subFolders);
    if ($sub !== null) {
        return $sub->getId();
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
} // end function_exists drive_buscarOCrearSubcarpetaProducto

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

    // "Asignado a" y "Ubicación" salen de un catálogo: el <select> del formulario
    // no alcanza como control, cualquiera puede mandar otro valor por POST. Se
    // valida contra la lista; si no pertenece, se descarta. Se acepta el valor
    // que el activo ya tenía para no romper la edición de activos viejos.
    $valores['asignado_a'] = catalogo_normalizar($conn, 'asignado_a', $valores['asignado_a'] ?? '', $producto['asignado_a'] ?? null);
    $valores['ubicacion']  = catalogo_normalizar($conn, 'ubicacion',  $valores['ubicacion']  ?? '', $producto['ubicacion']  ?? null);
    $valores['estado']     = catalogo_normalizar($conn, 'estado',     $valores['estado']     ?? '', $producto['estado']     ?? null);

    // Los datos financieros y administrativos viven en su propia tabla, asi que
    // se guardan aparte del UPDATE de `productos`. Van siempre, haya o no
    // cambios en los campos del activo: editar solo el numero de factura tiene
    // que guardar igual.
    infocom_guardar($conn, $producto_id, $_POST);
    campos_guardar($conn, $producto_id,
        (string)($valores['categoria'] ?? ''), (string)($valores['subcategoria'] ?? ''), $_POST);

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
        // carpeta_raiz_drive en DB YA apunta a la subcarpeta especifica del activo
        // (creada por upload_to_drive.php). No hay que crear nada extra: los archivos
        // van directo ahi.
        if (!empty($producto['carpeta_raiz_drive'])) {
            $carpeta_raiz_drive  = $producto['carpeta_raiz_drive'];
            $subcarpeta_drive_id = $producto['carpeta_raiz_drive'];
        } else {
            // Legacy/importado sin carpeta — replicamos estructura de upload_to_drive:
            // Root tenant → Modelo-Marca → Servicetag/activo_X
            $modeloFolderId = drive_buscarOCrearCarpetaRaizProducto(
                $googleService,
                $parentFolderId,
                $valores,
                ['carpeta_raiz_drive' => ''] + $producto,
                $conn,
                $producto_id
            );
            $subcarpeta_drive_id = drive_buscarOCrearSubcarpetaProducto(
                $googleService,
                $modeloFolderId,
                $valores['servis_tag'] ?? ($producto['servis_tag'] ?? ''),
                $producto_id
            );
            $carpeta_raiz_drive = $subcarpeta_drive_id;

            // Persistir el ID de la subcarpeta del activo (coherente con upload_to_drive)
            $upd = $conn->prepare("UPDATE `" . tn('productos') . "` SET carpeta_raiz_drive = ? WHERE id = ?");
            $upd->bind_param('si', $carpeta_raiz_drive, $producto_id);
            $upd->execute();
            $upd->close();
        }

        // === 4.3 Procesar uploads (directo a la carpeta del activo) ===
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
                $qFile   = drive_q_escape($fileName);
                $qFolder = drive_q_escape($subcarpeta_drive_id);
                $filesList = $googleService->files->listFiles([
                    'q' => "name = '{$qFile}' and '{$qFolder}' in parents and trashed = false",
                    'fields' => 'files(id, name)',
                    'spaces' => 'drive'
                ]);

                $duplicado = drive_primer_archivo($filesList);
                if ($duplicado !== null) {
                    // Duplicado: lo guardamos temporal para que el usuario decida.
                    // El nombre en disco NO conserva el original: se guarda un hash
                    // sin extensión y fuera del web root. Antes se escribía
                    // "dup_<uniqid>_<nombre original>" dentro de backend/temp/, así
                    // que un polyglot (cabecera de imagen + código PHP) llamado
                    // .php pasaba el chequeo de MIME y quedaba servible por HTTP.
                    $uniqueName = bin2hex(random_bytes(16)) . '.bin';
                    $destPath   = $tempDir . '/' . $uniqueName;
                    move_uploaded_file($tmpPath, $destPath);

                    $archivos_duplicados[] = [
                        'file_name'   => $fileName,
                        'file_id'     => $duplicado->getId(),
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

    $stmt_old = $conn->prepare("SELECT * FROM `" . tn('productos') . "` WHERE id = ?");
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

        // El string de tipos se DERIVA del mapa de columnas, no se escribe a mano.
        //
        // La versión anterior lo tenía hardcodeado como
        //   'sssssssissssssidsssssssssssssssii'  → 33 caracteres para 32 parámetros
        // (el comentario de al lado incluso decía "(32 chars)"). En PHP 8 ese
        // desajuste lanza ArgumentCountError, que no está atrapado: fatal error y
        // el navegador muestra HTTP 500 justo al guardar la edición.
        //
        // Con el mapa, agregar o sacar una columna ajusta los tipos solo.
        $columnas = [
            'modelo'             => 's',
            'marca'              => 's',
            'descripcion'        => 's',
            'servis_tag'         => 's',
            'estado'             => 's',
            'categoria'          => 's',
            'subcategoria'       => 's',
            'periodo_garantia'   => 'i',
            'fecha_garantia'     => 's',
            'ubicacion'          => 's',
            'fecha_ingreso'      => 's',
            'fecha_egreso'       => 's',
            'asignado_a'         => 's',
            'cantidad'           => 'i',
            'valor_usd'          => 'd',
            'detalle_repuesto'   => 's',
            'detalle_otros'      => 's',
            'patchcord_tipo'     => 's',
            'patchcord_longitud' => 's',
            'switch_gestion'     => 's',
            'antenas_tipo'       => 's',
            'bobina_categoria'   => 's',
            'bobina_ubicacion'   => 's',
            'bobina_longitud'    => 's',
            'conectores_tipo'    => 's',
            'detalle_conectores' => 's',
            'cintas_generacion'  => 's',
            'subsoftware'        => 's',
            'subsoftware_otros'  => 's',
            'detalle_tecnico'    => 's',
            'archivado'          => 'i',
        ];

        $asignaciones = [];
        foreach (array_keys($columnas) as $col) { $asignaciones[] = "`{$col}` = ?"; }
        $sql = "UPDATE `" . tn('productos') . "` SET " . implode(', ', $asignaciones) . " WHERE id = ?";

        $upd = $conn->prepare($sql);
        if (!$upd) {
            log_event("UsuarioID:{$_SESSION['user_id']}", "ERROR",
                "Prepare update ID:$producto_id - " . $conn->error, "transaccion");
            header('Location: resultado.php?status=error&origen=editar&msg='
                . urlencode('Error al preparar la actualización.'));
            exit();
        }

        $tipos  = implode('', array_values($columnas)) . 'i';   // + id del WHERE
        $params = [];
        foreach ($columnas as $col => $_t) { $params[] = $valores[$col] ?? null; }
        $params[] = $producto_id;

        $upd->bind_param($tipos, ...$params);

        if (!$upd->execute()) {
            log_event("UsuarioID:{$_SESSION['user_id']}", "ERROR",
                "Fallo update ID:$producto_id - " . $upd->error, "transaccion");
            header('Location: resultado.php?status=error&origen=editar&msg='
                . urlencode('Error al actualizar: ' . $upd->error));
            exit();
        }
        $upd->close();

        // El texto de la ubicación ya quedó guardado; acá se deja el enlace al
        // nodo del árbol, que es lo que permite filtrar por rama.
        ubicacion_sincronizar_id($conn, $producto_id, (string)($valores['ubicacion'] ?? ''));

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

// ── Layout vars ────────────────────────────────────────────────────────────
require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG])) {
    header('Location: /dashboard/dashboard_superadmin.php'); exit;
}

$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $mdbE = new MasterDB();
    $cidE = (int)($_SESSION['company_id'] ?? 0);
    if ($cidE > 0) {
        $csE = $mdbE->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$cidE]);
        $crE = $csE->get_result()->fetch_assoc();
        if ($crE) $tenant_name = $crE['nombre'];
        $csE->close();
    }
} catch (Throwable) {}

$nombreModelo    = $producto['modelo'] ?? 'Activo #' . $producto_id;
$page_title      = 'Editar · ' . $nombreModelo;
$page_section    = 'inventario';
$topbar_title    = 'Editar activo';
$topbar_subtitle = $nombreModelo;
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  =
    '<a href="/backend/view_details.php?id=' . $producto_id . '" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-eye"></i> <span class="ih-hidden-mobile">Ver</span></a>'
    . ' <a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>
<style>
.edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 20px; }
@media(max-width:640px){ .edit-grid { grid-template-columns:1fr; } }
.edit-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 20px; }
@media(max-width:640px){ .edit-grid-3 { grid-template-columns:1fr; } }
.edit-full { grid-column: 1 / -1; }
.edit-section-title {
    font-size: var(--ih-text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ih-gray-500);
    margin: 1.25rem 0 .75rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--ih-gray-100);
}
.edit-section-title:first-child { margin-top: 0; }
</style>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">
  <div class="ih-card">
    <div class="ih-card__body">

      <form id="form-editar" action="" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_edit, ENT_QUOTES, 'UTF-8') ?>">

        <!-- ── Datos principales ─────────────────────────────────────── -->
        <div class="edit-section-title"><i class="bi bi-person-badge"></i> Datos principales</div>
        <div class="edit-grid">
          <div class="ih-field">
            <label class="ih-field__label" for="f-asignado_a">Asignado a</label>
            <?php campo_catalogo($conn, 'asignado_a', $producto['asignado_a'] ?? '', $puedeGestionarCatalogos); ?>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Nombre/Modelo <span class="req">*</span></label>
            <input type="text" class="ih-input" name="modelo"
                   value="<?= htmlspecialchars($producto['modelo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Marca</label>
            <input type="text" class="ih-input" name="marca"
                   value="<?= htmlspecialchars($producto['marca'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">S/N Service Tag</label>
            <input type="text" class="ih-input" name="servis_tag" id="servis_tag"
                   value="<?= htmlspecialchars($producto['servis_tag'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field edit-full">
            <label class="ih-field__label">Descripción</label>
            <textarea class="ih-input" name="descripcion" rows="3"><?= htmlspecialchars($producto['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
        </div>

        <!-- ── Categorización ────────────────────────────────────────── -->
        <div class="edit-section-title"><i class="bi bi-folder2-open"></i> Categorización</div>
        <div class="edit-grid">
          <div class="ih-field">
            <label class="ih-field__label">Categoría <span class="req">*</span> <i id="icon-categoria" class="bi bi-folder ms-1"></i></label>
            <select class="ih-select" name="categoria" id="categoria-select" required>
              <option value="">Seleccione...</option>
              <?php foreach($opcionesPorCategoria as $cat => $subs): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                    <?= (($producto['categoria'] ?? '') === $cat) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field" id="subcategoria-container" style="display:none;">
            <label class="ih-field__label">Subcategoría <i id="icon-subcategoria" class="bi bi-folder ms-1"></i></label>
            <select id="subcategoria-select" name="subcategoria" class="ih-select">
              <option value="">Seleccione...</option>
            </select>
          </div>
          <div class="ih-field" id="subsoftware-container" style="display:none;">
            <label class="ih-field__label">Detalle Licencia/Plataforma</label>
            <select id="subsoftware-select" name="subsoftware" class="ih-select">
              <option value="">Seleccione...</option>
            </select>
          </div>
          <div class="ih-field" id="subsoftware-otros-container" style="display:none;">
            <label class="ih-field__label">Detalle "Otros"</label>
            <input type="text" name="subsoftware_otros" id="subsoftware-otros-input" class="ih-input"
                   value="<?= htmlspecialchars($producto['subsoftware_otros'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Escribí aquí el detalle...">
          </div>

          <!-- Cantidad (oculto si hay S/N) -->
          <div class="ih-field" id="cantidad-container">
            <label class="ih-field__label">Cantidad <span class="req">*</span></label>
            <input type="number" class="ih-input" name="cantidad" id="cantidad" min="1"
                   value="<?= htmlspecialchars((string)($producto['cantidad'] ?? 1), ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>

        <!-- ── Extras por subcategoría ─────────────────────────────── -->
        <div id="patchcord-extra" style="display:none;">
          <div class="edit-grid">
            <div class="ih-field" id="patchcord-categoria">
              <label class="ih-field__label">Tipo de Patchcord</label>
              <select name="patchcord_tipo" id="patchcord-categoria-select" class="ih-select">
                <option value="">— Tipo —</option>
                <option value="UTP" <?= ($producto['patchcord_tipo']??'')==='UTP'?'selected':'';?>>UTP</option>
                <option value="STP" <?= ($producto['patchcord_tipo']??'')==='STP'?'selected':'';?>>STP</option>
                <option value="FTP" <?= ($producto['patchcord_tipo']??'')==='FTP'?'selected':'';?>>FTP</option>
              </select>
            </div>
            <div class="ih-field" id="patchcord-longitud" style="display:none;">
              <label class="ih-field__label">Longitud</label>
              <select name="patchcord_longitud" id="patchcord-longitud-select" class="ih-select">
                <option value="">— Longitud —</option>
                <option value="1"   <?= ($producto['patchcord_longitud']??'')==='1'?'selected':'';?>>1 m</option>
                <option value="3"   <?= ($producto['patchcord_longitud']??'')==='3'?'selected':'';?>>3 m</option>
                <option value="5"   <?= ($producto['patchcord_longitud']??'')==='5'?'selected':'';?>>5 m</option>
                <option value="10"  <?= ($producto['patchcord_longitud']??'')==='10'?'selected':'';?>>10 m</option>
                <option value=">10" <?= ($producto['patchcord_longitud']??'')==='>10'?'selected':'';?>>>10 m</option>
              </select>
            </div>
          </div>
        </div>

        <div id="switch-extra" style="display:none;">
          <div class="edit-grid">
            <div class="ih-field">
              <label class="ih-field__label">Gestión del Switch</label>
              <select name="switch_gestion" id="switch_gestion" class="ih-select">
                <option value="">Seleccione...</option>
                <option value="No gestionado" <?= ($producto['switch_gestion']??'')==='No gestionado'?'selected':'';?>>No gestionado</option>
                <option value="Gestionado"     <?= ($producto['switch_gestion']??'')==='Gestionado'?'selected':'';?>>Gestionado</option>
              </select>
            </div>
          </div>
        </div>

        <div id="antenas-extra" style="display:none;">
          <div class="edit-grid">
            <div class="ih-field">
              <label class="ih-field__label">Tipo de Antena</label>
              <select name="antenas_tipo" id="antenas_tipo" class="ih-select">
                <option value="">Seleccione...</option>
                <option value="Omnidireccional" <?= ($producto['antenas_tipo']??'')==='Omnidireccional'?'selected':'';?>>Omnidireccional</option>
                <option value="Direccional"     <?= ($producto['antenas_tipo']??'')==='Direccional'?'selected':'';?>>Direccional</option>
              </select>
            </div>
          </div>
        </div>

        <div id="bobina-extra" style="display:none;">
          <div class="edit-grid-3">
            <div class="ih-field" id="bobina-categoria">
              <label class="ih-field__label">Categoría cable</label>
              <select name="bobina_categoria" id="bobina-categoria-select" class="ih-select">
                <option value="">— Categoría —</option>
                <option value="CAT5e" <?= ($producto['bobina_categoria']??'')==='CAT5e'?'selected':'';?>>CAT5e</option>
                <option value="CAT6e" <?= ($producto['bobina_categoria']??'')==='CAT6e'?'selected':'';?>>CAT6e</option>
              </select>
            </div>
            <div class="ih-field" id="bobina-ubicacion" style="display:none;">
              <label class="ih-field__label">Tipo (Interior/Exterior)</label>
              <select name="bobina_ubicacion" id="bobina-ubicacion-select" class="ih-select">
                <option value="">— Tipo —</option>
                <option value="Interior" <?= ($producto['bobina_ubicacion']??'')==='Interior'?'selected':'';?>>Interior</option>
                <option value="Exterior" <?= ($producto['bobina_ubicacion']??'')==='Exterior'?'selected':'';?>>Exterior</option>
              </select>
            </div>
            <div class="ih-field" id="bobina-longitud" style="display:none;">
              <label class="ih-field__label">Longitud</label>
              <select name="bobina_longitud" id="bobina-longitud-select" class="ih-select">
                <option value="">— Longitud —</option>
                <option value="10"  <?= ($producto['bobina_longitud']??'')==='10'?'selected':'';?>>10 m</option>
                <option value="25"  <?= ($producto['bobina_longitud']??'')==='25'?'selected':'';?>>25 m</option>
                <option value="50"  <?= ($producto['bobina_longitud']??'')==='50'?'selected':'';?>>50 m</option>
                <option value="100" <?= ($producto['bobina_longitud']??'')==='100'?'selected':'';?>>100 m</option>
                <option value=">100"<?= ($producto['bobina_longitud']??'')==='>100'?'selected':'';?>>>100 m</option>
              </select>
            </div>
          </div>
        </div>

        <div id="conectores-extra" style="display:none;">
          <div class="edit-grid">
            <div class="ih-field">
              <label class="ih-field__label">Tipo de Conector</label>
              <select id="conectores-tipo-select" name="conectores_tipo" class="ih-select">
                <option value="">Seleccione...</option>
                <option value="RJ45" <?= ($producto['conectores_tipo']??'')==='RJ45'?'selected':'';?>>RJ45</option>
                <option value="SC"   <?= ($producto['conectores_tipo']??'')==='SC'?'selected':'';?>>SC</option>
                <option value="LC"   <?= ($producto['conectores_tipo']??'')==='LC'?'selected':'';?>>LC</option>
                <option value="Otros"<?= ($producto['conectores_tipo']??'')==='Otros'?'selected':'';?>>Otros</option>
              </select>
            </div>
            <div class="ih-field" id="conectores-otros" style="display:none;">
              <label class="ih-field__label">Especifique conector</label>
              <input type="text" name="detalle_conectores" class="ih-input"
                     value="<?= htmlspecialchars($producto['detalle_conectores'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="Describa aquí…">
            </div>
          </div>
        </div>

        <div id="cintas-extra" style="display:none;">
          <div class="edit-grid">
            <div class="ih-field" id="cintas-generacion" style="display:none;">
              <label class="ih-field__label">Generación LTO</label>
              <select name="cintas_generacion" class="ih-select">
                <option value="">Seleccione...</option>
                <?php foreach(['LTO-1','LTO-2','LTO-3','LTO-4','LTO-5'] as $lto): ?>
                  <option value="<?= $lto ?>" <?= ($producto['cintas_generacion']??'')===$lto?'selected':'';?>><?= $lto ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div id="subcategoria-repuestos" style="display:none;">
          <div class="ih-field">
            <label class="ih-field__label">Detalle Repuesto</label>
            <input type="text" name="detalle_repuesto" class="ih-input"
                   value="<?= htmlspecialchars($producto['detalle_repuesto'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>

        <div id="subcategoria-otros" style="display:none;">
          <div class="ih-field">
            <label class="ih-field__label">Detalle "Otros"</label>
            <input type="text" name="detalle_otros" class="ih-input"
                   value="<?= htmlspecialchars($producto['detalle_otros'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Escribí aquí el detalle...">
          </div>
        </div>

        <div id="detalle-otros-categoria" style="display:none;">
          <div class="ih-field">
            <label class="ih-field__label">
              <?php
              if (($producto['categoria'] ?? '') === 'Otros') echo 'Detalle (Categoría "Otros")';
              elseif (($producto['categoria'] ?? '') === 'Stock de Repuestos') echo 'Detalle Repuesto';
              else echo 'Detalle';
              ?>
            </label>
            <input type="text" name="detalle_otros" class="ih-input"
                   value="<?= htmlspecialchars($producto['detalle_otros'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Escribí aquí el detalle...">
          </div>
        </div>

        <!-- ── Estado y valores ──────────────────────────────────────── -->
        <div class="edit-section-title"><i class="bi bi-currency-dollar"></i> Estado y valores</div>
        <div class="edit-grid">
          <div class="ih-field">
            <?php /* Estaba fijo en Nuevo/Usado. Ahora es catálogo: cada empresa
                     define su escala (en uso, en depósito, en reparación, de baja...). */ ?>
            <label class="ih-field__label" for="f-estado">Estado</label>
            <?php campo_catalogo($conn, 'estado', $producto['estado'] ?? '', $puedeGestionarCatalogos); ?>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Valor en USD</label>
            <input type="number" class="ih-input" name="valor_usd" step="0.01" min="0"
                   value="<?= htmlspecialchars((string)($producto['valor_usd'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label" for="f-ubicacion">Ubicación</label>
            <?php campo_catalogo($conn, 'ubicacion', $producto['ubicacion'] ?? '', $puedeGestionarCatalogos); ?>
          </div>
        </div>

        <!-- ── Garantía y fechas ─────────────────────────────────────── -->
        <div class="edit-section-title"><i class="bi bi-calendar3"></i> Garantía y fechas</div>
        <div class="edit-grid-3">
          <div class="ih-field">
            <label class="ih-field__label">Período garantía (meses)</label>
            <input type="number" class="ih-input" name="periodo_garantia"
                   value="<?= htmlspecialchars((string)($producto['periodo_garantia'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Inicio garantía</label>
            <input type="date" class="ih-input" name="fecha_garantia"
                   value="<?= (!empty($producto['fecha_garantia']) && $producto['fecha_garantia'] !== '0000-00-00')
                               ? htmlspecialchars($producto['fecha_garantia'], ENT_QUOTES, 'UTF-8') : '' ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Fecha de Ingreso</label>
            <input type="date" class="ih-input" name="fecha_ingreso"
                   value="<?= (!empty($producto['fecha_ingreso']) && $producto['fecha_ingreso'] !== '0000-00-00')
                               ? htmlspecialchars($producto['fecha_ingreso'], ENT_QUOTES, 'UTF-8') : '' ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Fecha de Egreso</label>
            <input type="date" class="ih-input" name="fecha_egreso"
                   value="<?= (!empty($producto['fecha_egreso']) && $producto['fecha_egreso'] !== '0000-00-00')
                               ? htmlspecialchars($producto['fecha_egreso'], ENT_QUOTES, 'UTF-8') : '' ?>">
          </div>
        </div>

        <!-- ── Archivos ──────────────────────────────────────────────── -->
                <!-- ── Informacion financiera y administrativa (Infocom) ── -->
        <?php /* Campos especificos de la categoria definidos en datos. */ ?>
        <?php ob_start(); campos_dinamicos_render($conn, campos_valores_de($conn, $producto_id, $producto)); $camposHtml = ob_get_clean(); ?>
        <?php if (str_contains($camposHtml, 'campos-dinamicos__grupo')): ?>
          <div class="edit-section-title"><i class="bi bi-sliders"></i> Detalles de la categoría</div>
          <?= $camposHtml ?>
        <?php endif; ?>

        <div class="edit-section-title"><i class="bi bi-receipt"></i> Compra y administración</div>
        <div class="edit-grid-3">
          <div class="ih-field">
            <label class="ih-field__label">N.º de pedido</label>
            <input type="text" class="ih-input" name="numero_pedido" maxlength="120"
                   value="<?= htmlspecialchars((string)($infocom['numero_pedido'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">N.º de remito</label>
            <input type="text" class="ih-input" name="numero_remito" maxlength="120"
                   value="<?= htmlspecialchars((string)($infocom['numero_remito'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">N.º de factura</label>
            <input type="text" class="ih-input" name="numero_factura" maxlength="120"
                   value="<?= htmlspecialchars((string)($infocom['numero_factura'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">N.º contable</label>
            <input type="text" class="ih-input" name="numero_contable" maxlength="120"
                   value="<?= htmlspecialchars((string)($infocom['numero_contable'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label" for="f-proveedor">Proveedor</label>
            <?php campo_catalogo($conn, 'proveedor', $infocom['proveedor'] ?? '', $puedeGestionarCatalogos); ?>
          </div>
          <div class="ih-field">
            <label class="ih-field__label" for="f-presupuesto">Presupuesto</label>
            <?php campo_catalogo($conn, 'presupuesto', $infocom['presupuesto'] ?? '', $puedeGestionarCatalogos); ?>
          </div>
        </div>

        <div class="edit-grid-3">
          <div class="ih-field">
            <label class="ih-field__label">Fecha de orden</label>
            <input type="date" class="ih-input" name="fecha_orden"
                   value="<?= htmlspecialchars((string)($infocom['fecha_orden'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Fecha de entrega</label>
            <input type="date" class="ih-input" name="fecha_entrega"
                   value="<?= htmlspecialchars((string)($infocom['fecha_entrega'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Puesta en servicio</label>
            <input type="date" class="ih-input" name="fecha_puesta_servicio"
                   value="<?= htmlspecialchars((string)($infocom['fecha_puesta_servicio'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Último inventario físico</label>
            <input type="date" class="ih-input" name="fecha_ultimo_inventario"
                   value="<?= htmlspecialchars((string)($infocom['fecha_ultimo_inventario'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Fecha de decomiso</label>
            <input type="date" class="ih-input" name="fecha_decomiso"
                   value="<?= htmlspecialchars((string)($infocom['fecha_decomiso'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label" for="f-criticidad">Criticidad de negocio</label>
            <?php campo_catalogo($conn, 'criticidad', $infocom['criticidad'] ?? '', $puedeGestionarCatalogos); ?>
          </div>
        </div>

        <div class="edit-section-title"><i class="bi bi-graph-down-arrow"></i> Garantía extendida y amortización</div>
        <div class="edit-grid-3">
          <div class="ih-field">
            <label class="ih-field__label">Datos de la garantía</label>
            <input type="text" class="ih-input" name="garantia_info" maxlength="255"
                   value="<?= htmlspecialchars((string)($infocom['garantia_info'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Valor extensión garantía (USD)</label>
            <input type="number" class="ih-input" name="valor_extension_garantia" step="0.01" min="0"
                   value="<?= htmlspecialchars((string)($infocom['valor_extension_garantia'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Años de amortización</label>
            <input type="number" class="ih-input" name="amortizacion_anios" step="1" min="0"
                   value="<?= htmlspecialchars((string)($infocom['amortizacion_anios'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <span class="ih-field__hint">Si queda vacío, el reporte usa el plazo general</span>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Tipo de amortización</label>
            <select class="ih-select" name="amortizacion_tipo">
              <option value="">—</option>
              <?php foreach (INFOCOM_TIPOS_AMORTIZACION as $k => $lbl): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= ($infocom['amortizacion_tipo'] ?? '') === $k ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Coeficiente</label>
            <input type="number" class="ih-input" name="amortizacion_coeficiente" step="0.001" min="0"
                   value="<?= htmlspecialchars((string)($infocom['amortizacion_coeficiente'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Comentarios administrativos</label>
            <input type="text" class="ih-input" name="comentarios_infocom" maxlength="255"
                   value="<?= htmlspecialchars((string)($infocom['comentarios'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>

<div class="edit-section-title"><i class="bi bi-paperclip"></i> Archivos adjuntos</div>
        <?php if (!$cloudUploadOk): ?>
          <?= cloud_upload_disabled_notice($_SESSION['role'] ?? '') ?>
        <?php else: ?>
          <div class="ih-field">
            <label class="ih-field__label">Subir archivos a <?= strtolower(cloud_provider_actual($conn)) === 'microsoft' ? 'OneDrive' : 'Google Drive' ?></label>
            <input type="file" class="ih-input" name="archivo[]" multiple id="file-input">
            <span class="ih-field__hint">.jpg, .png, .pdf, .docx, .xlsx, .pptx · máx 5 MB por archivo</span>
          </div>
        <?php endif; ?>

        <!-- ── Botón submit ──────────────────────────────────────────── -->
        <div style="display:flex;gap:12px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
          <button type="submit" class="ih-btn ih-btn--primary ih-btn--lg">
            <i class="bi bi-check-circle"></i> Guardar cambios
          </button>
          <a href="/backend/view_details.php?id=<?= $producto_id ?>" class="ih-btn ih-btn--ghost ih-btn--lg">
            Cancelar
          </a>
        </div>
      </form>

    </div><!-- /card__body -->
  </div><!-- /card -->
</main>
</div><!-- /ih-app -->
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
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
