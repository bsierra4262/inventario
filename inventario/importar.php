<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /auth/login.php'); exit; }

$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
require_permission('asset.backup_full', $me);

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

$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cs = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $cr = $cs->get_result()->fetch_assoc();
        if ($cr) $tenant_name = $cr['nombre'];
        $cs->close();
    }
} catch (Throwable) {}

// ── Layout vars ─────────────────────────────────────────────────────────────
$page_title      = 'Backup & Migración — Asset Vault';
$page_section    = 'importar';
$topbar_title    = 'Backup & Migración';
$topbar_subtitle = 'Exportá o importá tu inventario completo';
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main">

  <?php if ($msg !== ''): ?>
    <div class="ih-alert ih-alert--<?= $alertClass ?>" style="margin-bottom:24px;">
      <i class="bi bi-<?= $icon ?>"></i>
      <div><?= $msg ?></div>
      <button class="ih-alert__close" onclick="this.parentElement.style.display='none'"><i class="bi bi-x"></i></button>
    </div>
  <?php endif; ?>

  <!-- Grid de acciones -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

    <!-- EXPORT -->
    <div class="ih-card">
      <div class="ih-card__body" style="padding:28px;">
        <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#9CC930,#7AA725);color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:16px;">
          <i class="bi bi-download"></i>
        </div>
        <h3 style="font-size:var(--ih-text-lg);font-weight:700;color:var(--ih-gray-800);margin-bottom:8px;">Exportar backup completo</h3>
        <p style="font-size:var(--ih-text-sm);color:var(--ih-gray-600);margin-bottom:20px;line-height:1.6;">
          Descargá un CSV con <strong>todos los activos</strong> (incluso archivados),
          todas las columnas del schema y las referencias a Drive
          (<code>carpeta_raiz_drive</code>). Lo usás para hacer una copia de
          seguridad o migrar el inventario a otra instancia sin perder los
          enlaces a los archivos en Google Drive.
        </p>
        <form method="POST" action="/backend/export_full_backup.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <button class="ih-btn ih-btn--accent ih-btn--block" type="submit">
            <i class="bi bi-cloud-download-fill"></i> Descargar backup CSV
          </button>
        </form>
      </div>
    </div>

    <!-- IMPORT -->
    <div class="ih-card">
      <div class="ih-card__body" style="padding:28px;">
        <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#663399,#4E247A);color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:16px;">
          <i class="bi bi-upload"></i>
        </div>
        <h3 style="font-size:var(--ih-text-lg);font-weight:700;color:var(--ih-gray-800);margin-bottom:8px;">Importar backup desde CSV</h3>
        <p style="font-size:var(--ih-text-sm);color:var(--ih-gray-600);margin-bottom:20px;line-height:1.6;">
          Subí un CSV generado por <em>Exportar backup completo</em> (o el viejo
          export). Si la fila trae <code>id</code> y ya existe, se <strong>actualiza</strong>.
          Si no, se <strong>crea</strong> un nuevo activo. Las referencias a Drive
          se preservan tal cual están en el CSV.
        </p>
        <form method="POST" action="/backend/import_full_backup.php" enctype="multipart/form-data" id="import-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

          <label class="ih-dropzone" for="csv-file" id="dropzone" style="margin-bottom:12px;">
            <i class="bi bi-file-earmark-arrow-up" style="font-size:2.5rem;color:var(--ih-primary);display:block;margin-bottom:8px;"></i>
            <div style="font-size:var(--ih-text-sm);color:var(--ih-gray-500);">Click para seleccionar un CSV</div>
            <div id="file-name" style="font-weight:600;color:var(--ih-primary);margin-top:6px;font-size:var(--ih-text-sm);"></div>
            <input type="file" id="csv-file" name="csv_file" accept=".csv,text/csv" required
                   style="position:absolute;inset:0;opacity:0;cursor:pointer;">
          </label>

          <button class="ih-btn ih-btn--primary ih-btn--block" type="submit" id="import-btn" disabled>
            <i class="bi bi-cloud-upload-fill"></i> Importar
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Guía de migración -->
  <div class="ih-card">
    <div class="ih-card__header">
      <h2 class="ih-card__title"><i class="bi bi-info-circle"></i> Cómo migrar desde la versión vieja</h2>
    </div>
    <div class="ih-card__body">
      <ol style="padding-left:1.2rem;line-height:1.9;font-size:var(--ih-text-sm);color:var(--ih-gray-700);">
        <li>Entrá a la instancia vieja (ej. <code>inventario.intellihelp.tech</code>) como admin.</li>
        <li>Hacé clic en <strong>Exportar backup completo</strong> y guardá el archivo CSV.</li>
        <li>Entrá a la nueva instancia y subí ese CSV en <strong>Importar backup desde CSV</strong>.</li>
        <li>Los activos se importan con su <code>carpeta_raiz_drive</code> original — los archivos siguen en Google Drive y se muestran intactos.</li>
        <li>Cualquier modificación posterior queda registrada como movimiento <code>IMPORT_BACKUP_NEW</code> o <code>IMPORT_BACKUP_UPDATE</code>.</li>
      </ol>
      <div class="ih-alert ih-alert--info" style="margin-top:16px;">
        <i class="bi bi-shield-check"></i>
        <div>
          <strong>Compatibilidad:</strong> el importador acepta tanto el CSV del nuevo formato como el formato viejo de
          <code>export_csv.php</code>. Los archivos físicos no se descargan ni se vuelven a subir — se preservan las referencias por ID.
        </div>
      </div>
    </div>
  </div>

</main>
</div><!-- /ih-app -->

<style>
.ih-dropzone {
  border: 2px dashed var(--ih-gray-300);
  border-radius: 10px;
  padding: 24px;
  text-align: center;
  cursor: pointer;
  position: relative;
  display: block;
  transition: border-color .2s, background .2s;
  background: var(--ih-gray-50);
}
.ih-dropzone:hover { border-color: var(--ih-primary); background: #f4f1f9; }
@media(max-width:640px) {
  [style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }
}
</style>

<script>
const input = document.getElementById('csv-file');
const btn   = document.getElementById('import-btn');
const name  = document.getElementById('file-name');
input.addEventListener('change', () => {
  if (input.files.length) {
    name.textContent = input.files[0].name;
    btn.disabled = false;
  }
});
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
