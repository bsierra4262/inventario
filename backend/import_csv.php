<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Bloqueo acceso si no hay sesión válida
if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php'; // conexión del tenant actual
require_once __DIR__ . '/../core/security/permissions.php';

$user_id   = $_SESSION['user_id'];

// Verificar permiso de importar CSV
$_imp_us = tn('users');
$_imp_stmt = $conn->prepare("SELECT role FROM `{$_imp_us}` WHERE id = ? LIMIT 1");
$_imp_stmt->bind_param('i', $user_id);
$_imp_stmt->execute();
$_imp_user = $_imp_stmt->get_result()->fetch_assoc();
$_imp_stmt->close();
require_permission('asset.import_csv', $_imp_user);
$tenant_id = $_SESSION['tenant_id'] ?? null; // multiempresa futuro

// Permitimos SOLO POST con archivo
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_FILES['csv_file'])
    || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK
) {
    $_SESSION['mensaje'] =
        '<strong><i class="bi bi-x-circle-fill"></i> Error:</strong> No se pudo subir o procesar el archivo CSV.';
    header('Location: resultado.php?status=error&origen=importar');
    exit();
}

// CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf, 'import_csv')) {
    log_event((string)$user_id, 'CSRF_BLOCK', 'Intento de importación CSV con CSRF inválido', 'seguridad');
    $_SESSION['mensaje'] =
        '<strong><i class="bi bi-x-circle-fill"></i> Error:</strong> Seguridad CSRF inválida.';
    header('Location: resultado.php?status=error&origen=importar');
    exit();
}

// Conversor de fecha a YYYY-MM-DD
function normalizar_fecha(?string $fechaRaw): ?string {
    if (!$fechaRaw) return null;
    $fechaRaw = trim($fechaRaw);

    // yyyy-mm-dd ya válido
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)) {
        return $fechaRaw;
    }

    // d/m/yyyy o dd/mm/yyyy
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fechaRaw, $m)) {
        $d = (int)$m[1];
        $M = (int)$m[2];
        $Y = (int)$m[3];
        return sprintf('%04d-%02d-%02d', $Y, $M, $d);
    }

    // si viene vacío o basura, devolvemos null
    return null;
}

// Definimos las columnas esperadas en términos de la BD
// Estas claves son los nombres reales de columnas en `productos`
$columnMap = [
    'asignado_a'         => ['asignado a','asignado_a','asignado'],
    'modelo'             => ['nombre/modelo','modelo','nombre','marca/modelo'],
    'marca'              => ['marca','brand'],
    'descripcion'        => ['descripcion','descripción','desc'],
    'servis_tag'         => ['servis tag','service tag','servistag','servicetag','s/n service tag','s/n'],
    'cantidad'           => ['cantidad','qty','cant'],
    'valor_usd'          => ['valor usd','usd','valor','precio usd','precio'],
    'estado'             => ['estado','condicion','condición'],
    'categoria'          => ['categoria','categoría'],
    'subcategoria'       => ['subcategoria','sub-categoria','sub categoría'],
    'periodo_garantia'   => ['garantia','garantía','periodo garantia','garantia (meses)','periodo de garantia'],
    'fecha_garantia'     => ['fecha garantia','inicio garantia','fecha de garantia','fecha_garantia'],
    'fecha_ingreso'      => ['ingreso','fecha ingreso','fecha_ingreso'],
    'fecha_egreso'       => ['egreso','fecha egreso','fecha_egreso'],
    'ubicacion'          => ['ubicacion','ubicación','location'],
    'patchcord_tipo'     => ['patchcord_tipo'],
    'patchcord_longitud' => ['patchcord_longitud'],
    'switch_gestion'     => ['switch_gestion','gestion','gestión switch'],
    'antenas_tipo'       => ['antenas_tipo','tipo antena'],
    'bobina_categoria'   => ['bobina_categoria','categoria bobina','cat cable'],
    'bobina_ubicacion'   => ['bobina_ubicacion','ubicacion bobina','interior/exterior'],
    'bobina_longitud'    => ['bobina_longitud','longitud bobina','mts bobina'],
    'conectores_tipo'    => ['conectores_tipo','tipo conector'],
    'detalle_conectores' => ['detalle_conectores','detalle conectores'],
    'cintas_generacion'  => ['cintas_generacion','generacion cinta','lto'],
    'detalle_repuesto'   => ['detalle_repuesto','detalle repuesto'],
    'detalle_otros'      => ['detalle_otros','detalle otros','otros detalle'],
    'subsoftware'        => ['subsoftware','licencia','plataforma'],
    'subsoftware_otros'  => ['subsoftware_otros','subsoftware otros','detalle licencia otros']
];

