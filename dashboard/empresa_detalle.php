<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';
require_once __DIR__ . '/../includes/cloud_icons.php';

superadmin_require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$db   = new MasterDB();
$stmt = $db->pquery(
    'SELECT id, nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, subdomain, login_url, active, created_at
     FROM companies WHERE id = ? LIMIT 1',
    'i', [$id]
);
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

// Conectar al tenant y leer estadísticas
$stats = [
    'users_total'     => '—',
    'users_active'    => '—',
    'products_active' => '—',
    'products_arch'   => '—',
    'movements'       => '—',
];
$tenantUsers    = [];
$cloudConfig    = [];
$tenantError    = '';

try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($id);

    // Usuarios
    $usrTable = $pf . 'users';
    $res = $tconn->query("SELECT id, username, email, role, active, must_change_password FROM `$usrTable` ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $tenantUsers[] = $row; }
    }
    $stats['users_total']  = count($tenantUsers);
    $stats['users_active'] = count(array_filter($tenantUsers, fn($u) => (int)($u['active'] ?? 1) === 1));

    // Productos
    $prdTable = $pf . 'productos';
    $r = $tconn->query("SELECT COUNT(*) c FROM `$prdTable` WHERE archivado = 0");
    if ($r) $stats['products_active'] = (int)$r->fetch_assoc()['c'];
    $r = $tconn->query("SELECT COUNT(*) c FROM `$prdTable` WHERE archivado = 1");
    if ($r) $stats['products_arch']   = (int)$r->fetch_assoc()['c'];

    // Movimientos
    $movTable = $pf . 'producto_movimientos';
    $r = $tconn->query("SELECT COUNT(*) c FROM `$movTable`");
    if ($r) $stats['movements'] = (int)$r->fetch_assoc()['c'];

    // Cloud config
    $ccTable = $pf . 'cloud_config';
    $ccRes = $tconn->query("SELECT provider, config_key FROM `$ccTable`");
    if ($ccRes) {
        while ($ccRow = $ccRes->fetch_assoc()) {
            $cloudConfig[$ccRow['provider']][] = $ccRow['config_key'];
        }
    }
} catch (Throwable $te) {
    $tenantError = $te->getMessage();
}

function roleBadge(string $role): string {
    return match($role) {
        'admin'   => '<span class="ih-badge ih-badge--purple">Admin</span>',
        'manager' => '<span class="ih-badge ih-badge--info">Manager</span>',
        'user'    => '<span class="ih-badge ih-badge--success">Usuario</span>',
        'viewer'  => '<span class="ih-badge">Viewer</span>',
        default   => '<span class="ih-badge">' . htmlspecialchars($role) . '</span>',
    };
}

