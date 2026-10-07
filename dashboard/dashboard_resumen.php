<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();

$db = new MasterDB();

// ── KPIs plataforma ───────────────────────────────────────────────────────────
$kpi = [
    'companies'       => 0,
    'active'          => 0,
    'inactive'        => 0,
    'platform_users'  => 0,
];
try {
    $r = $db->conn->query("SELECT COUNT(*) c, SUM(active=1) act, SUM(active=0) inact FROM companies")->fetch_assoc();
    $kpi['companies'] = (int)($r['c']     ?? 0);
    $kpi['active']    = (int)($r['act']   ?? 0);
    $kpi['inactive']  = (int)($r['inact'] ?? 0);
    $kpi['platform_users'] = (int)($db->conn->query("SELECT COUNT(*) c FROM users_platform")->fetch_assoc()['c'] ?? 0);
} catch (Throwable) {}

// ── Estado SSO ────────────────────────────────────────────────────────────────
$ssoStatus = ['google' => false, 'microsoft' => false, 'google_hd' => '', 'ms_hd' => ''];
try {
    $res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key IN (
        'sa_google_client_id','sa_google_client_secret','sa_google_hd',
        'sa_ms_client_id','sa_ms_client_secret','sa_ms_hd'
    )");
    $cfg = [];
    if ($res) { while ($r = $res->fetch_assoc()) { $cfg[$r['config_key']] = $r['config_value']; } }
    $ssoStatus['google']    = ($cfg['sa_google_client_id'] ?? '') !== '' && ($cfg['sa_google_client_secret'] ?? '') !== '';
    $ssoStatus['microsoft'] = ($cfg['sa_ms_client_id']     ?? '') !== '' && ($cfg['sa_ms_client_secret']     ?? '') !== '';
    $ssoStatus['google_hd'] = $cfg['sa_google_hd'] ?? '';
    $ssoStatus['ms_hd']     = $cfg['sa_ms_hd']     ?? '';
} catch (Throwable) {}

// ── Últimas 6 entradas del log ────────────────────────────────────────────────
$recentLogs = [];
try {
    $logFile = __DIR__ . '/../logs/audit.log';
    if (is_file($logFile)) {
        $lines = array_filter(array_map('trim', file($logFile)));
        $lines = array_reverse(array_values($lines));
        foreach (array_slice($lines, 0, 6) as $line) {
            $parts = str_getcsv($line);
            if (count($parts) >= 5) {
                $recentLogs[] = [
                    'fecha'  => trim($parts[0] ?? ''),
                    'hora'   => trim($parts[1] ?? ''),
                    'origen' => trim($parts[2] ?? ''),
                    'tipo'   => trim($parts[3] ?? ''),
                    'msg'    => trim($parts[4] ?? ''),
                ];
            }
        }
    }
} catch (Throwable) {}

// ── Últimas 5 empresas ────────────────────────────────────────────────────────
$lastCompanies = [];
try {
    $res = $db->conn->query("SELECT id, nombre, active, created_at FROM companies ORDER BY id DESC LIMIT 5");
    while ($r = $res->fetch_assoc()) { $lastCompanies[] = $r; }
} catch (Throwable) {}

