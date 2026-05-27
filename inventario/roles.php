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
require_once __DIR__ . '/../core/security/permissions_db.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

// Solo admins
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    $cid = (int)($_SESSION['company_id'] ?? 0);
    header('Location: /auth/login.php' . ($cid ? '?company=' . $cid : ''));
    exit;
}
$usersTable = tn('users');
$stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
require_permission('user.edit', $user);

$feedback = ''; $feedbackOk = true;

// ── Procesar POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '', 'roles_edit')) {
        $feedback = 'CSRF invalido. Recargá la página.';
        $feedbackOk = false;
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            $role     = (string)($_POST['role'] ?? '');
            $perms    = $_POST['perms'] ?? [];
            if (!is_array($perms)) $perms = [];
            $valid    = array_keys(tenant_permissions_matrix());
            $perms    = array_values(array_intersect($perms, $valid));

            // Proteccion: admin siempre debe tener user.edit + integration.edit
            if ($role === 'admin') {
                $mustHave = ['user.view','user.edit','integration.view','integration.edit'];
                foreach ($mustHave as $mh) {
                    if (!in_array($mh, $perms, true)) $perms[] = $mh;
                }
            }

            if (in_array($role, TENANT_ROLES, true)) {
                if (role_perms_set($conn, $role, $perms)) {
                    log_event((string)$user_id, 'PERMS_SAVE', "Permisos custom guardados para rol {$role} (" . count($perms) . " acciones)", 'auth');
                    $feedback = 'Permisos guardados correctamente para el rol ' . role_label($role) . '.';
                } else {
                    $feedback = 'Error al guardar permisos.';
                    $feedbackOk = false;
                }
            } else {
                $feedback = 'Rol invalido.';
                $feedbackOk = false;
            }
        } elseif ($action === 'reset') {
            $role = (string)($_POST['role'] ?? '');
            if (in_array($role, TENANT_ROLES, true)) {
                if (role_perms_reset($conn, $role)) {
                    log_event((string)$user_id, 'PERMS_RESET', "Permisos restaurados a default para rol {$role}", 'auth');
                    $feedback = 'Permisos del rol ' . role_label($role) . ' restaurados a los valores por defecto.';
                } else {
                    $feedback = 'Error al restaurar.';
                    $feedbackOk = false;
                }
            }
        }
    }
}

$csrf_token = generateCsrfToken('roles_edit');

// Cargar estado actual de cada rol
$rolePerms     = [];
$roleOverrides = [];
foreach (TENANT_ROLES as $r) {
    $rolePerms[$r]     = role_perms_effective($conn, $r);
    $roleOverrides[$r] = role_perms_has_overrides($conn, $r);
}

$permGroups = permissions_grouped();

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

$page_title      = 'Roles y permisos — Asset Vault';
$page_section    = 'roles';
$topbar_title    = 'Roles y permisos';
$topbar_subtitle = 'Personalizá qué puede hacer cada rol en tu empresa';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> Volver</a>';

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main">

<?php if ($feedback !== ''): ?>
  <div class="ih-alert <?= $feedbackOk ? 'ih-alert--success' : 'ih-alert--warning' ?>" style="margin-bottom:20px;">
    <i class="bi bi-<?= $feedbackOk ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
    <div><?= htmlspecialchars($feedback) ?></div>
  </div>
<?php endif; ?>

<div class="ih-alert ih-alert--info" style="margin-bottom:20px;">
  <i class="bi bi-info-circle-fill"></i>
  <div>
    Configurá qué acciones puede realizar cada rol en tu empresa. Si dejás un rol sin overrides,
    se usan los <strong>permisos por defecto</strong> del sistema. El rol <strong>Administrador</strong>
    siempre mantiene Ver/Editar usuarios e integraciones (para evitar lockouts).
  </div>
</div>

