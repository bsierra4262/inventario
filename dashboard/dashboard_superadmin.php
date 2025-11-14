<?php
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();
$db = new MasterDB();

// Cargar empresas
$companies = [];
$res = $db->pquery('SELECT id,nombre,provider,tenant_mode,created_at FROM companies ORDER BY id DESC');
$rs = $res->get_result();
while($row = $rs->fetch_assoc()){ $companies[] = $row; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>SuperAdmin | Empresas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Saira,system-ui;-apple-system,Segoe UI,Roboto,Arial;background:#fff;margin:0}
.top{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#663399;color:#fff}
a.btn{background:#86B329;color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:600}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:12px;border-bottom:1px solid #eee;text-align:left}
.badge{padding:4px 8px;border-radius:8px;font-size:12px;background:#f5f5f5}
.badge.g{background:#e8f5e9;color:#2e7d32}
.badge.m{background:#e3f2fd;color:#1565c0}
.badge.p{background:#fff8e1;color:#8d6e63}
</style>
</head>
<body>
<div class="top">
  <div><strong>Asset Vault — Panel SuperAdmin</strong></div>
  <div>
    <a class="btn" href="/dashboard/dashboard_empresas.php">+ Crear empresa</a>
    <a class="btn" style="background:#444" href="/auth/superadmin_logout.php">Salir</a>
  </div>
</div>

<div style="padding:20px">
  <h2 style="margin:0 0 12px">Empresas</h2>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th><th>Nombre</th><th>Proveedor</th><th>Modo</th><th>Creada</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($companies as $c): ?>
      <tr>
        <td><?php echo (int)$c['id']; ?></td>
        <td><?php echo htmlspecialchars($c['nombre']); ?></td>
        <td>
          <?php if($c['provider']==='google'): ?>
            <span class="badge g">Google</span>
          <?php else: ?>
            <span class="badge m">Microsoft</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($c['tenant_mode']==='prefix'): ?>
            <span class="badge p">Tablas con prefijo</span>
          <?php else: ?>
            <span class="badge">DB separada</span>
          <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($c['created_at']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
