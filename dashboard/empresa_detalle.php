<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';
require_once __DIR__ . '/../includes/cloud_icons.php';
require_once __DIR__ . '/../core/security/csrf.php';

superadmin_require_login();
csrf_start();

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
$tenantUsers     = [];
$cloudConfig     = [];
$tenantSettings  = [];
$tenantError     = '';
$adminFeedback   = '';
$adminFeedbackOk = true;

try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($id);

    // ── Procesar acciones admin del superadmin ──────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
        // Chequeo CSRF manual (no usamos csrf_validate porque hace die() en vez de retornar)
        $csrfOk = isset($_POST[CSRF_TOKEN_NAME])
                  && hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', (string)$_POST[CSRF_TOKEN_NAME]);

        if (!$csrfOk) {
            $adminFeedback = 'CSRF invalido. Recargá la página.';
            $adminFeedbackOk = false;
        } else {
            $action = (string)$_POST['admin_action'];
            switch ($action) {
                case 'tenant_setting':
                    $tsKey = (string)($_POST['setting_key'] ?? '');
                    $tsVal = (string)($_POST['setting_value'] ?? '0');
                    $allowed = ['disable_local_login'];
                    if (in_array($tsKey, $allowed, true)) {
                        // crear tabla on-demand (mismo schema que tenant_settings.php)
                        $stTable = $pf . 'tenant_settings';
                        $tconn->query("CREATE TABLE IF NOT EXISTS `{$stTable}` (
                            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                            setting_value TEXT NULL,
                            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        $stmtSt = $tconn->prepare("INSERT INTO `{$stTable}` (setting_key, setting_value) VALUES (?, ?)
                                                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                        $valNorm = $tsVal === '1' ? '1' : '0';
                        $stmtSt->bind_param('ss', $tsKey, $valNorm);
                        $stmtSt->execute();
                        $stmtSt->close();
                        $adminFeedback = "Setting actualizado: {$tsKey} = {$valNorm}";
                    } else {
                        $adminFeedback = 'Setting no permitido.';
                        $adminFeedbackOk = false;
                    }
                    break;

                case 'clear_cloud_config':
                    $provider = (string)($_POST['provider'] ?? '');
                    if (in_array($provider, ['google', 'microsoft'], true)) {
                        $ccT = $pf . 'cloud_config';
                        $stmtClr = $tconn->prepare("DELETE FROM `{$ccT}` WHERE provider = ?");
                        $stmtClr->bind_param('s', $provider);
                        $stmtClr->execute();
                        $aff = $stmtClr->affected_rows;
                        $stmtClr->close();
                        $adminFeedback = "Config {$provider} eliminada ({$aff} entradas).";
                    } else {
                        $adminFeedback = 'Provider invalido.';
                        $adminFeedbackOk = false;
                    }
                    break;

                case 'reset_user_password':
                    $uid = (int)($_POST['user_id'] ?? 0);
                    $newPass = (string)($_POST['new_password'] ?? '');
                    if ($uid > 0 && strlen($newPass) >= 8) {
                        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                        $usrT = $pf . 'users';
                        $stmtRp = $tconn->prepare("UPDATE `{$usrT}` SET password = ?, must_change_password = 1 WHERE id = ?");
                        $stmtRp->bind_param('si', $hashed, $uid);
                        $stmtRp->execute();
                        $aff = $stmtRp->affected_rows;
                        $stmtRp->close();
                        if ($aff > 0) {
                            $adminFeedback = "Contraseña reseteada para usuario ID {$uid}. Se forzará cambio al próximo login.";
                        } else {
                            $adminFeedback = 'No se encontró el usuario.';
                            $adminFeedbackOk = false;
                        }
                    } else {
                        $adminFeedback = 'Contraseña debe tener al menos 8 caracteres.';
                        $adminFeedbackOk = false;
                    }
                    break;

                case 'toggle_user_active':
                    $uid = (int)($_POST['user_id'] ?? 0);
                    if ($uid > 0) {
                        $usrT = $pf . 'users';
                        $stmtTU = $tconn->prepare("UPDATE `{$usrT}` SET active = IF(active = 1, 0, 1) WHERE id = ?");
                        $stmtTU->bind_param('i', $uid);
                        $stmtTU->execute();
                        $stmtTU->close();
                        $adminFeedback = "Estado del usuario ID {$uid} cambiado.";
                    }
                    break;

                case 'sync_provider_from_cloud':
                    // Sincroniza companies.provider desde lo que tenga el tenant en cloud_config.storage_provider
                    $ccT = $pf . 'cloud_config';
                    $rs = $tconn->query("SELECT config_value FROM `{$ccT}` WHERE config_key = 'storage_provider' AND config_value != '' LIMIT 1");
                    $detected = '';
                    if ($rs && $row = $rs->fetch_assoc()) {
                        $detected = strtolower((string)$row['config_value']);
                    }
                    if ($detected === '') {
                        // Fallback: si hay creds google → google; si hay ms → microsoft
                        $rs2 = $tconn->query("SELECT DISTINCT provider FROM `{$ccT}` WHERE config_key IN ('client_id','client_secret','root_folder_id')");
                        $provs = [];
                        if ($rs2) { while ($r2 = $rs2->fetch_assoc()) $provs[] = (string)$r2['provider']; }
                        if (in_array('google', $provs, true))    $detected = 'google';
                        elseif (in_array('microsoft', $provs, true)) $detected = 'microsoft';
                    }
                    if (in_array($detected, ['google','microsoft'], true)) {
                        $db->pquery('UPDATE companies SET provider = ? WHERE id = ?', 'si', [$detected, $id]);
                        $company['provider'] = $detected;
                        $adminFeedback = "Provider sincronizado: companies.provider = {$detected}";
                    } else {
                        $adminFeedback = 'No se pudo detectar el provider del tenant (cloud_config vacío).';
                        $adminFeedbackOk = false;
                    }
                    break;

                case 'reset_user_must_change':
                    $uid = (int)($_POST['user_id'] ?? 0);
                    if ($uid > 0) {
                        $usrT = $pf . 'users';
                        $stmtMC = $tconn->prepare("UPDATE `{$usrT}` SET must_change_password = 1 WHERE id = ?");
                        $stmtMC->bind_param('i', $uid);
                        $stmtMC->execute();
                        $stmtMC->close();
                        $adminFeedback = "Usuario ID {$uid} marcado para cambiar contraseña al próximo login.";
                    }
                    break;
            }
        }
    }

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

    // Tenant settings
    $tsTable = $pf . 'tenant_settings';
    $tsRes = @$tconn->query("SELECT setting_key, setting_value FROM `$tsTable`");
    if ($tsRes) {
        while ($tsRow = $tsRes->fetch_assoc()) {
            $tenantSettings[$tsRow['setting_key']] = $tsRow['setting_value'];
        }
    }

    // Custom roles del tenant
    $tenantCustomRoles = [];
    $crTable = $pf . 'custom_roles';
    $crRes = @$tconn->query("SELECT role_key, label FROM `$crTable` ORDER BY label ASC");
    if ($crRes) {
        while ($crRow = $crRes->fetch_assoc()) {
            $tenantCustomRoles[(string)$crRow['role_key']] = (string)$crRow['label'];
        }
    }

    // Conteo de overrides de permisos
    $tenantPermOverrides = 0;
    $rpTable = $pf . 'role_permissions';
    $rpRes = @$tconn->query("SELECT COUNT(*) c FROM `$rpTable`");
    if ($rpRes) $tenantPermOverrides = (int)($rpRes->fetch_assoc()['c'] ?? 0);
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

    <?php if ($adminFeedback !== ''): ?>
    <div class="ih-alert <?= $adminFeedbackOk ? 'ih-alert--success' : 'ih-alert--warning' ?> ih-mt-6">
      <i class="bi bi-<?= $adminFeedbackOk ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
      <div><?= htmlspecialchars($adminFeedback) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Acciones del superadmin sobre el tenant ──────────────────── -->
    <?php if (!$tenantError): ?>
    <div class="ih-card ih-mt-6" style="border-left:4px solid #ef4444;">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-shield-fill-exclamation"></i> Control superadmin</h2>
          <div class="ih-card__subtitle">Acciones de recuperación / gestión sobre la configuración del tenant</div>
        </div>
      </div>
      <div class="ih-card__body">
        <table style="width:100%;border-collapse:collapse;font-size:14px;line-height:1.6;">
          <tr style="border-bottom:1px solid var(--ih-gray-100);">
            <td style="padding:12px 0;">
              <strong>Acceso alternativo (usuario+contraseña)</strong><br>
              <span class="ih-text-muted ih-text-sm">
                <?= ($tenantSettings['disable_local_login'] ?? '0') === '1'
                    ? 'Actualmente <strong style="color:#ef4444;">DESHABILITADO</strong> — solo SSO.'
                    : 'Actualmente <strong style="color:#10b981;">habilitado</strong> — fallback con usuario+pass.' ?>
              </span>
            </td>
            <td style="text-align:right;padding:12px 0;">
              <form method="POST" style="display:inline-block;">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="tenant_setting">
                <input type="hidden" name="setting_key" value="disable_local_login">
                <input type="hidden" name="setting_value" value="<?= ($tenantSettings['disable_local_login'] ?? '0') === '1' ? '0' : '1' ?>">
                <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm">
                  <?= ($tenantSettings['disable_local_login'] ?? '0') === '1' ? 'Habilitar' : 'Deshabilitar' ?>
                </button>
              </form>
            </td>
          </tr>

          <?php if (!empty($cloudConfig['google'])): ?>
          <tr style="border-bottom:1px solid var(--ih-gray-100);">
            <td style="padding:12px 0;">
              <strong>Cloud config Google</strong><br>
              <span class="ih-text-muted ih-text-sm">
                Keys: <?= htmlspecialchars(implode(', ', $cloudConfig['google'])) ?>
              </span>
            </td>
            <td style="text-align:right;padding:12px 0;">
              <form method="POST" style="display:inline-block;" onsubmit="return confirm('Borrar TODAS las credenciales Google de este tenant?');">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="clear_cloud_config">
                <input type="hidden" name="provider" value="google">
                <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
                  <i class="bi bi-trash"></i> Limpiar config Google
                </button>
              </form>
            </td>
          </tr>
          <?php endif; ?>

          <?php if (!empty($cloudConfig['microsoft'])): ?>
          <tr style="border-bottom:1px solid var(--ih-gray-100);">
            <td style="padding:12px 0;">
              <strong>Cloud config Microsoft</strong><br>
              <span class="ih-text-muted ih-text-sm">
                Keys: <?= htmlspecialchars(implode(', ', $cloudConfig['microsoft'])) ?>
              </span>
            </td>
            <td style="text-align:right;padding:12px 0;">
              <form method="POST" style="display:inline-block;" onsubmit="return confirm('Borrar TODAS las credenciales Microsoft de este tenant?');">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="clear_cloud_config">
                <input type="hidden" name="provider" value="microsoft">
                <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
                  <i class="bi bi-trash"></i> Limpiar config Microsoft
                </button>
              </form>
            </td>
          </tr>
          <?php endif; ?>

          <!-- Sincronizar provider -->
          <tr style="border-bottom:1px solid var(--ih-gray-100);">
            <td style="padding:12px 0;">
              <strong>Provider configurado en master</strong><br>
              <span class="ih-text-muted ih-text-sm">
                <code style="background:var(--ih-gray-100);padding:2px 6px;border-radius:4px;font-size:11px;"><?= htmlspecialchars($company['provider'] ?? '') ?></code>
                — si el cliente cambió el provider y la lista lo muestra desactualizado, sincronizalo desde el cloud_config del tenant.
              </span>
            </td>
            <td style="text-align:right;padding:12px 0;">
              <form method="POST" style="display:inline-block;">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="sync_provider_from_cloud">
                <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm">
                  <i class="bi bi-arrow-repeat"></i> Sincronizar provider
                </button>
              </form>
            </td>
          </tr>

          <!-- Custom roles del tenant -->
          <?php if (!empty($tenantCustomRoles)): ?>
          <tr style="border-bottom:1px solid var(--ih-gray-100);">
            <td style="padding:12px 0;">
              <strong>Roles personalizados del cliente</strong><br>
              <span class="ih-text-muted ih-text-sm">
                <?php foreach ($tenantCustomRoles as $rk => $rlbl): ?>
                  <span class="ih-badge ih-badge--purple" style="margin-right:4px;font-size:11px;">
                    <i class="bi bi-stars"></i> <?= htmlspecialchars($rlbl) ?>
                    <code style="opacity:.7;margin-left:4px;"><?= htmlspecialchars($rk) ?></code>
                  </span>
                <?php endforeach; ?>
              </span>
            </td>
            <td style="text-align:right;padding:12px 0;color:var(--ih-gray-500);font-size:12px;">
              <?= $tenantPermOverrides ?> override(s) de permisos
            </td>
          </tr>
          <?php endif; ?>

          <!-- Backup completo -->
          <tr>
            <td style="padding:12px 0;">
              <strong>Respaldo completo del tenant</strong><br>
              <span class="ih-text-muted ih-text-sm">
                Descarga un JSON con TODOS los datos: productos, movimientos, usuarios (hashes), cloud_config,
                tenant_settings, roles personalizados y overrides de permisos. Sin archivos binarios (los archivos viven en Drive/OneDrive).
              </span>
            </td>
            <td style="text-align:right;padding:12px 0;">
              <a href="/dashboard/empresa_backup.php?id=<?= (int)$id ?>" class="ih-btn ih-btn--primary ih-btn--sm">
                <i class="bi bi-download"></i> Descargar respaldo
              </a>
            </td>
          </tr>
        </table>
      </div>
    </div>
    <?php endif; ?>

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
                <th style="text-align:right;">Acciones</th>
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
                  <td style="text-align:right;white-space:nowrap;">
                    <!-- Reset password -->
                    <form method="POST" style="display:inline-block;" onsubmit="var p=prompt('Nueva contraseña para <?= htmlspecialchars($u['username']) ?> (min 8 caracteres):'); if(!p||p.length<8){alert('Cancelado o muy corta');return false;} this.new_password.value=p; return confirm('¿Resetear contraseña de '+<?= json_encode($u['username']) ?>+'? Se forzará cambio al próximo login.');">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="admin_action" value="reset_user_password">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="new_password" value="">
                      <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" title="Resetear contraseña">
                        <i class="bi bi-key"></i>
                      </button>
                    </form>
                    <!-- Toggle activo -->
                    <form method="POST" style="display:inline-block;" onsubmit="return confirm(<?= json_encode(($isActive ? 'Desactivar' : 'Activar') . ' al usuario ' . $u['username'] . '?') ?>);">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="admin_action" value="toggle_user_active">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="ih-btn <?= $isActive ? 'ih-btn--ghost' : 'ih-btn--success' ?> ih-btn--sm" title="<?= $isActive ? 'Desactivar' : 'Activar' ?>">
                        <i class="bi <?= $isActive ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
                      </button>
                    </form>
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
