<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/platform_permissions_db.php';

superadmin_require_login();
// Solo superadmin puede editar permisos de roles platform
if (($_SESSION['superadmin_role'] ?? '') !== 'superadmin') {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}
csrf_start();

$db = new MasterDB();

$feedback = ''; $feedbackOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF chequeo manual (csrf_validate hace die)
    $csrfOk = isset($_POST[CSRF_TOKEN_NAME])
              && hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', (string)$_POST[CSRF_TOKEN_NAME]);
    if (!$csrfOk) {
        $feedback = 'CSRF invalido. Recargá la página.'; $feedbackOk = false;
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $role  = (string)($_POST['role'] ?? '');
            $perms = $_POST['perms'] ?? [];
            if (!is_array($perms)) $perms = [];
            $valid = array_keys(platform_permissions_matrix());
            $perms = array_values(array_intersect($perms, $valid));

            if ($role === 'superadmin') {
                $feedback = 'No se puede editar el rol superadmin (siempre tiene todos los permisos).';
                $feedbackOk = false;
            } elseif (in_array($role, PLATFORM_ROLES, true)) {
                if (platform_perms_set($db->conn, $role, $perms)) {
                    $feedback = 'Permisos guardados para ' . (PLATFORM_ROLE_LABELS[$role] ?? $role) . ' (' . count($perms) . ' acciones).';
                } else {
                    $feedback = 'Error al guardar.'; $feedbackOk = false;
                }
            }
        } elseif ($action === 'reset') {
            $role = (string)($_POST['role'] ?? '');
            if (in_array($role, PLATFORM_ROLES, true) && $role !== 'superadmin') {
                if (platform_perms_reset($db->conn, $role)) {
                    $feedback = 'Permisos restaurados a los valores por defecto para ' . (PLATFORM_ROLE_LABELS[$role] ?? $role) . '.';
                } else {
                    $feedback = 'Error al restaurar.'; $feedbackOk = false;
                }
            }
        }
    }
}

// Cargar estado
$rolePerms = [];
$roleOverrides = [];
foreach (PLATFORM_ROLES as $r) {
    $rolePerms[$r]     = platform_perms_effective($db->conn, $r);
    $roleOverrides[$r] = platform_perms_has_overrides($db->conn, $r);
}

$groups = platform_perms_grouped();

$page_title = 'Roles y permisos · Superadmin';
$page_section = 'roles';
$topbar_title    = 'Roles y permisos';
$topbar_subtitle = 'Plataforma — usuarios superadmin';
$topbar_user     = $_SESSION['superadmin_username'] ?? 'superadmin';
$topbar_email    = $_SESSION['superadmin_email']    ?? '';
$topbar_pic      = $_SESSION['superadmin_pic']      ?? '';
$topbar_actions  = '';

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_superadmin.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

