<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();
csrf_start();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$db  = new MasterDB();
$err = '';

// Cargar empresa
$stmt = $db->pquery(
    'SELECT id, nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, db_pass, active
     FROM companies WHERE id = ? LIMIT 1',
    'i', [$id]
);
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

// Cargar cloud_config del tenant
$cloudConfig = [];
try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($id);
    $ccTable = $pf . 'cloud_config';
    $res = $tconn->query("SELECT provider, config_key, config_value FROM `$ccTable`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cloudConfig[$row['provider']][$row['config_key']] = $row['config_value'];
        }
    }
} catch (Throwable $ce) {
    $cloudConfigError = $ce->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $nombre   = trim($_POST['nombre']       ?? '');
    $razon    = trim($_POST['razon_social'] ?? '');
    $cuit     = trim($_POST['cuit']         ?? '');
    $provider = in_array($_POST['provider'] ?? '', PROVIDERS, true) ? $_POST['provider'] : 'google';
    $active   = isset($_POST['active']) ? 1 : 0;

    $clientId     = trim($_POST['client_id']     ?? '');
    $clientSecret = trim($_POST['client_secret'] ?? '');
    $redirectUri  = trim($_POST['redirect_uri']  ?? '');
    $tenantIdMs   = trim($_POST['ms_tenant_id']  ?? '');

    $dbHost = trim($_POST['db_host'] ?? $company['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? $company['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? $company['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');

    if ($nombre === '') {
        $err = 'El nombre es obligatorio.';
    }

    if (!$err) {
        try {
            // Actualizar companies en master
            // Si db_pass viene vacío, no la modificamos (no pisamos la contraseña)
            if ($dbPass !== '') {
                $db->pquery(
                    'UPDATE companies SET nombre=?, razon_social=?, cuit=?, provider=?, active=?, db_host=?, db_name=?, db_user=?, db_pass=? WHERE id=?',
                    'ssssisssssi',
                    [$nombre, $razon, $cuit, $provider, $active, $dbHost, $dbName, $dbUser, $dbPass, $id]
                );
            } else {
                $db->pquery(
                    'UPDATE companies SET nombre=?, razon_social=?, cuit=?, provider=?, active=?, db_host=?, db_name=?, db_user=? WHERE id=?',
                    'sssissssi',
                    [$nombre, $razon, $cuit, $provider, $active, $dbHost, $dbName, $dbUser, $id]
                );
            }

            // Actualizar cloud_config en el tenant (solo si se enviaron valores)
            if ($clientId !== '' || $clientSecret !== '') {
                try {
                    ['conn' => $tconn2, 'prefix' => $pf2] = tenant_conn_by_company($id);
                    $ccTable2 = $pf2 . 'cloud_config';

                    if ($provider === 'google') {
                        $pairs = [
                            ['google', 'client_id',    $clientId],
                            ['google', 'redirect_uri', $redirectUri],
                        ];
                        if ($clientSecret !== '') {
                            $pairs[] = ['google', 'client_secret', $clientSecret];
                        }
                    } else {
                        $pairs = [
                            ['microsoft', 'tenant_id', $tenantIdMs],
                            ['microsoft', 'client_id', $clientId],
                        ];
                        if ($clientSecret !== '') {
                            $pairs[] = ['microsoft', 'client_secret', $clientSecret];
                        }
                    }

                    foreach ($pairs as [$prov, $key, $val]) {
                        if ($val === '') continue;
                        $upd = $tconn2->prepare(
                            "INSERT INTO `$ccTable2` (provider, config_key, config_value) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
                        );
                        $upd->bind_param('sss', $prov, $key, $val);
                        $upd->execute();
                        $upd->close();
                    }
                } catch (Throwable $te) {
                    $err = 'Empresa actualizada pero falló actualizar credenciales cloud: ' . $te->getMessage();
                }
            }

            if (!$err) {
                header('Location: /dashboard/dashboard_superadmin.php?ok=empresa_editada');
                exit;
            }
        } catch (Throwable $ex) {
            $err = 'Error al actualizar: ' . $ex->getMessage();
        }
    }

    // Si hay error, refrescar $company con los valores del POST para mantener el form
    $company['nombre']       = $_POST['nombre']       ?? $company['nombre'];
    $company['razon_social'] = $_POST['razon_social'] ?? $company['razon_social'];
    $company['cuit']         = $_POST['cuit']         ?? $company['cuit'];
    $company['provider']     = $provider;
    $company['active']       = $active;
}

$googleCC    = $cloudConfig['google']    ?? [];
$microsoftCC = $cloudConfig['microsoft'] ?? [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Editar empresa — Asset Vault</title>
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
  .note { font-size: 12px; color: #888; margin-top: 4px; }
  .btn-save { background: #663399; border-color: #663399; border-radius: 12px; font-weight: 700; padding: 11px 24px; }
  .btn-save:hover { background: #4E247A; border-color: #4E247A; }
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">Editar empresa #<?php echo $id; ?></div>
  <a href="/dashboard/dashboard_superadmin.php"><i class="bi bi-arrow-left me-1"></i>Volver</a>
</div>

<div class="content">
  <?php if ($err): ?>
    <div class="alert alert-danger mt-3" style="border-radius:12px;"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>
  <?php if (!empty($cloudConfigError)): ?>
    <div class="alert alert-warning mt-3" style="border-radius:12px;">
      No se pudo leer cloud_config del tenant: <?php echo htmlspecialchars($cloudConfigError); ?>
    </div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-header">
      <h5><i class="bi bi-pencil-square me-2"></i><?php echo htmlspecialchars($company['nombre']); ?></h5>
    </div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <?php csrf_input(); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="section-title">Información general</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nombre *</label>
            <input class="form-control" name="nombre" value="<?php echo htmlspecialchars($company['nombre']); ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Razón social</label>
            <input class="form-control" name="razon_social" value="<?php echo htmlspecialchars($company['razon_social'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">CUIT</label>
            <input class="form-control" name="cuit" value="<?php echo htmlspecialchars($company['cuit'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Proveedor de nube</label>
            <select class="form-select" name="provider" onchange="toggleProvider(this.value)">
              <option value="google" <?php echo ($company['provider'] ?? 'google') === 'google' ? 'selected' : ''; ?>>Google Drive</option>
              <option value="microsoft" <?php echo ($company['provider'] ?? '') === 'microsoft' ? 'selected' : ''; ?>>Microsoft OneDrive</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Estado</label>
            <div class="form-check form-switch mt-2">
              <input class="form-check-input" type="checkbox" name="active" id="active" value="1"
                     <?php echo (int)($company['active'] ?? 1) === 1 ? 'checked' : ''; ?>>
              <label class="form-check-label" for="active">Empresa activa</label>
            </div>
          </div>
        </div>

        <!-- Credenciales Google -->
        <div id="cred-google">
          <div class="section-title">Credenciales OAuth — Google</div>
          <div class="note mb-2">Dejá en blanco los campos que no querés modificar.</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Client ID</label>
              <input class="form-control" name="client_id" value="<?php echo htmlspecialchars($googleCC['client_id'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Client Secret</label>
              <input class="form-control" name="client_secret" type="password" placeholder="(sin cambios si está vacío)">
            </div>
            <div class="col-12">
              <label class="form-label">Redirect URI</label>
              <input class="form-control" name="redirect_uri" value="<?php echo htmlspecialchars($googleCC['redirect_uri'] ?? ''); ?>">
            </div>
          </div>
        </div>

        <!-- Credenciales Microsoft -->
        <div id="cred-microsoft" style="display:none;">
          <div class="section-title">Credenciales OAuth — Microsoft</div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Tenant ID</label>
              <input class="form-control" name="ms_tenant_id" value="<?php echo htmlspecialchars($microsoftCC['tenant_id'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Client ID (App ID)</label>
              <input class="form-control" name="client_id" value="<?php echo htmlspecialchars($microsoftCC['client_id'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Client Secret</label>
              <input class="form-control" name="client_secret" type="password" placeholder="(sin cambios si está vacío)">
            </div>
          </div>
        </div>

        <?php if ($company['tenant_mode'] === 'external'): ?>
        <div class="section-title">Base de datos del tenant</div>
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Host</label>
            <input class="form-control" name="db_host" value="<?php echo htmlspecialchars($company['db_host'] ?? 'localhost'); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Nombre DB</label>
            <input class="form-control" name="db_name" value="<?php echo htmlspecialchars($company['db_name'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Usuario</label>
            <input class="form-control" name="db_user" value="<?php echo htmlspecialchars($company['db_user'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Contraseña</label>
            <input class="form-control" name="db_pass" type="password" placeholder="(sin cambios si está vacío)">
          </div>
        </div>
        <?php endif; ?>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary btn-save" type="submit">
            <i class="bi bi-check-lg me-1"></i> Guardar cambios
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
(function(){
  toggleProvider(document.querySelector('[name="provider"]').value);
})();
</script>
</body>
</html>
