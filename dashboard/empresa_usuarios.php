<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();
csrf_start();

if (!platform_can('tenant_user.view')) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$companyId = (int)($_GET['id'] ?? 0);
if (!$companyId) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$db  = new MasterDB();
$err = '';
$ok  = '';

// Cargar empresa
$stmt = $db->pquery('SELECT id, nombre, razon_social FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

// Conexión al tenant
try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($companyId);
    $usrTable = $pf . 'users';

    // Asegurar columnas extra en el tenant (silencioso)
    // IMPORTANTE: role es VARCHAR para soportar custom roles, no ENUM (que solo permite los 4 builtin)
    foreach ([
        "ALTER TABLE `{$usrTable}` ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE `{$usrTable}` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `{$usrTable}` MODIFY COLUMN `role` VARCHAR(40) NOT NULL DEFAULT 'user'",
    ] as $ddl) { try { $tconn->query($ddl); } catch (Throwable) {} }

    // Cargar roles asignables (builtin + custom) para el validador
    require_once __DIR__ . '/../core/security/permissions.php';
    require_once __DIR__ . '/../core/security/permissions_db.php';
    global $conn; $prevConn = $conn ?? null; $conn = $tconn;
    $rolesValidos = array_keys(all_assignable_roles($tconn));
    $conn = $prevConn;

} catch (Throwable $ex) {
    $dbError = 'No se pudo conectar a la base de datos del tenant: ' . $ex->getMessage();
    $tconn   = null;
    $usrTable = '';
}

$canManage = platform_can('tenant_user.manage');

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tconn && $canManage) {
    csrf_validate();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $u = trim($_POST['username'] ?? '');
            $p = (string)($_POST['password'] ?? '');
            $e = strtolower(trim($_POST['email'] ?? ''));
            $r = in_array($_POST['role'] ?? '', $rolesValidos ?? ['admin','manager','user','viewer'], true)
                ? $_POST['role'] : 'user';

            if ($u === '' || strlen($p) < 8) {
                $err = 'Usuario y contraseña (mínimo 8 caracteres) son obligatorios.';
            } elseif ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $err = 'El email no tiene formato válido.';
            } else {
                $h = password_hash($p, PASSWORD_DEFAULT);
                $st = $tconn->prepare("INSERT INTO `{$usrTable}` (username, password, email, role, must_change_password) VALUES (?, ?, ?, ?, 1)");
                $st->bind_param('ssss', $u, $h, $e, $r);
                $st->execute();
                $st->close();
                $ok = "Usuario <strong>$u</strong> creado. Deberá cambiar su contraseña al primer ingreso.";
            }

        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $e  = strtolower(trim($_POST['email'] ?? ''));
            $r  = in_array($_POST['role'] ?? '', $rolesValidos ?? ['admin','manager','user','viewer'], true)
                ? $_POST['role'] : 'user';
            if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $err = 'El email no tiene formato válido.';
            } else {
                $st = $tconn->prepare("UPDATE `{$usrTable}` SET email = ?, role = ? WHERE id = ?");
                $st->bind_param('ssi', $e, $r, $id);
                $st->execute();
                $st->close();
                $ok = 'Usuario actualizado.';
            }

        } elseif ($action === 'reset_pass') {
            $id = (int)($_POST['id'] ?? 0);
            $p  = (string)($_POST['new_password'] ?? '');
            if (strlen($p) < 8) {
                $err = 'La contraseña debe tener al menos 8 caracteres.';
            } else {
                $h  = password_hash($p, PASSWORD_DEFAULT);
                $st = $tconn->prepare("UPDATE `{$usrTable}` SET password = ?, must_change_password = 1 WHERE id = ?");
                $st->bind_param('si', $h, $id);
                $st->execute();
                $st->close();
                $ok = 'Contraseña reseteada. El usuario deberá cambiarla al ingresar.';
            }

        } elseif ($action === 'toggle') {
            $id  = (int)($_POST['id'] ?? 0);
            $st  = $tconn->prepare("SELECT active FROM `{$usrTable}` WHERE id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $cur = (int)($st->get_result()->fetch_assoc()['active'] ?? 1);
            $st->close();
            $new = $cur ? 0 : 1;
            $st2 = $tconn->prepare("UPDATE `{$usrTable}` SET active = ? WHERE id = ?");
            $st2->bind_param('ii', $new, $id);
            $st2->execute();
            $st2->close();
            $ok = $new ? 'Usuario activado.' : 'Usuario desactivado.';

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $tconn->prepare("DELETE FROM `{$usrTable}` WHERE id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $st->close();
            $ok = 'Usuario eliminado.';
        }
    } catch (Throwable $ex) {
        $err = 'Error: ' . $ex->getMessage();
    }
}

