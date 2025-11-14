<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../logs/logger.php';

// Identidad del usuario que navega
$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

// Datos de entrada controlados
$status = isset($_GET['status']) ? strtolower((string)$_GET['status']) : '';
$origen = isset($_GET['origen']) ? (string)$_GET['origen'] : '';
$msg    = isset($_GET['msg'])    ? (string)$_GET['msg']    : '';

// Si no viene msg por GET, mensaje por defecto segun status
if ($msg === '') {
    $msg = ($status === 'success')
        ? 'El proceso se completó correctamente.'
        : 'Hubo un problema al procesar la solicitud.';
}

/**
 * ¿Requiere token de Google Drive?
 * - editar / subida de archivos / sobrescribir / renombrar sí
 * - importar CSV o archivar puede NO requerir token de Drive
 * Ajustá esta lista según tu flujo.
 */
$origenesQueRequierenDrive = [
    'editar',
    'renombrar',
    'sobrescribir',
    'upload',
];

$requiresDrive = in_array($origen, $origenesQueRequierenDrive, true);

// Autorización mínima: debe haber sesión activa
if (empty($_SESSION['user_id'])) {
    log_event($user_id, 'ERROR', 'Acceso denegado a resultado.php: sin sesión de usuario.', 'transaccion');
    header('Location: ../login.php');
    exit();
}

// Si el flujo requiere Drive, validamos token de Drive
if ($requiresDrive && !isset($_SESSION['access_token'])) {
    log_event($user_id, 'ERROR', 'Acceso denegado a resultado.php: sin token de acceso Drive.', 'transaccion');
    header('Location: ../login.php');
    exit();
}

// En caso de error, algunas pantallas (ej. editar) necesitan saber el producto
if ($status !== 'success' && !isset($_SESSION['form_data']['producto_id']) && $origen === 'editar') {
    log_event($user_id, 'ERROR', 'Acceso inválido a resultado.php: sin producto_id en contexto para reintentar edición.', 'transaccion');
    header('Location: ../index.php');
    exit();
}

/**
 * Sanitizador seguro del mensaje mostrado:
 * - Si el backend mandó una lista de errores tipo "<ul><li>...</li>...</ul>" la
 *   queremos mostrar con bullets, pero sin permitir inyectar JS.
 * - Permitimos solo un subconjunto MUY chico de etiquetas (ul, li, strong, br).
 */
function formatMessageSafe(string $raw): string {

    // Normalizamos saltos de línea comunes
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    // Detectamos si viene como bloque tipo lista HTML
    if (stripos($raw, '<ul') !== false) {

        // Paso 1: escapamos TODO
        $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Paso 2: re-habilitamos las etiquetas seguras reemplazando su versión escapada
        // Esto deja volver solo las tags whitelisted, todo lo demás queda escapado.
        $allowTags = [
            '<ul>'   => '&lt;ul&gt;',
            '</ul>'  => '&lt;/ul&gt;',
            '<li>'   => '&lt;li&gt;',
            '</li>'  => '&lt;/li&gt;',
            '<strong>'  => '&lt;strong&gt;',
            '</strong>' => '&lt;/strong&gt;',
            '<br>'      => '&lt;br&gt;',
            '<br/>'     => '&lt;br/&gt;',
            '<br />'    => '&lt;br /&gt;',
        ];

        foreach ($allowTags as $real => $escapedTag) {
            // reemplazamos la versión escapada por la tag real permitida
            $escaped = str_ireplace($escapedTag, $real, $escaped);
        }

        // También soportar <p> que vos no usás pero podrías querer agregar:
        $escaped = str_ireplace('&lt;p&gt;', '<p>', $escaped);
        $escaped = str_ireplace('&lt;/p&gt;', '</p>', $escaped);

        return $escaped;
    }

    // Caso simple: mensaje plano → escapar + nl2br
    return nl2br(
        htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );
}

// Registramos en logs que el usuario llegó a esta vista
log_event(
    $user_id,
    strtoupper($status ?: 'INFO'),
    "Resultado mostrado al usuario. Origen: $origen. Mensaje: $msg",
    'transaccion'
);

// Para los botones de reintento edición
$productoIdSafe = 0;
if (isset($_SESSION['form_data']['producto_id'])) {
    $productoIdSafe = (int)($_SESSION['form_data']['producto_id']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resultado</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body {
      font-family: 'Saira', sans-serif;
      background-color: #F5F5F5;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
      padding-bottom: 60px; /* para no tapar con el footer fijo */
    }
    .container-box {
      background-color: #fff;
      padding: 3rem;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.1);
      text-align: center;
      max-width: 600px;
      width: 90%;
    }
    .icon {
      font-size: 4rem;
      margin-bottom: 1rem;
    }
    .success-icon { color: #86B329; }
    .error-icon   { color: #D9534F; }
    .footer {
      text-align: center;
      padding: 1rem;
      background-color: #663399;
      color: #fff;
      width: 100%;
      position: fixed;
      bottom: 0;
      left: 0;
      font-size: .9rem;
    }
    .btn-custom {
      border-radius: 30px;
      padding: 0.5rem 1.5rem;
      font-weight: 600;
    }
    ul {
      text-align: left;
      margin-top: 1rem;
      padding-left: 1.5rem;
    }
    li {
      margin-bottom: 0.5rem;
    }
  </style>
</head>
<body>

  <div class="container-box">
    <div class="icon <?php echo ($status === 'success' ? 'success-icon' : 'error-icon'); ?>">
      <i class="bi <?php echo ($status === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-octagon-fill'); ?>"></i>
    </div>

    <h2 class="fw-bold text-uppercase" style="color: #4E247A;">
      <?php echo ($status === 'success' ? 'Operación exitosa' : 'Ocurrió un error'); ?>
    </h2>

    <div class="lead text-muted mt-3">
      <?php echo formatMessageSafe($msg); ?>
    </div>

    <div class="mt-4 d-flex justify-content-center flex-wrap gap-3">
      <?php if ($status === 'success'): ?>

        <a href="../index.php"
           class="btn btn-custom"
           style="background-color: #86B329; color: white;">
            <i class="bi bi-house-door"></i> Volver al inicio
        </a>

      <?php else: ?>

        <?php if ($origen === 'editar' && $productoIdSafe > 0): ?>
          <a href="edit.php?id=<?php echo urlencode((string)$productoIdSafe); ?>"
             class="btn btn-custom"
             style="background-color: #D9534F; color: white;">
            <i class="bi bi-arrow-repeat"></i> Reintentar
          </a>

          <a href="edit.php?id=<?php echo urlencode((string)$productoIdSafe); ?>"
             class="btn btn-custom"
             style="background-color: #6c757d; color: white;">
            <i class="bi bi-pencil-square"></i> Editar de nuevo
          </a>

        <?php else: ?>
          <a href="../index.php"
             class="btn btn-custom"
             style="background-color: #D9534F; color: white;">
            <i class="bi bi-arrow-repeat"></i> Volver
          </a>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>

  <footer class="footer">© 2025 ITH - Todos los derechos reservados</footer>
</body>
</html>