$page_title      = 'Detalle empresa';
$page_section    = 'empresas';
$topbar_title    = 'Detalle empresa #' . $id;
$topbar_subtitle = htmlspecialchars($company['nombre']);
$topbar_user     = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email    = $_SESSION['superadmin_email'] ?? '';
$topbar_actions  = '<a href="/dashboard/empresa_editar.php?id=' . $id . '" class="ih-btn ih-btn--primary ih-btn--sm"><i class="bi bi-pencil"></i> Editar empresa</a>
                   <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--outline ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($tenantError): ?>
      <div class="ih-alert ih-alert--warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>No se pudo conectar a la base de datos del tenant: <?= htmlspecialchars($tenantError) ?></div>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="ih-stats">
      <div class="ih-stat ih-stat--<?= (int)($company['active'] ?? 1) === 1 ? 'green' : 'danger' ?>">
        <div class="ih-stat__label">Estado <i class="bi bi-circle-fill"></i></div>
        <div class="ih-stat__value" style="font-size:1.1rem"><?= (int)($company['active'] ?? 1) === 1 ? 'Activa' : 'Inactiva' ?></div>
        <div class="ih-stat__delta">Empresa #<?= $id ?></div>
      </div>
      <div class="ih-stat">
        <div class="ih-stat__label">Usuarios <i class="bi bi-people"></i></div>
        <div class="ih-stat__value"><?= $stats['users_total'] ?></div>
        <div class="ih-stat__delta"><?= $stats['users_active'] ?> activos</div>
      </div>
      <div class="ih-stat ih-stat--green">
        <div class="ih-stat__label">Activos <i class="bi bi-pc-display"></i></div>
        <div class="ih-stat__value"><?= $stats['products_active'] ?></div>
        <div class="ih-stat__delta">En inventario</div>
      </div>
      <div class="ih-stat ih-stat--danger">
        <div class="ih-stat__label">Archivados <i class="bi bi-archive"></i></div>
        <div class="ih-stat__value"><?= $stats['products_arch'] ?></div>
        <div class="ih-stat__delta">Dados de baja</div>
      </div>
      <div class="ih-stat ih-stat--info">
        <div class="ih-stat__label">Movimientos <i class="bi bi-arrow-left-right"></i></div>
        <div class="ih-stat__value"><?= $stats['movements'] ?></div>
        <div class="ih-stat__delta">Historial total</div>
      </div>
    </div>

    <div class="ih-grid" style="grid-template-columns:1fr 1fr;gap:1.5rem">

      <!-- Info general -->
      <div class="ih-card">
        <div class="ih-card__header">
          <h2 class="ih-card__title"><i class="bi bi-building"></i> Información</h2>
        </div>
        <div class="ih-card__body">
          <table style="width:100%;border-collapse:collapse;font-size:14px;line-height:2">
            <tr><td class="ih-text-muted" style="width:130px">Nombre</td><td><strong><?= htmlspecialchars($company['nombre']) ?></strong></td></tr>
            <?php if (!empty($company['razon_social'])): ?>
            <tr><td class="ih-text-muted">Razón social</td><td><?= htmlspecialchars($company['razon_social']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($company['cuit'])): ?>
            <tr><td class="ih-text-muted">CUIT</td><td><?= htmlspecialchars($company['cuit']) ?></td></tr>
            <?php endif; ?>
            <tr>
              <td class="ih-text-muted">Proveedor</td>
              <td>
                <?php if ($company['provider'] === 'google'): ?>
                  <span class="ih-badge ih-badge--green" style="display:inline-flex;align-items:center;gap:6px;"><?= cloud_icon_svg('google', 14) ?> Google Drive</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--info" style="display:inline-flex;align-items:center;gap:6px;"><?= cloud_icon_svg('microsoft', 14) ?> Microsoft OneDrive</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="ih-text-muted">Modo DB</td>
              <td>
                <?php if ($company['tenant_mode'] === 'external'): ?>
                  <span class="ih-badge ih-badge--purple">DB propia</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--warning">Prefijo</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr><td class="ih-text-muted">Alta</td><td><?= htmlspecialchars(substr((string)($company['created_at'] ?? ''), 0, 10)) ?></td></tr>
          </table>
        </div>
      </div>

      <!-- Acceso y cloud config -->
      <div class="ih-card">
        <div class="ih-card__header">
          <h2 class="ih-card__title"><i class="bi bi-link-45deg"></i> Acceso y credenciales</h2>
        </div>
        <div class="ih-card__body">
          <table style="width:100%;border-collapse:collapse;font-size:14px;line-height:2">
            <tr>
              <td class="ih-text-muted" style="width:130px">Subdominio</td>
              <td><?= $company['subdomain'] ? htmlspecialchars($company['subdomain']) . '.intellihelp.tech' : '<span class="ih-text-muted">—</span>' ?></td>
            </tr>
            <tr>
              <td class="ih-text-muted">Login URL</td>
              <td>
                <?php if (!empty($company['login_url'])): ?>
                  <a href="<?= htmlspecialchars($company['login_url']) ?>" target="_blank" rel="noopener" class="ih-text-sm" style="color:#663399">
                    <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($company['login_url']) ?>
                  </a>
                <?php else: ?>
                  <span class="ih-text-muted">— sin URL —</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($company['tenant_mode'] === 'external'): ?>
            <tr><td class="ih-text-muted">DB Host</td><td><?= htmlspecialchars($company['db_host'] ?? 'localhost') ?></td></tr>
            <tr><td class="ih-text-muted">DB Name</td><td><?= htmlspecialchars($company['db_name'] ?? '') ?></td></tr>
            <tr><td class="ih-text-muted">DB User</td><td><?= htmlspecialchars($company['db_user'] ?? '') ?></td></tr>
            <?php endif; ?>
            <tr>
              <td class="ih-text-muted">Credenciales</td>
              <td>
                <?php
                $googleKeys    = $cloudConfig['google']    ?? [];
                $microsoftKeys = $cloudConfig['microsoft'] ?? [];
                $hasGoogle    = in_array('client_id', $googleKeys, true) && in_array('client_secret', $googleKeys, true);
                $hasMicrosoft = in_array('client_id', $microsoftKeys, true) && in_array('client_secret', $microsoftKeys, true);
                ?>
                <?php if ($tenantError): ?>
                  <span class="ih-badge ih-badge--warning">Sin acceso al tenant</span>
                <?php elseif ($company['provider'] === 'google' && $hasGoogle): ?>
                  <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle"></i> Google OK</span>
                <?php elseif ($company['provider'] === 'microsoft' && $hasMicrosoft): ?>
                  <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle"></i> Microsoft OK</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--danger"><i class="bi bi-exclamation-circle"></i> No configuradas</span>
                <?php endif; ?>
              </td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <!-- Usuarios del tenant -->
    <?php if (!$tenantError): ?>
    <div class="ih-card ih-mt-6">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-people"></i> Usuarios del tenant</h2>
          <div class="ih-card__subtitle"><?= count($tenantUsers) ?> usuarios</div>
        </div>
      </div>
      <?php if (empty($tenantUsers)): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-person-x"></i></div>
          <div class="ih-empty__title">Sin usuarios</div>
          <div class="ih-empty__text">Este tenant no tiene usuarios registrados.</div>
        </div>
      <?php else: ?>
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead>
              <tr>
                <th>Usuario</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Contraseña</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tenantUsers as $u): ?>
                <?php $isActive = (int)($u['active'] ?? 1) === 1; ?>
                <tr style="<?= $isActive ? '' : 'opacity:.55' ?>">
                  <td><strong><?= htmlspecialchars($u['username'] ?? '') ?></strong></td>
                  <td class="ih-text-sm"><?= htmlspecialchars($u['email'] ?? '') ?></td>
                  <td><?= roleBadge($u['role'] ?? 'user') ?></td>
                  <td>
                    <?php if ($isActive): ?>
                      <span class="ih-badge ih-badge--success ih-badge--dot">Activo</span>
                    <?php else: ?>
                      <span class="ih-badge ih-badge--danger ih-badge--dot">Inactivo</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($u['must_change_password'])): ?>
                      <span class="ih-badge ih-badge--warning"><i class="bi bi-key"></i> Cambio pendiente</span>
                    <?php else: ?>
                      <span class="ih-badge ih-badge--success"><i class="bi bi-check2"></i> Configurada</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
