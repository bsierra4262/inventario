<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php'); exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

// Verificación de permiso: solo admin / manager pueden archivar
$_ut  = tn('users');
$_st  = $conn->prepare("SELECT role FROM `{$_ut}` WHERE id = ?");
$_st->bind_param('i', $user_id);
$_st->execute();
$_me  = $_st->get_result()->fetch_assoc();
$_st->close();
require_permission('asset.archive', $_me);

if (!function_exists('esc_del')) {
function esc_del(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
}

// ======================================================
// FLUJO POST — procesar archivado y redirigir
// ======================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['id'], $_POST['motivo']) &&
    filter_var($_POST['id'], FILTER_VALIDATE_INT)
) {
    csrf_or_fail('archivar_activo', true);

    $producto_id = (int)$_POST['id'];
    $motivo      = htmlspecialchars(trim($_POST['motivo']  ?? ''), ENT_QUOTES, 'UTF-8');
    $detalle     = htmlspecialchars(trim($_POST['detalle'] ?? ''), ENT_QUOTES, 'UTF-8');
    $fecha_baja  = trim($_POST['fecha_baja'] ?? '');

    if (!$fecha_baja || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_baja)) {
        $fecha_baja = date('Y-m-d');
    }

    $descripcion_mov = "Motivo: {$motivo}";
    if ($detalle !== '') {
        $descripcion_mov .= " | Detalle: {$detalle}";
    }

    $conn->begin_transaction();
    try {
        // Marcar como archivado con fecha de baja
        $stmt_update = $conn->prepare(
            "UPDATE `" . tn('productos') . "` SET archivado = 1, fecha_baja = ? WHERE id = ?"
        );
        $stmt_update->bind_param('si', $fecha_baja, $producto_id);
        $stmt_update->execute();
        $stmt_update->close();

        // Obtener snapshot del activo para el movimiento
        $stmt_prod = $conn->prepare("SELECT * FROM `" . tn('productos') . "` WHERE id = ?");
        $stmt_prod->bind_param('i', $producto_id);
        $stmt_prod->execute();
        $producto = $stmt_prod->get_result()->fetch_assoc();
        $stmt_prod->close();

        if (!$producto) {
            throw new Exception("No se encontró el activo con ID $producto_id.");
        }

        $accion = "Archivado";

        $stmt_mov = $conn->prepare("
            INSERT INTO `" . tn('producto_movimientos') . "`
            (
                producto_id, accion, descripcion,
                modelo, marca, servis_tag, cantidad, valor_usd,
                estado, fecha_garantia, fecha_ingreso, fecha_egreso,
                ubicacion, asignado_a, categoria, subcategoria,
                detalle_tecnico, patchcord_tipo, patchcord_longitud,
                subsoftware, realizado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt_mov->bind_param(
            'issssssdssssssssssssi',
            $producto['id'],
            $accion,
            $descripcion_mov,
            $producto['modelo'],
            $producto['marca'],
            $producto['servis_tag'],
            $producto['cantidad'],
            $producto['valor_usd'],
            $producto['estado'],
            $producto['fecha_garantia'],
            $producto['fecha_ingreso'],
            $producto['fecha_egreso'],
            $producto['ubicacion'],
            $producto['asignado_a'],
            $producto['categoria'],
            $producto['subcategoria'],
            $producto['detalle_tecnico'],
            $producto['patchcord_tipo'],
            $producto['patchcord_longitud'],
            $producto['subsoftware'],
            $user_id
        );
        $stmt_mov->execute();
        $stmt_mov->close();

        $conn->commit();

        log_event(
            (string)$user_id,
            'ARCHIVO',
            "Activo ID $producto_id archivado. Motivo: {$motivo}",
            'archivo'
        );

        // ── Renombrar carpeta en Drive para marcarla como archivada ──────────
        $driveMsg = '';
        $carpetaId = $producto['carpeta_raiz_drive'] ?? '';
        if ($carpetaId !== '' && isset($_SESSION['access_token'])) {
            try {
                require_once __DIR__ . '/../core/config/platform.php';
                $driveClientId     = GOOGLE_CLIENT_ID;
                $driveClientSecret = GOOGLE_CLIENT_SECRET;
                $driveRedirectUri  = GOOGLE_REDIRECT_URI ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/auth/superadmin_callback.php');

                // Override con config del tenant
                $cfgT = tn('cloud_config');
                $cfgR = $conn->query("SELECT config_key, config_value FROM `{$cfgT}` WHERE provider = 'google'");
                if ($cfgR) {
                    while ($cfgRow = $cfgR->fetch_assoc()) {
                        match ($cfgRow['config_key']) {
                            'client_id'     => $driveClientId     = $cfgRow['config_value'],
                            'client_secret' => $driveClientSecret = $cfgRow['config_value'],
                            'redirect_uri'  => $driveRedirectUri  = $cfgRow['config_value'],
                            default         => null,
                        };
                    }
                }

                if ($driveClientId && $driveClientSecret) {
                    $gClient = new Google\Client();
                    $gClient->setClientId($driveClientId);
                    $gClient->setClientSecret($driveClientSecret);
                    $gClient->setRedirectUri($driveRedirectUri);
                    $gClient->setAccessToken($_SESSION['access_token']);

                    if ($gClient->isAccessTokenExpired()) {
                        $rt = $gClient->getRefreshToken();
                        if ($rt) {
                            $_SESSION['access_token'] = $gClient->fetchAccessTokenWithRefreshToken($rt);
                        } else {
                            throw new Exception('Token Drive expirado al archivar.');
                        }
                    }

                    $driveService = new Google\Service\Drive($gClient);

                    // Obtener nombre actual de la carpeta
                    $fileInfo  = $driveService->files->get($carpetaId, ['fields' => 'name']);
                    $nombreOrig = $fileInfo->getName();

                    // Renombrar solo si no tiene el prefijo ya
                    if (!str_starts_with($nombreOrig, '[ARCHIVADO]')) {
                        $nuevoNombre = '[ARCHIVADO ' . $fecha_baja . '] ' . $nombreOrig;
                        $updatedFile = new Google\Service\Drive\DriveFile(['name' => $nuevoNombre]);
                        $driveService->files->update($carpetaId, $updatedFile);
                        $driveMsg = ' La carpeta en Drive fue renombrada como archivada.';
                        log_event((string)$user_id, 'ARCHIVO_DRIVE', "Carpeta Drive $carpetaId renombrada a '$nuevoNombre'", 'archivo');
                    }
                }
            } catch (Throwable $driveEx) {
                // Drive error no bloquea el archivado — solo logueamos
                log_event((string)$user_id, 'WARN', "No se pudo renombrar carpeta Drive al archivar: " . $driveEx->getMessage(), 'archivo');
            }
        }

        header('Location: /backend/resultado.php?status=success&origen=archivar&msg='
            . urlencode('Activo archivado correctamente. Motivo: ' . $motivo . $driveMsg));
        exit;

    } catch (Throwable $ex) {
        $conn->rollback();
        log_event(
            (string)$user_id,
            'ERROR',
            "Error al archivar ID $producto_id: " . $ex->getMessage(),
            'archivo'
        );
        header('Location: /backend/resultado.php?status=error&origen=archivar&msg='
            . urlencode('No se pudo archivar el activo: ' . $ex->getMessage()));
        exit;
    }
}

// ======================================================
// FLUJO GET — mostrar formulario de confirmación
// ======================================================
if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    header('Location: /inventario/index.php');
    exit;
}

