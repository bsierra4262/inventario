<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

try {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit();
    }

    $user_id    = (int)$_SESSION['user_id'];
    $usersTable = tn('users');

    $stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        session_destroy();
        header('Location: /auth/login.php');
        exit();
    }

    // Permiso minimo para ver la pantalla de export
    require_permission('asset.export', $user);

    $tenant_name = 'Mi empresa';
    try {
        require_once __DIR__ . '/../core/db/master_connection.php';
        $masterDb = new MasterDB();
        $companyId = (int)($_SESSION['company_id'] ?? 0);
        if ($companyId > 0) {
            $cstmt = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
            $crow = $cstmt->get_result()->fetch_assoc();
            if ($crow) $tenant_name = $crow['nombre'];
            $cstmt->close();
        }
    } catch (Throwable) {}

    // ── Stats del inventario ───────────────────────────────────────────
    $productosTable = tn('productos');
    $stats = ['activos' => 0, 'archivados' => 0, 'total' => 0];
    try {
        $r = $conn->query("SELECT archivado, COUNT(*) AS c FROM `{$productosTable}` GROUP BY archivado");
        if ($r) while ($row = $r->fetch_assoc()) {
            if ((int)$row['archivado'] === 0) $stats['activos']    = (int)$row['c'];
            else                               $stats['archivados'] = (int)$row['c'];
        }
        $stats['total'] = $stats['activos'] + $stats['archivados'];
    } catch (Throwable) {}

} catch (Throwable $e) {
    log_event('inventario/exportar', 'ERROR', $e->getMessage());
    header('Location: /auth/login.php?err=' . urlencode('Error interno'));
    exit();
}

$csrf_activos    = generateCsrfToken('export_inventario');
// Reusar el mismo token (ambos botones comparten el scope, no importa cuál se use primero)
$csrf_archivados = $csrf_activos;

