<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';
require_once __DIR__ . '/../includes/cloud_icons.php';
require_once __DIR__ . '/../core/security/csrf.php';
/* Sólo por TENANT_ROLE_LABELS: los nombres de los roles tienen que ser los
   mismos que ve el cliente. El archivo no hace nada al cargarse. */
require_once __DIR__ . '/../core/security/permissions.php';

superadmin_require_login();
if (!platform_can('company.view')) {
    http_response_code(403);
    exit('Sin permiso para ver empresas.');
}
// Las acciones de esta pantalla (resetear contraseñas de usuarios del tenant,
// activarlos/desactivarlos, borrar cloud_config) son de gestión, no de lectura.
// Antes alcanzaba con estar logueado: un rol `viewer` podía resetear la
// contraseña de cualquier usuario de cualquier empresa.
$puedeGestionar = platform_can('tenant_user.manage');
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
        /* Sin cortar la página: acá el aviso va dentro de la pantalla, al lado
           del resto de la información de la empresa. `csrf_validate()` la
           reemplazaría entera. */
        if (!csrf_ok()) {
            $adminFeedback = 'La validación de seguridad falló. Recargá la página.';
            $adminFeedbackOk = false;
        } elseif (!$puedeGestionar) {
            $adminFeedback = 'Tu rol no tiene permiso para administrar usuarios de empresas.';
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
                        $adminFeedback = 'El proveedor de identidad no es válido.';
                        $adminFeedbackOk = false;
                    }
                    break;

                /* `reset_user_password` y `toggle_user_active` vivian acá y se
                   fueron con los botones que los llamaban: las dos acciones son
                   de `empresa_usuarios.php`, que ya las hace con los modales y
                   las confirmaciones del sistema. Dejar el `case` sin el botón
                   habría conservado un camino para cambiarle la contraseña a
                   alguien, o desactivarlo, sin pasar por ninguna pantalla. */

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
    /* Un rol propio de la empresa —«auditor_externo»— se mostraba con la clave
       cruda. Se busca su nombre en los que trae la empresa y, si ni eso, se
       humaniza: guiones bajos a espacios y la primera en mayúscula. Mejor eso
       que un `snake_case` en pantalla. */
    global $tenantCustomRoles;
    $texto = TENANT_ROLE_LABELS[$role]
        ?? ($tenantCustomRoles[$role] ?? ucfirst(str_replace('_', ' ', $role)));
    $clase = match($role) {
        'admin'   => 'ih-badge ih-badge--purple',
        'manager' => 'ih-badge ih-badge--info',
        'user'    => 'ih-badge ih-badge--success',
        'viewer'  => 'ih-badge',
        default   => 'ih-badge',
    };
    return '<span class="' . $clase . '">' . htmlspecialchars($texto) . '</span>';
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
        <div>No se pudo conectar a la base de datos de la empresa: <?= htmlspecialchars($tenantError) ?></div>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="ih-stats">
      <div class="ih-stat ih-stat--<?= (int)($company['active'] ?? 1) === 1 ? 'green' : 'danger' ?>">
        <div class="ih-stat__label">Estado <i class="bi bi-circle-fill"></i></div>
        <div class="ih-stat__value ih-stat__value--texto"><?= (int)($company['active'] ?? 1) === 1 ? 'Activa' : 'Inactiva' ?></div>
        <div class="ih-stat__delta">Empresa #<?= $id ?></div>
      </div>
      <div class="ih-stat">
        <div class="ih-stat__label">Usuarios <i class="bi bi-people"></i></div>
        <div class="ih-stat__value"><?= $stats['users_total'] ?></div>
        <div class="ih-stat__delta"><?= $stats['users_active'] ?> de alta</div>
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

    <div class="ih-mosaico">

      <!-- Info general -->
      <div class="ih-card">
        <div class="ih-card__header">
          <h2 class="ih-card__title"><i class="bi bi-building"></i> Información</h2>
        </div>
        <div class="ih-card__body">
          <?php /* `.ih-datos`, el mismo componente que el detalle de un activo. Era
                   una <table> escrita a mano, y una tabla no se encoge: a 752 px se
                   salía de la tarjeta y lo que sobraba quedaba recortado sin forma
                   de llegar. */ ?>
          <div class="ih-datos">
            <div class="ih-dato"><span class="ih-dato__label">Nombre</span>
              <span class="ih-dato__valor"><?= htmlspecialchars($company['nombre']) ?></span></div>
            <?php if (!empty($company['razon_social'])): ?>
            <div class="ih-dato"><span class="ih-dato__label">Razón social</span>
              <span class="ih-dato__valor"><?= htmlspecialchars($company['razon_social']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($company['cuit'])): ?>
            <div class="ih-dato"><span class="ih-dato__label">CUIT</span>
              <span class="ih-dato__valor"><?= htmlspecialchars($company['cuit']) ?></span></div>
            <?php endif; ?>
            <div class="ih-dato"><span class="ih-dato__label">Proveedor</span>
              <span class="ih-dato__valor">
                <?php if ($company['provider'] === 'google'): ?>
                  <span class="ih-badge ih-badge--neutral"><?= marca_svg('drive', 14) ?> Google Drive</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--neutral"><?= marca_svg('onedrive', 14) ?> Microsoft OneDrive</span>
                <?php endif; ?>
              </span></div>
            <div class="ih-dato"><span class="ih-dato__label">Modo de base</span>
              <span class="ih-dato__valor">
                <?php if ($company['tenant_mode'] === 'external'): ?>
                  <span class="ih-badge ih-badge--purple">Base propia</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--warning">Con prefijo</span>
                <?php endif; ?>
              </span></div>
            <div class="ih-dato"><span class="ih-dato__label">Alta</span>
              <span class="ih-dato__valor"><?= htmlspecialchars(substr((string)($company['created_at'] ?? ''), 0, 10)) ?></span></div>
          </div>
        </div>
      </div>

      <!-- Acceso y cloud config -->
      <div class="ih-card">
        <div class="ih-card__header">
          <h2 class="ih-card__title"><i class="bi bi-link-45deg"></i> Acceso y credenciales</h2>
        </div>
        <div class="ih-card__body">
          <div class="ih-datos">
            <div class="ih-dato"><span class="ih-dato__label">Subdominio</span><span class="ih-dato__valor"><?= $company['subdomain'] ? htmlspecialchars($company['subdomain']) . '.intellihelp.tech' : '<span class="ih-text-muted">—</span>' ?></span></div>
            <div class="ih-dato"><span class="ih-dato__label">Dirección de acceso</span><span class="ih-dato__valor"><?php if (!empty($company['login_url'])): ?>
                  <a href="<?= htmlspecialchars($company['login_url']) ?>" target="_blank" rel="noopener" class="ih-text-sm">
                    <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($company['login_url']) ?>
                  </a>
                <?php else: ?>
                  <span class="ih-text-muted">— sin URL —</span>
                <?php endif; ?></span></div>
            <?php if ($company['tenant_mode'] === 'external'): ?>
            <div class="ih-dato"><span class="ih-dato__label">Servidor</span><span class="ih-dato__valor"><?= htmlspecialchars($company['db_host'] ?? 'localhost') ?></span></div>
            <div class="ih-dato"><span class="ih-dato__label">Base</span><span class="ih-dato__valor"><?= htmlspecialchars($company['db_name'] ?? '') ?></span></div>
            <div class="ih-dato"><span class="ih-dato__label">Usuario</span><span class="ih-dato__valor"><?= htmlspecialchars($company['db_user'] ?? '') ?></span></div>
            <?php endif; ?>
            <div class="ih-dato"><span class="ih-dato__label">Credenciales</span><span class="ih-dato__valor"><?php
                $googleKeys    = $cloudConfig['google']    ?? [];
                $microsoftKeys = $cloudConfig['microsoft'] ?? [];
                $hasGoogle    = in_array('client_id', $googleKeys, true) && in_array('client_secret', $googleKeys, true);
                $hasMicrosoft = in_array('client_id', $microsoftKeys, true) && in_array('client_secret', $microsoftKeys, true);
                ?>
                <?php if ($tenantError): ?>
                  <span class="ih-badge ih-badge--warning">Sin acceso a su base</span>
                <?php elseif ($company['provider'] === 'google' && $hasGoogle): ?>
                  <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle"></i> Google OK</span>
                <?php elseif ($company['provider'] === 'microsoft' && $hasMicrosoft): ?>
                  <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle"></i> Microsoft OK</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--danger"><i class="bi bi-exclamation-circle"></i> No configuradas</span>
                <?php endif; ?></span></div>
          </div>
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
    <div class="ih-card ih-mt-6 ih-card--peligro">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-shield-fill-exclamation"></i> Control superadmin</h2>
          <div class="ih-card__subtitle">Lo que se puede tocar de esta empresa desde acá</div>
        </div>
      </div>
      <div class="ih-card__body">
        <?php /* Era una <table> con los bordes y el relleno escritos a mano en
                 cada <div>. Es una lista de «esto se puede hacer», no una tabla de
                 datos: `.ih-fila-lista` la dibuja y además se acomoda sola cuando
                 la pantalla es angosta, que una tabla no hace. */ ?>
        <div>
          <div class="ih-fila-lista">
            <div class="ih-fila-lista__texto">
              <strong>Acceso alternativo (usuario+contraseña)</strong><br>
              <span class="ih-text-muted ih-text-sm">
                <?= ($tenantSettings['disable_local_login'] ?? '0') === '1'
                    ? 'Actualmente <strong class="ih-text-danger">deshabilitado</strong> — se entra sólo con SSO.'
                    : 'Actualmente <strong class="ih-text-success">habilitado</strong> — queda la salida con usuario y contraseña.' ?>
              </span>
            </div>
            <div>
              <form method="POST">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="tenant_setting">
                <input type="hidden" name="setting_key" value="disable_local_login">
                <input type="hidden" name="setting_value" value="<?= ($tenantSettings['disable_local_login'] ?? '0') === '1' ? '0' : '1' ?>">
                <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm">
                  <?= ($tenantSettings['disable_local_login'] ?? '0') === '1' ? 'Habilitar' : 'Deshabilitar' ?>
                </button>
              </form>
            </div>
          </div>

          <?php if (!empty($cloudConfig['google'])): ?>
          <div class="ih-fila-lista">
            <div class="ih-fila-lista__texto">
              <strong>Cloud config Google</strong><br>
              <span class="ih-text-muted ih-text-sm">
                Claves guardadas: <?= htmlspecialchars(implode(', ', $cloudConfig['google'])) ?>
              </span>
            </div>
            <div>
              <form method="POST" onsubmit="return confirm('¿Borrar todas las credenciales de Google de esta empresa?');">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="clear_cloud_config">
                <input type="hidden" name="provider" value="google">
                <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
                  <i class="bi bi-trash"></i> Limpiar config Google
                </button>
              </form>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($cloudConfig['microsoft'])): ?>
          <div class="ih-fila-lista">
            <div class="ih-fila-lista__texto">
              <strong>Cloud config Microsoft</strong><br>
              <span class="ih-text-muted ih-text-sm">
                Claves guardadas: <?= htmlspecialchars(implode(', ', $cloudConfig['microsoft'])) ?>
              </span>
            </div>
            <div>
              <form method="POST" onsubmit="return confirm('¿Borrar todas las credenciales de Microsoft de esta empresa?');">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="clear_cloud_config">
                <input type="hidden" name="provider" value="microsoft">
                <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
                  <i class="bi bi-trash"></i> Limpiar config Microsoft
                </button>
              </form>
            </div>
          </div>
          <?php endif; ?>

          <!-- Sincronizar provider -->
          <div class="ih-fila-lista">
            <div class="ih-fila-lista__texto">
              <strong>Provider configurado en master</strong><br>
              <span class="ih-text-muted ih-text-sm">
                <code class="ih-text-xs"><?= htmlspecialchars($company['provider'] ?? '') ?></code>
                — si la empresa cambió de nube y la lista quedó vieja, se sincroniza desde su configuración.
              </span>
            </div>
            <div>
              <form method="POST">
                <?php csrf_input(); ?>
                <input type="hidden" name="admin_action" value="sync_provider_from_cloud">
                <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm">
                  <i class="bi bi-arrow-repeat"></i> Sincronizar provider
                </button>
              </form>
            </div>
          </div>

          <!-- Custom roles del tenant -->
          <?php if (!empty($tenantCustomRoles)): ?>
          <div class="ih-fila-lista">
            <div class="ih-fila-lista__texto">
              <strong>Roles personalizados del cliente</strong><br>
              <span class="ih-text-muted ih-text-sm">
                <?php foreach ($tenantCustomRoles as $rk => $rlbl): ?>
                  <span class="ih-badge ih-badge--purple">
                    <i class="bi bi-stars"></i> <?= htmlspecialchars($rlbl) ?>
                    <code><?= htmlspecialchars($rk) ?></code>
                  </span>
                <?php endforeach; ?>
              </span>
            </div>
            <div>
              <?= $tenantPermOverrides ?> permisos cambiados
            </div>
          </div>
          <?php endif; ?>

          <!-- Backup completo -->
          <div class="ih-fila-lista">
            <div class="ih-fila-lista__texto">
              <strong>Respaldo completo de la empresa</strong><br>
              <span class="ih-text-muted ih-text-sm">
                Descarga un JSON con TODOS los datos: productos, movimientos, usuarios (hashes), cloud_config,
                tenant_settings, roles personalizados y overrides de permisos. Sin archivos binarios (los archivos viven en Drive/OneDrive).
              </span>
            </div>
            <div>
              <?php if (platform_can('company.backup')): ?>
                <a href="/dashboard/empresa_backup.php?id=<?= (int)$id ?>" class="ih-btn ih-btn--primary ih-btn--sm">
                  <i class="bi bi-download"></i> Descargar respaldo
                </a>
              <?php else: ?>
                <span class="ih-text-xs ih-text-muted">Requiere rol superadmin</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Usuarios del tenant -->
    <?php if (!$tenantError): ?>
    <div class="ih-card ih-mt-6">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-people"></i> Usuarios de la empresa</h2>
          <div class="ih-card__subtitle">
            <?php
              $deBaja = 0;
              foreach ($tenantUsers as $uu) { if ((int)($uu['active'] ?? 1) !== 1) { $deBaja++; } }
              echo (count($tenantUsers) - $deBaja) . ' de alta' . ($deBaja > 0 ? " · {$deBaja} de baja" : '');
            ?>
          </div>
        </div>
        <?php /* Acá se mira; se toca en «Usuarios», que es la pantalla hecha
                 para eso. Tener los mismos botones en dos lugares significo
                 durante un tiempo que acá estaban peor: el de desactivar salia
                 sin confirmación y el de la contraseña usaba el prompt() del
                 navegador. */ ?>
        <a href="/dashboard/empresa_usuarios.php?id=<?= $id ?>"
           class="ih-btn ih-btn--outline ih-btn--sm">
          <i class="bi bi-pencil-square"></i> Administrar usuarios
        </a>
      </div>
      <?php if (empty($tenantUsers)): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-person-x"></i></div>
          <div class="ih-empty__title">Sin usuarios</div>
          <div class="ih-empty__text">Esta empresa todavía no tiene usuarios.</div>
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
                <tr<?= $isActive ? '' : ' class="es-inactiva"' ?>>
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