$producto_id = (int)$_GET['id'];
$csrf_token  = generateCsrfToken('archivar_activo');

log_event(
    (string)$user_id,
    'CONFIRMACION',
    "Confirmación solicitada para archivar activo ID $producto_id",
    'archivo'
);

// Nombre del activo para el subtítulo
$stmt_p = $conn->prepare(
    "SELECT modelo, marca FROM `" . tn('productos') . "` WHERE id = ? AND archivado = 0 LIMIT 1"
);
$stmt_p->bind_param('i', $producto_id);
$stmt_p->execute();
$prod_del = $stmt_p->get_result()->fetch_assoc();
$stmt_p->close();

if (!$prod_del) {
    header('Location: /inventario/index.php');
    exit;
}

$nombreActivo = trim(($prod_del['modelo'] ?? '') . ($prod_del['marca'] ? ' — ' . $prod_del['marca'] : ''));
if ($nombreActivo === '') $nombreActivo = 'Activo #' . $producto_id;

// Tenant name
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $mdb = new MasterDB();
    $cid = (int)($_SESSION['company_id'] ?? 0);
    if ($cid > 0) {
        $cs = $mdb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$cid]);
        $cr = $cs->get_result()->fetch_assoc();
        if ($cr) $tenant_name = $cr['nombre'];
        $cs->close();
    }
} catch (Throwable) {}