<style>
.role-tab { padding: 10px 18px; border: 1px solid #e5e1ec; background: #fff; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 14px; color: #6b6b7b; }
.role-tab.is-active { background: #663399; color: #fff; border-color: #663399; }
.role-tab .badge { font-size: 10px; margin-left: 6px; padding: 2px 6px; border-radius: 8px; background: #f59e0b; color: #fff; }
.role-tab.is-active .badge { background: rgba(255,255,255,.25); }
.perm-group { background: #fff; border: 1px solid #e5e1ec; border-radius: 10px; margin-bottom: 14px; }
.perm-group__title { padding: 12px 16px; border-bottom: 1px solid #f1eef7; font-weight: 700; color: #1a1a2e; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; background: #faf9fc; border-radius: 10px 10px 0 0; }
.perm-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid #f1eef7; }
.perm-row:last-child { border-bottom: 0; }
.perm-row label { display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1; user-select: none; }
.perm-row input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
.perm-row .perm-key { color: #999; font-size: 11px; font-family: monospace; margin-left: auto; }
</style>

<div class="ih-container" style="max-width:1100px;padding:24px;">
  <h1 style="font-size:24px;color:#1a1a2e;margin:0 0 6px;font-weight:700;">
    <i class="bi bi-shield-lock-fill" style="color:#663399;"></i> Roles y permisos
  </h1>
  <p style="color:#6b6b7b;margin:0 0 24px;">Configurá qué puede hacer cada rol superadmin en la plataforma.</p>

  <?php if ($feedback !== ''): ?>
    <div class="ih-alert <?= $feedbackOk ? 'ih-alert--success' : 'ih-alert--warning' ?>" style="margin-bottom:20px;">
      <i class="bi bi-<?= $feedbackOk ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
      <div><?= htmlspecialchars($feedback) ?></div>
    </div>
  <?php endif; ?>

  <div class="ih-alert ih-alert--info" style="margin-bottom:20px;">
    <i class="bi bi-info-circle-fill"></i>
    <div>
      El rol <strong>Super Administrador</strong> siempre mantiene todos los permisos (protección anti-lockout).
      Para los demás roles podés ajustar permisos. Si dejás un rol sin overrides, se usan los defaults del sistema.
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (PLATFORM_ROLES as $r): ?>
      <button class="role-tab" data-role="<?= $r ?>" type="button">
        <?= htmlspecialchars(PLATFORM_ROLE_LABELS[$r] ?? $r) ?>
        <?php if ($r === 'superadmin'): ?>
          <span class="badge" style="background:#10b981;">PROTEGIDO</span>
        <?php elseif ($roleOverrides[$r]): ?>
          <span class="badge">CUSTOM</span>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach (PLATFORM_ROLES as $r): ?>
  <div class="role-panel" data-role-panel="<?= $r ?>" style="display:none;">
    <form method="POST" action="/dashboard/superadmin_roles.php" id="form-role-<?= $r ?>">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= htmlspecialchars($_SESSION[CSRF_TOKEN_NAME] ?? '') ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="role" value="<?= $r ?>">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div>
          <h3 style="margin:0;font-size:18px;color:#1a1a2e;font-weight:700;">
            <?= htmlspecialchars(PLATFORM_ROLE_LABELS[$r] ?? $r) ?>
            <?php if ($r === 'superadmin'): ?>
              <span class="ih-badge ih-badge--success" style="margin-left:8px;font-size:11px;">PROTEGIDO</span>
            <?php elseif ($roleOverrides[$r]): ?>
              <span class="ih-badge ih-badge--warning" style="margin-left:8px;font-size:11px;">Custom</span>
            <?php else: ?>
              <span class="ih-badge ih-badge--success" style="margin-left:8px;font-size:11px;">Default</span>
            <?php endif; ?>
          </h3>
          <p style="margin:4px 0 0;color:#6b6b7b;font-size:13px;">
            <?= $r === 'superadmin' ? 'Acceso total — no editable' : (count($rolePerms[$r]) . ' permisos activos') ?>
          </p>
        </div>
        <?php if ($r !== 'superadmin'): ?>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="ih-btn ih-btn--primary">
            <i class="bi bi-check2"></i> Guardar cambios
          </button>
          <?php if ($roleOverrides[$r]): ?>
            <button type="submit" class="ih-btn ih-btn--ghost"
                    onclick="if(!confirm('Restaurar permisos por defecto?')){return false;} this.form.elements['action'].value='reset';">
              <i class="bi bi-arrow-counterclockwise"></i> Restaurar default
            </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php foreach ($groups as $groupName => $perms): ?>
        <div class="perm-group">
          <div class="perm-group__title"><?= htmlspecialchars($groupName) ?></div>
          <?php foreach ($perms as $action => $label):
            $checked = in_array($action, $rolePerms[$r], true);
            $isProtected = ($r === 'superadmin');
          ?>
            <div class="perm-row">
              <label>
                <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($action) ?>"
                       <?= $checked ? 'checked' : '' ?>
                       <?= $isProtected ? 'disabled' : '' ?>>
                <span><?= htmlspecialchars($label) ?></span>
              </label>
              <span class="perm-key"><?= htmlspecialchars($action) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<script>
const tabs = document.querySelectorAll('.role-tab');
const panels = document.querySelectorAll('.role-panel');
function showRole(r) {
  tabs.forEach(t => t.classList.toggle('is-active', t.dataset.role === r));
  panels.forEach(p => p.style.display = p.dataset.rolePanel === r ? 'block' : 'none');
  try { localStorage.setItem('sa_roles_last_tab', r); } catch {}
}
tabs.forEach(t => t.addEventListener('click', () => showRole(t.dataset.role)));
const lastTab = (() => { try { return localStorage.getItem('sa_roles_last_tab'); } catch { return null; }})();
showRole(lastTab && document.querySelector('[data-role-panel="'+lastTab+'"]') ? lastTab : 'admin');
</script>

</main>
</div>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