// Listar usuarios del tenant
$tenantUsers = [];
if ($tconn) {
    try {
        $res = $tconn->query("SELECT id, username, email, role, active, must_change_password, created_at FROM `{$usrTable}` ORDER BY id ASC");
        if ($res) { while ($r = $res->fetch_assoc()) { $tenantUsers[] = $r; } }
    } catch (Throwable) {}
}

$roleLabels = [
    'admin'   => ['label' => 'Admin',    'class' => 'ih-badge--purple'],
    'manager' => ['label' => 'Gerente',  'class' => 'ih-badge--info'],
    'user'    => ['label' => 'Usuario',  'class' => 'ih-badge--green'],
    'viewer'  => ['label' => 'Viewer',   'class' => 'ih-badge'],
];

$companyName     = htmlspecialchars((string)$company['nombre']);
$page_title      = "Usuarios · $companyName";
$page_section    = 'empresas';
$topbar_title    = "Usuarios de $companyName";
$topbar_subtitle = htmlspecialchars((string)($company['razon_social'] ?? ''));
$topbar_user     = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email    = $_SESSION['superadmin_email'] ?? '';
$topbar_actions  = '<a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--outline ih-btn--sm"><i class="bi bi-arrow-left"></i> Empresas</a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($ok): ?>
      <div class="ih-alert ih-alert--success"><i class="bi bi-check-circle-fill"></i><div><?= $ok ?></div></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="ih-alert ih-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($err) ?></div></div>
    <?php endif; ?>
    <?php if (!empty($dbError)): ?>
      <div class="ih-alert ih-alert--danger"><i class="bi bi-database-x"></i><div><?= htmlspecialchars($dbError) ?></div></div>
    <?php endif; ?>

    <?php if ($canManage && $tconn): ?>
    <div class="ih-card ih-mb-6">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-person-plus"></i> Crear usuario para <?= $companyName ?></h2>
          <div class="ih-card__subtitle">El usuario se crea directamente en la base de datos del cliente</div>
        </div>
      </div>
      <div class="ih-card__body">
        <form method="post" autocomplete="off">
          <?php csrf_input(); ?>
          <input type="hidden" name="action" value="create">
          <div class="ih-grid">
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Usuario <span class="req">*</span></label>
                <input class="ih-input" name="username" required pattern="[a-zA-Z0-9._\-]{3,120}"
                       title="Mínimo 3 caracteres, solo letras, números, punto, guión o guión bajo.">
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Email</label>
                <input class="ih-input" type="email" name="email" placeholder="usuario@empresa.com">
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Contraseña inicial <span class="req">*</span></label>
                <input class="ih-input" type="password" name="password" minlength="8" required>
                <div class="ih-field__hint">El usuario deberá cambiarla al ingresar.</div>
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Rol</label>
                <select class="ih-select" name="role">
                  <?php
                  global $conn; $prevConn = $conn ?? null; $conn = $tconn;
                  foreach (all_assignable_roles($tconn) as $rk => $rlbl):
                  ?>
                    <option value="<?= htmlspecialchars($rk) ?>" <?= $rk === 'user' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($rlbl) ?>
                    </option>
                  <?php endforeach; $conn = $prevConn; ?>
                </select>
              </div>
            </div>
          </div>
          <button class="ih-btn ih-btn--accent ih-mt-4" type="submit"><i class="bi bi-check-lg"></i> Crear usuario</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-person-lines-fill"></i> Usuarios de <?= $companyName ?></h2>
          <div class="ih-card__subtitle"><?= count($tenantUsers) ?> usuario<?= count($tenantUsers) !== 1 ? 's' : '' ?> registrado<?= count($tenantUsers) !== 1 ? 's' : '' ?></div>
        </div>
      </div>

      <?php if (empty($tenantUsers)): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-person-x"></i></div>
          <div class="ih-empty__title">Sin usuarios</div>
          <div class="ih-empty__text">Creá el primer usuario usando el formulario de arriba.</div>
        </div>
      <?php else: ?>
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Creado</th>
                <?php if ($canManage): ?>
                <th class="ih-text-center">Acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tenantUsers as $u): ?>
                <?php $isActive = (int)($u['active'] ?? 1) === 1; ?>
                <?php $rl = $roleLabels[$u['role']] ?? ['label' => $u['role'], 'class' => 'ih-badge']; ?>
                <tr <?= $isActive ? '' : ' class="es-inactiva"' ?>>
                  <td><strong>#<?= (int)$u['id'] ?></strong></td>
                  <td>
                    <div class="ih-flex ih-items-center ih-gap-2">
                      <span class="ih-user-chip__avatar"><?= strtoupper(substr((string)$u['username'], 0, 1)) ?></span>
                      <div>
                        <div class="ih-fw-600"><?= htmlspecialchars((string)$u['username']) ?></div>
                        <?php if ((int)($u['must_change_password'] ?? 0)): ?>
                          <span class="ih-badge ih-badge--warning"><i class="bi bi-key"></i> Debe cambiar la contraseña</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td class="ih-text-sm"><?= htmlspecialchars((string)($u['email'] ?? '—')) ?></td>
                  <td><span class="ih-badge <?= $rl['class'] ?>"><?= $rl['label'] ?></span></td>
                  <td>
                    <?php if ($isActive): ?>
                      <span class="ih-badge ih-badge--success ih-badge--dot">Activo</span>
                    <?php else: ?>
                      <span class="ih-badge ih-badge--danger ih-badge--dot">Inactivo</span>
                    <?php endif; ?>
                  </td>
                  <td class="ih-table__nowrap">
                    <?php $alta = strtotime((string)($u['created_at'] ?? '')); ?>
                    <?php if ($alta): ?>
                      <div class="ih-celda__texto">
                        <div class="ih-celda__nombre"><?= date('d/m/Y', $alta) ?></div>
                        <div class="ih-celda__sub"><?= date('H:i', $alta) ?></div>
                      </div>
                    <?php else: ?><span class="ih-text-muted">—</span><?php endif; ?>
                  </td>
                  <?php if ($canManage): ?>
                  <?php /* Tenía `class` dos veces y el navegador se queda con el
                           primero. Y los botones eran un icono solo: ni globito ni
                           texto para el lector de pantalla. */ ?>
                  <td class="ih-table__acciones">
                    <div class="ih-acciones">
                    <button class="ih-btn ih-btn--outline ih-btn--sm" type="button" title="Editar el rol y el correo"
                            onclick="abrirEditar(<?= (int)$u['id'] ?>, '<?= htmlspecialchars((string)($u['email'] ?? ''), ENT_QUOTES) ?>', '<?= $u['role'] ?>')">
                      <i class="bi bi-pencil"></i>
                      <span class="ih-sr-only">Editar a <?= htmlspecialchars((string)$u['username']) ?></span>
                    </button>
                    <form method="post" 
                          onsubmit="return confirm('<?= $isActive ? '¿Desactivar usuario?' : '¿Activar usuario?' ?>')">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="ih-btn ih-btn--<?= $isActive ? 'ghost' : 'accent' ?> ih-btn--sm" type="submit"
                              title="<?= $isActive ? 'Desactivar' : 'Activar' ?>">
                        <?= $isActive ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play-fill"></i>' ?>
                        <span class="ih-sr-only"><?= $isActive ? 'Desactivar' : 'Activar' ?> a <?= htmlspecialchars((string)$u['username']) ?></span>
                      </button>
                    </form>
                    <button class="ih-btn ih-btn--outline ih-btn--sm" type="button" title="Poner una contraseña nueva"
                            onclick="abrirReset(<?= (int)$u['id'] ?>, '<?= htmlspecialchars((string)$u['username'], ENT_QUOTES) ?>')">
                      <i class="bi bi-key"></i>
                      <span class="ih-sr-only">Poner una contraseña nueva a <?= htmlspecialchars((string)$u['username']) ?></span>
                    </button>
                    <form method="post" 
                          onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars((string)$u['username'], ENT_QUOTES) ?>?')">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="ih-btn ih-btn--danger ih-btn--sm" type="submit" title="Eliminar">
                        <i class="bi bi-trash"></i>
                        <span class="ih-sr-only">Eliminar a <?= htmlspecialchars((string)$u['username']) ?></span>
                      </button>
                    </form>
                    </div>
                  </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<?php /* Modales nativos <dialog> con las clases compartidas .ih-modal.
         Antes eran de Bootstrap, que se cargaba al FINAL del body —después del
         sistema de diseño—, así que su reset le ganaba a todo y esta pantalla
         no se parecía al resto del panel. Ver assets/css/componentes.css. */ ?>

