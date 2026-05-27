<?php
/**
 * IMPORT FULL BACKUP — INTELLIHELP Asset Vault
 *
 * Importa un CSV generado por export_full_backup.php.
 * - Si el CSV trae columna `id` y ya existe un producto con ese ID → UPDATE
 * - Si no existe el ID o la columna está vacía → INSERT (nuevo)
 * - Preserva carpeta_raiz_drive, fechas, archivado, etc.
 * - Registra cada inserción/actualización como movimiento.
 *
 * Acceso: solo admin del tenant. Requiere CSRF.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';

function back_with_msg(string $msg, string $type = 'success'): void {
    $u = '/importar.php?type=' . urlencode($type) . '&msg=' . urlencode($msg);
    header('Location: ' . $u);
    exit;
}

// --- Auth admin ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    http_response_code(403);
    echo 'Sesión inválida.';
    exit;
}

require_once __DIR__ . '/../core/security/permissions.php';

$usersTable = tn('users');
$s = $conn->prepare("SELECT username, role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
if (!user_can('asset.backup_full', $me)) {
    back_with_msg('No tenés permiso para importar el backup completo.', 'error');
}

$user_name = (string)($me['username'] ?? $user_id);

// --- CSRF ---
$csrf_token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf_token, 'full_backup')) {
    log_event((string)$user_id, 'CSRF_BLOCK', 'Intento import_full_backup con token inválido', 'seguridad');
    back_with_msg('CSRF inválido.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit;
}

if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    back_with_msg('No se recibió un archivo CSV válido.', 'error');
}

$tmpPath = $_FILES['csv_file']['tmp_name'];
$fh = fopen($tmpPath, 'r');
if (!$fh) {
    back_with_msg('No se pudo abrir el CSV cargado.', 'error');
}

// Skip BOM si existe
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$headers = fgetcsv($fh);
if (!$headers) {
    fclose($fh);
    back_with_msg('CSV vacío o sin encabezados.', 'error');
}
// Normalizar headers (trim + lowercase)
$headers = array_map(fn($h) => trim(strtolower((string)$h)), $headers);

// Columnas que aceptamos del schema productos
$validCols = [
    'modelo','marca','descripcion','detalle_tecnico','asignado_a',
    'categoria','subcategoria','servis_tag','cantidad','valor_usd',
    'ubicacion','estado','carpeta_raiz_drive',
    'fecha_ingreso','fecha_egreso','fecha_garantia','periodo_garantia',
    'archivado',
    'patchcord_tipo','patchcord_longitud','switch_gestion','antenas_tipo',
    'bobina_categoria','bobina_ubicacion','bobina_longitud',
    'conectores_tipo','detalle_conectores','cintas_generacion',
    'detalle_repuesto','detalle_otros','subsoftware','subsoftware_otros',
    'fecha_compra','fecha_baja','fecha_carga','fecha_modificacion',
];

// Mapa header → posición
$colIdx = [];
foreach ($headers as $i => $h) { $colIdx[$h] = $i; }

$hasId = isset($colIdx['id']);

$productosTable    = tn('productos');
$movimientosTable  = tn('producto_movimientos');

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];

function clean(string $s): string {
    $s = trim($s);
    if ($s === '' || strtolower($s) === 'null') return '';
    return $s;
}
function as_int(string $s): int { return (int)preg_replace('/[^\d-]/', '', $s); }
function as_dec(string $s): float {
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}
function as_date(string $s): ?string {
    $s = trim($s);
    if ($s === '' || $s === '0000-00-00') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

$conn->begin_transaction();

try {
    $lineNum = 1; // header
    while (($row = fgetcsv($fh)) !== false) {
        $lineNum++;
        // Línea vacía
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

        // Pad si vienen menos columnas
        while (count($row) < count($headers)) $row[] = '';

        $get = function(string $k) use ($row, $colIdx) {
            return isset($colIdx[$k]) ? clean((string)($row[$colIdx[$k]] ?? '')) : '';
        };

        // Construir el array de valores válidos del schema
        $data = [];
        foreach ($validCols as $c) {
            if (!isset($colIdx[$c])) continue;
            $v = $get($c);
            // Casting según columna
            if (in_array($c, ['cantidad','periodo_garantia','archivado'], true)) {
                $data[$c] = $v === '' ? 0 : as_int($v);
            } elseif ($c === 'valor_usd') {
                $data[$c] = $v === '' ? 0.0 : as_dec($v);
            } elseif (in_array($c, ['fecha_ingreso','fecha_egreso','fecha_garantia','fecha_compra','fecha_baja'], true)) {
                $data[$c] = as_date($v);
            } elseif (in_array($c, ['fecha_carga','fecha_modificacion'], true)) {
                $data[$c] = ($v === '' ? null : $v);
            } else {
                $data[$c] = $v;
            }
        }

        $id = $hasId ? as_int($get('id')) : 0;

        // Intentar UPDATE si trae id y existe
        $isUpdate = false;
        if ($id > 0) {
            $chk = $conn->prepare("SELECT id FROM `$productosTable` WHERE id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $isUpdate = (bool)$chk->get_result()->fetch_assoc();
            $chk->close();
        }

        if (empty($data)) { $skipped++; continue; }

        try {
            if ($isUpdate) {
                $set    = [];
                $types  = '';
                $params = [];
                foreach ($data as $k => $v) {
                    $set[] = "`$k` = ?";
                    if (is_int($v))    { $types .= 'i'; $params[] = $v; }
                    elseif (is_float($v)) { $types .= 'd'; $params[] = $v; }
                    else                 { $types .= 's'; $params[] = $v; }
                }
                $types .= 'i';
                $params[] = $id;

                $sql = "UPDATE `$productosTable` SET " . implode(', ', $set) . " WHERE id = ?";
                $st  = $conn->prepare($sql);
                $st->bind_param($types, ...$params);
                $st->execute();
                $st->close();
                $updated++;

                // movimiento
                @$conn->query("INSERT INTO `$movimientosTable` (producto_id, accion, realizado_por, detalle_otros) VALUES ($id, 'IMPORT_BACKUP_UPDATE', '" . $conn->real_escape_string($user_name) . "', 'Actualizado desde backup completo')");
            } else {
                $cols   = array_keys($data);
                $types  = '';
                $params = [];
                foreach ($data as $v) {
                    if (is_int($v))    { $types .= 'i'; $params[] = $v; }
                    elseif (is_float($v)) { $types .= 'd'; $params[] = $v; }
                    else                 { $types .= 's'; $params[] = $v; }
                }
                // Si trae id explícito y no existe, intentamos preservarlo
                if ($id > 0) {
                    array_unshift($cols, 'id');
                    array_unshift($params, $id);
                    $types = 'i' . $types;
                }
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $colList      = implode(', ', array_map(fn($c) => "`$c`", $cols));
                $sql = "INSERT INTO `$productosTable` ($colList) VALUES ($placeholders)";
                $st  = $conn->prepare($sql);
                $st->bind_param($types, ...$params);
                $st->execute();
                $newId = (int)$st->insert_id;
                $st->close();
                $inserted++;

                @$conn->query("INSERT INTO `$movimientosTable` (producto_id, accion, realizado_por, detalle_otros) VALUES ($newId, 'IMPORT_BACKUP_NEW', '" . $conn->real_escape_string($user_name) . "', 'Creado desde backup completo')");
            }
        } catch (Throwable $rowErr) {
            $errors[] = "Línea $lineNum: " . $rowErr->getMessage();
            $skipped++;
        }
    }

    $conn->commit();
    fclose($fh);

    log_event(
        $user_id,
        'IMPORT_FULL_BACKUP',
        "Backup importado: inserted=$inserted updated=$updated skipped=$skipped errors=" . count($errors),
        'transaccion'
    );

    $msg = "Backup importado: $inserted nuevos, $updated actualizados, $skipped omitidos.";
    if (count($errors) > 0) {
        $msg .= ' Errores: ' . count($errors) . ' (primer error: ' . htmlspecialchars($errors[0]) . ')';
    }
    back_with_msg($msg, count($errors) > 0 ? 'warning' : 'success');

} catch (Throwable $e) {
    $conn->rollback();
    fclose($fh);
    log_event($user_id, 'ERROR', 'Import full backup falló: ' . $e->getMessage(), 'transaccion');
    back_with_msg('Error procesando el CSV: ' . $e->getMessage(), 'error');
}
