<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/security/permissions_db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    header('Location: /auth/login.php');
    exit();
}

$usersTable = tn('users');
$stmt = $conn->prepare("SELECT * FROM `{$usersTable}` WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: /auth/login.php');
    exit();
}

// Solo admin del tenant puede gestionar usuarios
require_permission('user.view', $currentUser);

$allUsers = [];
$res = $conn->query("SELECT id, username, email, role, created_at FROM `{$usersTable}` ORDER BY id ASC");
while ($row = $res->fetch_assoc()) {
    $allUsers[] = $row;
}

$csrf = generateCsrfToken('users_crud');

$feedbackMsg  = $_GET['msg']  ?? '';
$feedbackType = ($_GET['type'] ?? 'success') === 'error' ? 'danger' : 'success';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#663399">
<title>Usuarios del tenant · Asset Vault · INTELLIHELP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" rel="modulepreload">
<link href="/assets/css/intellihelp.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  /* Modales: usamos Bootstrap pero con estilo INTELLIHELP */
  .modal-content { border-radius: 16px; border: 0; box-shadow: 0 20px 60px rgba(102,51,153,.18); }
  .modal-header { background: #663399; color: #fff; border-radius: 16px 16px 0 0; padding: 16px 22px; border-bottom: 0; }
  .modal-header .modal-title { font-weight: 700; font-size: 16px; }
  .modal-body { padding: 22px; }
  .modal-footer { border-top: 1px solid #E4E4E7; padding: 14px 22px; }
  .form-label { font-size: 12px; font-weight: 600; color: #3F3F46; text-transform: uppercase; letter-spacing: .3px; }
  .form-control, .form-select { border-radius: 10px; border-color: #D4D4D8; font-family: 'Saira', sans-serif; }
  .form-control:focus, .form-select:focus { border-color: #663399; box-shadow: 0 0 0 3px rgba(102,51,153,.12); }
</style>
</head>
<body>

<!-- Topbar simple (compartido con index del tenant) -->
<style>
  header.ih-topbar-tenant { display: flex; align-items: center; padding: 0 16px; height: 64px; background: #fff; border-bottom: 1px solid #E4E4E7; position: sticky; top: 0; z-index: 900; box-shadow: 0 1px 3px rgba(17,20,24,.04); }
  header.ih-topbar-tenant .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
  header.ih-topbar-tenant .brand__logo { width: 36px; height: 36px; background: #663399; color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; position: relative; }
  header.ih-topbar-tenant .brand__logo::after { display: none; }
  header.ih-topbar-tenant .brand__text strong { font-size: 15px; color: #18181B; font-weight: 700; letter-spacing: -.3px; display: block; line-height: 1.1; }
  header.ih-topbar-tenant .brand__text span { font-size: 11px; color: #71717A; }
  header.ih-topbar-tenant .topbar-spacer { flex: 1; }
  header.ih-topbar-tenant a.back { color: #663399; font-weight: 600; font-size: 13px; text-decoration: none; padding: 8px 14px; border-radius: 10px; border: 1px solid #D0BFEA; background: #fff; transition: all .15s; }
  header.ih-topbar-tenant a.back:hover { background: #F4F1F9; }
</style>
<header class="ih-topbar-tenant">
  <a class="brand" href="/inventario/index.php">
    <div class="brand__logo"><?= platform_logo_html() ?></div>
    <div class="brand__text">
      <strong>Asset Vault</strong>
      <span>INTELLIHELP · Inventario</span>
    </div>
  </a>
  <div class="topbar-spacer"></div>
  <a class="back" href="/inventario/index.php"><i class="bi bi-arrow-left"></i> Volver al inventario</a>
</header>

<main style="max-width:1100px;margin:0 auto;padding:24px 16px 60px;">

  <div class="ih-page-header">
    <div>
      <h1 class="ih-page-header__title"><i class="bi bi-people-fill" style="color:#663399"></i> Usuarios del tenant</h1>
      <div class="ih-page-header__subtitle">Administrá los usuarios de esta empresa cliente</div>
    </div>
  </div>

  <?php if ($feedbackMsg !== ''): ?>
    <div class="ih-alert ih-alert--<?= $feedbackType === 'danger' ? 'danger' : 'success' ?>">
      <i class="bi bi-<?= $feedbackType === 'danger' ? 'exclamation-triangle' : 'check-circle' ?>-fill"></i>
      <div><?= htmlspecialchars($feedbackMsg) ?></div>
      <button class="ih-alert__close" onclick="this.parentElement.style.display='none'"><i class="bi bi-x"></i></button>
    </div>
  <?php endif; ?>

  <!-- Formulario nuevo usuario -->
  <div class="ih-card ih-mb-6">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-person-plus"></i> Crear nuevo usuario</h2>
        <div class="ih-card__subtitle">Asignale rol Admin o Usuario regular</div>
      </div>
    </div>
    <div class="ih-card__body">
      <form method="POST" action="/backend/users_crud.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <div class="ih-grid">
          <div class="ih-col-3">
            <div class="ih-field">
              <label class="ih-field__label">Usuario <span class="req">*</span></label>
              <input class="ih-input" type="text" name="username" required
                     pattern="[a-zA-Z0-9._\-]{3,120}"
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
              <label class="ih-field__label">Contraseña <span class="req">*</span></label>
              <input class="ih-input" type="password" name="password" minlength="8" required>
              <div class="ih-field__hint">Mínimo 8 caracteres.</div>
            </div>
          </div>
          <div class="ih-col-3">
            <div class="ih-field">
              <label class="ih-field__label">Rol <span class="req">*</span></label>
              <select class="ih-select" name="role" required>
                <?php foreach (all_assignable_roles($conn) as $rk => $rlbl): ?>
                  <option value="<?= htmlspecialchars($rk) ?>" <?= $rk === 'user' ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rlbl) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="ih-field__hint">
                Crea roles personalizados desde <a href="/inventario/roles.php" style="color:var(--ih-primary);">Roles y permisos</a>.
              </div>
            </div>
          </div>
        </div>

        <button class="ih-btn ih-btn--accent" type="submit"><i class="bi bi-check-lg"></i> Crear usuario</button>
      </form>
    </div>
  </div>

  <!-- Tabla de usuarios -->
  <div class="ih-card">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-person-lines-fill"></i> Usuarios registrados</h2>
        <div class="ih-card__subtitle"><?= count($allUsers) ?> usuarios</div>
      </div>
    </div>

    <?php if (empty($allUsers)): ?>
      <div class="ih-empty">
        <div class="ih-empty__icon"><i class="bi bi-person-x"></i></div>
        <div class="ih-empty__title">No hay usuarios</div>
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
              <th>Creado</th>
              <th class="ih-text-center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allUsers as $u): ?>
              <tr>
                <td><strong>#<?= (int)$u['id'] ?></strong></td>
                <td>
                  <strong><?= htmlspecialchars($u['username']) ?></strong>
                  <?php if ((int)$u['id'] === $user_id): ?>
                    <span class="ih-badge ih-badge--purple" style="margin-left:6px">VOS</span>
                  <?php endif; ?>
                </td>
                <td class="ih-text-sm"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                <td>
                  <?php
                    $roleClass = match ($u['role']) {
                        'admin'   => 'ih-badge--purple',
                        'manager' => 'ih-badge--info',
                        'user'    => 'ih-badge--green',
                        'viewer'  => 'ih-badge',
                        default   => 'ih-badge',
                    };
                  ?>
                  <span class="ih-badge <?= $roleClass ?>"><?= htmlspecialchars(role_label($u['role'])) ?></span>
                </td>
                <td class="ih-text-xs ih-text-muted"><?= htmlspecialchars($u['created_at'] ?? '') ?></td>
                <td class="ih-text-center">
                  <button class="ih-btn ih-btn--outline ih-btn--sm" type="button"
                          onclick="abrirEditar(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>', '<?= $u['role'] ?>')">
                    <i class="bi bi-pencil"></i> Editar
                  </button>
                  <?php if ((int)$u['id'] !== $user_id): ?>
                    <button class="ih-btn ih-btn--danger ih-btn--sm" type="button"
                            onclick="confirmarEliminar(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                      <i class="bi bi-trash"></i>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar: <span id="editUsername"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="/backend/users_crud.php" autocomplete="off">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="target_id" id="editTargetId">

          <div class="ih-field">
            <label class="ih-field__label">Email</label>
            <input type="email" name="email" id="editEmail" class="ih-input">
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Rol</label>
            <select name="role" id="editRole" class="ih-select">
              <?php foreach (all_assignable_roles($conn) as $rk => $rlbl): ?>
                <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rlbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field">
            <label class="ih-field__label">Nueva contraseña</label>
            <input type="password" name="password" class="ih-input" minlength="8" autocomplete="new-password" placeholder="(dejá vacío para no cambiar)">
            <div class="ih-field__hint">Mínimo 8 caracteres si la modificás.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="ih-btn ih-btn--ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="ih-btn ih-btn--primary">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Eliminar -->
<div class="modal fade" id="modalEliminar" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:#DC2626">
        <h5 class="modal-title"><i class="bi bi-trash"></i> Eliminar usuario</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        ¿Confirmás eliminar a <strong id="deleteUsername"></strong>?
        <div class="ih-text-xs ih-text-muted ih-mt-2">Esta acción no se puede deshacer.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="ih-btn ih-btn--ghost" data-bs-dismiss="modal">Cancelar</button>
        <form method="POST" action="/backend/users_crud.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="target_id" id="deleteTargetId">
          <button type="submit" class="ih-btn ih-btn--danger">Eliminar</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirEditar(id, username, email, role) {
  document.getElementById('editTargetId').value = id;
  document.getElementById('editUsername').textContent = username;
  document.getElementById('editEmail').value = email;
  document.getElementById('editRole').value = role;
  new bootstrap.Modal(document.getElementById('modalEditar')).show();
}
function confirmarEliminar(id, username) {
  document.getElementById('deleteTargetId').value = id;
  document.getElementById('deleteUsername').textContent = username;
  new bootstrap.Modal(document.getElementById('modalEliminar')).show();
}
</script>
</body>
</html>
