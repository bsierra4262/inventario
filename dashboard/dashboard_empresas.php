<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();
if (!platform_can('company.create')) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}
csrf_start();

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $nombre      = trim($_POST['nombre']        ?? '');
    $razon       = trim($_POST['razon_social']  ?? '');
    $cuit        = trim($_POST['cuit']          ?? '');
    $provider    = in_array($_POST['provider'] ?? '', PROVIDERS, true) ? $_POST['provider'] : 'google';
    $tenant_mode = (($_POST['tenant_mode'] ?? '') === 'external') ? 'external' : 'prefix';

    // Google fields
    $clientId     = trim($_POST['client_id']     ?? '');
    $clientSecret = trim($_POST['client_secret'] ?? '');
    $redirectUri  = trim($_POST['redirect_uri']  ?? '');
    // Microsoft fields (nombres propios para evitar colisión)
    $tenantIdMs   = trim($_POST['ms_tenant_id']  ?? '');
    $msClientId   = trim($_POST['ms_client_id']  ?? '');
    $msClientSecret = trim($_POST['ms_client_secret'] ?? '');

    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');

    $subdomain  = trim((string)($_POST['subdomain']  ?? ''));
    $loginUrl   = trim((string)($_POST['login_url']  ?? ''));
    $baseUrl    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($loginUrl === '' && $subdomain !== '') {
        $loginUrl = $baseUrl . '/auth/login.php?company=' . preg_replace('/[^a-z0-9\-]/i', '', $subdomain);
    }
    // Auto-generar redirect_uri si está vacío (siempre apunta al callback unificado)
    if ($redirectUri === '' && $provider === 'google') {
        $redirectUri = $baseUrl . '/auth/superadmin_callback.php';
    }

    $adminEmail = trim($_POST['admin_email']    ?? '');
    $adminPass  = trim($_POST['admin_password'] ?? '');

    if ($nombre === '') {
        $err = 'El nombre de la empresa es obligatorio.';
    } elseif ($tenant_mode === 'external' && ($dbName === '' || $dbUser === '')) {
        $err = 'Completá los datos de la DB externa (nombre y usuario).';
    } elseif ($adminEmail === '' || $adminPass === '') {
        $err = 'Email y contraseña del administrador del tenant son obligatorios.';
    } elseif (strlen($adminPass) < 8) {
        $err = 'La contraseña del admin debe tener al menos 8 caracteres.';
    }

    if (!$err) {
        try {
            $db = new MasterDB();

            $stmt = $db->pquery(
                'INSERT INTO companies (nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, db_pass, subdomain, login_url, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                'sssssssssss',
                [$nombre, $razon, $cuit, $provider, $tenant_mode,
                 $dbHost ?: 'localhost', $dbName ?: null, $dbUser ?: null, $dbPass ?: null,
                 $subdomain ?: null, $loginUrl ?: null]
            );
            $newId = $db->conn->insert_id;

            install_tenant_schema($newId, $tenant_mode, [
                'host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass,
            ]);

            ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($newId);
            $ccTable  = $pf . 'cloud_config';
            $usrTable = $pf . 'users';

            // Solo guardar credenciales si las ingresaron (son opcionales)
            $hasCredentials = $provider === 'google'
                ? ($clientId !== '' && $clientSecret !== '')
                : ($msClientId !== '' && $msClientSecret !== '');

            if ($hasCredentials) {
                if ($provider === 'google') {
                    $ccStmt = $tconn->prepare(
                        "INSERT INTO `$ccTable` (provider, config_key, config_value) VALUES
                         ('google', 'client_id', ?), ('google', 'client_secret', ?), ('google', 'redirect_uri', ?)
                         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
                    );
                    $ccStmt->bind_param('sss', $clientId, $clientSecret, $redirectUri);
                    $ccStmt->execute();
                    $ccStmt->close();
                } else {
                    $ccStmt = $tconn->prepare(
                        "INSERT INTO `$ccTable` (provider, config_key, config_value) VALUES
                         ('microsoft', 'tenant_id', ?), ('microsoft', 'client_id', ?), ('microsoft', 'client_secret', ?)
                         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
                    );
                    $ccStmt->bind_param('sss', $tenantIdMs, $msClientId, $msClientSecret);
                    $ccStmt->execute();
                    $ccStmt->close();
                }
            }

            $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
            $usrStmt = $tconn->prepare(
                "INSERT INTO `$usrTable` (username, password, email, role, must_change_password)
                 VALUES (?, ?, ?, 'admin', 1)
                 ON DUPLICATE KEY UPDATE password = VALUES(password), email = VALUES(email), must_change_password = 1"
            );
            $adminUser = 'admin';
            $usrStmt->bind_param('sss', $adminUser, $adminHash, $adminEmail);
            $usrStmt->execute();
            $usrStmt->close();

            header("Location: /dashboard/dashboard_superadmin.php?ok=empresa_creada");
            exit;
        } catch (Throwable $ex) {
            $err = 'Error al crear la empresa: ' . $ex->getMessage();
        }
    }
}

