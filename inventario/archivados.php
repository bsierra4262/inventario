<?php
/**
 * Papelera — activos archivados, con opción de restaurar.
 *
 * Antes archivar era un camino de ida: el activo desaparecía del listado y la
 * única forma de recuperarlo era editarlo a mano y destildar `archivado`. Acá
 * están todos los archivados, con quién los archivó, cuándo y por qué, y un
 * botón para devolverlos al inventario (también mueve la carpeta de Drive de
 * vuelta desde «Archivados»).
 *
 * Permiso: asset.trash.view para ver, asset.restore para restaurar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/cloud_icons.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

if (empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$usersTable = tn('users');
$stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { session_destroy(); header('Location: /auth/login.php'); exit; }

require_permission('asset.trash.view', $user);
$puedeRestaurar = user_can('asset.restore', $user);
$puedeBorrar    = user_can('asset.delete', $user);

// Nombre de la empresa
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst  = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = $crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

// ── Búsqueda y paginación ────────────────────────────────────────────────────
$q         = trim((string)($_GET['q'] ?? ''));
$perPage   = 25;
$page      = max(1, (int)($_GET['page'] ?? 1));
$tProd     = tn('productos');

$where  = ['archivado = 1'];
$params = [];
$types  = '';
if ($q !== '') {
    $where[] = '(modelo LIKE ? OR marca LIKE ? OR servis_tag LIKE ? OR asignado_a LIKE ? OR archivado_motivo LIKE ?)';
    for ($i = 0; $i < 5; $i++) { $params[] = '%' . $q . '%'; $types .= 's'; }
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = 0;
try {
    $sqlC = "SELECT COUNT(*) t FROM `{$tProd}` {$whereSql}";
    if ($types !== '') {
        $c = $conn->prepare($sqlC);
        $c->bind_param($types, ...$params);
        $c->execute();
        $total = (int)$c->get_result()->fetch_assoc()['t'];
        $c->close();
    } else {
        $total = (int)$conn->query($sqlC)->fetch_assoc()['t'];
    }
} catch (Throwable $e) {
    log_event('inventario/archivados', 'ERROR', $e->getMessage());
}

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$filas = [];
try {
    $sqlL = "SELECT id, modelo, marca, servis_tag, categoria, asignado_a, ubicacion,
                    valor_usd, cantidad, fecha_baja, archivado_en, archivado_por,
                    archivado_motivo, carpeta_raiz_drive
               FROM `{$tProd}` {$whereSql}
              ORDER BY COALESCE(archivado_en, fecha_baja) DESC, id DESC
              LIMIT {$perPage} OFFSET {$offset}";
    if ($types !== '') {
        $l = $conn->prepare($sqlL);
        $l->bind_param($types, ...$params);
        $l->execute();
        $rs = $l->get_result();
    } else {
        $rs = $conn->query($sqlL);
    }
    while ($rs && ($r = $rs->fetch_assoc())) { $filas[] = $r; }
} catch (Throwable $e) {
    log_event('inventario/archivados', 'ERROR', $e->getMessage());
}

$okMsg  = (string)($_GET['ok']  ?? '');
$errMsg = (string)($_GET['err'] ?? '');

$page_title      = 'Papelera';
$page_section    = 'archivados';
$topbar_title    = 'Papelera';
$topbar_subtitle = $total . ' activo' . ($total === 1 ? '' : 's') . ' archivado' . ($total === 1 ? '' : 's');
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($okMsg): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i><div><?= htmlspecialchars($okMsg) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($errMsg): ?>
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($errMsg) ?></div>
      </div>
    <?php endif; ?>

    <div class="ih-alert ih-alert--info">
      <i class="bi bi-info-circle-fill"></i>
      <div>
        Los activos archivados no se borran: salen del listado principal y de los totales,
        pero conservan su historial y sus archivos. Restaurarlos los devuelve al inventario
        y trae su carpeta de Drive de vuelta desde «Archivados».
      </div>
    </div>

    <div class="ih-card">
      <div class="ih-card__header">
        <h2 class="ih-card__title">Activos archivados</h2>
        <form method="get" class="ih-flex ih-gap-2">
          <input type="text" name="q" class="ih-input" value="<?= htmlspecialchars($q) ?>"
                 placeholder="Buscar por modelo, marca, service tag o motivo…" style="min-width:240px">
          <button class="ih-btn ih-btn--ghost ih-btn--sm" type="submit"><i class="bi bi-search"></i></button>
          <?php if ($q !== ''): ?>
            <a class="ih-btn ih-btn--ghost ih-btn--sm" href="/inventario/archivados.php">Limpiar</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="ih-card__body">
        <?php if (!$filas): ?>
          <div class="ih-empty">
            <div class="ih-empty__icon"><i class="bi bi-archive"></i></div>
            <div class="ih-empty__title"><?= $q !== '' ? 'Sin resultados' : 'La papelera está vacía' ?></div>
            <p class="ih-empty__text">
              <?= $q !== ''
                  ? 'Ningún activo archivado coincide con esa búsqueda.'
                  : 'Cuando des de baja un activo desde el inventario, va a aparecer acá y vas a poder restaurarlo.' ?>
            </p>
          </div>
        <?php else: ?>
          <div class="ih-table-wrap">
            <table class="ih-table">
              <thead>
                <tr>
                  <th>Activo</th>
                  <th>Service tag</th>
                  <th>Archivado</th>
                  <th>Motivo</th>
                  <th style="width:210px">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($filas as $f):
                    $nombre = trim((string)($f['modelo'] ?? ''));
                    if (!empty($f['marca'])) { $nombre .= ' — ' . $f['marca']; }
                    if ($nombre === '') { $nombre = 'Activo #' . (int)$f['id']; }
                    $cuando = $f['archivado_en'] ?: $f['fecha_baja'];
              ?>
                <tr>
                  <td>
                    <div class="ih-text-semibold"><?= htmlspecialchars($nombre) ?></div>
                    <div class="ih-text-xs ih-text-muted">
                      #<?= (int)$f['id'] ?>
                      <?php if (!empty($f['categoria'])): ?> · <?= htmlspecialchars($f['categoria']) ?><?php endif; ?>
                      <?php if (!empty($f['asignado_a'])): ?> · <?= htmlspecialchars($f['asignado_a']) ?><?php endif; ?>
                    </div>
                  </td>
                  <td><?= $f['servis_tag'] ? '<code>' . htmlspecialchars($f['servis_tag']) . '</code>' : '<span class="ih-text-muted">—</span>' ?></td>
                  <td>
                    <div class="ih-text-sm"><?= $cuando ? htmlspecialchars(date('d/m/Y', strtotime((string)$cuando))) : '—' ?></div>
                    <?php if (!empty($f['archivado_por'])): ?>
                      <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars($f['archivado_por']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= !empty($f['archivado_motivo'])
                        ? '<span class="ih-badge ih-badge--warning">' . htmlspecialchars($f['archivado_motivo']) . '</span>'
                        : '<span class="ih-text-muted">—</span>' ?>
                  </td>
                  <td>
                    <div class="ih-flex ih-gap-2 ih-fw">
                      <a class="ih-btn ih-btn--ghost ih-btn--sm" href="/backend/view_details.php?id=<?= (int)$f['id'] ?>">
                        <i class="bi bi-eye"></i> Ver
                      </a>
                      <?php if ($puedeRestaurar): ?>
                        <form method="post" action="/backend/restaurar.php" style="display:inline"
                              onsubmit="return confirm('¿Restaurar «<?= htmlspecialchars(addslashes($nombre)) ?>» al inventario?')">
                          <?php csrfInput('restaurar_activo'); ?>
                          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                          <button type="submit" class="ih-btn ih-btn--accent ih-btn--sm">
                            <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
            <div class="ih-flex ih-gap-2 ih-justify-center ih-mt-4">
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="ih-btn ih-btn--sm <?= $p === $page ? 'ih-btn--primary' : 'ih-btn--ghost' ?>"
                   href="?page=<?= $p ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $p ?></a>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div><!-- /ih-app -->

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
