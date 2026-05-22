<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();

$db = new MasterDB();

// Cargar empresas
$companies = [];
try {
    $res = $db->conn->query("
        SELECT id, nombre, razon_social, cuit, provider, tenant_mode, active, created_at
        FROM companies
        ORDER BY id ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $companies[] = $row;
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

// Mensaje flash (desde redireccionamiento)
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
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>SuperAdmin — Asset Vault</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body            { font-family: 'Saira', system-ui, sans-serif; background: #f4f1f9; }
  .topbar         { background: #663399; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
  .topbar .brand  { font-size: 18px; font-weight: 700; letter-spacing: -.3px; }
  .topbar .brand small { font-weight: 400; opacity: .7; font-size: 13px; display: block; }
  .topbar-actions a { color: #fff; text-decoration: none; margin-left: 10px; padding: 7px 14px; border-radius: 10px; font-weight: 600; font-size: 13px; }
  .topbar-actions .btn-new    { background: #86B329; }
  .topbar-actions .btn-new:hover { background: #6e931f; }
  .topbar-actions .btn-logout { background: rgba(255,255,255,.15); }
  .topbar-actions .btn-logout:hover { background: rgba(255,255,255,.25); }
  .content        { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
  .card           { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(102,51,153,.08); }
  .card-header    { background: #fff; border-bottom: 1px solid #ede8f5; border-radius: 16px 16px 0 0 !important; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
  .card-header h5 { margin: 0; font-weight: 700; color: #333; }
  .table          { margin-bottom: 0; }
  .table th       { background: #f9f5ff; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #7a5fa3; border-bottom: 2px solid #ede8f5; }
  .table td       { vertical-align: middle; font-size: 14px; }
  .badge-provider { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; }
  .badge-google   { background: #e8f5e9; color: #2e7d32; }
  .badge-ms       { background: #e3f2fd; color: #1565c0; }
  .badge-prefix   { background: #fff8e1; color: #8d6e63; }
  .badge-external { background: #f3e5f5; color: #6a1b9a; }
  .badge-active   { background: #e8f5e9; color: #2e7d32; }
  .badge-inactive { background: #ffebee; color: #c62828; }
  .action-btn     { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; }
  .action-edit    { background: #ede8f5; color: #663399; }
  .action-edit:hover { background: #663399; color: #fff; }
  .action-toggle  { background: #fff3e0; color: #e65100; }
  .action-toggle:hover { background: #e65100; color: #fff; }
  .action-toggle.activate { background: #e8f5e9; color: #2e7d32; }
  .action-toggle.activate:hover { background: #2e7d32; color: #fff; }
  .empty-state    { text-align: center; padding: 60px 20px; color: #999; }
  .stat-cards     { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
  .stat-card      { background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 2px 12px rgba(102,51,153,.07); }
  .stat-card .num { font-size: 32px; font-weight: 700; color: #663399; line-height: 1; }
  .stat-card .lbl { font-size: 13px; color: #888; margin-top: 4px; }
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    Asset Vault
    <small>Panel SuperAdmin</small>
  </div>
  <div class="topbar-actions">
    <a href="/dashboard/dashboard_empresas.php" class="btn-new"><i class="bi bi-plus-lg"></i> Nueva empresa</a>
    <a href="/auth/superadmin_logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Salir</a>
  </div>
</div>

<div class="content">

  <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:12px;">
      <i class="bi bi-check-circle-fill me-1"></i> <?php echo htmlspecialchars($flash); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (!empty($loadError)): ?>
    <div class="alert alert-danger" style="border-radius:12px;">
      Error cargando datos: <?php echo htmlspecialchars($loadError); ?>
    </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <?php
    $totalActive   = count(array_filter($companies, fn($c) => (int)($c['active'] ?? 1) === 1));
    $totalInactive = count($companies) - $totalActive;
    $totalGoogle   = count(array_filter($companies, fn($c) => ($c['provider'] ?? 'google') === 'google'));
    $totalMs       = count($companies) - $totalGoogle;
  ?>
  <div class="stat-cards">
    <div class="stat-card">
      <div class="num"><?php echo count($companies); ?></div>
      <div class="lbl">Empresas totales</div>
    </div>
    <div class="stat-card">
      <div class="num" style="color:#86B329"><?php echo $totalActive; ?></div>
      <div class="lbl">Activas</div>
    </div>
    <div class="stat-card">
      <div class="num" style="color:#c62828"><?php echo $totalInactive; ?></div>
      <div class="lbl">Desactivadas</div>
    </div>
    <div class="stat-card">
      <div class="num" style="color:#2e7d32"><?php echo $totalGoogle; ?></div>
      <div class="lbl">Google Drive</div>
    </div>
    <div class="stat-card">
      <div class="num" style="color:#1565c0"><?php echo $totalMs; ?></div>
      <div class="lbl">Microsoft</div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h5><i class="bi bi-buildings me-2"></i>Empresas</h5>
      <span style="font-size:13px;color:#999;"><?php echo count($companies); ?> registros</span>
    </div>
    <div class="card-body p-0">
      <?php if (empty($companies)): ?>
        <div class="empty-state">
          <i class="bi bi-building" style="font-size:48px;opacity:.3;"></i>
          <p style="margin-top:12px;">No hay empresas registradas.<br>
            <a href="/dashboard/dashboard_empresas.php">Crear la primera empresa</a>
          </p>
        </div>
      <?php else: ?>
      <table class="table table-hover">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Razón social</th>
            <th>Proveedor</th>
            <th>Modo</th>
            <th>Estado</th>
            <th>Creada</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($companies as $c): ?>
          <?php
            $isActive = (int)($c['active'] ?? 1) === 1;
            $provider = $c['provider'] ?? 'google';
          ?>
          <tr style="<?php echo $isActive ? '' : 'opacity:.55;'; ?>">
            <td><strong><?php echo (int)$c['id']; ?></strong></td>
            <td><?php echo htmlspecialchars($c['nombre']); ?></td>
            <td><?php echo htmlspecialchars($c['razon_social'] ?? ''); ?></td>
            <td>
              <?php if ($provider === 'google'): ?>
                <span class="badge-provider badge-google"><i class="bi bi-google"></i> Google</span>
              <?php else: ?>
                <span class="badge-provider badge-ms"><i class="bi bi-microsoft"></i> Microsoft</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['tenant_mode'] === 'prefix'): ?>
                <span class="badge-provider badge-prefix">Prefijo</span>
              <?php else: ?>
                <span class="badge-provider badge-external">DB propia</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isActive): ?>
                <span class="badge-provider badge-active"><i class="bi bi-circle-fill" style="font-size:8px;"></i> Activa</span>
              <?php else: ?>
                <span class="badge-provider badge-inactive"><i class="bi bi-circle" style="font-size:8px;"></i> Inactiva</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;font-size:13px;color:#888;"><?php echo htmlspecialchars(substr($c['created_at'] ?? '', 0, 10)); ?></td>
            <td>
              <a href="/dashboard/empresa_editar.php?id=<?php echo (int)$c['id']; ?>"
                 class="action-btn action-edit me-1">
                <i class="bi bi-pencil-fill"></i> Editar
              </a>
              <form method="post" action="/dashboard/empresa_eliminar.php" class="d-inline"
                    onsubmit="return confirm('<?php echo $isActive ? '¿Desactivar esta empresa?' : '¿Activar esta empresa?'; ?>')">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <input type="hidden" name="action" value="<?php echo $isActive ? 'deactivate' : 'activate'; ?>">
                <button type="submit" class="action-btn action-toggle <?php echo $isActive ? '' : 'activate'; ?>">
                  <?php if ($isActive): ?>
                    <i class="bi bi-pause-fill"></i> Desactivar
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
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
