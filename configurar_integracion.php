<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/csrf.php';
require_once __DIR__ . '/logs/logger.php';

// --- Solo admins ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: login.php'); exit(); }

$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
if (!$me || $me['role'] !== 'admin') { header('Location: index.php'); exit(); }

// --- Leer config actual de DB ---
$configTable = tn('cloud_config');
$existsConfig = false;
$googleCfg = [];
$msCfg     = [];

try {
    $res = $conn->query("SELECT provider, config_key, config_value FROM `{$configTable}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['provider'] === 'google') {
                $googleCfg[$row['config_key']] = $row['config_value'];
            } else {
                $msCfg[$row['config_key']] = $row['config_value'];
            }
        }
        $existsConfig = true;
    }
} catch (Throwable $e) {
    // Tabla puede no existir en instancias viejas — no romper
}

$csrf = generateCsrfToken('cloud_config');
$feedbackMsg  = htmlspecialchars($_GET['msg']  ?? '');
$feedbackType = ($_GET['type'] ?? 'success') === 'error' ? 'danger' : 'success';

function mask(string $val): string {
    if (strlen($val) <= 8) return str_repeat('*', strlen($val));
    return substr($val, 0, 4) . str_repeat('*', strlen($val) - 8) . substr($val, -4);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integración Cloud – Asset Vault</title>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html,body{height:100%;margin:0;padding:0;font-family:'Saira',sans-serif;background:#F5F5F5;color:#333}
        body{display:flex;flex-direction:column;min-height:100vh}
        header{background:#663399;color:#fff;padding:1rem;display:flex;justify-content:space-between;align-items:center}
        header h1{font-size:1.5rem;margin:0}
        .back-btn{background:#4E247A;color:#fff;border:none;padding:.5rem 1rem;border-radius:5px;text-decoration:none;font-weight:500}
        .back-btn:hover{background:#86B329;color:#fff}
        .main{flex:1;padding:2rem 1rem 5rem;max-width:960px;margin:0 auto;width:100%}
        h2{color:#4E247A;font-weight:700}
        .provider-card{background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 10px rgba(102,51,153,.08)}
        .provider-card .card-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:.5rem}
        .google-hdr{color:#EA4335}
        .ms-hdr{color:#0078D4}
        .badge-active{background:#86B329;color:#fff;font-size:.75rem;padding:.3em .7em;border-radius:20px}
        .badge-inactive{background:#aaa;color:#fff;font-size:.75rem;padding:.3em .7em;border-radius:20px}
        .btn-purple{background:#663399;color:#fff;border:none}.btn-purple:hover{background:#4E247A;color:#fff}
        .btn-green{background:#86B329;color:#fff;border:none}.btn-green:hover{background:#5a8a1b;color:#fff}
        .cfg-val{font-family:monospace;font-size:.85rem;background:#f0f0f0;padding:.2rem .5rem;border-radius:4px}
        .footer{text-align:center;padding:1rem;background:#663399;color:#fff;position:fixed;bottom:0;left:0;width:100%}
        nav.tabs-cloud{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
        nav.tabs-cloud button{border:none;padding:.5rem 1.2rem;border-radius:20px;font-weight:600;cursor:pointer;background:#e8e8e8;color:#555}
        nav.tabs-cloud button.active{background:#663399;color:#fff}
        .tab-panel{display:none}.tab-panel.active{display:block}
        .help-link{font-size:.82rem;color:#663399;text-decoration:none}
        .help-link:hover{text-decoration:underline}
    </style>
</head>
<body>
<header>
    <h1><i class="bi bi-cloud-arrow-up"></i> Integración Cloud Storage</h1>
    <a href="index.php" class="back-btn"><i class="bi bi-arrow-left"></i> Volver</a>
</header>

<div class="main">

    <?php if ($feedbackMsg): ?>
    <div class="alert alert-<?= $feedbackType ?> alert-dismissible fade show">
        <?= $feedbackMsg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <h2 class="mb-1"><i class="bi bi-hdd-network me-2"></i>Configuración de almacenamiento</h2>
    <p class="text-muted mb-4">Conectá Google Drive o Microsoft OneDrive para guardar archivos adjuntos a los activos. Las credenciales se almacenan encriptadas en tu base de datos.</p>

    <nav class="tabs-cloud">
        <button class="active" onclick="showTab('google', this)"><i class="bi bi-google me-1"></i> Google Drive</button>
        <button onclick="showTab('microsoft', this)"><i class="bi bi-microsoft me-1"></i> Microsoft OneDrive</button>
    </nav>

    <!-- ======================== GOOGLE ======================== -->
    <div id="tab-google" class="tab-panel active">
        <div class="provider-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="card-title google-hdr">
                    <i class="bi bi-google"></i> Google Drive / Google Workspace
                    <?php if (!empty($googleCfg['client_id'])): ?>
                    <span class="badge-active">✓ Configurado</span>
                    <?php else: ?>
                    <span class="badge-inactive">Sin configurar</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($googleCfg)): ?>
                <form method="POST" action="backend/cloud_config_crud.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="clear">
                    <input type="hidden" name="provider" value="google">
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar config de Google?')">
                        <i class="bi bi-trash"></i> Limpiar
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (!empty($googleCfg)): ?>
            <div class="mb-3 p-3" style="background:#f8f8f8;border-radius:8px;">
                <strong>Config actual:</strong><br>
                <small>Client ID: <span class="cfg-val"><?= htmlspecialchars(mask($googleCfg['client_id'] ?? '')) ?></span></small><br>
                <?php if (!empty($googleCfg['root_folder_id'])): ?>
                <small>Carpeta raíz Drive: <span class="cfg-val"><?= htmlspecialchars($googleCfg['root_folder_id']) ?></span></small><br>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Tabs internos: JSON vs Manual -->
            <ul class="nav nav-pills mb-3" id="google-mode-tabs">
                <li class="nav-item">
                    <button class="nav-link active" onclick="switchGoogleMode('json', this)">
                        <i class="bi bi-file-earmark-code me-1"></i> Pegar / Subir JSON
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" onclick="switchGoogleMode('form', this)">
                        <i class="bi bi-pencil me-1"></i> Ingresar manualmente
                    </button>
                </li>
            </ul>

            <form method="POST" action="backend/cloud_config_crud.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_google">
                <input type="hidden" name="provider" value="google">
                <input type="hidden" name="google_mode" id="google_mode_input" value="json">

                <!-- Modo JSON -->
                <div id="google-json-mode">
                    <div class="alert alert-info py-2 mb-3" style="font-size:.88rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Descargá el JSON desde
                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="help-link">Google Cloud Console</a>
                        → Credenciales → OAuth 2.0 → Descargar JSON.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subir archivo credentials.json</label>
                        <input type="file" name="credentials_file" class="form-control" accept=".json">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">O pegar el contenido del JSON</label>
                        <textarea name="credentials_json" class="form-control font-monospace" rows="6" placeholder='{"web":{"client_id":"...","client_secret":"...","redirect_uris":[...]}}'></textarea>
                    </div>
                </div>

                <!-- Modo Manual -->
                <div id="google-form-mode" style="display:none">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Client ID *</label>
                            <input type="text" name="google_client_id" class="form-control"
                                value="<?= htmlspecialchars($googleCfg['client_id'] ?? '') ?>"
                                placeholder="xxxxx.apps.googleusercontent.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Client Secret *</label>
                            <input type="text" name="google_client_secret" class="form-control"
                                placeholder="GOCSPX-...">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Redirect URI</label>
                            <input type="url" name="google_redirect_uri" class="form-control"
                                value="<?= htmlspecialchars($googleCfg['redirect_uri'] ?? 'https://desarrollo.intellihelp.tech/login.php') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">ID Carpeta Raíz Drive <small class="text-muted">(opcional)</small></label>
                            <input type="text" name="google_root_folder" class="form-control"
                                value="<?= htmlspecialchars($googleCfg['root_folder_id'] ?? '') ?>"
                                placeholder="1BxiMVs...">
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-green">
                        <i class="bi bi-save me-1"></i> Guardar configuración de Google Drive
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================== MICROSOFT ======================== -->
    <div id="tab-microsoft" class="tab-panel">
        <div class="provider-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="card-title ms-hdr">
                    <i class="bi bi-microsoft"></i> Microsoft OneDrive / SharePoint
                    <?php if (!empty($msCfg['client_id'])): ?>
                    <span class="badge-active">✓ Configurado</span>
                    <?php else: ?>
                    <span class="badge-inactive">Sin configurar</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($msCfg)): ?>
                <form method="POST" action="backend/cloud_config_crud.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="clear">
                    <input type="hidden" name="provider" value="microsoft">
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar config de Microsoft?')">
                        <i class="bi bi-trash"></i> Limpiar
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (!empty($msCfg)): ?>
            <div class="mb-3 p-3" style="background:#f8f8f8;border-radius:8px;">
                <strong>Config actual:</strong><br>
                <small>Tenant ID: <span class="cfg-val"><?= htmlspecialchars(mask($msCfg['tenant_id'] ?? '')) ?></span></small><br>
                <small>Client ID: <span class="cfg-val"><?= htmlspecialchars(mask($msCfg['client_id'] ?? '')) ?></span></small><br>
            </div>
            <?php endif; ?>

            <div class="alert alert-info py-2 mb-3" style="font-size:.88rem;">
                <i class="bi bi-info-circle me-1"></i>
                Registrá una app en
                <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps" target="_blank" class="help-link">Azure Portal</a>
                → App registrations → New registration. Necesitás los permisos <code>Files.ReadWrite</code> y <code>Sites.ReadWrite.All</code>.
            </div>

            <form method="POST" action="backend/cloud_config_crud.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_microsoft">
                <input type="hidden" name="provider" value="microsoft">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Tenant ID (Directory ID) *</label>
                        <input type="text" name="ms_tenant_id" class="form-control" required
                            value="<?= htmlspecialchars($msCfg['tenant_id'] ?? '') ?>"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        <small class="text-muted">Azure Active Directory → Properties → Tenant ID</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Client ID (Application ID) *</label>
                        <input type="text" name="ms_client_id" class="form-control" required
                            value="<?= htmlspecialchars($msCfg['client_id'] ?? '') ?>"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Client Secret *</label>
                        <input type="password" name="ms_client_secret" class="form-control" required
                            placeholder="Secreto generado en Azure">
                        <small class="text-muted">Certificates & secrets → New client secret</small>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Redirect URI</label>
                        <input type="url" name="ms_redirect_uri" class="form-control"
                            value="<?= htmlspecialchars($msCfg['redirect_uri'] ?? 'https://desarrollo.intellihelp.tech/login.php') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Drive ID / Site ID <small class="text-muted">(SharePoint)</small></label>
                        <input type="text" name="ms_drive_id" class="form-control"
                            value="<?= htmlspecialchars($msCfg['drive_id'] ?? '') ?>"
                            placeholder="Opcional — para SharePoint específico">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-purple">
                        <i class="bi bi-save me-1"></i> Guardar configuración de Microsoft
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<div class="footer">© <?= date('Y') ?> ITH — Asset Vault</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('nav.tabs-cloud button').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
}

function switchGoogleMode(mode, btn) {
    document.getElementById('google_mode_input').value = mode;
    document.getElementById('google-json-mode').style.display = mode === 'json' ? '' : 'none';
    document.getElementById('google-form-mode').style.display = mode === 'form' ? '' : 'none';
    document.querySelectorAll('#google-mode-tabs .nav-link').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
</script>
</body>
</html>