<dialog class="ih-modal" id="modalEditar">
  <div class="ih-modal__header">
    <h2 class="ih-modal__title"><i class="bi bi-pencil-square" aria-hidden="true"></i> Editar usuario</h2>
    <button type="button" class="ih-modal__close" data-cerrar aria-label="Cerrar">&times;</button>
  </div>
  <form method="post">
    <?php csrf_input(); ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" id="editId">
    <div class="ih-modal__body">
      <div class="ih-field">
        <label class="ih-field__label" for="editEmail">Email</label>
        <input type="email" name="email" id="editEmail" class="ih-input" placeholder="Opcional">
      </div>
      <div class="ih-field">
        <label class="ih-field__label" for="editRole">Rol</label>
        <select name="role" id="editRole" class="ih-select">
          <?php
          global $conn; $prevConnE = $conn ?? null; $conn = $tconn;
          foreach (all_assignable_roles($tconn) as $rk => $rlbl):
          ?>
            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rlbl) ?></option>
          <?php endforeach; $conn = $prevConnE; ?>
        </select>
      </div>
    </div>
    <div class="ih-modal__footer">
      <button type="button" class="ih-btn ih-btn--ghost" data-cerrar>Cancelar</button>
      <button type="submit" class="ih-btn ih-btn--primary">Guardar</button>
    </div>
  </form>
