<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';

// -----------------------------------------------------
// 1. AUTENTICACIÓN BÁSICA
// -----------------------------------------------------
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    // Nadie logueado => no podés exportar datos
    http_response_code(403);
    echo "Acceso denegado: sesión no válida.";
    exit();
}

// Opcional / recomendado para multiempresa:
$tenant_id = $_SESSION['tenant_id'] ?? null; // <- asumimos que en tu login seteás esto
if (!$tenant_id) {
    // Si todavía no tenés multiempresa activa podés no forzar esto.
    // Si ya la tenés, conviene bloquear acá también.
    // http_response_code(403);
    // echo "Acceso denegado: tenant no asociado.";
    // exit();
}

// -----------------------------------------------------
// 2. CSRF
// -----------------------------------------------------
// El export es una acción que revela datos internos. Protegemos contra ataques
// tipo "visitá esta URL y sin querer descargás el inventario del cliente".
//
// Para eso este script DEBE ser llamado vía POST desde un formulario interno
// que incluya <input type="hidden" name="csrf_token" value="..."> generado con
// generateCsrfToken('export_inventario')
//
// Si querés seguir permitiendo GET directo manual (vos mismo pegando la URL),
// podés generar el token antes en la vista y meterlo en la URL como ?csrf_token=...
// Igual, mantenemos la verificación.
$csrf_token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!checkCsrfToken('export_inventario', $csrf_token)) {
    log_event($user_id, "CSRF_BLOCK", "Intento de exportación CSV con token inválido", "seguridad");
    http_response_code(403);
    echo "CSRF inválido o ausente.";
    exit();
}
// No rotamos el token acá porque es una acción 'descargable' que quizá abrís en pestaña nueva;
// si querés forzar rotación única, usando csrf_or_fail('export_inventario', true) en vez de checkCsrfToken()
// vas a invalidar reusos. Te dejo la versión no destructiva por comodidad.


// -----------------------------------------------------
// 3. PREPARAR RESPUESTA CSV
// -----------------------------------------------------
$filename   = 'inventario_' . date('Ymd_His') . '.csv';
$clean_name = str_replace(['"', "\r", "\n"], ['','', ''], $filename);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$clean_name.'"');

$output = fopen('php://output', 'w');

// Encabezados compatibles con import_csv.php
fputcsv($output, [
    'asignado a',
    'nombre/modelo',
    'marca',
    'descripcion',
    'S/N Service Tag',
    'cantidad',
    'valor usd',
    'estado',
    'categoria',
    'subcategoria',
    'garantia',
    'fecha garantia',
    'ingreso',
    'egreso',
    'ubicacion',
    'patchcord_tipo',
    'patchcord_longitud',
    'switch_gestion',
    'antenas_tipo',
    'bobina_categoria',
    'bobina_ubicacion',
    'bobina_longitud',
    'conectores_tipo',
    'detalle_conectores',
    'cintas_generacion',
    'detalle_repuesto',
    'detalle_otros',
    'subsoftware',
    'subsoftware_otros'
]);

// -----------------------------------------------------
// 4. CONSULTA A BD
// -----------------------------------------------------
// Ahora mismo tu query trae TODOS los productos no archivados.
// Lo voy a reescribir usando alias p.* por claridad y dejar el hook tenant.
//
// IMPORTANTE: asumo que 'productos' tiene columna 'archivado' y que
// vas a agregar (o ya agregaste) 'tenant_id' o similar para aislar clientes.
// Si NO la tenés todavía, dejá comentada esa parte del WHERE.
//
$sql = "
    SELECT 
        p.asignado_a,
        p.modelo,
        p.marca,
        p.descripcion,
        p.servis_tag,
        p.cantidad,
        p.valor_usd,
        p.estado,
        p.categoria,
        p.subcategoria,
        p.periodo_garantia AS garantia,
        p.fecha_garantia,
        p.fecha_ingreso AS ingreso,
        p.fecha_egreso AS egreso,
        p.ubicacion,
        p.patchcord_tipo,
        p.patchcord_longitud,
        p.switch_gestion,
        p.antenas_tipo,
        p.bobina_categoria,
        p.bobina_ubicacion,
        p.bobina_longitud,
        p.conectores_tipo,
        p.detalle_conectores,
        p.cintas_generacion,
        p.detalle_repuesto,
        p.detalle_otros,
        p.subsoftware,
        p.subsoftware_otros
    FROM productos p
    WHERE p.archivado = 0
    /* AND p.tenant_id = ? */ 
    ORDER BY p.id ASC
";

// Si ya tenés multiempresa real, usamos prepare con bind_param:
if ($tenant_id) {
    $stmt = $conn->prepare($sql);
    // sacá el comentario de la condición AND p.tenant_id = ? en el SQL de arriba antes de esto
    // $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    // modo legacy sin tenant filter
    $result = $conn->query($sql);
}

// -----------------------------------------------------
// 5. NORMALIZACIÓN DE FECHAS
// -----------------------------------------------------
function convertir_fecha(?string $fecha): ?string {
    if ($fecha === null) return $fecha;

    // ya viene YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return $fecha;
    }

    // viene dd/mm/yyyy o d/m/yyyy
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
        // yyyy-mm-dd
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    // "0000-00-00" o vacío -> lo dejamos igual, así el import sabe que es nulo
    return $fecha;
}

// -----------------------------------------------------
// 6. ESCRITURA DE FILAS
// -----------------------------------------------------
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        // normalizamos fechas
        $row['fecha_garantia'] = convertir_fecha($row['fecha_garantia'] ?? '');
        $row['ingreso']        = convertir_fecha($row['ingreso'] ?? '');
        $row['egreso']         = convertir_fecha($row['egreso'] ?? '');

        // garantizamos string vacío en null
        $data = [];
        foreach ($row as $k => $value) {
            $data[] = is_null($value) ? '' : $value;
        }

        fputcsv($output, $data);
    }
} else {
    fputcsv($output, ['No hay datos disponibles']);
}

fclose($output);

// -----------------------------------------------------
// 7. LOG
// -----------------------------------------------------
log_event(
    $user_id,
    "EXPORT_CSV",
    "Exportación CSV de inventario (archivado=0) generada correctamente",
    "transaccion"
);

exit();