<style>
.role-tab { padding: 10px 18px; border: 1px solid var(--ih-gray-200); background: #fff; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 14px; color: var(--ih-gray-700); }
.role-tab.is-active { background: var(--ih-primary); color: #fff; border-color: var(--ih-primary); }
.role-tab .badge { font-size: 10px; margin-left: 6px; padding: 2px 6px; border-radius: 8px; }
.role-tab.is-active .badge { background: rgba(255,255,255,.25); }
.role-tab:not(.is-active) .badge { background: var(--ih-warning); color: #fff; }
.perm-group { background: #fff; border: 1px solid var(--ih-gray-200); border-radius: 10px; margin-bottom: 14px; }
.perm-group__title { padding: 12px 16px; border-bottom: 1px solid var(--ih-gray-100); font-weight: 700; color: var(--ih-gray-800); font-size: 13px; text-transform: uppercase; letter-spacing: .04em; background: var(--ih-gray-50); border-radius: 10px 10px 0 0; }
.perm-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid var(--ih-gray-100); }
.perm-row:last-child { border-bottom: 0; }
.perm-row label { display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1; user-select: none; }
.perm-row input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
.perm-row .perm-key { color: var(--ih-gray-400); font-size: 11px; font-family: monospace; margin-left: auto; }
</style>

<!-- Tabs de rol -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
  <?php foreach (TENANT_ROLES as $r): ?>
    <button class="role-tab" data-role="<?= $r ?>">
      <?= htmlspecialchars(role_label($r)) ?>
      <?php if ($roleOverrides[$r]): ?><span class="badge">CUSTOM</span><?php endif; ?>
    </button>
  <?php endforeach; ?>
</div>

<?php foreach (TENANT_ROLES as $r): ?>
<div class="role-panel" data-role-panel="<?= $r ?>" style="display:none;">
  <form method="POST" action="/inventario/roles.php" id="form-role-<?= $r ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="role" value="<?= $r ?>">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
      <div>
        <h3 style="margin:0;font-size:var(--ih-text-lg);color:var(--ih-gray-800);font-weight:700;">
          <?= htmlspecialchars(role_label($r)) ?>
          <?php if ($roleOverrides[$r]): ?>
            <span class="ih-badge ih-badge--warning" style="margin-left:8px;font-size:11px;">Custom</span>
          <?php else: ?>
            <span class="ih-badge ih-badge--success" style="margin-left:8px;font-size:11px;">Default</span>
          <?php endif; ?>
        </h3>
        <p style="margin:4px 0 0;color:var(--ih-gray-500);font-size:var(--ih-text-sm);">
          <?= count($rolePerms[$r]) ?> permisos activos
        </p>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="ih-btn ih-btn--primary">
          <i class="bi bi-check2"></i> Guardar cambios
        </button>
        <?php if ($roleOverrides[$r]): ?>
          <button type="submit" class="ih-btn ih-btn--ghost"
                  formaction="/inventario/roles.php"
                  onclick="if(!confirm('Restaurar permisos por defecto?')){return false;} this.form.action.value='reset';">
            <i class="bi bi-arrow-counterclockwise"></i> Restaurar default
          </button>
        <?php endif; ?>
      </div>
    </div>

    <?php foreach ($permGroups as $groupName => $perms): ?>
      <div class="perm-group">
        <div class="perm-group__title"><?= htmlspecialchars($groupName) ?></div>
        <?php foreach ($perms as $action => $label):
          $checked = in_array($action, $rolePerms[$r], true);
          $isAdminProtected = ($r === 'admin' && in_array($action, ['user.view','user.edit','integration.view','integration.edit'], true));
        ?>
          <div class="perm-row">
            <label>
              <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($action) ?>"
                     <?= $checked ? 'checked' : '' ?>
                     <?= $isAdminProtected ? 'disabled' : '' ?>>
              <?php if ($isAdminProtected): ?>
                <input type="hidden" name="perms[]" value="<?= htmlspecialchars($action) ?>">
              <?php endif; ?>
              <span>
                <?= htmlspecialchars($label) ?>
                <?php if ($isAdminProtected): ?>
                  <span style="font-size:10px;color:var(--ih-gray-400);margin-left:6px;">(siempre activo para admin)</span>
                <?php endif; ?>
              </span>
            </label>
            <span class="perm-key"><?= htmlspecialchars($action) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </form>
</div>
<?php endforeach; ?>

<script>
const tabs = document.querySelectorAll('.role-tab');
const panels = document.querySelectorAll('.role-panel');
function showRole(r) {
  tabs.forEach(t => t.classList.toggle('is-active', t.dataset.role === r));
  panels.forEach(p => p.style.display = p.dataset.rolePanel === r ? 'block' : 'none');
  try { localStorage.setItem('av_roles_last_tab', r); } catch {}
}
tabs.forEach(t => t.addEventListener('click', () => showRole(t.dataset.role)));
const lastTab = (() => { try { return localStorage.getItem('av_roles_last_tab'); } catch { return null; }})();
showRole(lastTab && document.querySelector('[data-role-panel="'+lastTab+'"]') ? lastTab : 'admin');
</script>

</main>
</div>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