$page_title    = 'Nueva empresa';
$page_section  = 'nueva';
$topbar_title  = 'Nueva empresa';
$topbar_subtitle = 'Onboarding de un nuevo cliente';
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
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?= htmlspecialchars($err) ?></div>
      </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-building-add"></i> Datos de la empresa</h2>
          <div class="ih-card__subtitle">Completá la información para aprovisionar el tenant</div>
        </div>
      </div>

      <div class="ih-card__body">
        <form method="post" autocomplete="off">
          <?php csrf_input(); ?>

          <div class="ih-section-title">Información general</div>
          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Nombre <span class="req">*</span></label>
                <input class="ih-input" name="nombre" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Razón social</label>
                <input class="ih-input" name="razon_social" value="<?= htmlspecialchars($_POST['razon_social'] ?? '') ?>">
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">CUIT</label>
                <input class="ih-input" name="cuit" placeholder="Opcional" value="<?= htmlspecialchars($_POST['cuit'] ?? '') ?>">
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Proveedor de nube <span class="req">*</span></label>
                <select class="ih-select" name="provider" onchange="ihToggleProvider(this.value)">
                  <option value="google" <?= (($_POST['provider'] ?? 'google') === 'google') ? 'selected' : '' ?>>Google Drive</option>
                  <option value="microsoft" <?= (($_POST['provider'] ?? '') === 'microsoft') ? 'selected' : '' ?>>Microsoft OneDrive</option>
                </select>
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Modo de tenant <span class="req">*</span></label>
                <select class="ih-select" name="tenant_mode" onchange="ihToggleDB(this.value)">
                  <option value="external" <?= (($_POST['tenant_mode'] ?? 'external') === 'external') ? 'selected' : '' ?>>DB separada (recomendado)</option>
                  <option value="prefix" <?= (($_POST['tenant_mode'] ?? '') === 'prefix') ? 'selected' : '' ?>>Tablas con prefijo</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Google -->
          <div id="cred-google">
            <div class="ih-section-title">Credenciales OAuth — Google <span style="font-weight:400;color:#71717A;font-size:12px">(opcional — podés configurarlas después desde el inventario del cliente)</span></div>
            <div class="ih-grid">
              <div class="ih-col-6">
                <div class="ih-field">
                  <label class="ih-field__label">Client ID</label>
                  <input class="ih-input" name="client_id" value="<?= htmlspecialchars($_POST['client_id'] ?? '') ?>" placeholder="xxxxx.apps.googleusercontent.com">
                  <div class="ih-field__hint">Google Cloud Console → APIs &amp; Services → Credentials</div>
                </div>
              </div>
              <div class="ih-col-6">
                <div class="ih-field">
                  <label class="ih-field__label">Client Secret</label>
                  <input class="ih-input" name="client_secret" type="password" value="<?= htmlspecialchars($_POST['client_secret'] ?? '') ?>" placeholder="GOCSPX-...">
                </div>
              </div>
              <div class="ih-col-12">
                <div class="ih-field">
                  <label class="ih-field__label">Redirect URI</label>
                  <input class="ih-input" name="redirect_uri" placeholder="(se autogenera: https://[dominio]/auth/login.php)" value="<?= htmlspecialchars($_POST['redirect_uri'] ?? '') ?>">
                  <div class="ih-field__hint">Si lo dejás vacío, se autogenera con el dominio actual. Debe coincidir exactamente con la URI configurada en Google Cloud Console.</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Microsoft -->
          <div id="cred-microsoft" style="display:none;">
            <div class="ih-section-title">Credenciales OAuth — Microsoft <span style="font-weight:400;color:#71717A;font-size:12px">(opcional — podés configurarlas después desde el inventario del cliente)</span></div>
            <div class="ih-grid">
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">Tenant ID (Directory ID)</label>
                  <input class="ih-input" name="ms_tenant_id" value="<?= htmlspecialchars($_POST['ms_tenant_id'] ?? '') ?>" placeholder="common ó GUID del directorio">
                </div>
              </div>
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">Client ID (App ID)</label>
                  <input class="ih-input" name="ms_client_id" value="<?= htmlspecialchars($_POST['ms_client_id'] ?? '') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                </div>
              </div>
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">Client Secret</label>
                  <input class="ih-input" name="ms_client_secret" type="password" placeholder="Secret de Azure AD">
                </div>
              </div>
            </div>
          </div>

          <!-- DB externa -->
          <div id="db-ext" style="display:none;">
            <div class="ih-section-title">Base de datos del tenant</div>
            <div class="ih-grid">
              <div class="ih-col-3">
                <div class="ih-field">
                  <label class="ih-field__label">Host</label>
                  <input class="ih-input" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
                </div>
              </div>
              <div class="ih-col-3">
                <div class="ih-field">
                  <label class="ih-field__label">Nombre DB <span class="req">*</span></label>
                  <input class="ih-input" name="db_name" placeholder="u566451014_cliente" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>">
                </div>
              </div>
              <div class="ih-col-3">
                <div class="ih-field">
                  <label class="ih-field__label">Usuario DB <span class="req">*</span></label>
                  <input class="ih-input" name="db_user" placeholder="u566451014_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>">
                </div>
              </div>
              <div class="ih-col-3">
                <div class="ih-field">
                  <label class="ih-field__label">Contraseña DB</label>
                  <input class="ih-input" name="db_pass" type="password">
                </div>
              </div>
            </div>
            <div class="ih-field__hint">Creá la DB y el usuario en el hosting antes de continuar.</div>
          </div>

          <!-- Acceso del tenant -->
          <div class="ih-section-title">Acceso del cliente</div>
          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Subdominio del cliente</label>
                <div class="ih-input-group">
                  <input class="ih-input" name="subdomain" value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>" placeholder="cliente">
                  <span class="ih-input-group__addon">.intellihelp.tech</span>
                </div>
                <div class="ih-field__hint">Opcional. Si vas a configurar un subdominio dedicado para esta empresa.</div>
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">URL completa de login</label>
                <input class="ih-input" name="login_url" value="<?= htmlspecialchars($_POST['login_url'] ?? '') ?>" placeholder="https://desarrollo.intellihelp.tech/auth/login.php?company=cliente">
                <div class="ih-field__hint">Si lo dejás vacío, se autogenera con el dominio actual + <code>/auth/login.php?company=[subdominio]</code>.</div>
              </div>
            </div>
          </div>

          <!-- Admin del tenant -->
          <div class="ih-section-title">Administrador del tenant</div>
          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Email del admin <span class="req">*</span></label>
                <input class="ih-input" name="admin_email" type="email" placeholder="admin@empresa.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                <div class="ih-field__hint">Se crea el usuario "admin" con este email.</div>
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Contraseña inicial <span class="req">*</span></label>
                <input class="ih-input" name="admin_password" type="password" minlength="8" required>
                <div class="ih-field__hint">Mínimo 8 caracteres. El admin la cambiará después.</div>
              </div>
            </div>
          </div>

          <div class="ih-flex ih-gap-2 ih-mt-6">
            <button class="ih-btn ih-btn--accent" type="submit">
              <i class="bi bi-check-lg"></i> Crear empresa
            </button>
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
function ihToggleDB(val) {
  document.getElementById('db-ext').style.display = (val === 'external') ? '' : 'none';
}
(function(){
  ihToggleProvider(document.querySelector('[name="provider"]').value);
  ihToggleDB(document.querySelector('[name="tenant_mode"]').value);
})();
</script>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
