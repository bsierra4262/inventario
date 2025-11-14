<?php
declare(strict_types=1);

// ============================================
// Confirmar acción ante archivo duplicado
// Compatible: Google Drive / Microsoft OneDrive
// ============================================

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// --- Conexión del tenant (multiempresa)
require_once __DIR__ . '/../backend/db.php';

// --- Logger opcional (no rompe si falta)
$loggerPath = __DIR__ . '/../logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
    }
}

// --- CSRF centralizado
require_once __DIR__ . '/csrf.php';

// Generamos token CSRF específico para esta vista/acción.
// Este scope ("confirmar_accion") se debe validar en sobrescribir.php y renombrar.php
// con csrf_or_fail('confirmar_accion', true);
$csrf_token = generateCsrfToken('confirmar_accion');

// --- Proveedor de almacenamiento (por tenant o por sesión)
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

/**
 * VALIDACIONES:
 * - Google IDs: alfanumérico con -, _
 * - OneDrive IDs: pueden incluir alfanumérico, -, _, ., ! (base64url-like)
 * Ampliamos el patrón para cubrir ambas plataformas de forma segura.
 */
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
if (
    !$tmpPath ||
    !is_string($tmpPath) ||
    !file_exists($tmpPath)
) {
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

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirmar Acción - Asset Vault</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body {
      font-family:'Saira',sans-serif;
      background-color:#F5F5F5;
      margin:0;padding:0;
      display:flex;
      flex-direction:column;
      min-height:100vh
    }
    header {
      background-color:#663399;
      color:#fff;
      padding:1rem;
      display:flex;
      justify-content:space-between;
      align-items:center
    }
    header h1 {
      font-size:1.5rem;
      margin:0
    }
    .logout-btn {
      background-color:#4E247A;
      color:#fff;
      border:none;
      padding:0.5rem 1rem;
      border-radius:5px;
      text-decoration:none;
      font-weight:500
    }
    .logout-btn:hover {
      background-color:#86B329;
      color:#fff
    }
    .container {
      flex:1;
      padding:2rem 1rem;
      background-color:#fff;
      box-shadow:0 0 10px rgba(0,0,0,0.05);
      border-radius:0;
      max-width:800px;
      margin:2rem auto 4rem auto
    }
    .footer {
      text-align:center;
      padding:1rem;
      background-color:#663399;
      color:#fff;
      width:100%;
      position:fixed;
      bottom:0;
      left:0;
      z-index:999
    }
    .form-label {
      color:#4E247A;
      font-weight:600
    }
    .btn-danger {
      background-color:#D9534F;
      border:none
    }
    .btn-danger:hover {
      background-color:#C9302C
    }
    .btn-primary {
      background-color:#86B329;
      border:none
    }
    .btn-primary:hover {
      background-color:#4E247A
    }
  </style>
</head>
<body>
<header>
  <h1><i class="bi bi-exclamation-triangle"></i> Confirmar Subida</h1>
  <a href="../logout.php" class="logout-btn"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
</header>

<div class="container text-center">
  <h3 class="mb-4 text-danger">
    El archivo <strong><?= htmlspecialchars((string)$actual['file_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
    ya existe en <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8'); ?>
  </h3>

  <p class="text-muted">Elegí una de las siguientes opciones:</p>

  <div class="row g-4 justify-content-center">

    <!-- Opción: Sobrescribir -->
    <div class="col-md-6">
      <form action="sobrescribir.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="file_id" value="<?= htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8'); ?>">

        <button type="submit" class="btn btn-danger w-100">
          <i class="bi bi-arrow-repeat"></i> Sobrescribir
        </button>
      </form>
    </div>

    <!-- Opción: Renombrar y Subir -->
    <div class="col-md-6">
      <form action="renombrar.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="folder_id" value="<?= htmlspecialchars($actual['folder_id'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="producto_id" value="<?= htmlspecialchars((string)$productoId, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="input-group">
          <input
            type="text"
            class="form-control"
            name="new_name"
            placeholder="Nuevo nombre"
            required
          >
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-upload"></i> Renombrar y Subir
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<footer class="footer">© 2025 ITH - Todos los derechos reservados</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