// ── Layout vars ─────────────────────────────────────────────────────────────
$page_title      = 'Archivar activo — Asset Vault';
$page_section    = 'inventario';
$topbar_title    = 'Archivar activo';
$topbar_subtitle = $nombreActivo;
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  =
    '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main" style="display:flex;align-items:center;justify-content:center;min-height:60vh;">
  <div class="ih-card" style="max-width:540px;width:100%;">
    <div class="ih-card__body" style="padding:2rem;">

      <!-- Encabezado -->
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:1.5rem;">
        <div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;">
          <i class="bi bi-archive"></i>
        </div>
        <div>
          <h2 style="margin:0;font-size:var(--ih-text-lg);font-weight:700;color:var(--ih-gray-800);">Archivar activo</h2>
          <p style="margin:0;font-size:var(--ih-text-sm);color:var(--ih-gray-500);"><?= esc_del($nombreActivo) ?></p>
        </div>
      </div>

      <!-- Aviso -->
      <div class="ih-alert ih-alert--warning" style="margin-bottom:1.5rem;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>El activo se marcará como <strong>archivado</strong> y quedará fuera del inventario activo. Se conserva el historial y se puede restaurar.</div>
      </div>

      <!-- Formulario -->
      <form method="post" action="/backend/delete.php">
        <input type="hidden" name="csrf_token" value="<?= esc_del($csrf_token) ?>">
        <input type="hidden" name="id" value="<?= esc_del((string)$producto_id) ?>">

        <div class="ih-field" style="margin-bottom:1rem;">
          <label class="ih-field__label">Motivo <span style="color:var(--ih-danger)">*</span></label>
          <select name="motivo" class="ih-select" required>
            <option value="" disabled selected>Seleccionar motivo...</option>
            <option>Venta</option>
            <option>Donación</option>
            <option>Scrapping</option>
            <option>Desguace</option>
            <option>Perdida</option>
            <option>Robo</option>
          </select>
        </div>

        <div class="ih-field" style="margin-bottom:1rem;">
          <label class="ih-field__label">Fecha de baja</label>
          <input type="date" name="fecha_baja" class="ih-input" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="ih-field" style="margin-bottom:1.5rem;">
          <label class="ih-field__label">
            Descripción adicional
            <span style="color:var(--ih-gray-400);font-weight:400;">(opcional)</span>
          </label>
          <textarea name="detalle" class="ih-input" rows="3"
                    placeholder="Ej: Activo vendido al área de contabilidad..."></textarea>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button type="submit" class="ih-btn ih-btn--danger" style="flex:1;">
            <i class="bi bi-archive-fill"></i> Confirmar archivado
          </button>
          <a href="/inventario/index.php" class="ih-btn ih-btn--ghost" style="flex:1;">
            <i class="bi bi-x-circle"></i> Cancelar
          </a>
        </div>
      </form>

    </div>
  </div>
</main>

</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
