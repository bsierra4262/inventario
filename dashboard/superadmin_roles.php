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
    // Sin cortar la página: el aviso va arriba de la matriz de permisos.
    if (!csrf_ok()) {
        $feedback = 'La validación de seguridad falló. Recargá la página.'; $feedbackOk = false;
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

<?php /* Acá vivía un <style> con doce reglas que volvían a dibujar, con otros
         nombres y otros colores, componentes que ya existen en la hoja
         compartida: las pastillas de rol eran `.ih-pill`, y los grupos de
         permisos, `.ih-permisos`. Las dos pantallas de roles —la del cliente y
         ésta— hacían lo mismo de dos formas, y los colores escritos a mano
         (#1a1a2e, #6b6b7b, #999, #f59e0b) no eran los de la identidad. */ ?>

<?php /* El título y la bajada estaban escritos DOS veces en la misma pantalla:
         acá en un <h1> propio y arriba en la barra, que ya recibe
         `$topbar_title` y `$topbar_subtitle`. Se leía el mismo texto dos veces y
         ocupaba el doble de alto. Queda el de la barra, que es el que tienen
         todas las demás pantallas. */ ?>

  <?php if ($feedback !== ''): ?>
    <div class="ih-alert <?= $feedbackOk ? 'ih-alert--success' : 'ih-alert--warning' ?>">
      <i class="bi bi-<?= $feedbackOk ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
      <div><?= htmlspecialchars($feedback) ?></div>
    </div>
  <?php endif; ?>

  <div class="ih-alert ih-alert--info">
    <i class="bi bi-info-circle-fill"></i>
    <div>
      El rol <strong>Super Administrador</strong> siempre mantiene todos los permisos (protección anti-lockout).
      Para los demás roles podés ajustar permisos. Si dejás un rol sin overrides, se usan los defaults del sistema.
    </div>
  </div>

  <?php /* Las pastillas eligen el rol que se está editando. No navegan: muestran
           y esconden los paneles de abajo. Mismo componente que en el portal del
           cliente. */ ?>
  <div class="ih-pills" role="group" aria-label="Rol que se está editando">
    <?php foreach (PLATFORM_ROLES as $r): ?>
      <button class="ih-pill" data-role="<?= $r ?>" type="button">
        <?= htmlspecialchars(PLATFORM_ROLE_LABELS[$r] ?? $r) ?>
        <?php if ($r === 'superadmin'): ?>
          <span class="ih-badge ih-badge--success">Protegido</span>
        <?php elseif ($roleOverrides[$r]): ?>
          <span class="ih-badge ih-badge--warning">Editado</span>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach (PLATFORM_ROLES as $r): ?>
  <div class="role-panel" data-role-panel="<?= $r ?>" hidden>
    <form method="POST" action="/dashboard/superadmin_roles.php" id="form-role-<?= $r ?>">
      <?php /* Este campo estaba escrito a mano, leyendo la sesión directo. Es
               justo cómo se llega a que un portal use un nombre y el otro, otro:
               el helper existe para que el nombre esté en un solo lugar. */ ?>
      <?php csrf_input(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="role" value="<?= $r ?>">

      <div class="ih-barra">
        <div class="ih-barra__texto">
          <h3 class="ih-barra__titulo">
            <?= htmlspecialchars(PLATFORM_ROLE_LABELS[$r] ?? $r) ?>
            <?php if ($r === 'superadmin'): ?>
              <span class="ih-badge ih-badge--success">Protegido</span>
            <?php elseif ($roleOverrides[$r]): ?>
              <span class="ih-badge ih-badge--warning">Editado</span>
            <?php else: ?>
              <span class="ih-badge ih-badge--neutral">Por defecto</span>
            <?php endif; ?>
          </h3>
          <p class="ih-barra__ayuda">
            <?= $r === 'superadmin' ? 'Acceso total — no editable' : (count($rolePerms[$r]) . ' permisos activos') ?>
          </p>
        </div>
        <?php if ($r !== 'superadmin'): ?>
        <div class="ih-barra__acciones">
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

      <div class="ih-permisos">
      <?php foreach ($groups as $groupName => $perms): ?>
        <div class="ih-permisos__grupo">
          <div class="ih-permisos__titulo"><?= htmlspecialchars($groupName) ?></div>
          <?php foreach ($perms as $action => $label):
            $checked = in_array($action, $rolePerms[$r], true);
            $isProtected = ($r === 'superadmin');
          ?>
            <div class="ih-permisos__fila">
              <label>
                <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($action) ?>"
                       <?= $checked ? 'checked' : '' ?>
                       <?= $isProtected ? 'disabled' : '' ?>>
                <span><?= htmlspecialchars($label) ?></span>
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
/* `hidden` y no `style.display`: la hoja compartida declara
   `[hidden] { display: none !important }`, asi que mezclar las dos formas deja
   paneles que no se pueden volver a mostrar. El estado vive en el HTML. */
const tabs = document.querySelectorAll('.ih-pill[data-role]');
const panels = document.querySelectorAll('.role-panel');
function showRole(r) {
  tabs.forEach(t => {
    const activa = t.dataset.role === r;
    t.classList.toggle('is-active', activa);
    t.setAttribute('aria-pressed', activa ? 'true' : 'false');
  });
  panels.forEach(p => { p.hidden = p.dataset.rolePanel !== r; });
  try { localStorage.setItem('sa_roles_last_tab', r); } catch {}
}
tabs.forEach(t => t.addEventListener('click', () => showRole(t.dataset.role)));
const lastTab = (() => { try { return localStorage.getItem('sa_roles_last_tab'); } catch { return null; }})();
showRole(lastTab && document.querySelector('[data-role-panel="'+lastTab+'"]') ? lastTab : 'admin');
</script>

</main>
</div>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
