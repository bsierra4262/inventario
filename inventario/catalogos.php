<?php
/**
 * Gestión de las listas de "Asignado a" y "Ubicación".
 *
 * Antes esos dos campos eran texto libre en el alta y en la edición. El
 * resultado era el previsible: "Depósito", "deposito", "Dep." y "DEPOSITO"
 * conviviendo, con los reportes por ubicación y por responsable sin agrupar
 * nada. Ahora son desplegables, y esta pantalla es donde el admin los mantiene.
 *
 * Permiso: catalog.manage (por defecto, sólo admin del tenant).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/catalogos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

if (empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$usersTable = tn('users');
$stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { session_destroy(); header('Location: /auth/login.php'); exit; }

require_permission('catalog.manage', $user);

// Nombre de la empresa para la marca del topbar
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = $crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

$tipo = (string)($_GET['tipo'] ?? 'asignado_a');
if (!catalogo_tipo_valido($tipo)) { $tipo = 'asignado_a'; }
$meta  = CATALOGO_TIPOS[$tipo];
$filas = catalogo_filas($conn, $tipo);

$okMsg  = (string)($_GET['ok']  ?? '');
$errMsg = (string)($_GET['err'] ?? '');

$page_title      = 'Listas · ' . $meta['label'];
$page_section    = 'catalogos';
$topbar_title    = 'Listas';
$topbar_subtitle = $meta['label'];
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($okMsg): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i>
        <div><?= htmlspecialchars($okMsg) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($errMsg): ?>
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?= htmlspecialchars($errMsg) ?></div>
      </div>
    <?php endif; ?>

    <!-- Selector de lista -->
    <div class="ih-flex ih-gap-2 ih-mb-4 ih-fw">
      <?php foreach (CATALOGO_TIPOS as $k => $m): ?>
        <a href="?tipo=<?= urlencode($k) ?>"
           class="ih-btn ih-btn--sm <?= $k === $tipo ? 'ih-btn--primary' : 'ih-btn--ghost' ?>">
          <?= htmlspecialchars($m['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><?= htmlspecialchars($meta['label']) ?></h2>
          <p class="ih-card__subtitle"><?= htmlspecialchars($meta['ayuda']) ?></p>
        </div>
        <span class="ih-badge ih-badge--purple"><?= count($filas) ?> valores</span>
      </div>

      <div class="ih-card__body">

        <!-- Alta -->
        <form method="post" action="/backend/catalogos_crud.php" class="ih-flex ih-gap-2 ih-fw ih-mb-5">
          <?php csrfInput('catalogos_abm'); ?>
          <input type="hidden" name="accion" value="agregar">
          <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
          <input type="text" name="valor" class="ih-input" required maxlength="255"
                 style="flex:1 1 260px"
                 placeholder="<?= $tipo === 'ubicacion' ? 'Ej: Depósito Central, Oficina 2, Rack A3…' : 'Ej: Soporte Técnico, Brian Sierra, Administración…' ?>">
          <?php if (catalogo_es_arbol($tipo)): ?>
            <?php /* Un árbol necesita saber de quién cuelga el nodo nuevo. */ ?>
            <select name="padre_id" class="ih-input" style="flex:0 1 260px" title="Dentro de qué ubicación">
              <option value="">— Como ubicación principal —</option>
              <?php foreach ($filas as $op): ?>
                <option value="<?= (int)$op['id'] ?>">
                  <?= str_repeat('— ', (int)($op['nivel'] ?? 0)) . htmlspecialchars((string)$op['valor'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button type="submit" class="ih-btn ih-btn--accent">
            <i class="bi bi-plus-lg"></i> Agregar
          </button>
        </form>

        <?php if (!$filas): ?>
          <div class="ih-empty">
            <div class="ih-empty__icon"><i class="bi bi-list-ul"></i></div>
            <div class="ih-empty__title">La lista está vacía</div>
            <p class="ih-empty__text">
              Cargá acá los valores que van a poder elegirse en el campo
              «<?= htmlspecialchars($meta['label']) ?>» al dar de alta o editar un activo.
            </p>
          </div>
        <?php else: ?>
          <div class="ih-table-wrap">
            <table class="ih-table">
              <thead>
                <tr>
                  <th>Valor</th>
                  <th style="width:110px">En uso</th>
                  <th style="width:110px">Estado</th>
                  <th style="width:300px">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($filas as $f):
                    // En un árbol los activos guardan la RUTA completa, así que
                    // el conteo de uso se hace contra ella y no contra el nombre
                    // del nodo.
                    $usos = catalogo_uso($conn, $tipo, (string)($f['ruta'] ?? $f['valor']));
                    $activo = (int)$f['activo'] === 1;
                    $nivel  = (int)($f['nivel'] ?? 0); ?>
                <tr>
                  <td>
                    <form method="post" action="/backend/catalogos_crud.php" class="ih-flex ih-gap-2 ih-items-center"
                          style="padding-left:<?= $nivel * 22 ?>px">
                      <?php csrfInput('catalogos_abm'); ?>
                      <input type="hidden" name="accion" value="renombrar">
                      <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
                      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                      <?php if ($nivel > 0): ?>
                        <i class="bi bi-arrow-return-right ih-text-muted" title="<?= htmlspecialchars((string)($f['ruta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></i>
                      <?php endif; ?>
                      <input type="text" name="valor" class="ih-input" maxlength="255"
                             value="<?= htmlspecialchars((string)$f['valor'], ENT_QUOTES, 'UTF-8') ?>"
                             style="min-width:180px">
                      <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" title="Guardar el nuevo nombre">
                        <i class="bi bi-check-lg"></i>
                      </button>
                    </form>
                  </td>
                  <td>
                    <?php if ($usos > 0): ?>
                      <span class="ih-badge ih-badge--info"><?= $usos ?> activo<?= $usos === 1 ? '' : 's' ?></span>
                    <?php else: ?>
                      <span class="ih-text-muted ih-text-sm">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="ih-badge <?= $activo ? 'ih-badge--success' : 'ih-badge--neutral' ?>">
                      <?= $activo ? 'Activo' : 'De baja' ?>
                    </span>
                  </td>
                  <td>
                    <div class="ih-flex ih-gap-2 ih-fw">
                      <form method="post" action="/backend/catalogos_crud.php" style="display:inline">
                        <?php csrfInput('catalogos_abm'); ?>
                        <input type="hidden" name="accion" value="<?= $activo ? 'desactivar' : 'activar' ?>">
                        <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm">
                          <i class="bi bi-<?= $activo ? 'eye-slash' : 'eye' ?>"></i>
                          <?= $activo ? 'Dar de baja' : 'Reactivar' ?>
                        </button>
                      </form>

                      <?php if ($usos === 0): ?>
                        <form method="post" action="/backend/catalogos_crud.php" style="display:inline"
                              onsubmit="return confirm('¿Eliminar «<?= htmlspecialchars(addslashes((string)$f['valor'])) ?>» definitivamente?')">
                          <?php csrfInput('catalogos_abm'); ?>
                          <input type="hidden" name="accion" value="eliminar">
                          <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
                          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                          <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
                            <i class="bi bi-trash3"></i> Eliminar
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="ih-text-xs ih-text-muted" style="align-self:center">
                          No se puede eliminar mientras haya activos usándolo
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <p class="ih-text-xs ih-text-muted ih-mt-4">
            <i class="bi bi-info-circle"></i>
            <strong>Dar de baja</strong> saca el valor del desplegable pero deja intactos los activos que ya lo usaban.
            <strong>Renombrar</strong> arrastra el cambio a esos activos, así no quedan apuntando a un texto que ya no existe.
          </p>
        <?php endif; ?>

      </div>
    </div>

  </main>
</div><!-- /ih-app -->

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
