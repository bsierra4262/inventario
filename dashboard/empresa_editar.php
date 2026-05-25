<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();
if (!platform_can('company.edit')) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}
csrf_start();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$db  = new MasterDB();
$err = '';

$stmt = $db->pquery(
    'SELECT id, nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, db_pass, subdomain, login_url, active
     FROM companies WHERE id = ? LIMIT 1',
    'i', [$id]
);
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$cloudConfig = [];
$cloudConfigError = '';
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

    // Google fields
    $clientId     = trim($_POST['client_id']     ?? '');
    $clientSecret = trim($_POST['client_secret'] ?? '');
    $redirectUri  = trim($_POST['redirect_uri']  ?? '');
    // Microsoft fields (nombres propios para evitar colisión con Google)
    $tenantIdMs      = trim($_POST['ms_tenant_id']     ?? '');
    $msClientId      = trim($_POST['ms_client_id']     ?? '');
    $msClientSecret  = trim($_POST['ms_client_secret'] ?? '');

    $dbHost = trim($_POST['db_host'] ?? $company['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? $company['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? $company['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');

    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $loginUrl  = trim((string)($_POST['login_url'] ?? ''));
    $baseUrl   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($loginUrl === '' && $subdomain !== '') {
        $loginUrl = $baseUrl . '/auth/login.php?company=' . preg_replace('/[^a-z0-9\-]/i', '', $subdomain);
    }
    // Auto-generar redirect_uri si está vacío (solo para Google)
    if ($redirectUri === '' && $provider === 'google') {
        $redirectUri = $baseUrl . '/auth/login.php';
    }

    if ($nombre === '') { $err = 'El nombre es obligatorio.'; }

    if (!$err) {
        try {
            if ($dbPass !== '') {
                $db->pquery(
                    'UPDATE companies SET nombre=?, razon_social=?, cuit=?, provider=?, active=?, db_host=?, db_name=?, db_user=?, db_pass=?, subdomain=?, login_url=? WHERE id=?',
                    'ssssisssssssi',
                    [$nombre, $razon, $cuit, $provider, $active, $dbHost, $dbName, $dbUser, $dbPass, $subdomain ?: null, $loginUrl ?: null, $id]
                );
            } else {
                $db->pquery(
                    'UPDATE companies SET nombre=?, razon_social=?, cuit=?, provider=?, active=?, db_host=?, db_name=?, db_user=?, subdomain=?, login_url=? WHERE id=?',
                    'sssissssssi',
                    [$nombre, $razon, $cuit, $provider, $active, $dbHost, $dbName, $dbUser, $subdomain ?: null, $loginUrl ?: null, $id]
                );
            }

            // Usar los campos correctos según proveedor (nombres separados en el form)
            $hasCredentials = $provider === 'google'
                ? ($clientId !== '' || $clientSecret !== '')
                : ($msClientId !== '' || $msClientSecret !== '');

            if ($hasCredentials) {
                try {
                    ['conn' => $tconn2, 'prefix' => $pf2] = tenant_conn_by_company($id);
                    $ccTable2 = $pf2 . 'cloud_config';

                    if ($provider === 'google') {
                        $pairs = [
                            ['google', 'client_id',    $clientId],
                            ['google', 'redirect_uri', $redirectUri],
                        ];
                        if ($clientSecret !== '') $pairs[] = ['google', 'client_secret', $clientSecret];
                    } else {
                        $pairs = [
                            ['microsoft', 'tenant_id', $tenantIdMs],
                            ['microsoft', 'client_id', $msClientId],
                        ];
                        if ($msClientSecret !== '') $pairs[] = ['microsoft', 'client_secret', $msClientSecret];
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

    $company['nombre']       = $_POST['nombre']       ?? $company['nombre'];
    $company['razon_social'] = $_POST['razon_social'] ?? $company['razon_social'];
    $company['cuit']         = $_POST['cuit']         ?? $company['cuit'];
    $company['provider']     = $provider;
    $company['active']       = $active;
}

$googleCC    = $cloudConfig['google']    ?? [];
$microsoftCC = $cloudConfig['microsoft'] ?? [];

$page_title    = 'Editar empresa';
$page_section  = 'empresas';
$topbar_title  = 'Editar empresa #' . $id;
$topbar_subtitle = htmlspecialchars($company['nombre']);
$topbar_user   = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email  = $_SESSION['superadmin_email'] ?? '';
$topbar_actions = '<a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--outline ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($err): ?>
      <div class="ih-alert ih-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($err) ?></div></div>
    <?php endif; ?>
    <?php if ($cloudConfigError): ?>
      <div class="ih-alert ih-alert--warning">
        <i class="bi bi-info-circle-fill"></i>
        <div>No se pudo leer cloud_config del tenant: <?= htmlspecialchars($cloudConfigError) ?></div>
      </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-pencil-square"></i> <?= htmlspecialchars($company['nombre']) ?></h2>
          <div class="ih-card__subtitle">Modificar datos generales, SSO y conexión DB</div>
        </div>
      </div>
      <div class="ih-card__body">
        <form method="post" autocomplete="off">
          <?php csrf_input(); ?>
          <input type="hidden" name="id" value="<?= $id ?>">

          <div class="ih-section-title">Información general</div>
          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Nombre <span class="req">*</span></label>
                <input class="ih-input" name="nombre" value="<?= htmlspecialchars($company['nombre']) ?>" required>
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Razón social</label>
                <input class="ih-input" name="razon_social" value="<?= htmlspecialchars($company['razon_social'] ?? '') ?>">
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">CUIT</label>
                <input class="ih-input" name="cuit" value="<?= htmlspecialchars($company['cuit'] ?? '') ?>">
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Proveedor</label>
                <select class="ih-select" name="provider" onchange="ihToggleProvider(this.value)">
                  <option value="google"    <?= ($company['provider'] ?? 'google') === 'google'    ? 'selected' : '' ?>>Google Drive</option>
                  <option value="microsoft" <?= ($company['provider'] ?? '') === 'microsoft' ? 'selected' : '' ?>>Microsoft OneDrive</option>
                </select>
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Estado</label>
                <label class="ih-switch" style="margin-top:6px">
                  <input type="checkbox" name="active" value="1" <?= (int)($company['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                  <span class="ih-switch__slider"></span>
                </label>
                <span class="ih-text-sm ih-text-muted" style="margin-left:10px">Empresa activa</span>
              </div>
            </div>
          </div>

          <div class="ih-section-title">Acceso del cliente</div>
          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Subdominio</label>
                <div class="ih-input-group">
                  <input class="ih-input" name="subdomain" value="<?= htmlspecialchars($company['subdomain'] ?? '') ?>" placeholder="cliente">
                  <span class="ih-input-group__addon">.intellihelp.tech</span>
                </div>
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">URL completa de login</label>
                <input class="ih-input" name="login_url" value="<?= htmlspecialchars($company['login_url'] ?? '') ?>" placeholder="https://intellihelp.tech/auth/login.php?company=cliente">
              </div>
            </div>
          </div>

          <div id="cred-google">
            <div class="ih-section-title">Credenciales OAuth — Google</div>
            <div class="ih-field__hint ih-mb-2">Dejá vacío lo que no quieras modificar.</div>
            <div class="ih-grid">
              <div class="ih-col-6">
                <div class="ih-field">
                  <label class="ih-field__label">Client ID</label>
                  <input class="ih-input" name="client_id" value="<?= htmlspecialchars($googleCC['client_id'] ?? '') ?>">
                </div>
              </div>
              <div class="ih-col-6">
                <div class="ih-field">
                  <label class="ih-field__label">Client Secret</label>
                  <input class="ih-input" name="client_secret" type="password" placeholder="(sin cambios si está vacío)">
                </div>
              </div>
              <div class="ih-col-12">
                <div class="ih-field">
                  <label class="ih-field__label">Redirect URI</label>
                  <input class="ih-input" name="redirect_uri" value="<?= htmlspecialchars($googleCC['redirect_uri'] ?? '') ?>" placeholder="(se autogenera: https://[dominio]/auth/login.php)">
                  <div class="ih-field__hint">Si lo dejás vacío al guardar credenciales, se autogenera con el dominio actual. Debe coincidir con Google Cloud Console.</div>
                </div>
              </div>
            </div>
          </div>

          <div id="cred-microsoft" style="display:none;">
            <div class="ih-section-title">Credenciales OAuth — Microsoft</div>
            <div class="ih-field__hint ih-mb-2">Dejá vacío lo que no quieras modificar.</div>
            <div class="ih-grid">
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">Tenant ID (Directory ID)</label>
                  <input class="ih-input" name="ms_tenant_id" value="<?= htmlspecialchars($microsoftCC['tenant_id'] ?? '') ?>" placeholder="common ó GUID">
                </div>
              </div>
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">Client ID (App ID)</label>
                  <input class="ih-input" name="ms_client_id" value="<?= htmlspecialchars($microsoftCC['client_id'] ?? '') ?>" placeholder="xxxxxxxx-xxxx-...">
                </div>
              </div>
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">Client Secret</label>
                  <input class="ih-input" name="ms_client_secret" type="password" placeholder="(sin cambios si está vacío)">
                </div>
              </div>
            </div>
          </div>

          <?php if ($company['tenant_mode'] === 'external'): ?>
            <div class="ih-section-title">Base de datos del tenant</div>
            <div class="ih-grid">
              <div class="ih-col-3">
                <div class="ih-field"><label class="ih-field__label">Host</label>
                  <input class="ih-input" name="db_host" value="<?= htmlspecialchars($company['db_host'] ?? 'localhost') ?>"></div>
              </div>
              <div class="ih-col-3">
                <div class="ih-field"><label class="ih-field__label">Nombre DB</label>
                  <input class="ih-input" name="db_name" value="<?= htmlspecialchars($company['db_name'] ?? '') ?>"></div>
              </div>
              <div class="ih-col-3">
                <div class="ih-field"><label class="ih-field__label">Usuario</label>
                  <input class="ih-input" name="db_user" value="<?= htmlspecialchars($company['db_user'] ?? '') ?>"></div>
              </div>
              <div class="ih-col-3">
                <div class="ih-field"><label class="ih-field__label">Contraseña</label>
                  <input class="ih-input" name="db_pass" type="password" placeholder="(sin cambios si está vacío)"></div>
              </div>
            </div>
          <?php endif; ?>

          <div class="ih-flex ih-gap-2 ih-mt-6">
            <button class="ih-btn ih-btn--primary" type="submit"><i class="bi bi-check-lg"></i> Guardar cambios</button>
            <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--ghost">Cancelar</a>
          </div>
        </form>
      </div>
    </div>

  </main>
</div>

<script>
function ihToggleProvider(val) {
  document.getElementById('cred-google').style.display    = (val === 'google')    ? '' : 'none';
  document.getElementById('cred-microsoft').style.display = (val === 'microsoft') ? '' : 'none';
}
(function(){ ihToggleProvider(document.querySelector('[name="provider"]').value); })();
</script>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
