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
require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
if (empty($_SESSION['user_id'])) {
    log_event($user_id, 'ERROR', 'Acceso denegado a resultado.php: sin sesión de usuario.', 'transaccion');
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit();
}

// Si el flujo requiere Drive, validamos token de Drive
if ($requiresDrive && !isset($_SESSION['access_token'])) {
    log_event($user_id, 'ERROR', 'Acceso denegado a resultado.php: sin token de acceso Drive.', 'transaccion');
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit();
}

// En caso de error, algunas pantallas (ej. editar) necesitan saber el producto
if ($status !== 'success' && !isset($_SESSION['form_data']['producto_id']) && $origen === 'editar') {
    log_event($user_id, 'ERROR', 'Acceso inválido a resultado.php: sin producto_id en contexto para reintentar edición.', 'transaccion');
    header('Location: /inventario/index.php');
    exit();
}

/**
 * Sanitizador seguro del mensaje mostrado:
 * - Si el backend mandó una lista de errores tipo "<ul><li>...</li>...</ul>" la
 *   queremos mostrar con bullets, pero sin permitir inyectar JS.
 * - Permitimos solo un subconjunto MUY chico de etiquetas (ul, li, strong, br).
 */
if (!function_exists('formatMessageSafe')) {
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
} // end function_exists('formatMessageSafe')

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

// ── Layout ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';

$tenant_name = 'Mi empresa';
try {
    $mdbR = new MasterDB();
    $cidR = (int)($_SESSION['company_id'] ?? 0);
    if ($cidR > 0) {
        $csR = $mdbR->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$cidR]);
        $crR = $csR->get_result()->fetch_assoc();
        if ($crR) $tenant_name = $crR['nombre'];
        $csR->close();
    }
} catch (Throwable) {}

$isSuccess = ($status === 'success');

$page_title   = $isSuccess ? 'Operación exitosa' : 'Error en operación';
$page_section = 'inventario';

$topbar_title    = $isSuccess ? 'Operación exitosa' : 'Ocurrió un error';
$topbar_subtitle = '';
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Inventario</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main ih-app__main--centrado">
  <div class="ih-card ih-foco ih-foco--resultado">
    <div class="ih-card__body">

      <div class="ih-foco__marca <?= $isSuccess ? 'ih-foco__marca--bien' : 'ih-foco__marca--mal' ?>">
        <i class="bi bi-<?= $isSuccess ? 'check-circle-fill' : 'exclamation-octagon-fill' ?>"></i>
      </div>

      <h2 class="ih-foco__titulo ih-mb-3">
        <?= $isSuccess ? 'Listo' : 'No se pudo' ?>
      </h2>

      <?php /* El detalle va alineado a la izquierda aunque el resto este
               centrado: puede ser una lista de archivos o un error de varias
               lineas, y eso centrado no se lee. */ ?>
      <div class="ih-foco__detalle">
        <?= formatMessageSafe($msg) ?>
      </div>

      <div class="ih-foco__acciones">
        <?php if ($isSuccess): ?>
          <a href="/inventario/index.php" class="ih-btn ih-btn--primary">
            <i class="bi bi-house-door"></i> Volver al inventario
          </a>
          <?php if ($origen === 'editar' && $productoIdSafe > 0): ?>
            <a href="/backend/view_details.php?id=<?= $productoIdSafe ?>" class="ih-btn ih-btn--ghost">
              <i class="bi bi-eye"></i> Ver activo
            </a>
          <?php endif; ?>

        <?php else: ?>
          <?php if ($origen === 'editar' && $productoIdSafe > 0): ?>
            <a href="/backend/edit.php?id=<?= $productoIdSafe ?>" class="ih-btn ih-btn--danger">
              <i class="bi bi-arrow-repeat"></i> Reintentar
            </a>
          <?php endif; ?>
          <a href="/inventario/index.php" class="ih-btn ih-btn--ghost">
            <i class="bi bi-arrow-left"></i> Volver
          </a>
        <?php endif; ?>
      </div>

    </div>
  </div>
</main>

</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
