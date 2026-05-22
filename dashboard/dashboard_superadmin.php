<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();

$db = new MasterDB();

$companies = [];
$loadError = '';
try {
    $res = $db->conn->query("
        SELECT id, nombre, razon_social, cuit, provider, tenant_mode, active, created_at, login_url, subdomain
        FROM companies
        ORDER BY id ASC
    ");
    while ($row = $res->fetch_assoc()) { $companies[] = $row; }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$flash = '';
if (!empty($_GET['ok'])) {
    $flash = match ($_GET['ok']) {
        'empresa_creada'    => 'Empresa creada correctamente.',
        'empresa_editada'   => 'Empresa actualizada correctamente.',
        'empresa_eliminada' => 'Empresa desactivada correctamente.',
        'empresa_activada'  => 'Empresa activada correctamente.',
        default             => ''
    };
}

$totalActive   = count(array_filter($companies, fn($c) => (int)($c['active'] ?? 1) === 1));
$totalInactive = count($companies) - $totalActive;
$totalGoogle   = count(array_filter($companies, fn($c) => ($c['provider'] ?? 'google') === 'google'));
$totalMs       = count($companies) - $totalGoogle;

$page_title    = 'Empresas';
$page_section  = 'empresas';
$topbar_title  = 'Empresas';
$topbar_subtitle = 'Gestión de clientes de la plataforma';
$topbar_user   = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email  = $_SESSION['superadmin_email'] ?? '';
$topbar_actions = '<a href="/dashboard/dashboard_empresas.php" class="ih-btn ih-btn--accent ih-btn--sm"><i class="bi bi-plus-lg"></i> <span class="ih-hidden-mobile">Nueva empresa</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($flash): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i>
        <div><?= htmlspecialchars($flash) ?></div>
        <button class="ih-alert__close" onclick="this.parentElement.style.display='none'"><i class="bi bi-x"></i></button>
      </div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>Error cargando empresas: <?= htmlspecialchars($loadError) ?></div>
      </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="ih-stats">
      <div class="ih-stat">
        <div class="ih-stat__label">Empresas <i class="bi bi-buildings"></i></div>
        <div class="ih-stat__value"><?= count($companies) ?></div>
        <div class="ih-stat__delta">Total registradas</div>
      </div>
      <div class="ih-stat ih-stat--green">
        <div class="ih-stat__label">Activas <i class="bi bi-check-circle"></i></div>
        <div class="ih-stat__value"><?= $totalActive ?></div>
        <div class="ih-stat__delta is-up">Operando</div>
      </div>
      <div class="ih-stat ih-stat--danger">
        <div class="ih-stat__label">Inactivas <i class="bi bi-pause-circle"></i></div>
        <div class="ih-stat__value"><?= $totalInactive ?></div>
        <div class="ih-stat__delta">Suspendidas</div>
      </div>
      <div class="ih-stat ih-stat--info">
        <div class="ih-stat__label">Google <i class="bi bi-google"></i></div>
        <div class="ih-stat__value"><?= $totalGoogle ?></div>
        <div class="ih-stat__delta">Workspace / Drive</div>
      </div>
      <div class="ih-stat ih-stat--warn">
        <div class="ih-stat__label">Microsoft <i class="bi bi-microsoft"></i></div>
        <div class="ih-stat__value"><?= $totalMs ?></div>
        <div class="ih-stat__delta">365 / OneDrive</div>
      </div>
    </div>

    <!-- Tabla de empresas -->
    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-buildings"></i> Empresas registradas</h2>
          <div class="ih-card__subtitle"><?= count($companies) ?> registros</div>
        </div>
        <a href="/dashboard/dashboard_empresas.php" class="ih-btn ih-btn--primary ih-btn--sm">
          <i class="bi bi-plus-lg"></i> Crear empresa
        </a>
      </div>

      <?php if (empty($companies)): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-building"></i></div>
          <div class="ih-empty__title">No hay empresas registradas</div>
          <div class="ih-empty__text">Empezá creando tu primera empresa cliente. Vas a definir credenciales SSO, base de datos y administrador.</div>
          <a href="/dashboard/dashboard_empresas.php" class="ih-btn ih-btn--primary">
            <i class="bi bi-plus-lg"></i> Crear primera empresa
          </a>
        </div>
      <?php else: ?>
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Empresa</th>
                <th>URL acceso</th>
                <th>Proveedor</th>
                <th>Modo</th>
                <th>Estado</th>
                <th>Creada</th>
                <th class="ih-text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($companies as $c): ?>
              <?php
                $isActive = (int)($c['active'] ?? 1) === 1;
                $provider = $c['provider'] ?? 'google';
              ?>
              <tr style="<?= $isActive ? '' : 'opacity:.55;' ?>">
                <td><strong>#<?= (int)$c['id'] ?></strong></td>
                <td>
                  <a href="/dashboard/empresa_detalle.php?id=<?= (int)$c['id'] ?>" class="ih-fw-700" style="color:inherit;text-decoration:none">
                    <?= htmlspecialchars($c['nombre']) ?>
                  </a>
                  <?php if (!empty($c['razon_social'])): ?>
                    <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars($c['razon_social']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php $url = $c['login_url'] ?? ''; ?>
                  <?php if ($url): ?>
                    <div class="ih-flex ih-items-center ih-gap-2">
                      <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" class="ih-text-sm ih-fw-600" style="color:#663399;text-decoration:none;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block">
                        <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars(preg_replace('#^https?://#', '', $url)) ?>
                      </a>
                      <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($url, ENT_QUOTES) ?>');this.innerHTML='<i class=\'bi bi-check2\'></i>'" title="Copiar URL">
                        <i class="bi bi-clipboard"></i>
                      </button>
                    </div>
                  <?php else: ?>
                    <span class="ih-text-xs ih-text-muted">— sin URL definida —</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($provider === 'google'): ?>
                    <span class="ih-badge ih-badge--green"><i class="bi bi-google"></i> Google</span>
                  <?php else: ?>
                    <span class="ih-badge ih-badge--info"><i class="bi bi-microsoft"></i> Microsoft</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($c['tenant_mode'] === 'external'): ?>
                    <span class="ih-badge ih-badge--purple">DB propia</span>
                  <?php else: ?>
                    <span class="ih-badge ih-badge--warning">Prefijo</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="ih-badge ih-badge--success ih-badge--dot">Activa</span>
                  <?php else: ?>
                    <span class="ih-badge ih-badge--danger ih-badge--dot">Inactiva</span>
                  <?php endif; ?>
                </td>
                <td class="ih-text-muted ih-text-xs">
                  <?= htmlspecialchars(substr((string)($c['created_at'] ?? ''), 0, 10)) ?>
                </td>
                <td class="ih-text-center">
                  <a href="/dashboard/empresa_usuarios.php?id=<?= (int)$c['id'] ?>" class="ih-btn ih-btn--outline ih-btn--sm" title="Gestionar usuarios del tenant">
                    <i class="bi bi-people"></i>
                  </a>
                  <a href="/dashboard/empresa_editar.php?id=<?= (int)$c['id'] ?>" class="ih-btn ih-btn--outline ih-btn--sm">
                    <i class="bi bi-pencil"></i> Editar
                  </a>
                  <form method="post" action="/dashboard/empresa_eliminar.php" style="display:inline"
                        onsubmit="return confirm('<?= $isActive ? '¿Desactivar esta empresa?' : '¿Activar esta empresa?' ?>')">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                    <button type="submit" class="ih-btn ih-btn--<?= $isActive ? 'ghost' : 'accent' ?> ih-btn--sm">
                      <?php if ($isActive): ?>
                        <i class="bi bi-pause"></i> Pausar
                      <?php else: ?>
                        <i class="bi bi-play-fill"></i> Activar
                      <?php endif; ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