</dialog>

<dialog class="ih-modal ih-ancho-modal" id="modalReset">
  <div class="ih-modal__header">
    <h2 class="ih-modal__title"><i class="bi bi-key" aria-hidden="true"></i> Restablecer contraseña</h2>
    <button type="button" class="ih-modal__close" data-cerrar aria-label="Cerrar">&times;</button>
  </div>
  <form method="post">
    <?php csrf_input(); ?>
    <input type="hidden" name="action" value="reset_pass">
    <input type="hidden" name="id" id="resetId">
    <div class="ih-modal__body">
      <p class="ih-text-sm ih-text-muted ih-mb-0 ih-mt-0">Nueva contraseña para <strong id="resetUsername"></strong>.</p>
      <div class="ih-field">
        <label class="ih-field__label" for="resetPass">Nueva contraseña</label>
        <input class="ih-input" type="password" name="new_password" id="resetPass" minlength="8" required
               autocomplete="new-password" placeholder="Mínimo 8 caracteres">
      </div>
    </div>
    <div class="ih-modal__footer">
      <button type="button" class="ih-btn ih-btn--ghost" data-cerrar>Cancelar</button>
      <button type="submit" class="ih-btn ih-btn--primary">Guardar</button>
    </div>
  </form>
</dialog>

<script>
function abrirEditar(id, email, role) {
  document.getElementById('editId').value = id;
  document.getElementById('editEmail').value = email;
  var sel = document.getElementById('editRole');
  sel.value = role;
  // `change` para que la cara visible del desplegable propio tome el valor que
  // acabamos de poner por código (ver assets/js/componentes.js).
  sel.dispatchEvent(new Event('change', { bubbles: true }));
  document.getElementById('modalEditar').showModal();
}
function abrirReset(id, username) {
  document.getElementById('resetId').value = id;
  document.getElementById('resetUsername').textContent = username;
  document.getElementById('modalReset').showModal();
}
// Cierre: los botones marcados con data-cerrar y el clic en el fondo oscuro.
document.addEventListener('click', function (e) {
  var cerrar = e.target.closest('[data-cerrar]');
  if (cerrar) { var d = cerrar.closest('dialog'); if (d) { d.close(); } return; }
  if (e.target.tagName === 'DIALOG') { e.target.close(); }
});
</script>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
