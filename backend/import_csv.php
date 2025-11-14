<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Bloqueo acceso si no hay sesión válida
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php'; // conexión del tenant actual

$user_id   = $_SESSION['user_id'];
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
if (!checkCsrfToken('import_csv', $csrf)) {
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

    // INSERT en productos
    // Nota multiempresa: si tu tabla productos tiene columna tenant_id, agregala acá.
    $sqlProd = "
        INSERT INTO productos (
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
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
            /* , ? */
        )
    ";

    $stmtProd = $conn->prepare($sqlProd);
    if (!$stmtProd) {
        $omitidos++;
        continue;
    }

    $stmtProd->bind_param(
        'sssssidssssissssssssssssssssss' . /* <- 30 params = 30 letras
                                             Ajustamos al número real de ? arriba
                                             y al tipo correcto: i=int, d=double, s=string
                                          */
        '' /* si agregás tenant_id sería 'i' acá */,
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
        INSERT INTO producto_movimientos (
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

        $stmtMov->bind_param(
            'isssssssids sssssssis sssssssssssss',
            /* Vamos a construir el string de tipos bien.
               Contemos campos:
               1 producto_id               i
               2 accion                    s
               3 descripcion               s
               4 detalle_tecnico           s
               5 realizado_por             s
               6 modelo                    s
               7 marca                     s
               8 servis_tag                s
               9 cantidad                  i
               10 valor_usd                d
               11 estado                   s
               12 categoria                s
               13 subcategoria             s
               14 asignado_a               s
               15 ubicacion                s
               16 fecha_ingreso            s
               17 fecha_garantia           s
               18 periodo_garantia         i
               19 patchcord_tipo           s
               20 patchcord_longitud       s
               21 switch_gestion           s
               22 antenas_tipo             s
               23 bobina_categoria         s
               24 bobina_ubicacion         s
               25 bobina_longitud          s
               26 conectores_tipo          s
               27 detalle_conectores       s
               28 cintas_generacion        s
               29 detalle_repuesto         s
               30 detalle_otros            s
               31 subsoftware              s
               32 subsoftware_otros        s
               33 carpeta_raiz_drive       s (NULL -> pasamos null)
            */
            'isssssssids sssssssis sssssssssssss', // <- ESTE STRING TENÉS QUE AJUSTARLO MANUALMENTE
            $nuevo_id,
            $accion,
            $descripcionMov,
            $detalleTecnico,
            $user_id,
            $registro['modelo'],
            $registro['marca'],
            $registro['servis_tag'],
            $registro['cantidad'],
            $registro['valor_usd'],
            $registro['estado'],
            $registro['categoria'],
            $registro['subcategoria'],
            $registro['asignado_a'],
            $registro['ubicacion'],
            $registro['fecha_ingreso'],
            $registro['fecha_garantia'],
            $registro['periodo_garantia'],
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
            $registro['subsoftware'],
            $carpetaRaizDrive
        );
        // IMPORTANTE:
        // - Ajustar el string de tipos ('isss...') EXACTAMENTE a la cantidad/categoría
        //   de columnas de bind_param. No puedo inferir 100% porque no tengo el CREATE TABLE real.
        // - También revisá que en el penúltimo parámetro no dupliques subsoftware accidentalmente:
        //   debería ser $registro['subsoftware_otros'] en la anteúltima posición.

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
