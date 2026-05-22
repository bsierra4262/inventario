<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();
csrf_start();

$db  = new MasterDB();
$err = '';
$ok  = '';

// ── Migraciones de tabla ──────────────────────────────────────────────────────
$db->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(100) NOT NULL,
    password    VARCHAR(255) DEFAULT NULL,
    email       VARCHAR(255) DEFAULT NULL,
    google_sub  VARCHAR(64)  DEFAULT NULL,
    google_pic  VARCHAR(500) DEFAULT NULL,
    ms_sub      VARCHAR(64)  DEFAULT NULL,
    ms_pic      VARCHAR(500) DEFAULT NULL,
    role        ENUM('superadmin','admin','support','viewer') NOT NULL DEFAULT 'support',
    active      TINYINT(1)  NOT NULL DEFAULT 1,
    last_login  DATETIME    DEFAULT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ampliar ENUM si la instalación es vieja (silencioso)
foreach ([
    "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_sub` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_pic` VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE `users_platform` MODIFY COLUMN role ENUM('superadmin','admin','support','viewer') NOT NULL DEFAULT 'support'",
] as $ddl) { try { $db->conn->query($ddl); } catch (Throwable) {} }

$currentUserId = (int)($_SESSION['superadmin_user_id'] ?? 0);

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';

    // Solo superadmin puede gestionar usuarios de plataforma
    if (!platform_can('platform_user.manage')) {
        $err = 'No tenés permisos para gestionar usuarios de plataforma.';
    } else {
        try {
            if ($action === 'create') {
                $u = trim($_POST['username'] ?? '');
                $p = (string)($_POST['password'] ?? '');
                $e = strtolower(trim($_POST['email'] ?? ''));
                $r = in_array($_POST['role'] ?? '', ['superadmin','admin','support','viewer'], true)
                    ? $_POST['role'] : 'support';

                if ($u === '' || $e === '' || strlen($p) < 8) {
                    $err = 'Usuario, email y contraseña (mínimo 8 caracteres) son obligatorios.';
                } elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $err = 'El email no tiene formato válido.';
                } else {
                    $h = password_hash($p, PASSWORD_DEFAULT);
                    $db->pquery(
                        "INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, ?)",
                        'ssss', [$u, $h, $e, $r]
                    );
                    $ok = "Usuario <strong>$u</strong> creado correctamente.";
                }

            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                $e  = strtolower(trim($_POST['email'] ?? ''));
                $r  = in_array($_POST['role'] ?? '', ['superadmin','admin','support','viewer'], true)
                    ? $_POST['role'] : 'support';
                // No bajar a alguien de superadmin si es el único
                if ($id === 1 && $r !== 'superadmin') {
                    $err = 'No podés cambiar el rol del superadmin principal.';
                } elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $err = 'El email no tiene formato válido.';
                } else {
                    $db->pquery('UPDATE users_platform SET email = ?, role = ? WHERE id = ?', 'ssi', [$e, $r, $id]);
                    $ok = 'Usuario actualizado.';
                }

            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === $currentUserId) {
                    $err = 'No podés desactivar tu propia cuenta.';
                } else {
                    $st  = $db->pquery('SELECT active FROM users_platform WHERE id = ?', 'i', [$id]);
                    $cur = (int)($st->get_result()->fetch_assoc()['active'] ?? 1);
                    $st->close();
                    $new = $cur ? 0 : 1;
                    $db->pquery('UPDATE users_platform SET active = ? WHERE id = ?', 'ii', [$new, $id]);
                    $ok = $new ? 'Usuario activado.' : 'Usuario desactivado.';
                }

            } elseif ($action === 'reset_pass') {
                $id = (int)($_POST['id'] ?? 0);
                $p  = (string)($_POST['new_password'] ?? '');
                if (strlen($p) < 8) {
                    $err = 'La nueva contraseña debe tener al menos 8 caracteres.';
                } else {
                    $h = password_hash($p, PASSWORD_DEFAULT);
                    $db->pquery('UPDATE users_platform SET password = ? WHERE id = ?', 'si', [$h, $id]);
                    $ok = 'Contraseña actualizada.';
                }

            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === $currentUserId || $id === 1) {
                    $err = 'No podés eliminar tu propia cuenta ni al superadmin principal.';
                } else {
                    $db->pquery('DELETE FROM users_platform WHERE id = ?', 'i', [$id]);
                    $ok = 'Usuario eliminado.';
                }
            }
        } catch (Throwable $ex) {
            $err = 'Error: ' . $ex->getMessage();
        }
    }
}

