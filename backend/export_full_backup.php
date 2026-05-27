<?php
/**
 * EXPORT FULL BACKUP — INTELLIHELP Asset Vault
 *
 * Exporta TODOS los activos (incluyendo archivados) con TODAS las columnas
 * del schema, incluyendo carpeta_raiz_drive — lo que permite re-importar el
 * inventario en otra instancia y mantener intactas las referencias a los
 * archivos guardados en Google Drive / OneDrive.
 *
 * Los archivos físicos viven en Drive — este backup preserva la METADATA y la
 * referencia (drive folder id), no descarga los binarios.
 *
 * Acceso: solo usuarios admin del tenant.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';

// --- Auth: solo admin ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    http_response_code(403);
    echo 'Sesión inválida.';
    exit;
}

$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
require_permission('asset.backup_full', $me);

// --- CSRF ---
$csrf_token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf_token, 'full_backup')) {
    log_event((string)$user_id, 'CSRF_BLOCK', 'Intento export_full_backup con token inválido', 'seguridad');
    http_response_code(403);
    echo 'CSRF inválido.';
    exit;
}

// --- Preparar respuesta ---
$tenant_label = (string)($_SESSION['company_id'] ?? 'tenant');
$filename = 'assetvault_backup_' . $tenant_label . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, must-revalidate');
header('X-Asset-Vault-Backup: full-v1');

$output = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fwrite($output, "\xEF\xBB\xBF");

// --- Header CSV ---
// Mantenemos el orden EXACTO de columnas del schema para que el import sea
// determinista. Cualquier columna nueva debe agregarse al final.
$headers = [
    'id',
    'modelo',
    'marca',
    'descripcion',
    'detalle_tecnico',
    'asignado_a',
    'categoria',
    'subcategoria',
    'servis_tag',
    'cantidad',
    'valor_usd',
    'ubicacion',
    'estado',
    'carpeta_raiz_drive',
    'fecha_ingreso',
    'fecha_egreso',
    'fecha_garantia',
    'periodo_garantia',
    'archivado',
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
    'subsoftware_otros',
    'fecha_compra',
    'fecha_baja',
    'fecha_carga',
    'fecha_modificacion',
];
fputcsv($output, $headers);

// --- Query ---
$productosTable = tn('productos');
$cols = implode(', ', array_map(fn($c) => "`$c`", $headers));
$sql  = "SELECT $cols FROM `$productosTable` ORDER BY id ASC";

try {
    $res = $conn->query($sql);
    $total = 0;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $line = [];
            foreach ($headers as $h) {
                $v = $row[$h] ?? '';
                $line[] = is_null($v) ? '' : (string)$v;
            }
            fputcsv($output, $line);
            $total++;
        }
    }

    log_event(
        $user_id,
        'EXPORT_FULL_BACKUP',
        "Backup completo CSV exportado: $total activos (tenant $tenant_label)",
        'transaccion'
    );
} catch (Throwable $e) {
    log_event($user_id, 'ERROR', 'Export full backup fallo: ' . $e->getMessage(), 'transaccion');
    fputcsv($output, ['ERROR', $e->getMessage()]);
}

fclose($output);
exit;
