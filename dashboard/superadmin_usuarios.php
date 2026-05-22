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

// Asegurar tabla users_platform
$db->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(100) NOT NULL,
    password    VARCHAR(255) DEFAULT NULL,
    email       VARCHAR(255) DEFAULT NULL,
    google_sub  VARCHAR(64)  DEFAULT NULL,
    google_pic  VARCHAR(500) DEFAULT NULL,
    role        ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
    active      TINYINT(1)  NOT NULL DEFAULT 1,
    last_login  DATETIME    DEFAULT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $u = trim($_POST['username'] ?? '');
            $p = (string)($_POST['password'] ?? '');
            $e = trim($_POST['email'] ?? '');
            $r = in_array($_POST['role'] ?? '', ['superadmin','support'], true) ? $_POST['role'] : 'support';

            if ($u === '' || $e === '' || strlen($p) < 8) {
                $err = 'Usuario, email y contraseña (mínimo 8) son obligatorios.';
            } else {
                $h = password_hash($p, PASSWORD_DEFAULT);
                $db->pquery(
                    "INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, ?)",
                    'ssss', [$u, $h, $e, $r]
                );
                $ok = "Usuario «$u» creado.";
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->pquery('SELECT active FROM users_platform WHERE id = ?', 'i', [$id]);
            $cur = (int)($st->get_result()->fetch_assoc()['active'] ?? 1);
            $st->close();
            $new = $cur ? 0 : 1;
            $db->pquery('UPDATE users_platform SET active = ? WHERE id = ?', 'ii', [$new, $id]);
            $ok = $new ? 'Usuario activado.' : 'Usuario desactivado.';
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
        }
    } catch (Throwable $ex) {
        $err = 'Error: ' . $ex->getMessage();
    }
}

$users = [];
$res = $db->conn->query("SELECT id, username, email, role, active, last_login, created_at, google_sub, google_pic FROM users_platform ORDER BY id ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) { $users[] = $r; }
}

$page_title    = 'Usuarios plataforma';
$page_section  = 'users';
$topbar_title  = 'Usuarios plataforma';
$topbar_subtitle = 'Administradores y soporte interno';
$topbar_user   = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email  = $_SESSION['superadmin_email'] ?? '';
$topbar_actions = '';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($ok): ?>
      <div class="ih-alert ih-alert--success"><i class="bi bi-check-circle-fill"></i><div><?= htmlspecialchars($ok) ?></div></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="ih-alert ih-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($err) ?></div></div>
    <?php endif; ?>

    <div class="ih-card ih-mb-6">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-person-plus"></i> Crear nuevo usuario</h2>
          <div class="ih-card__subtitle">SuperAdmin o Soporte (acceso a panel)</div>
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
                <input class="ih-input" name="username" required>
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Email <span class="req">*</span></label>
                <input class="ih-input" type="email" name="email" required>
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Contraseña <span class="req">*</span></label>
                <input class="ih-input" type="password" name="password" minlength="8" required>
              </div>
            </div>
            <div class="ih-col-3">
              <div class="ih-field">
                <label class="ih-field__label">Rol</label>
                <select class="ih-select" name="role">
                  <option value="superadmin">SuperAdmin</option>
                  <option value="support">Soporte</option>
                </select>
              </div>
            </div>
          </div>
          <button class="ih-btn ih-btn--accent" type="submit"><i class="bi bi-check-lg"></i> Crear usuario</button>
        </form>
      </div>
    </div>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-people"></i> Usuarios registrados</h2>
          <div class="ih-card__subtitle"><?= count($users) ?> usuarios</div>
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
              <th class="ih-text-center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php $isActive = (int)$u['active'] === 1; ?>
              <tr style="<?= $isActive ? '' : 'opacity:.55' ?>">
                <td><strong>#<?= (int)$u['id'] ?></strong></td>
                <td>
                  <div class="ih-flex ih-items-center ih-gap-2">
                    <?php if (!empty($u['google_pic'])): ?>
                      <img src="<?= htmlspecialchars($u['google_pic']) ?>" alt="" style="width:28px;height:28px;border-radius:50%">
                    <?php else: ?>
                      <span class="ih-user-chip__avatar"><?= htmlspecialchars(strtoupper(substr($u['username'],0,1))) ?></span>
                    <?php endif; ?>
                    <span class="ih-fw-600"><?= htmlspecialchars($u['username']) ?></span>
                  </div>
                </td>
                <td class="ih-text-sm"><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td>
                  <?php if ($u['role'] === 'superadmin'): ?>
                    <span class="ih-badge ih-badge--purple">SuperAdmin</span>
                  <?php else: ?>
                    <span class="ih-badge ih-badge--info">Soporte</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($u['google_sub'])): ?>
                    <span class="ih-badge ih-badge--green"><i class="bi bi-google"></i> Vinculado</span>
                  <?php else: ?>
                    <span class="ih-badge">No vinculado</span>
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
                  <?= $u['last_login'] ? htmlspecialchars($u['last_login']) : '—' ?>
                </td>
                <td class="ih-text-center">
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= $isActive ? 'Desactivar este usuario?' : 'Activar este usuario?' ?>')">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="ih-btn ih-btn--<?= $isActive ? 'ghost' : 'accent' ?> ih-btn--sm" type="submit">
                      <?= $isActive ? '<i class="bi bi-pause"></i> Pausar' : '<i class="bi bi-play-fill"></i> Activar' ?>
                    </button>
                  </form>
                  <button class="ih-btn ih-btn--outline ih-btn--sm" type="button"
                          onclick="document.getElementById('reset-<?= (int)$u['id'] ?>').style.display='inline-flex'">
                    <i class="bi bi-key"></i> Reset
                  </button>
                  <form id="reset-<?= (int)$u['id'] ?>" method="post" style="display:none;margin-top:6px;gap:4px"
                        onsubmit="return confirm('Setear nueva contraseña?')">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="reset_pass">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <input class="ih-input" type="password" name="new_password" minlength="8" placeholder="Nueva contraseña" required style="display:inline-block;width:160px">
                    <button class="ih-btn ih-btn--primary ih-btn--sm" type="submit">Guardar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
