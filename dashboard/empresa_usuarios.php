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
    foreach ([
        "ALTER TABLE `{$usrTable}` ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE `{$usrTable}` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `{$usrTable}` MODIFY COLUMN `role` ENUM('admin','manager','user','viewer') NOT NULL DEFAULT 'user'",
    ] as $ddl) { try { $tconn->query($ddl); } catch (Throwable) {} }

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
            $r = in_array($_POST['role'] ?? '', ['admin','manager','user','viewer'], true)
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
            $r  = in_array($_POST['role'] ?? '', ['admin','manager','user','viewer'], true)
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
                  <option value="admin">Admin — acceso total al tenant</option>
                  <option value="manager">Gerente — CRUD activos + reportes</option>
                  <option value="user" selected>Usuario — ver, crear, editar</option>
                  <option value="viewer">Viewer — solo lectura</option>
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
                <tr style="<?= $isActive ? '' : 'opacity:.55' ?>">
                  <td><strong>#<?= (int)$u['id'] ?></strong></td>
                  <td>
                    <div class="ih-flex ih-items-center ih-gap-2">
                      <span class="ih-user-chip__avatar"><?= strtoupper(substr((string)$u['username'], 0, 1)) ?></span>
                      <div>
                        <div class="ih-fw-600"><?= htmlspecialchars((string)$u['username']) ?></div>
                        <?php if ((int)($u['must_change_password'] ?? 0)): ?>
                          <span class="ih-badge" style="font-size:9px;color:#D97706"><i class="bi bi-key"></i> debe cambiar pass</span>
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
                  <td class="ih-text-xs ih-text-muted"><?= htmlspecialchars((string)($u['created_at'] ?? '')) ?></td>
                  <?php if ($canManage): ?>
                  <td class="ih-text-center" style="white-space:nowrap">
                    <button class="ih-btn ih-btn--outline ih-btn--sm" type="button"
                            onclick="abrirEditar(<?= (int)$u['id'] ?>, '<?= htmlspecialchars((string)($u['email'] ?? ''), ENT_QUOTES) ?>', '<?= $u['role'] ?>')">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?= $isActive ? '¿Desactivar usuario?' : '¿Activar usuario?' ?>')">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="ih-btn ih-btn--<?= $isActive ? 'ghost' : 'accent' ?> ih-btn--sm" type="submit">
                        <?= $isActive ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play-fill"></i>' ?>
                      </button>
                    </form>
                    <button class="ih-btn ih-btn--outline ih-btn--sm" type="button"
                            onclick="abrirReset(<?= (int)$u['id'] ?>, '<?= htmlspecialchars((string)$u['username'], ENT_QUOTES) ?>')">
                      <i class="bi bi-key"></i>
                    </button>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars((string)$u['username'], ENT_QUOTES) ?>?')">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="ih-btn ih-btn--danger ih-btn--sm" type="submit"><i class="bi bi-trash"></i></button>
                    </form>
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

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:0;box-shadow:0 20px 60px rgba(102,51,153,.18)">
      <div class="modal-header" style="background:#663399;color:#fff;border-radius:16px 16px 0 0;padding:16px 22px;border-bottom:0">
        <h5 class="modal-title" style="font-weight:700;font-size:16px"><i class="bi bi-pencil-square"></i> Editar usuario</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editId">
        <div class="modal-body" style="padding:22px">
          <div class="ih-field">
            <label class="ih-field__label">Email</label>
            <input type="email" name="email" id="editEmail" class="ih-input" placeholder="(opcional)">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Rol</label>
            <select name="role" id="editRole" class="ih-select">
              <option value="admin">Admin — acceso total al tenant</option>
              <option value="manager">Gerente — CRUD activos + reportes</option>
              <option value="user">Usuario — ver, crear, editar</option>
              <option value="viewer">Viewer — solo lectura</option>
            </select>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #E4E4E7;padding:14px 22px">
          <button type="button" class="ih-btn ih-btn--ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="ih-btn ih-btn--primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Reset -->
<div class="modal fade" id="modalReset" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:16px;border:0;box-shadow:0 20px 60px rgba(102,51,153,.18)">
      <div class="modal-header" style="background:#663399;color:#fff;border-radius:16px 16px 0 0;padding:16px 22px;border-bottom:0">
        <h5 class="modal-title" style="font-weight:700;font-size:16px"><i class="bi bi-key"></i> Resetear contraseña</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="reset_pass">
        <input type="hidden" name="id" id="resetId">
        <div class="modal-body" style="padding:22px">
          <div class="ih-text-sm ih-text-muted ih-mb-2">Usuario: <strong id="resetUsername"></strong></div>
          <div class="ih-field">
            <label class="ih-field__label">Nueva contraseña</label>
            <input class="ih-input" type="password" name="new_password" minlength="8" required>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #E4E4E7;padding:14px 22px">
          <button type="button" class="ih-btn ih-btn--ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="ih-btn ih-btn--primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirEditar(id, email, role) {
  document.getElementById('editId').value = id;
  document.getElementById('editEmail').value = email;
  document.getElementById('editRole').value = role;
  new bootstrap.Modal(document.getElementById('modalEditar')).show();
}
function abrirReset(id, username) {
  document.getElementById('resetId').value = id;
  document.getElementById('resetUsername').textContent = username;
  new bootstrap.Modal(document.getElementById('modalReset')).show();
}
</script>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
