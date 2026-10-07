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
require_once __DIR__ . '/../core/security/permissions_db.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

// Solo admins
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
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
        $feedback = 'La validación de seguridad falló. Recargá la página.';
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
                $feedback = 'El rol indicado no existe.';
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
                $feedback = 'No se pudo generar una clave técnica válida a partir del nombre. Probá agregando letras o números.'; $feedbackOk = false;
            } elseif (strlen($newKey) > 40) {
                $feedback = 'La clave técnica es demasiado larga (máximo 40 caracteres). Acortala a mano.'; $feedbackOk = false;
            } elseif (in_array($newKey, TENANT_ROLES, true)) {
                $feedback = 'Esa clave coincide con un rol del sistema. Cambiá el nombre o ingresá una clave técnica distinta.'; $feedbackOk = false;
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
$topbar_actions  = hub_volver('configuracion');

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main">

<?php if ($feedback !== ''): ?>
  <div class="ih-alert <?= $feedbackOk ? 'ih-alert--success' : 'ih-alert--warning' ?>">
    <i class="bi bi-<?= $feedbackOk ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
    <div><?= htmlspecialchars($feedback) ?></div>
  </div>
<?php endif; ?>

<div class="ih-alert ih-alert--info">
  <i class="bi bi-info-circle-fill"></i>
  <div>
    Configurá qué acciones puede realizar cada rol en tu empresa. Si dejás un rol sin overrides,
    se usan los <strong>permisos por defecto</strong> del sistema. El rol <strong>Administrador</strong>
    siempre mantiene Ver/Editar usuarios e integraciones (para evitar lockouts).
  </div>
</div>

<!-- Diagnostico: si la columna role sigue siendo ENUM, ofrecer reparacion -->
<?php if ($colIsEnum): ?>
<div class="ih-alert ih-alert--warning ih-alert--accion">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div class="ih-alert__cuerpo">
    <strong>Roles personalizados deshabilitados</strong><br>
    <span class="ih-text-sm">
      La columna <code>role</code> de tu base de datos es de tipo <code><?= htmlspecialchars($colRoleType) ?></code>,
      que solo acepta los 4 roles del sistema (admin, manager, user, viewer).
      Si asignás un rol personalizado a un usuario, MySQL lo rechaza y guarda vacío
      (por eso aparece «Sin rol»). Con el botón de al lado se cambia el tipo de la
      columna y los roles propios vuelven a funcionar.
    </span>
  </div>
  <form class="ih-alert__accion" method="POST" action="/inventario/roles.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="action" value="fix_role_column">
    <button type="submit" class="ih-btn ih-btn--primary ih-btn--sm">
      <i class="bi bi-tools"></i> Reparar columna role
    </button>
  </form>
</div>
<?php endif; ?>

<?php /* Las pastillas eligen el rol que se esta editando. No navegan a otra
         pantalla: muestran y esconden los paneles de abajo. */ ?>
<div class="ih-pills" role="group" aria-label="Rol que se está editando">
  <?php foreach (TENANT_ROLES as $r): ?>
    <button class="ih-pill" data-role="<?= $r ?>" type="button">
      <?= htmlspecialchars($roleLabels[$r]) ?>
      <?php if ($roleOverrides[$r]): ?><span class="ih-badge ih-badge--warning">Editado</span><?php endif; ?>
    </button>
  <?php endforeach; ?>
  <?php foreach ($customRoles as $rk => $rlbl): ?>
    <button class="ih-pill ih-pill--propia" data-role="<?= htmlspecialchars($rk) ?>" type="button">
      <i class="bi bi-stars"></i>
      <?= htmlspecialchars($rlbl) ?>
    </button>
  <?php endforeach; ?>
  <button class="ih-pill ih-pill--nueva" data-role="__new__" type="button">
    <i class="bi bi-plus-lg"></i> Nuevo rol
  </button>
</div>

<!-- Panel: nuevo rol -->
<div class="role-panel" data-role-panel="__new__" hidden>
  <div class="ih-card ih-card--angosta">
    <div class="ih-card__body">
      <div class="ih-barra">
        <div>
          <h3 class="ih-barra__titulo"><i class="bi bi-stars"></i> Crear un rol propio</h3>
          <p class="ih-barra__ayuda">
            Primero el nombre. Los permisos se eligen después, en la pastilla del rol nuevo.
          </p>
        </div>
      </div>
      <form method="POST" action="/inventario/roles.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="create_custom">
        <div class="ih-field">
          <label class="ih-field__label">Nombre visible *</label>
          <input class="ih-input" name="new_role_label" required maxlength="120" placeholder="Ej: Auditor externo">
        </div>
        <div class="ih-field">
          <label class="ih-field__label">Clave técnica (opcional)</label>
          <input class="ih-input" name="new_role_key" maxlength="40" placeholder="auditor_externo (auto-generada del nombre)"
                 pattern="[a-z0-9_]+" title="Sólo minúsculas, números y guion bajo">
          <div class="ih-field__hint">Si la dejás vacía, se arma sola a partir del nombre.</div>
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
<div class="role-panel" data-role-panel="<?= $r ?>" hidden>
  <form method="POST" action="/inventario/roles.php" id="form-role-<?= $r ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="role" value="<?= $r ?>">

    <?php /* La barra de título es la del sistema (.ih-barra): en pantalla angosta
             los botones bajan y ocupan el ancho completo en lugar de apretarse
             en una fila donde no entran. */ ?>
    <div class="ih-barra">
      <div>
        <h3 class="ih-barra__titulo">
          <?= htmlspecialchars($roleLabels[$r]) ?>
          <?php if (!$isBuiltin): ?>
            <span class="ih-badge ih-badge--purple"><i class="bi bi-stars"></i> Personalizado</span>
          <?php elseif ($roleOverrides[$r]): ?>
            <span class="ih-badge ih-badge--warning">Editado</span>
          <?php else: ?>
            <span class="ih-badge ih-badge--neutral">Sin cambios</span>
          <?php endif; ?>
        </h3>
        <p class="ih-barra__ayuda">
          <?= count($rolePerms[$r]) ?> <?= count($rolePerms[$r]) === 1 ? 'permiso activo' : 'permisos activos' ?>
          <?php if (!$isBuiltin): ?>
            · <code><?= htmlspecialchars($r) ?></code>
          <?php endif; ?>
        </p>
      </div>
      <div class="ih-barra__acciones">
        <button type="submit" class="ih-btn ih-btn--primary">
          <i class="bi bi-check2"></i> Guardar cambios
        </button>
        <?php if ($isBuiltin && $roleOverrides[$r]): ?>
          <button type="submit" class="ih-btn ih-btn--ghost"
                  onclick="if(!confirm('¿Restaurar los permisos originales de <?= htmlspecialchars($roleLabels[$r], ENT_QUOTES) ?>? Se pierden los cambios que le hiciste.')){return false;} this.form.elements['action'].value='reset';">
            <i class="bi bi-arrow-counterclockwise"></i> Restaurar originales
          </button>
        <?php endif; ?>
        <?php if (!$isBuiltin): ?>
          <button type="submit" class="ih-btn ih-btn--danger"
                  onclick="if(!confirm('¿Eliminar el rol <?= htmlspecialchars($roleLabels[$r], ENT_QUOTES) ?>? Esta acción no se puede deshacer.')){return false;} this.form.elements['action'].value='delete_custom';">
            <i class="bi bi-trash"></i> Eliminar rol
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="ih-permisos">
    <?php foreach ($permGroups as $groupName => $perms): ?>
      <div class="ih-permisos__grupo">
        <div class="ih-permisos__titulo"><?= htmlspecialchars($groupName) ?></div>
        <?php foreach ($perms as $action => $label):
          $checked = in_array($action, $rolePerms[$r], true);
          $isAdminProtected = ($r === 'admin' && in_array($action, ['user.view','user.edit','integration.view','integration.edit'], true));
        ?>
          <div class="ih-permisos__fila">
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
                  <span class="ih-text-xs ih-text-muted">(siempre activo para el administrador)</span>
                <?php endif; ?>
              </span>
            </label>
            <span class="ih-permisos__clave"><?= htmlspecialchars($action) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </form>
</div>
<?php endforeach; ?>

<script>
/* Las pastillas muestran y esconden los paneles de permisos. Se usa el atributo
   `hidden` en lugar de `style.display`, para que el estado viva en el HTML y no
   en un estilo escrito a mano. */
(function () {
  var pastillas = document.querySelectorAll('.ih-pill[data-role]');
  var paneles   = document.querySelectorAll('.role-panel');
  var CLAVE     = 'av_roles_last_tab';

  function mostrar(rol) {
    pastillas.forEach(function (p) {
      var activa = p.dataset.role === rol;
      p.classList.toggle('is-active', activa);
      p.setAttribute('aria-pressed', activa ? 'true' : 'false');
    });
    paneles.forEach(function (p) { p.hidden = p.dataset.rolePanel !== rol; });
    try { localStorage.setItem(CLAVE, rol); } catch (e) {}
  }

  pastillas.forEach(function (p) {
    p.addEventListener('click', function () { mostrar(p.dataset.role); });
  });

  /* Se vuelve al rol que se estaba viendo. Si ese rol ya no existe —lo
     borraron, o se cambió de empresa— se cae al administrador. */
  var ultimo = null;
  try { ultimo = localStorage.getItem(CLAVE); } catch (e) {}
  var existe = ultimo && document.querySelector('[data-role-panel="' + ultimo + '"]');
  mostrar(existe ? ultimo : 'admin');
})();
</script>

</main>
</div>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
