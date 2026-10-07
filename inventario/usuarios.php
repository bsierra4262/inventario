<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
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

// La migración de `role` ENUM→VARCHAR vivía acá y corría en cada carga de la
// pantalla. Ahora es la migración 1 de core/db/migraciones.php, que se aplica
// una sola vez con `php bin/migrar.php`.

$allUsers = [];
$res = $conn->query("SELECT id, username, email, role, created_at FROM `{$usersTable}` ORDER BY id ASC");
while ($row = $res->fetch_assoc()) {
    $allUsers[] = $row;
}

$csrf = generateCsrfToken('users_crud');

$feedbackMsg  = $_GET['msg']  ?? '';
$feedbackType = ($_GET['type'] ?? 'success') === 'error' ? 'danger' : 'success';

// Nombre de la empresa para la marca del topbar
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst  = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = $crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

$page_title      = 'Usuarios';
$page_section    = 'usuarios';
$topbar_title    = 'Usuarios';
$topbar_subtitle = count($allUsers) . ' en la empresa';
$topbar_user     = $currentUser['username'] ?? '';
$topbar_email    = $currentUser['email']    ?? '';
$topbar_actions  = hub_volver('configuracion');

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

<main class="ih-app__main ih-anim-fade">

  <?php if ($feedbackMsg !== ''): ?>
    <div class="ih-alert ih-alert--<?= $feedbackType === 'danger' ? 'danger' : 'success' ?>">
      <i class="bi bi-<?= $feedbackType === 'danger' ? 'exclamation-triangle' : 'check-circle' ?>-fill"></i>
      <div><?= htmlspecialchars($feedbackMsg) ?></div>
      <button class="ih-alert__close" type="button" aria-label="Cerrar el aviso"><i class="bi bi-x"></i></button>
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
                     placeholder="Ej: jperez"
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
</div><!-- /ih-app -->

<?php /* Modales nativos <dialog>. Antes eran de Bootstrap: traían una hoja de
         estilos entera y un bundle de JS sólo para estas dos ventanitas, y el
         reset de Bootstrap peleaba con el sistema de diseño de la app. */ ?>

<!-- Modal Editar -->
<dialog class="ih-modal" id="modalEditar">
      <div class="ih-modal__header">
        <h5 class="ih-modal__title"><i class="bi bi-pencil-square"></i> Editar: <span id="editUsername"></span></h5>
        <button type="button" class="ih-modal__close" data-cerrar aria-label="Cerrar">&times;</button>
      </div>
      <form method="POST" action="/backend/users_crud.php" autocomplete="off">
        <div class="ih-modal__body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="target_id" id="editTargetId">

          <div class="ih-field">
            <label class="ih-field__label">Email</label>
            <input type="email" name="email" id="editEmail" class="ih-input" placeholder="usuario@empresa.com">
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
        <div class="ih-modal__footer">
          <button type="button" class="ih-btn ih-btn--ghost" data-cerrar>Cancelar</button>
          <button type="submit" class="ih-btn ih-btn--primary">Guardar cambios</button>
        </div>
      </form>
</dialog>

<!-- Modal Eliminar -->
<dialog class="ih-modal" id="modalEliminar" style="width:min(420px,calc(100vw - 2rem))">
      <div class="ih-modal__header" style="background:var(--danger)">
        <h5 class="ih-modal__title"><i class="bi bi-trash"></i> Eliminar usuario</h5>
        <button type="button" class="ih-modal__close" data-cerrar aria-label="Cerrar">&times;</button>
      </div>
      <div class="ih-modal__body">
        ¿Confirmás eliminar a <strong id="deleteUsername"></strong>?
        <div class="ih-text-xs ih-text-muted ih-mt-2">Esta acción no se puede deshacer.</div>
      </div>
      <div class="ih-modal__footer">
        <button type="button" class="ih-btn ih-btn--ghost" data-cerrar>Cancelar</button>
        <form method="POST" action="/backend/users_crud.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="target_id" id="deleteTargetId">
          <button type="submit" class="ih-btn ih-btn--danger">Eliminar</button>
        </form>
      </div>
</dialog>

<script>
function abrirEditar(id, username, email, role) {
  document.getElementById('editTargetId').value = id;
  document.getElementById('editUsername').textContent = username;
  document.getElementById('editEmail').value = email;
  document.getElementById('editRole').value = role;
  document.getElementById('modalEditar').showModal();
}
function confirmarEliminar(id, username) {
  document.getElementById('deleteTargetId').value = id;
  document.getElementById('deleteUsername').textContent = username;
  document.getElementById('modalEliminar').showModal();
}
// Cerrar: botones marcados con data-cerrar y clic en el fondo oscuro.
document.addEventListener('click', function (e) {
  const cerrar = e.target.closest('[data-cerrar]');
  if (cerrar) { cerrar.closest('dialog')?.close(); return; }
  if (e.target.tagName === 'DIALOG') { e.target.close(); }
});
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
