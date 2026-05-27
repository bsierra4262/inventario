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
        $customRolesNow = custom_roles_all($conn);

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

            $isBuiltin = in_array($role, TENANT_ROLES, true);
            $isCustom  = array_key_exists($role, $customRolesNow);
            if ($isBuiltin || $isCustom) {
                if (role_perms_set($conn, $role, $perms)) {
                    log_event((string)$user_id, 'PERMS_SAVE', "Permisos guardados para rol {$role} (" . count($perms) . " acciones)", 'auth');
                    $lbl = $isBuiltin ? role_label($role) : $customRolesNow[$role];
                    $feedback = 'Permisos guardados correctamente para el rol ' . $lbl . '.';
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
        } elseif ($action === 'create_custom') {
            $newLabel = trim((string)($_POST['new_role_label'] ?? ''));
            $rawKey = trim((string)($_POST['new_role_key'] ?? ''));
            if ($rawKey === '') $rawKey = $newLabel; // fallback al nombre visible
            // ORDEN CORRECTO: strtolower PRIMERO, despues preg_replace.
            // Antes era al reves y las mayusculas se trataban como char invalido
            // (ej: "Auditor externo" → "_uditor_externo" → trim _ → "uditor_externo").
            $newKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($rawKey));
            $newKey = trim($newKey, '_');
            if ($newLabel === '') {
                $feedback = 'El nombre visible es obligatorio.'; $feedbackOk = false;
            } elseif ($newKey === '') {
                $feedback = 'No se pudo generar una clave tecnica valida desde el nombre. Probá agregando letras o numeros.'; $feedbackOk = false;
            } elseif (strlen($newKey) > 40) {
                $feedback = 'La clave tecnica es muy larga (max 40 caracteres). Acortala manualmente.'; $feedbackOk = false;
            } elseif (in_array($newKey, TENANT_ROLES, true)) {
                $feedback = 'Esa clave coincide con un rol del sistema. Cambia el nombre o ingresá una clave tecnica diferente.'; $feedbackOk = false;
            } else {
                if (custom_role_create($conn, $newKey, $newLabel)) {
                    log_event((string)$user_id, 'ROLE_CREATE', "Rol custom creado: {$newKey} ({$newLabel})", 'auth');
                    $feedback = 'Rol "' . $newLabel . '" creado. Ya podés asignar permisos.';
                } else {
                    $feedback = 'No se pudo crear el rol en la base de datos. Revisá los logs.'; $feedbackOk = false;
                }
            }
        } elseif ($action === 'delete_custom') {
            $role = (string)($_POST['role'] ?? '');
            if (in_array($role, TENANT_ROLES, true)) {
                $feedback = 'No se pueden eliminar roles del sistema.'; $feedbackOk = false;
            } else {
                // Chequear que no haya usuarios asignados a este rol antes de borrar
                $stmtChk = $conn->prepare("SELECT COUNT(*) c FROM `{$usersTable}` WHERE role = ?");
                $stmtChk->bind_param('s', $role);
                $stmtChk->execute();
                $countUsers = (int)($stmtChk->get_result()->fetch_assoc()['c'] ?? 0);
                $stmtChk->close();
                if ($countUsers > 0) {
                    $feedback = "No se puede eliminar — hay {$countUsers} usuario(s) con ese rol. Reasignalos primero.";
                    $feedbackOk = false;
                } else {
                    if (custom_role_delete($conn, $role)) {
                        log_event((string)$user_id, 'ROLE_DELETE', "Rol custom eliminado: {$role}", 'auth');
                        $feedback = 'Rol eliminado correctamente.';
                    } else {
                        $feedback = 'Error al eliminar.'; $feedbackOk = false;
                    }
                }
            }
        }
    }
}

$csrf_token = generateCsrfToken('roles_edit');

// Procesar boton de reparar tipos de columna (admin trigger manual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix_role_column') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '', 'roles_edit')) {
        $alterRes = $conn->query("ALTER TABLE `{$usersTable}` MODIFY COLUMN `role` VARCHAR(40) NOT NULL DEFAULT 'user'");
        if ($alterRes) {
            $feedback = 'Columna role migrada a VARCHAR(40) correctamente. Ya podes asignar roles personalizados.';
            $feedbackOk = true;
        } else {
            $feedback = 'No se pudo migrar la columna: ' . $conn->error;
            $feedbackOk = false;
        }
    }
}

