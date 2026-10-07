<?php
declare(strict_types=1);

// ============================================
// Confirmar acción ante archivo duplicado
// Compatible: Google Drive / Microsoft OneDrive
// ============================================

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php'); exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

// --- Conexión del tenant (multiempresa)
require_once __DIR__ . '/../backend/db.php';

// --- Autorización ---
// La página decide qué pasa con un archivo de un activo (sobrescribir o
// renombrar), así que pide el mismo permiso que las dos acciones que ofrece.
// Sin esto un `viewer` veía el nombre de los archivos del activo y tenía los
// dos botones a mano.
require_once __DIR__ . '/../core/security/permissions.php';
require_tenant_permission('asset.edit');

// --- Logger
$loggerPath = __DIR__ . '/../logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
    }
}

// --- CSRF
require_once __DIR__ . '/csrf.php';
$csrf_token = generateCsrfToken('confirmar_accion');

// --- Proveedor de almacenamiento
$provider = strtolower((string)($_SESSION['storage_provider'] ?? 'google'));
$providerLabel = match ($provider) {
    'microsoft' => 'Microsoft OneDrive / SharePoint',
    'google'    => 'Google Drive',
    default     => 'el almacenamiento en la nube',
};

// --- Validar parámetros mínimos (GET)
if (!isset($_GET['file_name'], $_GET['folder_id'], $_GET['file_id'], $_GET['id'])) {
    log_event("confirmar_accion", "ERROR", "Datos insuficientes para verificar archivo", "transaccion");
    header('Location: resultado.php?msg=' . urlencode('Error: Datos insuficientes para verificar el archivo.'));
    exit();
}

$fileName   = (string)$_GET['file_name'];
$folderId   = (string)$_GET['folder_id'];
$fileId     = (string)$_GET['file_id'];
$productoId = (int)$_GET['id'];

$validIdPattern = '/^[A-Za-z0-9_\-\.!]+$/';

if (!preg_match($validIdPattern, $folderId)) {
    log_event("confirmar_accion", "ERROR", "ID de carpeta no válido: $folderId", "transaccion");
    header('Location: resultado.php?msg=' . urlencode('Error: ID de carpeta no válido.'));
    exit();
}
if (!preg_match($validIdPattern, $fileId)) {
    log_event("confirmar_accion", "ERROR", "ID de archivo no válido: $fileId", "transaccion");
    header('Location: resultado.php?msg=' . urlencode('Error: ID de archivo no válido.'));
    exit();
}

// --- Recuperar datos previos de sesión
$formData = $_SESSION['form_data'] ?? null;
if (!$formData) {
    log_event("confirmar_accion", "ERROR", "Sin datos de formulario para producto ID: $productoId", "transaccion");
    header('Location: resultado.php?msg=' . urlencode('Error: No se encontraron datos del formulario. Volvé a editar el producto.'));
    exit();
}

// --- Control de duplicados
if (!isset($_SESSION['archivo_actual'])) {
    $duplicados = $_SESSION['archivos_duplicados'] ?? [];
    if (empty($duplicados)) {
        unset($_SESSION['archivo_actual'], $_SESSION['archivos_duplicados']);
        log_event("confirmar_accion", "TRANSACCION", "Todos los archivos procesados para producto ID: $productoId", "transaccion");
        header('Location: resultado.php?status=success&msg=' . urlencode('Todos los archivos procesados.'));
        exit();
    }

    $_SESSION['archivo_actual'] = array_shift($duplicados);
    $_SESSION['archivos_duplicados'] = $duplicados;

    header('Location: confirmar_accion.php?file_name=' . urlencode($_SESSION['archivo_actual']['file_name']) .
           '&folder_id=' . urlencode($_SESSION['archivo_actual']['folder_id']) .
           '&file_id=' . urlencode($_SESSION['archivo_actual']['file_id']) .
           '&id=' . $productoId);
    exit();
}

// --- Validar archivo temporal actual
$actual  = $_SESSION['archivo_actual'];
$tmpPath = $actual['tmpPath'] ?? null;
if (!$tmpPath || !is_string($tmpPath) || !file_exists($tmpPath)) {
    $duplicados = $_SESSION['archivos_duplicados'] ?? [];
    if (empty($duplicados)) {
        unset($_SESSION['archivo_actual'], $_SESSION['archivos_duplicados']);
        log_event("confirmar_accion", "TRANSACCION", "Todos los archivos procesados correctamente para producto ID: $productoId", "transaccion");
        header('Location: resultado.php?status=success&msg=' . urlencode('Todos los archivos fueron procesados correctamente.'));
        exit();
    }

    $_SESSION['archivo_actual'] = array_shift($duplicados);
    $_SESSION['archivos_duplicados'] = $duplicados;

    header('Location: confirmar_accion.php?file_name=' . urlencode($_SESSION['archivo_actual']['file_name']) .
           '&folder_id=' . urlencode($_SESSION['archivo_actual']['folder_id']) .
           '&file_id=' . urlencode($_SESSION['archivo_actual']['file_id']) .
           '&id=' . $productoId);
    exit();
}