// Función para normalizar nombres de columnas CSV
function norm_col(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = preg_replace('/[^a-z0-9\/ ]/', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

// Abrimos el CSV
$fileTmpPath = $_FILES['csv_file']['tmp_name'];
$handle = fopen($fileTmpPath, 'r');
if (!$handle) {
    $_SESSION['mensaje'] =
        '<strong><i class="bi bi-x-circle-fill"></i> Error:</strong> No se pudo abrir el archivo.';
    header('Location: resultado.php?status=error&origen=importar');
    exit();
}

// Leemos cabecera
$headers = fgetcsv($handle, 2000, ',');
if (!$headers) {
    $_SESSION['mensaje'] =
        '<strong><i class="bi bi-x-circle-fill"></i> Error:</strong> No se pudieron leer los encabezados del CSV.';
    header('Location: resultado.php?status=error&origen=importar');
    exit();
}

// Mapeamos cada campo de BD -> índice en el CSV
$headerIndex = []; // ejemplo: ['modelo' => 2, 'marca'=>3, ...]
foreach ($columnMap as $campoBD => $variantesPosibles) {
    foreach ($variantesPosibles as $var) {
        foreach ($headers as $i => $hRaw) {
            if (norm_col($hRaw) === norm_col($var)) {
                $headerIndex[$campoBD] = $i;
                break 2;
            }
        }
    }
}

// Validación mínima: modelo y categoria son obligatorios
if (!isset($headerIndex['modelo']) || !isset($headerIndex['categoria'])) {
    $_SESSION['mensaje'] =
        '<strong><i class="bi bi-x-circle-fill"></i> Error:</strong> El CSV debe incluir al menos las columnas "modelo" y "categoría".';
    header('Location: resultado.php?status=error&origen=importar');
    exit();
}

$importados = 0;
$omitidos   = 0;

// Procesamos cada fila de datos
while (($row = fgetcsv($handle, 2000, ",")) !== false) {

    // Armamos un array asociativo limpio con claves de BD
    $registro = [];
    foreach ($columnMap as $campoBD => $variantesPosibles) {
        if (isset($headerIndex[$campoBD])) {
            $idx = $headerIndex[$campoBD];
            $registro[$campoBD] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
        } else {
            $registro[$campoBD] = '';
        }
    }

    // Normalizaciones por tipo
    $registro['cantidad']         = ($registro['cantidad'] === '')         ? 0 : (int)$registro['cantidad'];
    $registro['valor_usd']        = ($registro['valor_usd'] === '')        ? 0 : (float)$registro['valor_usd'];
    $registro['periodo_garantia'] = ($registro['periodo_garantia'] === '') ? 0 : (int)$registro['periodo_garantia'];

    $registro['fecha_garantia'] = normalizar_fecha($registro['fecha_garantia']) ?? '';
    $registro['fecha_ingreso']  = normalizar_fecha($registro['fecha_ingreso'])  ?? '';
    $registro['fecha_egreso']   = normalizar_fecha($registro['fecha_egreso'])   ?? '';

    // Campos obligatorios mínimos
    if ($registro['modelo'] === '' || $registro['categoria'] === '') {
        $omitidos++;
        continue;
    }

    $sqlProd = "
        INSERT INTO `" . tn('productos') . "` (
            asignado_a,
            modelo,
            marca,
            descripcion,
            servis_tag,
            cantidad,
            valor_usd,
            estado,
            categoria,
            subcategoria,
            periodo_garantia,
            fecha_garantia,
            fecha_ingreso,
            fecha_egreso,
            ubicacion,
            patchcord_tipo,
            patchcord_longitud,
            switch_gestion,
            antenas_tipo,
            bobina_categoria,
            bobina_ubicacion,
            bobina_longitud,
            conectores_tipo,
            detalle_conectores,
            cintas_generacion,
            detalle_repuesto,
            detalle_otros,
            subsoftware,
            subsoftware_otros
            /* , tenant_id */
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    "; // 29 placeholders (matches 29 columns above)

    $stmtProd = $conn->prepare($sqlProd);
    if (!$stmtProd) {
        $omitidos++;
        continue;
    }

    // Types (29 params, matching 29 columns):
    //  asignado_a, modelo, marca, descripcion, servis_tag (5s)
    //  + cantidad(i), valor_usd(d) + estado, categoria, subcategoria (3s)
    //  + periodo_garantia(i) + fecha_garantia, fecha_ingreso, fecha_egreso, ubicacion (4s)
    //  + 14 strings de detalles (patchcord_tipo, _longitud, switch_gestion, antenas_tipo,
    //    bobina_categoria/_ubicacion/_longitud, conectores_tipo, detalle_conectores,
    //    cintas_generacion, detalle_repuesto, _otros, subsoftware, _otros)
    //  = 5s + i + d + 3s + i + 4s + 14s = 29 chars
    $stmtProd->bind_param(
        'sssssidsssissssssssssssssssss',
        $registro['asignado_a'],
        $registro['modelo'],
        $registro['marca'],
        $registro['descripcion'],
        $registro['servis_tag'],
        $registro['cantidad'],
        $registro['valor_usd'],
        $registro['estado'],
        $registro['categoria'],
        $registro['subcategoria'],
        $registro['periodo_garantia'],
        $registro['fecha_garantia'],
        $registro['fecha_ingreso'],
        $registro['fecha_egreso'],
        $registro['ubicacion'],
        $registro['patchcord_tipo'],
        $registro['patchcord_longitud'],
        $registro['switch_gestion'],
        $registro['antenas_tipo'],
        $registro['bobina_categoria'],
        $registro['bobina_ubicacion'],
        $registro['bobina_longitud'],
        $registro['conectores_tipo'],
        $registro['detalle_conectores'],
        $registro['cintas_generacion'],
        $registro['detalle_repuesto'],
        $registro['detalle_otros'],
        $registro['subsoftware'],
        $registro['subsoftware_otros']
        // , $tenant_id
    );

    if (!$stmtProd->execute()) {
        $omitidos++;
        $stmtProd->close();
        continue;
    }

    $nuevo_id = $stmtProd->insert_id;
    $stmtProd->close();
    $importados++;

    // Registrar movimiento "Agregado"
    // ⚠ Ajustá esta INSERT a la estructura REAL de producto_movimientos.
    // Tomé como referencia lo que vimos en tus otros scripts:
    // producto_movimientos(
    //   producto_id, accion, descripcion, detalle_tecnico,
    //   realizado_por, modelo, marca, servis_tag, cantidad,
    //   valor_usd, estado, categoria, subcategoria,
    //   asignado_a, ubicacion,
    //   fecha_ingreso, fecha_garantia, periodo_garantia,
    //   patchcord_tipo, patchcord_longitud,
    //   switch_gestion, antenas_tipo,
    //   bobina_categoria, bobina_ubicacion, bobina_longitud,
    //   conectores_tipo, detalle_conectores,
    //   cintas_generacion, detalle_repuesto, detalle_otros,
    //   subsoftware, subsoftware_otros,
    //   carpeta_raiz_drive
    // )
    $sqlMov = "
        INSERT INTO `" . tn('producto_movimientos') . "` (
            producto_id,
            accion,
            descripcion,
            detalle_tecnico,
            realizado_por,
            modelo,
            marca,
            servis_tag,
            cantidad,
            valor_usd,
            estado,
            categoria,
            subcategoria,
            asignado_a,
            ubicacion,
            fecha_ingreso,
            fecha_garantia,
            periodo_garantia,
            patchcord_tipo,
            patchcord_longitud,
            switch_gestion,
            antenas_tipo,
            bobina_categoria,
            bobina_ubicacion,
            bobina_longitud,
            conectores_tipo,
            detalle_conectores,
            cintas_generacion,
            detalle_repuesto,
            detalle_otros,
            subsoftware,
            subsoftware_otros,
            carpeta_raiz_drive
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmtMov = $conn->prepare($sqlMov);
    if ($stmtMov) {
        $accion = 'Agregado';
        $descripcionMov = $registro['descripcion']; // usamos descripción del producto
        $detalleTecnico = ''; // vacío al importar
        $carpetaRaizDrive = null; // aún no creada

        // Igual que en edit.php: los tipos se derivan de la lista de valores, no se
        // escriben a mano. El string anterior tenía 32 caracteres para 33 parámetros
        // → ArgumentCountError (fatal) en PHP 8, así que la importación de CSV
        // moría al registrar el movimiento del primer activo.
        // [tipo, valor] en el MISMO orden que las columnas del INSERT de arriba.
        $bindMov = [
            ['i', $nuevo_id],
            ['s', $accion],
            ['s', $descripcionMov],
            ['s', $detalleTecnico],
            ['s', $user_id],
            ['s', $registro['modelo']],
            ['s', $registro['marca']],
            ['s', $registro['servis_tag']],
            ['i', $registro['cantidad']],
            ['d', $registro['valor_usd']],
            ['s', $registro['estado']],
            ['s', $registro['categoria']],
            ['s', $registro['subcategoria']],
            ['s', $registro['asignado_a']],
            ['s', $registro['ubicacion']],
            ['s', $registro['fecha_ingreso']],
            ['s', $registro['fecha_garantia']],
            ['i', $registro['periodo_garantia']],
            ['s', $registro['patchcord_tipo']],
            ['s', $registro['patchcord_longitud']],
            ['s', $registro['switch_gestion']],
            ['s', $registro['antenas_tipo']],
            ['s', $registro['bobina_categoria']],
            ['s', $registro['bobina_ubicacion']],
            ['s', $registro['bobina_longitud']],
            ['s', $registro['conectores_tipo']],
            ['s', $registro['detalle_conectores']],
            ['s', $registro['cintas_generacion']],
            ['s', $registro['detalle_repuesto']],
            ['s', $registro['detalle_otros']],
            ['s', $registro['subsoftware']],
            ['s', $registro['subsoftware_otros']],
            ['s', $carpetaRaizDrive],
        ];

        $tiposMov  = implode('', array_column($bindMov, 0));
        $paramsMov = array_column($bindMov, 1);
        $stmtMov->bind_param($tiposMov, ...$paramsMov);

        $stmtMov->execute();
        $stmtMov->close();
    }

} // while

fclose($handle);

// Log y salida final
log_event(
    (string)$user_id,
    'IMPORT_CSV',
    "Importación CSV finalizada. importados=$importados omitidos=$omitidos",
    'transaccion'
);

$_SESSION['mensaje'] =
    "<strong><i class='bi bi-check-circle-fill'></i> Importación completada:</strong> $importados productos importados, $omitidos omitidos.";
header('Location: resultado.php?status=success&origen=importar');
exit();