// ── Variables de layout ─────────────────────────────────────────────────────
$page_title      = 'Exportar CSV — Asset Vault';
$page_section    = 'exportar';
$topbar_title    = 'Exportar inventario';
$topbar_subtitle = 'Descargá el inventario en formato CSV';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = hub_volver('datos');

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <!-- ── Resumen del inventario ─────────────────────────────── -->
    <div class="ih-stats" style="margin-bottom:24px;">
      <div class="ih-stat ih-stat--green">
        <div class="ih-stat__label">Activos en operación <i class="bi bi-box-seam"></i></div>
        <div class="ih-stat__value"><?= number_format($stats['activos']) ?></div>
        <div class="ih-stat__delta">Se incluirán en el export</div>
      </div>
      <div class="ih-stat ih-stat--warn">
        <div class="ih-stat__label">Archivados <i class="bi bi-archive"></i></div>
        <div class="ih-stat__value"><?= number_format($stats['archivados']) ?></div>
        <div class="ih-stat__delta">Disponibles en export completo</div>
      </div>
      <div class="ih-stat">
        <div class="ih-stat__label">Total registros <i class="bi bi-database"></i></div>
        <div class="ih-stat__value"><?= number_format($stats['total']) ?></div>
        <div class="ih-stat__delta">Todos los estados</div>
      </div>
    </div>

    <!-- ── Opciones de exportación ───────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;margin-bottom:32px;">

      <!-- Export activos vigentes -->
      <div class="ih-card">
        <div class="ih-card__body" style="text-align:center;padding:32px 24px;">
          <div style="width:64px;height:64px;background:#f0faf0;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="bi bi-file-earmark-spreadsheet" style="font-size:28px;color:var(--ih-green);"></i>
          </div>
          <h3 style="font-size:18px;font-weight:700;margin:0 0 8px;color:var(--ih-gray-900);">Activos en operación</h3>
          <p style="color:var(--ih-gray-500);font-size:14px;margin:0 0 20px;">
            Exporta únicamente los <strong><?= number_format($stats['activos']) ?> activos</strong> no archivados.
            Es el reporte habitual para auditorías y revisiones.
          </p>
          <form method="POST" action="/backend/export_csv.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_activos) ?>">
            <input type="hidden" name="incluir_archivados" value="0">
            <button type="submit" class="ih-btn ih-btn--primary" style="width:100%;">
              <i class="bi bi-download"></i> Descargar CSV
            </button>
          </form>
        </div>
      </div>

      <!-- Export completo (activos + archivados) -->
      <div class="ih-card">
        <div class="ih-card__body" style="text-align:center;padding:32px 24px;">
          <div style="width:64px;height:64px;background:#fff8f0;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="bi bi-file-earmark-spreadsheet-fill" style="font-size:28px;color:var(--ih-warn,#d97706);"></i>
          </div>
          <h3 style="font-size:18px;font-weight:700;margin:0 0 8px;color:var(--ih-gray-900);">Export completo</h3>
          <p style="color:var(--ih-gray-500);font-size:14px;margin:0 0 20px;">
            Exporta los <strong><?= number_format($stats['total']) ?> registros</strong> totales
            incluyendo activos archivados/dados de baja. Útil para historial completo.
          </p>
          <form method="POST" action="/backend/export_csv.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_archivados) ?>">
            <input type="hidden" name="incluir_archivados" value="1">
            <button type="submit" class="ih-btn ih-btn--outline" style="width:100%;">
              <i class="bi bi-download"></i> Descargar CSV completo
            </button>
          </form>
        </div>
      </div>

    </div>

    <!-- ── Columnas que incluye el CSV ───────────────────────── -->
    <div class="ih-card">
      <div class="ih-card__header">
        <h3 class="ih-card__title"><i class="bi bi-table"></i> Columnas del archivo CSV</h3>
      </div>
      <div class="ih-card__body">
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
          <?php
          $cols = [
            'ID','Asignado a','Nombre/Modelo','Marca','Descripción','S/N Service Tag',
            'Cantidad','Valor USD','Estado','Categoría','Subcategoría',
            'Garantía (meses)','Inicio garantía','Fecha recepción','Fecha compra',
            'Ubicación','Fecha carga','Patchcord tipo','Patchcord longitud',
            'Switch gestión','Antenas tipo','Bobina categoría','Bobina ubicación',
            'Bobina longitud','Conectores tipo','Detalle conectores','Cintas generación',
            'Detalle repuesto','Detalle otros','Software','Software detalle',
          ];
          foreach ($cols as $col): ?>
            <span class="ih-badge" style="background:var(--ih-gray-100);color:var(--ih-gray-600);font-size:11px;">
              <?= htmlspecialchars($col) ?>
            </span>
          <?php endforeach; ?>
        </div>
        <p class="ih-text-sm ih-text-muted" style="margin-top:12px;">
          <i class="bi bi-info-circle"></i>
          El archivo se genera en formato UTF-8. Si lo abrís en Excel en Windows,
          puede que necesites importarlo como texto delimitado para que los tildes se vean bien.
        </p>
      </div>
    </div>

    <?php if (user_can('asset.import_csv', $user)): ?>
    <!-- ── Importar CSV (solo si tiene permiso) ───────────────── -->
    <div class="ih-card" style="margin-top:20px;margin-bottom:40px;">
      <div class="ih-card__body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <i class="bi bi-upload" style="font-size:28px;color:var(--ih-gray-400);flex-shrink:0;"></i>
        <div style="flex:1;">
          <p style="font-weight:600;margin:0 0 4px;">¿Querés importar activos desde un CSV?</p>
          <p class="ih-text-sm ih-text-muted" style="margin:0;">
            Subí un archivo CSV con el mismo formato para agregar activos en masa.
          </p>
        </div>
        <a href="/inventario/importar.php" class="ih-btn ih-btn--ghost">
          <i class="bi bi-upload"></i> Ir a Importar
        </a>
      </div>
    </div>
    <?php endif; ?>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