$page_title    = 'Resumen';
$page_section  = 'home';
$topbar_title  = 'Resumen';
$topbar_subtitle = 'Estado general de la plataforma';
$topbar_user   = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email  = $_SESSION['superadmin_email'] ?? '';
$topbar_actions = '<a href="/dashboard/dashboard_empresas.php" class="ih-btn ih-btn--primary ih-btn--sm"><i class="bi bi-plus-lg"></i> <span class="ih-hidden-mobile">Nueva empresa</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if (!$ssoStatus['google'] && !$ssoStatus['microsoft']): ?>
      <div class="ih-alert ih-alert--warning">
        <i class="bi bi-shield-exclamation" style="font-size:22px;flex-shrink:0"></i>
        <div>
          <div class="ih-alert__title">SSO no configurado</div>
          El acceso al panel usa solo contraseña. Configurá Google Workspace o Microsoft 365 para mayor seguridad.
          <br><a href="/dashboard/superadmin_integraciones.php" class="ih-btn ih-btn--warning ih-btn--sm ih-mt-2"><i class="bi bi-plug"></i> Configurar SSO ahora</a>
        </div>
      </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="ih-stats">
      <div class="ih-stat">
        <div class="ih-stat__label">Empresas <i class="bi bi-buildings"></i></div>
        <div class="ih-stat__value"><?= $kpi['companies'] ?></div>
        <div class="ih-stat__delta">Total registradas</div>
      </div>
      <div class="ih-stat ih-stat--green">
        <div class="ih-stat__label">Activas <i class="bi bi-check-circle"></i></div>
        <div class="ih-stat__value"><?= $kpi['active'] ?></div>
        <div class="ih-stat__delta is-up">Operando</div>
      </div>
      <div class="ih-stat ih-stat--danger">
        <div class="ih-stat__label">Inactivas <i class="bi bi-pause-circle"></i></div>
        <div class="ih-stat__value"><?= $kpi['inactive'] ?></div>
        <div class="ih-stat__delta">Suspendidas</div>
      </div>
      <div class="ih-stat">
        <div class="ih-stat__label">Usuarios plataforma <i class="bi bi-people"></i></div>
        <div class="ih-stat__value"><?= $kpi['platform_users'] ?></div>
        <div class="ih-stat__delta">SuperAdmin / Soporte</div>
      </div>
    </div>

    <div class="ih-grid" style="margin-top:20px">

      <!-- SSO Status -->
      <div class="ih-col-6">
        <div class="ih-card" style="height:100%">
          <div class="ih-card__header">
            <h2 class="ih-card__title"><i class="bi bi-shield-check"></i> Estado SSO</h2>
            <a href="/dashboard/superadmin_integraciones.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-pencil"></i> Editar</a>
          </div>
          <div class="ih-card__body">
            <div class="ih-flex ih-items-center ih-gap-3" style="padding:10px 0;border-bottom:1px solid #f0ebf8">
              <i class="bi bi-google" style="font-size:20px;color:#4285F4;width:24px;text-align:center"></i>
              <div style="flex:1">
                <div class="ih-fw-600" style="font-size:14px">Google Workspace</div>
                <?php if ($ssoStatus['google_hd']): ?>
                  <div class="ih-text-xs ih-text-muted">Dominio: @<?= htmlspecialchars($ssoStatus['google_hd']) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($ssoStatus['google']): ?>
                <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle-fill"></i> Activo</span>
              <?php else: ?>
                <span class="ih-badge ih-badge--warning">Sin configurar</span>
              <?php endif; ?>
            </div>
            <div class="ih-flex ih-items-center ih-gap-3" style="padding:10px 0">
              <i class="bi bi-microsoft" style="font-size:20px;color:#00A4EF;width:24px;text-align:center"></i>
              <div style="flex:1">
                <div class="ih-fw-600" style="font-size:14px">Microsoft 365 / Azure AD</div>
                <?php if ($ssoStatus['ms_hd']): ?>
                  <div class="ih-text-xs ih-text-muted">Dominio: @<?= htmlspecialchars($ssoStatus['ms_hd']) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($ssoStatus['microsoft']): ?>
                <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle-fill"></i> Activo</span>
              <?php else: ?>
                <span class="ih-badge ih-badge--warning">Sin configurar</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Acciones rápidas -->
      <div class="ih-col-6">
        <div class="ih-card" style="height:100%">
          <div class="ih-card__header">
            <h2 class="ih-card__title"><i class="bi bi-lightning-charge"></i> Acciones rápidas</h2>
          </div>
          <div class="ih-card__body">
            <div class="ih-flex ih-flex-col ih-gap-2">
              <a href="/dashboard/dashboard_empresas.php" class="ih-btn ih-btn--outline ih-btn--block" style="justify-content:flex-start">
                <i class="bi bi-plus-square"></i> Crear nueva empresa
              </a>
              <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--outline ih-btn--block" style="justify-content:flex-start">
                <i class="bi bi-buildings"></i> Ver todas las empresas
              </a>
              <a href="/dashboard/superadmin_usuarios.php" class="ih-btn ih-btn--outline ih-btn--block" style="justify-content:flex-start">
                <i class="bi bi-people"></i> Gestionar usuarios plataforma
              </a>
              <a href="/dashboard/superadmin_logs.php" class="ih-btn ih-btn--outline ih-btn--block" style="justify-content:flex-start">
                <i class="bi bi-list-ul"></i> Ver auditoría completa
              </a>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Últimas empresas -->
    <?php if (!empty($lastCompanies)): ?>
      <div class="ih-card" style="margin-top:20px">
        <div class="ih-card__header">
          <h2 class="ih-card__title"><i class="bi bi-clock-history"></i> Empresas recientes</h2>
          <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--ghost ih-btn--sm">Ver todas</a>
        </div>
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead><tr><th>#</th><th>Empresa</th><th>Estado</th><th>Creada</th></tr></thead>
            <tbody>
            <?php foreach ($lastCompanies as $c): ?>
              <tr>
                <td><strong>#<?= (int)$c['id'] ?></strong></td>
                <td><a href="/dashboard/empresa_detalle.php?id=<?= (int)$c['id'] ?>" style="color:inherit;font-weight:600;text-decoration:none"><?= htmlspecialchars($c['nombre']) ?></a></td>
                <td>
                  <?php if ((int)$c['active']): ?>
                    <span class="ih-badge ih-badge--success ih-badge--dot">Activa</span>
                  <?php else: ?>
                    <span class="ih-badge ih-badge--danger ih-badge--dot">Inactiva</span>
                  <?php endif; ?>
                </td>
                <td class="ih-text-xs ih-text-muted"><?= htmlspecialchars(substr((string)$c['created_at'], 0, 10)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- Log reciente -->
    <?php if (!empty($recentLogs)): ?>
      <div class="ih-card" style="margin-top:20px">
        <div class="ih-card__header">
          <h2 class="ih-card__title"><i class="bi bi-list-ul"></i> Actividad reciente</h2>
          <a href="/dashboard/superadmin_logs.php" class="ih-btn ih-btn--ghost ih-btn--sm">Ver auditoría</a>
        </div>
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead><tr><th>Hora</th><th>Tipo</th><th>Origen</th><th>Mensaje</th></tr></thead>
            <tbody>
            <?php foreach ($recentLogs as $l): ?>
              <tr>
                <td class="ih-text-xs ih-text-muted"><?= htmlspecialchars($l['hora']) ?></td>
                <td><span class="ih-badge"><?= htmlspecialchars($l['tipo']) ?></span></td>
                <td class="ih-text-xs"><?= htmlspecialchars($l['origen']) ?></td>
                <td class="ih-text-xs"><?= htmlspecialchars(mb_strimwidth($l['msg'], 0, 90, '…')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