// --- Listo para renderizar
log_event(
    "confirmar_accion",
    "INFO",
    "Preparado para confirmar acción para archivo '{$actual['file_name']}' en producto ID: $productoId (prov: $provider)",
    "transaccion"
);

// Tenant name
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $mdbC = new MasterDB();
    $cidC = (int)($_SESSION['company_id'] ?? 0);
    if ($cidC > 0) {
        $csC = $mdbC->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$cidC]);
        $crC = $csC->get_result()->fetch_assoc();
        if ($crC) $tenant_name = $crC['nombre'];
        $csC->close();
    }
} catch (Throwable) {}

// ── Layout vars ─────────────────────────────────────────────────────────────
$page_title      = 'Confirmar subida — Asset Vault';
$page_section    = 'inventario';
$topbar_title    = 'Archivo duplicado';
$topbar_subtitle = htmlspecialchars($actual['file_name'], ENT_QUOTES, 'UTF-8');
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  =
    '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Inventario</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main ih-app__main--centrado">
  <div class="ih-card ih-foco ih-foco--ancha">
    <div class="ih-card__body">

      <div class="ih-foco__cabecera">
        <div class="ih-foco__icono ih-foco__icono--aviso">
          <i class="bi bi-files"></i>
        </div>
        <div>
          <h2 class="ih-foco__titulo">Ese archivo ya está subido</h2>
          <p class="ih-foco__sub">En <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      </div>

      <div class="ih-alert ih-alert--warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
          En la carpeta de este activo ya hay un archivo que se llama
          <strong><?= htmlspecialchars((string)$actual['file_name'], ENT_QUOTES, 'UTF-8') ?></strong>.
          Hay dos salidas, y conviene mirar cuál antes de elegir.
        </div>
      </div>

      <?php /* Dos caminos, uno al lado del otro. El de la izquierda no se
               deshace —el archivo viejo se pierde— y el borde lo dice. */ ?>
      <div class="ih-caminos">

        <div class="ih-card ih-camino ih-camino--peligro">
          <div class="ih-card__body ih-card__body--compacto ih-col ih-gap-3">
            <div class="ih-camino__cabecera">
              <i class="bi bi-arrow-repeat"></i>
              <strong class="ih-camino__titulo">Reemplazar el que está</strong>
            </div>
            <p class="ih-camino__texto">
              El archivo que ya estaba se pierde y queda sólo el nuevo. No se puede deshacer.
            </p>
            <form action="sobrescribir.php" method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="file_id" value="<?= htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="ih-btn ih-btn--danger ih-btn--block ih-btn--sm">
                <i class="bi bi-arrow-repeat"></i> Reemplazar
              </button>
            </form>
          </div>
        </div>

        <div class="ih-card ih-camino ih-camino--seguro">
          <div class="ih-card__body ih-card__body--compacto ih-col ih-gap-3">
            <div class="ih-camino__cabecera">
              <i class="bi bi-pencil-square"></i>
              <strong class="ih-camino__titulo">Subirlo con otro nombre</strong>
            </div>
            <p class="ih-camino__texto">
              Quedan los dos. El que ya estaba no se toca.
            </p>
            <form action="renombrar.php" method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="folder_id" value="<?= htmlspecialchars($actual['folder_id'], ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="producto_id" value="<?= htmlspecialchars((string)$productoId, ENT_QUOTES, 'UTF-8') ?>">
              <div class="ih-buscador">
                <label class="ih-sr-only" for="new_name">El nombre nuevo del archivo</label>
                <input type="text" class="ih-input" id="new_name" name="new_name" required
                       placeholder="Ej.: factura-dell-marzo-v2.pdf">
                <button type="submit" class="ih-btn ih-btn--primary ih-btn--sm">
                  <i class="bi bi-upload"></i><span class="ih-sr-only">Subir con ese nombre</span>
                </button>
              </div>
            </form>
          </div>
        </div>
            </form>
          </div>
        </div>

      </div><!-- /grid -->

    </div>
  </div>
</main>

</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
