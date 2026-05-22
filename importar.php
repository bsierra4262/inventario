<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/csrf.php';
require_once __DIR__ . '/logs/logger.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: login.php'); exit; }

$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
if (!$me || $me['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$csrf = generateCsrfToken('full_backup');
$msg  = htmlspecialchars($_GET['msg'] ?? '');
$type = $_GET['type'] ?? 'success';
$alertClass = match ($type) {
    'error'   => 'danger',
    'warning' => 'warning',
    default   => 'success'
};
$icon = match ($type) {
    'error'   => 'exclamation-triangle-fill',
    'warning' => 'exclamation-circle-fill',
    default   => 'check-circle-fill'
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#663399">
<title>Backup &amp; Migración · Asset Vault · INTELLIHELP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/css/intellihelp.css" rel="stylesheet">
<style>
  header.ih-topbar-tenant { display: flex; align-items: center; padding: 0 16px; height: 64px; background: #fff; border-bottom: 1px solid #E4E4E7; position: sticky; top: 0; z-index: 900; box-shadow: 0 1px 3px rgba(17,20,24,.04); }
  header.ih-topbar-tenant .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
  header.ih-topbar-tenant .brand__logo { width: 36px; height: 36px; background: #663399; color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; position: relative; }
  header.ih-topbar-tenant .brand__logo::after { content: ''; position: absolute; right: 4px; bottom: 4px; width: 7px; height: 7px; background: #9CC930; border-radius: 50%; }
  header.ih-topbar-tenant .brand__text strong { font-size: 15px; color: #18181B; font-weight: 700; letter-spacing: -.3px; display: block; line-height: 1.1; }
  header.ih-topbar-tenant .brand__text span { font-size: 11px; color: #71717A; }
  header.ih-topbar-tenant .topbar-spacer { flex: 1; }
  header.ih-topbar-tenant a.back { color: #663399; font-weight: 600; font-size: 13px; text-decoration: none; padding: 8px 14px; border-radius: 10px; border: 1px solid #D0BFEA; background: #fff; transition: all .15s; }
  header.ih-topbar-tenant a.back:hover { background: #F4F1F9; }

  .actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 24px; }
  @media (max-width: 767px) { .actions-grid { grid-template-columns: 1fr; } }

  .feature-card {
    background: #fff; border-radius: 16px; border: 1px solid #E4E4E7;
    box-shadow: 0 4px 16px rgba(102,51,153,.06);
    padding: 28px; transition: all .15s;
  }
  .feature-card:hover { box-shadow: 0 8px 32px rgba(102,51,153,.12); transform: translateY(-2px); }
  .feature-card__icon {
    width: 56px; height: 56px; border-radius: 14px;
    background: linear-gradient(135deg, #663399, #4E247A);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin-bottom: 16px;
  }
  .feature-card--green .feature-card__icon { background: linear-gradient(135deg, #9CC930, #7AA725); }
  .feature-card__title { font-size: 18px; font-weight: 700; color: #18181B; margin-bottom: 6px; }
  .feature-card__desc  { font-size: 14px; color: #52525B; margin-bottom: 18px; line-height: 1.5; }

  .dropzone {
    border: 2px dashed #D0BFEA;
    border-radius: 12px;
    padding: 32px;
    text-align: center;
    background: #F4F1F9;
    transition: all .15s;
    cursor: pointer;
  }
  .dropzone:hover { border-color: #663399; background: #E5DCF1; }
  .dropzone i { font-size: 36px; color: #663399; }
  .dropzone-text { font-size: 14px; color: #52525B; margin-top: 8px; }
  .dropzone input[type="file"] { display: none; }
  .file-name { font-weight: 600; color: #663399; margin-top: 8px; }
</style>
</head>
<body>

<header class="ih-topbar-tenant">
  <a class="brand" href="index.php">
    <div class="brand__logo">i</div>
    <div class="brand__text">
      <strong>Asset Vault</strong>
      <span>INTELLIHELP · Inventario</span>
    </div>
  </a>
  <div class="topbar-spacer"></div>
  <a class="back" href="index.php"><i class="bi bi-arrow-left"></i> Volver al inventario</a>
</header>

<main style="max-width:1000px;margin:0 auto;padding:24px 16px 60px;">

  <div class="ih-page-header">
    <div>
      <h1 class="ih-page-header__title"><i class="bi bi-cloud-arrow-up-fill" style="color:#663399"></i> Backup &amp; Migración</h1>
      <div class="ih-page-header__subtitle">Exportá un backup completo o importá un CSV desde otra instancia preservando los enlaces a Drive</div>
    </div>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="ih-alert ih-alert--<?= $alertClass ?>">
      <i class="bi bi-<?= $icon ?>"></i>
      <div><?= $msg ?></div>
      <button class="ih-alert__close" onclick="this.parentElement.style.display='none'"><i class="bi bi-x"></i></button>
    </div>
  <?php endif; ?>

  <div class="actions-grid">

    <!-- EXPORT -->
    <div class="feature-card feature-card--green">
      <div class="feature-card__icon"><i class="bi bi-download"></i></div>
      <div class="feature-card__title">Exportar backup completo</div>
      <div class="feature-card__desc">
        Descargá un CSV con <strong>todos los activos</strong> (incluso archivados),
        todas las columnas del schema y las referencias a Drive
        (<code>carpeta_raiz_drive</code>). Lo usás para hacer una copia de
        seguridad o migrar el inventario a otra instancia sin perder los enlaces
        a los archivos en Google Drive.
      </div>
      <form method="POST" action="backend/export_full_backup.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button class="ih-btn ih-btn--accent ih-btn--block" type="submit">
          <i class="bi bi-cloud-download-fill"></i> Descargar backup CSV
        </button>
      </form>
    </div>

    <!-- IMPORT -->
    <div class="feature-card">
      <div class="feature-card__icon"><i class="bi bi-upload"></i></div>
      <div class="feature-card__title">Importar backup desde CSV</div>
      <div class="feature-card__desc">
        Subí un CSV generado por <em>Exportar backup completo</em> (o el viejo
        export). Si la fila trae <code>id</code> y ya existe, se <strong>actualiza</strong>.
        Si no, se <strong>crea</strong> un nuevo activo. Las referencias a Drive
        se preservan tal cual están en el CSV.
      </div>
      <form method="POST" action="backend/import_full_backup.php" enctype="multipart/form-data" id="import-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <label class="dropzone" for="csv-file" id="dropzone">
          <i class="bi bi-file-earmark-arrow-up"></i>
          <div class="dropzone-text">Click para seleccionar un CSV</div>
          <div class="file-name" id="file-name"></div>
          <input type="file" id="csv-file" name="csv_file" accept=".csv,text/csv" required>
        </label>

        <button class="ih-btn ih-btn--primary ih-btn--block ih-mt-4" type="submit" id="import-btn" disabled>
          <i class="bi bi-cloud-upload-fill"></i> Importar
        </button>
      </form>
    </div>
  </div>

  <div class="ih-card ih-mt-6">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-info-circle"></i> Cómo migrar desde la versión vieja</h2>
      </div>
    </div>
    <div class="ih-card__body">
      <ol style="padding-left:1.2rem;line-height:1.9;font-size:14px;">
        <li>Entrá a la instancia vieja (ej. <code>inventario.intellihelp.tech</code>) como admin.</li>
        <li>Hacé clic en <strong>Exportar backup completo</strong> y guardá el archivo CSV.</li>
        <li>Entrá a la nueva instancia y subí ese CSV en <strong>Importar backup desde CSV</strong>.</li>
        <li>Los activos se importan con su <code>carpeta_raiz_drive</code> original — los archivos siguen en Google Drive y se muestran intactos.</li>
        <li>Cualquier modificación posterior queda registrada como movimiento <code>IMPORT_BACKUP_NEW</code> o <code>IMPORT_BACKUP_UPDATE</code>.</li>
      </ol>
      <div class="ih-alert ih-alert--info ih-mt-4">
        <i class="bi bi-shield-check"></i>
        <div>
          <strong>Compatibilidad:</strong> el importador acepta tanto el CSV del nuevo formato como el formato viejo de
          <code>export_csv.php</code>. Los archivos físicos no se descargan ni se vuelven a subir — se preservan las referencias por ID.
        </div>
      </div>
    </div>
  </div>

</main>

<script>
const input = document.getElementById('csv-file');
const btn   = document.getElementById('import-btn');
const name  = document.getElementById('file-name');
input.addEventListener('change', e => {
  if (input.files.length) {
    name.textContent = input.files[0].name;
    btn.disabled = false;
  }
});
</script>
</body>
</html>
