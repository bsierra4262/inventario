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
require_once __DIR__ . '/../core/tenants/campos.php';
require_once __DIR__ . '/../core/tenants/papelera.php';
require_once __DIR__ . '/../includes/volver.php';

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
        // Marcar como archivado con fecha de baja.
        // Se guarda además quién, cuándo y por qué, para que la papelera pueda
        // mostrarlo y para poder restaurar con contexto.
        $quien = (string)($_SESSION['user_email'] ?? ($_me['username'] ?? ('id:' . $user_id)));
        $stmt_update = $conn->prepare(
            "UPDATE `" . tn('productos') . "`
                SET archivado = 1, fecha_baja = ?, archivado_en = NOW(),
                    archivado_por = ?, archivado_motivo = ?
              WHERE id = ?"
        );
        $stmt_update->bind_param('sssi', $fecha_baja, $quien, $motivo, $producto_id);
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

        // ── Mover la carpeta del activo a "Archivados" en Drive ──────────────
        //
        // Antes sólo se le anteponía "[ARCHIVADO]" al nombre y la carpeta se
        // quedaba mezclada con las de los activos en uso. Ahora se mueve a una
        // carpeta "Archivados" colgada de la raíz del tenant, y se recuerda de
        // dónde salió para poder devolverla si el activo se restaura.
        //
        // Si Drive no está configurado o el token venció, no pasa nada: el
        // archivado en la base ya se hizo y no se bloquea por esto.
        $driveMsg  = '';
        $carpetaId = (string)($producto['carpeta_raiz_drive'] ?? '');
        if ($carpetaId !== '') {
            require_once __DIR__ . '/../core/integraciones/drive.php';
            $res = drive_archivar_carpeta($conn, $carpetaId, $fecha_baja);
            if ($res['ok']) {
                $driveMsg = $res['mensaje'];
                if ($res['padre_previo']) {
                    $stPP = $conn->prepare("UPDATE `" . tn('productos') . "` SET drive_padre_previo = ? WHERE id = ?");
                    $stPP->bind_param('si', $res['padre_previo'], $producto_id);
                    $stPP->execute();
                    $stPP->close();
                }
                log_event((string)$user_id, 'ARCHIVO_DRIVE',
                    "Carpeta Drive {$carpetaId} movida a Archivados (padre previo: " . ($res['padre_previo'] ?? '—') . ")", 'archivo');
            } else {
                log_event((string)$user_id, 'WARN',
                    "No se pudo mover la carpeta Drive {$carpetaId} al archivar (Drive no disponible o sin token)", 'archivo');
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
require_once __DIR__ . '/../core/db/empresa_cache.php';
$tenant_name = empresa_nombre();

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

<main class="ih-app__main ih-app__main--centrado">
  <div class="ih-card ih-foco">
    <div class="ih-card__body">

      <?php /* Una pantalla con una sola decision usa el molde del sistema
               (.ih-foco). Antes estaba escrita a mano aca, en
               confirmar_accion.php y en resultado.php, con la tarjeta de 540,
               600 y 560 px y el relleno de 2, 2 y 2.5 rem. */ ?>
      <div class="ih-foco__cabecera">
        <div class="ih-foco__icono ih-foco__icono--aviso">
          <i class="bi bi-archive"></i>
        </div>
        <div>
          <h2 class="ih-foco__titulo">Archivar este activo</h2>
          <p class="ih-foco__sub"><?= esc_del($nombreActivo) ?></p>
        </div>
      </div>

      <div class="ih-alert ih-alert--warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
          Sale del inventario y de los totales, pero no se borra: conserva su historial y sus
          archivos, y desde la papelera se puede devolver. Su carpeta de Drive se mueve a
          «Archivados» y vuelve sola al restaurarlo.
        </div>
      </div>

      <form method="post" action="/backend/delete.php">
        <input type="hidden" name="csrf_token" value="<?= esc_del($csrf_token) ?>">
        <input type="hidden" name="id" value="<?= esc_del((string)$producto_id) ?>">

        <div class="ih-field">
          <label class="ih-field__label" for="motivo">
            Por qué se archiva <span class="ih-field__req" aria-hidden="true">*</span>
          </label>
          <select name="motivo" id="motivo" class="ih-select" required>
            <option value="" disabled selected>Elegí un motivo…</option>
            <?php foreach (PAPELERA_MOTIVOS as $__v => $__t): ?>
              <option value="<?= esc_del($__v) ?>"><?= esc_del($__t) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ih-field__hint">Queda escrito en el historial del activo y en la papelera.</div>
        </div>

        <div class="ih-field">
          <label class="ih-field__label" for="fecha_baja">Desde cuándo</label>
          <input type="date" name="fecha_baja" id="fecha_baja" class="ih-input" value="<?= date('Y-m-d') ?>">
          <div class="ih-field__hint">La fecha en que dejó de usarse. Por defecto, hoy.</div>
        </div>

        <div class="ih-field">
          <label class="ih-field__label" for="detalle">
            Algo más que convenga anotar
            <span class="ih-field__opcional">(se puede dejar vacío)</span>
          </label>
          <textarea name="detalle" id="detalle" class="ih-input" rows="3"
                    placeholder="Ej.: lo retiró Contaduría el 12/03, remito 0001-00045"></textarea>
        </div>

        <div class="ih-foco__acciones ih-mt-5">
          <button type="submit" class="ih-btn ih-btn--danger">
            <i class="bi bi-archive-fill"></i> Archivar
          </button>
          <a href="<?= esc_del(volver_destino('/inventario/index.php')) ?>" class="ih-btn ih-btn--ghost">
            <i class="bi bi-x-circle"></i> No archivar
          </a>
        </div>
      </form>

    </div>
  </div>
</main>

</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
