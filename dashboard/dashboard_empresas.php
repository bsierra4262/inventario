<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();
csrf_start();

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $nombre      = trim($_POST['nombre']      ?? '');
    $razon       = trim($_POST['razon_social'] ?? '');
    $cuit        = trim($_POST['cuit']        ?? '');
    $provider    = in_array($_POST['provider'] ?? '', PROVIDERS, true) ? $_POST['provider'] : 'google';
    $tenant_mode = (($_POST['tenant_mode'] ?? '') === 'external') ? 'external' : 'prefix';

    // Credenciales OAuth
    $clientId     = trim($_POST['client_id']      ?? '');
    $clientSecret = trim($_POST['client_secret']  ?? '');
    $redirectUri  = trim($_POST['redirect_uri']   ?? '');
    $tenantIdMs   = trim($_POST['ms_tenant_id']   ?? ''); // solo Microsoft

    // DB externa
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');

    // Admin del tenant
    $adminEmail = trim($_POST['admin_email']    ?? '');
    $adminPass  = trim($_POST['admin_password'] ?? '');

    // Validaciones básicas
    if ($nombre === '') {
        $err = 'El nombre de la empresa es obligatorio.';
    } elseif ($clientId === '' || $clientSecret === '') {
        $err = 'Las credenciales OAuth (Client ID y Client Secret) son obligatorias.';
    } elseif ($tenant_mode === 'external' && ($dbName === '' || $dbUser === '')) {
        $err = 'Completá los datos de la DB externa (nombre y usuario).';
    } elseif ($adminEmail === '' || $adminPass === '') {
        $err = 'El email y la contraseña del administrador del tenant son obligatorios.';
    } elseif (strlen($adminPass) < 8) {
        $err = 'La contraseña del admin debe tener al menos 8 caracteres.';
    }

    if (!$err) {
        try {
            $db = new MasterDB();

            // 1. Insertar empresa en master
            $stmt = $db->pquery(
                'INSERT INTO companies (nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, db_pass, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                'sssssssss',
                [$nombre, $razon, $cuit, $provider, $tenant_mode,
                 $dbHost ?: 'localhost', $dbName ?: null, $dbUser ?: null, $dbPass ?: null]
            );
            $newId = $db->conn->insert_id;

            // 2. Instalar schema del tenant
            install_tenant_schema($newId, $tenant_mode, [
                'host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass,
            ]);

            // 3. Conectar al tenant e insertar cloud_config + usuario admin
            ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($newId);

            $ccTable   = $pf . 'cloud_config';
            $usrTable  = $pf . 'users';

            // cloud_config — Google
            if ($provider === 'google') {
                $ccStmt = $tconn->prepare(
                    "INSERT INTO `$ccTable` (provider, config_key, config_value) VALUES
                     ('google', 'client_id',     ?),
                     ('google', 'client_secret', ?),
                     ('google', 'redirect_uri',  ?)
                     ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
                );
                $ccStmt->bind_param('sss', $clientId, $clientSecret, $redirectUri);
                $ccStmt->execute();
                $ccStmt->close();
            } else {
                // Microsoft
                $ccStmt = $tconn->prepare(
                    "INSERT INTO `$ccTable` (provider, config_key, config_value) VALUES
                     ('microsoft', 'tenant_id',     ?),
                     ('microsoft', 'client_id',     ?),
                     ('microsoft', 'client_secret', ?)
                     ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
                );
                $ccStmt->bind_param('sss', $tenantIdMs, $clientId, $clientSecret);
                $ccStmt->execute();
                $ccStmt->close();
            }

            // Usuario admin del tenant
            $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
            $adminRole = 'admin';
            $usrStmt = $tconn->prepare(
                "INSERT INTO `$usrTable` (username, password, email, role)
                 VALUES (?, ?, ?, 'admin')
                 ON DUPLICATE KEY UPDATE password = VALUES(password), email = VALUES(email)"
            );
            $adminUser = 'admin';
            $usrStmt->bind_param('sss', $adminUser, $adminHash, $adminEmail);
            $usrStmt->execute();
            $usrStmt->close();

            $ok = "Empresa «$nombre» creada con ID $newId. Usuario admin: admin / (contraseña ingresada).";
            header("Location: /dashboard/dashboard_superadmin.php?ok=empresa_creada");
            exit;

        } catch (Throwable $ex) {
            $err = 'Error al crear la empresa: ' . $ex->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Nueva empresa — Asset Vault</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body      { font-family: 'Saira', system-ui, sans-serif; background: #f4f1f9; }
  .topbar   { background: #663399; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
  .topbar .brand { font-size: 18px; font-weight: 700; }
  .topbar a { color: #fff; text-decoration: none; padding: 7px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; background: rgba(255,255,255,.15); }
  .topbar a:hover { background: rgba(255,255,255,.25); }
  .content  { max-width: 820px; margin: 28px auto; padding: 0 16px; }
  .card     { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(102,51,153,.08); }
  .card-header { background: #663399; color: #fff; border-radius: 16px 16px 0 0 !important; padding: 18px 24px; }
  .card-header h5 { margin: 0; font-weight: 700; }
  .card-body { padding: 24px; }
  .form-label { font-size: 13px; font-weight: 600; color: #555; }
  .form-control, .form-select { border-radius: 10px; border-color: #d0bfea; }
  .form-control:focus, .form-select:focus { border-color: #663399; box-shadow: 0 0 0 .2rem rgba(102,51,153,.15); }
  .section-title { font-size: 13px; font-weight: 700; color: #663399; text-transform: uppercase; letter-spacing: .5px; margin: 20px 0 10px; border-bottom: 2px solid #ede8f5; padding-bottom: 6px; }
  .box-ext   { background: #f9f5ff; border: 1px dashed #c9a8e8; border-radius: 12px; padding: 16px; margin-top: 8px; }
  .btn-save  { background: #86B329; border-color: #86B329; border-radius: 12px; font-weight: 700; padding: 11px 24px; }
  .btn-save:hover { background: #6e931f; border-color: #6e931f; }
  .note      { font-size: 12px; color: #888; margin-top: 4px; }
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">Nueva empresa</div>
  <a href="/dashboard/dashboard_superadmin.php"><i class="bi bi-arrow-left me-1"></i>Volver</a>
</div>

<div class="content">
  <?php if ($err): ?>
    <div class="alert alert-danger mt-3" style="border-radius:12px;"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-header">
      <h5><i class="bi bi-building-add me-2"></i>Datos de la empresa</h5>
    </div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <?php csrf_input(); ?>

        <div class="section-title">Información general</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nombre de la empresa *</label>
            <input class="form-control" name="nombre" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Razón social</label>
            <input class="form-control" name="razon_social" value="<?php echo htmlspecialchars($_POST['razon_social'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">CUIT</label>
            <input class="form-control" name="cuit" placeholder="Opcional" value="<?php echo htmlspecialchars($_POST['cuit'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Proveedor de nube</label>
            <select class="form-select" name="provider" onchange="toggleProvider(this.value)">
              <option value="google" <?php echo (($_POST['provider'] ?? 'google') === 'google') ? 'selected' : ''; ?>>Google Drive</option>
              <option value="microsoft" <?php echo (($_POST['provider'] ?? '') === 'microsoft') ? 'selected' : ''; ?>>Microsoft OneDrive</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Modo de tenant</label>
            <select class="form-select" name="tenant_mode" onchange="toggleDBExt(this.value)">
              <option value="external" <?php echo (($_POST['tenant_mode'] ?? 'external') === 'external') ? 'selected' : ''; ?>>DB separada (recomendado)</option>
              <option value="prefix" <?php echo (($_POST['tenant_mode'] ?? '') === 'prefix') ? 'selected' : ''; ?>>Tablas con prefijo en DB master</option>
            </select>
          </div>
        </div>

        <!-- Credenciales Google -->
        <div id="cred-google">
          <div class="section-title">Credenciales OAuth — Google</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Client ID *</label>
              <input class="form-control" name="client_id" value="<?php echo htmlspecialchars($_POST['client_id'] ?? ''); ?>">
              <div class="note">Obtenelo desde Google Cloud Console → APIs &amp; Services → Credentials.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Client Secret *</label>
              <input class="form-control" name="client_secret" type="password" value="<?php echo htmlspecialchars($_POST['client_secret'] ?? ''); ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Redirect URI</label>
              <input class="form-control" name="redirect_uri" placeholder="https://dominio.com/login.php" value="<?php echo htmlspecialchars($_POST['redirect_uri'] ?? ''); ?>">
              <div class="note">Debe coincidir exactamente con la URI configurada en Google Cloud Console.</div>
            </div>
          </div>
        </div>

        <!-- Credenciales Microsoft (oculto por defecto) -->
        <div id="cred-microsoft" style="display:none;">
          <div class="section-title">Credenciales OAuth — Microsoft</div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Tenant ID (Directory ID)</label>
              <input class="form-control" name="ms_tenant_id" value="<?php echo htmlspecialchars($_POST['ms_tenant_id'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Client ID (App ID) *</label>
              <input class="form-control" name="client_id" value="<?php echo htmlspecialchars($_POST['client_id'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Client Secret *</label>
              <input class="form-control" name="client_secret" type="password">
            </div>
          </div>
        </div>

        <!-- DB externa -->
        <div id="db-ext" class="box-ext" style="display:none;">
          <div class="section-title" style="margin-top:0;">Base de datos del tenant</div>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Host</label>
              <input class="form-control" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Nombre DB *</label>
              <input class="form-control" name="db_name" placeholder="u566451014_cliente" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Usuario DB *</label>
              <input class="form-control" name="db_user" placeholder="u566451014_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Contraseña DB</label>
              <input class="form-control" name="db_pass" type="password">
            </div>
          </div>
          <p class="note mt-2">Creá la DB y el usuario en Hostinger antes de continuar.</p>
        </div>

        <!-- Admin del tenant -->
        <div class="section-title">Administrador del tenant</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Email del admin *</label>
            <input class="form-control" name="admin_email" type="email" placeholder="admin@empresa.com" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" required>
            <div class="note">Se crea el usuario "admin" en el tenant con este email.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Contraseña inicial *</label>
            <input class="form-control" name="admin_password" type="password" minlength="8" required>
            <div class="note">Mínimo 8 caracteres. El admin puede cambiarla luego.</div>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-success btn-save" type="submit">
            <i class="bi bi-check-lg me-1"></i> Crear empresa
          </button>
          <a href="/dashboard/dashboard_superadmin.php" class="btn btn-outline-secondary" style="border-radius:12px;">Cancelar</a>
        </div>

      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleProvider(val) {
  document.getElementById('cred-google').style.display    = (val === 'google')    ? '' : 'none';
  document.getElementById('cred-microsoft').style.display = (val === 'microsoft') ? '' : 'none';
}
function toggleDBExt(val) {
  document.getElementById('db-ext').style.display = (val === 'external') ? '' : 'none';
}
// Init on load
(function(){
  const provider = document.querySelector('[name="provider"]').value;
  const mode     = document.querySelector('[name="tenant_mode"]').value;
  toggleProvider(provider);
  toggleDBExt(mode);
})();
</script>
</body>
</html>