// Diagnostico actual de la columna role
$colRoleType = '';
try {
    $cinfo = $conn->query("SHOW COLUMNS FROM `{$usersTable}` LIKE 'role'");
    if ($cinfo && $cr = $cinfo->fetch_assoc()) { $colRoleType = (string)$cr['Type']; }
} catch (Throwable) {}
$colIsEnum = str_starts_with(strtolower($colRoleType), 'enum');

// Cargar custom roles del tenant
$customRoles = custom_roles_all($conn);

// Cargar estado actual de cada rol (builtin + custom)
$rolePerms     = [];
$roleOverrides = [];
$roleLabels    = [];
foreach (TENANT_ROLES as $r) {
    $rolePerms[$r]     = role_perms_effective($conn, $r);
    $roleOverrides[$r] = role_perms_has_overrides($conn, $r);
    $roleLabels[$r]    = role_label($r);
}
foreach ($customRoles as $rk => $rlbl) {
    $rolePerms[$rk]     = role_perms_effective_v2($conn, $rk);
    $roleOverrides[$rk] = true; // custom roles siempre son "custom"
    $roleLabels[$rk]    = $rlbl;
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

<!-- Diagnostico: si la columna role sigue siendo ENUM, ofrecer reparacion -->
<?php if ($colIsEnum): ?>
<div class="ih-alert ih-alert--warning" style="margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div style="flex:1;min-width:280px;">
    <strong>Roles personalizados deshabilitados</strong><br>
    <span class="ih-text-sm">
      La columna <code>role</code> de tu base de datos es de tipo <code><?= htmlspecialchars($colRoleType) ?></code>,
      que solo acepta los 4 roles del sistema (admin, manager, user, viewer).
      Si asignás un rol personalizado a un usuario, MySQL lo rechaza y guarda vacío
      (por eso aparece "Sin rol"). Click acá para reparar:
    </span>
  </div>
  <form method="POST" action="/inventario/roles.php" style="margin:0;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="action" value="fix_role_column">
    <button type="submit" class="ih-btn ih-btn--primary ih-btn--sm">
      <i class="bi bi-tools"></i> Reparar columna role
    </button>
  </form>
</div>
<?php endif; ?>

<style>
.role-tab {
  padding: 10px 18px;
  border: 1px solid #e5e1ec;
  background: #ffffff !important;
  border-radius: 10px;
  cursor: pointer;
  font-weight: 600;
  font-size: 14px;
  color: #4a4a5e !important;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background .15s, color .15s, border-color .15s;
}
.role-tab:hover {
  border-color: #663399;
  background: #f7f5fb !important;
}
.role-tab.is-active {
  background: #663399 !important;
  color: #ffffff !important;
  border-color: #663399;
}
.role-tab--custom { border-color: #663399; }
.role-tab--new { border-style: dashed; color: #663399 !important; }
.role-tab--new:hover { background: #f0e8fa !important; }
.role-tab .badge {
  font-size: 10px;
  padding: 2px 6px;
  border-radius: 8px;
  background: #f59e0b;
  color: #ffffff;
  font-weight: 700;
}
.role-tab.is-active .badge { background: rgba(255,255,255,.25); color:#fff; }
.perm-group { background: #fff; border: 1px solid var(--ih-gray-200); border-radius: 10px; margin-bottom: 14px; }
.perm-group__title { padding: 12px 16px; border-bottom: 1px solid var(--ih-gray-100); font-weight: 700; color: var(--ih-gray-800); font-size: 13px; text-transform: uppercase; letter-spacing: .04em; background: var(--ih-gray-50); border-radius: 10px 10px 0 0; }
.perm-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid var(--ih-gray-100); }
.perm-row:last-child { border-bottom: 0; }
.perm-row label { display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1; user-select: none; }
.perm-row input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
.perm-row .perm-key { color: var(--ih-gray-400); font-size: 11px; font-family: monospace; margin-left: auto; }
</style>

<!-- Tabs de rol -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
  <?php foreach (TENANT_ROLES as $r): ?>
    <button class="role-tab" data-role="<?= $r ?>" type="button">
      <?= htmlspecialchars($roleLabels[$r]) ?>
      <?php if ($roleOverrides[$r]): ?><span class="badge">CUSTOM</span><?php endif; ?>
    </button>
  <?php endforeach; ?>
  <?php foreach ($customRoles as $rk => $rlbl): ?>
    <button class="role-tab role-tab--custom" data-role="<?= htmlspecialchars($rk) ?>" type="button">
      <i class="bi bi-stars" style="font-size:11px;"></i>
      <?= htmlspecialchars($rlbl) ?>
    </button>
  <?php endforeach; ?>
  <button class="role-tab role-tab--new" data-role="__new__" type="button">
    <i class="bi bi-plus-lg"></i> Nuevo rol
  </button>
</div>

<!-- Panel: nuevo rol -->
<div class="role-panel" data-role-panel="__new__" style="display:none;">
  <div class="ih-card" style="max-width:540px;">
    <div class="ih-card__body" style="padding:24px;">
      <h3 style="margin:0 0 6px;font-size:var(--ih-text-lg);color:var(--ih-gray-800);font-weight:700;">
        <i class="bi bi-stars" style="color:var(--ih-primary);"></i> Crear rol personalizado
      </h3>
      <p style="margin:0 0 18px;color:var(--ih-gray-500);font-size:var(--ih-text-sm);">
        Definí un nombre y una clave técnica. Después podrás asignarle permisos en su pestaña.
      </p>
      <form method="POST" action="/inventario/roles.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="create_custom">
        <div class="ih-field" style="margin-bottom:14px;">
          <label class="ih-field__label">Nombre visible *</label>
          <input class="ih-input" name="new_role_label" required maxlength="120" placeholder="Ej: Auditor externo">
        </div>
        <div class="ih-field" style="margin-bottom:18px;">
          <label class="ih-field__label">Clave técnica (opcional)</label>
          <input class="ih-input" name="new_role_key" maxlength="40" placeholder="auditor_externo (auto-generada del nombre)"
                 pattern="[a-z0-9_]+" title="Solo minusculas, numeros y guion bajo">
          <div class="ih-field__hint">Si la dejas vacía, se genera automáticamente desde el nombre.</div>
        </div>
        <button type="submit" class="ih-btn ih-btn--primary">
          <i class="bi bi-plus-lg"></i> Crear rol
        </button>
      </form>
    </div>
  </div>
</div>

<?php
$allRoles = array_merge(TENANT_ROLES, array_keys($customRoles));
foreach ($allRoles as $r):
  $isBuiltin = in_array($r, TENANT_ROLES, true);
?>
<div class="role-panel" data-role-panel="<?= $r ?>" style="display:none;">
  <form method="POST" action="/inventario/roles.php" id="form-role-<?= $r ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="role" value="<?= $r ?>">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
      <div>
        <h3 style="margin:0;font-size:var(--ih-text-lg);color:var(--ih-gray-800);font-weight:700;">
          <?= htmlspecialchars($roleLabels[$r]) ?>
          <?php if (!$isBuiltin): ?>
            <span class="ih-badge ih-badge--purple" style="margin-left:8px;font-size:11px;"><i class="bi bi-stars"></i> Personalizado</span>
          <?php elseif ($roleOverrides[$r]): ?>
            <span class="ih-badge ih-badge--warning" style="margin-left:8px;font-size:11px;">Custom</span>
          <?php else: ?>
            <span class="ih-badge ih-badge--success" style="margin-left:8px;font-size:11px;">Default</span>
          <?php endif; ?>
        </h3>
        <p style="margin:4px 0 0;color:var(--ih-gray-500);font-size:var(--ih-text-sm);">
          <?= count($rolePerms[$r]) ?> permisos activos
          <?php if (!$isBuiltin): ?>
            · <code style="font-size:11px;color:var(--ih-gray-400);"><?= htmlspecialchars($r) ?></code>
          <?php endif; ?>
        </p>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="ih-btn ih-btn--primary">
          <i class="bi bi-check2"></i> Guardar cambios
        </button>
        <?php if ($isBuiltin && $roleOverrides[$r]): ?>
          <button type="submit" class="ih-btn ih-btn--ghost"
                  onclick="if(!confirm('Restaurar permisos por defecto?')){return false;} this.form.elements['action'].value='reset';">
            <i class="bi bi-arrow-counterclockwise"></i> Restaurar default
          </button>
        <?php endif; ?>
        <?php if (!$isBuiltin): ?>
          <button type="submit" class="ih-btn ih-btn--danger"
                  onclick="if(!confirm('Eliminar el rol <?= htmlspecialchars($roleLabels[$r], ENT_QUOTES) ?>? Esta accion no se puede deshacer.')){return false;} this.form.elements['action'].value='delete_custom';">
            <i class="bi bi-trash"></i> Eliminar rol
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