$users = [];
$res = $db->conn->query(
    "SELECT id, username, email, role, active, last_login, created_at, google_sub, google_pic, ms_sub
     FROM users_platform ORDER BY id ASC"
);
if ($res) {
    while ($r = $res->fetch_assoc()) { $users[] = $r; }
}

$roleLabels = [
    'superadmin' => ['label' => 'SuperAdmin', 'class' => 'ih-badge--purple'],
    'admin'      => ['label' => 'Admin',       'class' => 'ih-badge--info'],
    'support'    => ['label' => 'Soporte',      'class' => 'ih-badge--warning'],
    'viewer'     => ['label' => 'Viewer',       'class' => 'ih-badge'],
];

$page_title      = 'Usuarios plataforma';
$page_section    = 'users';
$topbar_title    = 'Usuarios plataforma';
$topbar_subtitle = 'Administradores y soporte interno';
$topbar_user     = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email    = $_SESSION['superadmin_email'] ?? '';
$topbar_actions  = '';

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

    <?php if (platform_can('platform_user.manage')): ?>
    <div class="ih-card ih-mb-6">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-person-plus"></i> Crear nuevo usuario</h2>
          <div class="ih-card__subtitle">El usuario podrá iniciar sesión con Google/Microsoft usando el email registrado</div>
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
                <input class="ih-input" name="username" required placeholder="nombre.apellido">
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Email corporativo <span class="req">*</span></label>
                <input class="ih-input" type="email" name="email" required placeholder="usuario@intellihelp.tech">
                <div class="ih-field__hint">Con este email se vinculará automáticamente al iniciar con Google/Microsoft.</div>
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Contraseña inicial <span class="req">*</span></label>
                <input class="ih-input" type="password" name="password" minlength="8" required>
                <div class="ih-field__hint">Mínimo 8 caracteres.</div>
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Rol</label>
                <select class="ih-select" name="role">
                  <option value="superadmin">SuperAdmin — acceso total</option>
                  <option value="admin">Admin — gestión de empresas</option>
                  <option value="support" selected>Soporte — solo lectura + ayuda</option>
                  <option value="viewer">Viewer — solo dashboard</option>
                </select>
              </div>
            </div>
          </div>
          <div class="ih-field__hint ih-mt-2" style="background:#F4F1F9;padding:10px 14px;border-radius:10px;max-width:600px;">
            <i class="bi bi-info-circle" style="color:#663399"></i>
            <strong>Vinculación automática:</strong> La primera vez que el usuario inicie sesión con Google o Microsoft usando el email registrado, su cuenta quedará vinculada automáticamente.
          </div>
          <button class="ih-btn ih-btn--accent ih-mt-4" type="submit"><i class="bi bi-check-lg"></i> Crear usuario</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-people"></i> Usuarios registrados</h2>
          <div class="ih-card__subtitle"><?= count($users) ?> usuario<?= count($users) !== 1 ? 's' : '' ?></div>
        </div>
      </div>
      <div class="ih-table-wrap">
        <table class="ih-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Usuario</th>
              <th>Email</th>
              <th>Rol</th>
              <th>SSO</th>
              <th>Estado</th>
              <th>Último login</th>
              <?php if (platform_can('platform_user.manage')): ?>
              <th class="ih-text-center">Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php $isActive = (int)$u['active'] === 1; ?>
              <?php $rl = $roleLabels[$u['role']] ?? ['label' => $u['role'], 'class' => 'ih-badge']; ?>
              <tr style="<?= $isActive ? '' : 'opacity:.55' ?>">
                <td><strong>#<?= (int)$u['id'] ?></strong></td>
                <td>
                  <div class="ih-flex ih-items-center ih-gap-2">
                    <?php if (!empty($u['google_pic'])): ?>
                      <img src="<?= htmlspecialchars($u['google_pic']) ?>" alt="" style="width:28px;height:28px;border-radius:50%;flex-shrink:0">
                    <?php else: ?>
                      <span class="ih-user-chip__avatar" style="flex-shrink:0"><?= htmlspecialchars(strtoupper(substr((string)$u['username'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <div>
                      <div class="ih-fw-600"><?= htmlspecialchars((string)$u['username']) ?></div>
                      <?php if ((int)$u['id'] === $currentUserId): ?>
                        <span class="ih-badge ih-badge--purple" style="font-size:9px">VOS</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="ih-text-sm"><?= htmlspecialchars((string)($u['email'] ?? '')) ?></td>
                <td><span class="ih-badge <?= $rl['class'] ?>"><?= $rl['label'] ?></span></td>
                <td>
                  <?php if (!empty($u['google_sub'])): ?>
                    <span class="ih-badge ih-badge--success"><i class="bi bi-google"></i> Google</span>
                  <?php elseif (!empty($u['ms_sub'])): ?>
                    <span class="ih-badge ih-badge--info"><i class="bi bi-microsoft"></i> Microsoft</span>
                  <?php else: ?>
                    <span class="ih-badge" style="color:#A1A1AA">No vinculado</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="ih-badge ih-badge--success ih-badge--dot">Activo</span>
                  <?php else: ?>
                    <span class="ih-badge ih-badge--danger ih-badge--dot">Inactivo</span>
                  <?php endif; ?>
                </td>
                <td class="ih-text-xs ih-text-muted">
                  <?= $u['last_login'] ? htmlspecialchars((string)$u['last_login']) : '—' ?>
                </td>
                <?php if (platform_can('platform_user.manage')): ?>
                <td class="ih-text-center" style="white-space:nowrap">
                  <!-- Editar rol/email -->
                  <button class="ih-btn ih-btn--outline ih-btn--sm" type="button"
                          onclick="abrirEditar(<?= (int)$u['id'] ?>, '<?= htmlspecialchars((string)$u['email'], ENT_QUOTES) ?>', '<?= $u['role'] ?>')">
                    <i class="bi bi-pencil"></i>
                  </button>

                  <!-- Toggle activo -->
                  <?php if ((int)$u['id'] !== $currentUserId): ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('<?= $isActive ? '¿Desactivar este usuario?' : '¿Activar este usuario?' ?>')">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="ih-btn ih-btn--<?= $isActive ? 'ghost' : 'accent' ?> ih-btn--sm" type="submit">
                      <?= $isActive ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play-fill"></i>' ?>
                    </button>
                  </form>
                  <?php endif; ?>

                  <!-- Reset pass -->
                  <button class="ih-btn ih-btn--outline ih-btn--sm" type="button"
                          onclick="abrirReset(<?= (int)$u['id'] ?>, '<?= htmlspecialchars((string)$u['username'], ENT_QUOTES) ?>')">
                    <i class="bi bi-key"></i>
                  </button>

                  <!-- Eliminar -->
                  <?php if ((int)$u['id'] !== $currentUserId && (int)$u['id'] !== 1): ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars((string)$u['username'], ENT_QUOTES) ?>? Esta acción no se puede deshacer.')">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="ih-btn ih-btn--danger ih-btn--sm" type="submit"><i class="bi bi-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
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
      <form method="post" autocomplete="off">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editId">
        <div class="modal-body" style="padding:22px">
          <div class="ih-field">
            <label class="ih-field__label">Email corporativo</label>
            <input type="email" name="email" id="editEmail" class="ih-input" required>
            <div class="ih-field__hint">La vinculación OAuth se basa en este email.</div>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Rol</label>
            <select name="role" id="editRole" class="ih-select">
              <option value="superadmin">SuperAdmin — acceso total</option>
              <option value="admin">Admin — gestión de empresas</option>
              <option value="support">Soporte — solo lectura + ayuda</option>
              <option value="viewer">Viewer — solo dashboard</option>
            </select>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #E4E4E7;padding:14px 22px">
          <button type="button" class="ih-btn ih-btn--ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="ih-btn ih-btn--primary">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Reset contraseña -->
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
          <div class="ih-text-sm ih-text-muted ih-mb-2">Seteando contraseña para: <strong id="resetUsername"></strong></div>
          <div class="ih-field">
            <label class="ih-field__label">Nueva contraseña</label>
            <input class="ih-input" type="password" name="new_password" minlength="8" required placeholder="Mínimo 8 caracteres">
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
