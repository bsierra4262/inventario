<?php
/**
 * Exportar el inventario a una planilla.
 *
 * Dos descargas, y la diferencia entre ellas es una sola: si van los
 * archivados o no. Van separadas porque son dos usos distintos —pasarle a
 * alguien el inventario de hoy, o tener el historial completo— y no una casilla
 * que hay que acordarse de tildar.
 *
 * El archivo que sale de acá se puede volver a importar tal cual. Las dos
 * columnas que no vuelven son a propósito: la fecha de carga la pone el
 * sistema, y el estado de archivado no se toca desde una planilla, para que una
 * importación no mande activos a la papelera sin avisar.
 *
 * Permiso: asset.export.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

try {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit();
    }

    $user_id    = (int)$_SESSION['user_id'];
    $usersTable = tn('users');

    $stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        session_destroy();
        header('Location: /auth/login.php');
        exit();
    }

    // Permiso minimo para ver la pantalla de export
    require_permission('asset.export', $user);

    require_once __DIR__ . '/../core/db/empresa_cache.php';
    $tenant_name = empresa_nombre();

    // ── Stats del inventario ───────────────────────────────────────────
    $productosTable = tn('productos');
    $stats = ['activos' => 0, 'archivados' => 0, 'total' => 0];
    try {
        $r = $conn->query("SELECT archivado, COUNT(*) AS c FROM `{$productosTable}` GROUP BY archivado");
        if ($r) while ($row = $r->fetch_assoc()) {
            if ((int)$row['archivado'] === 0) $stats['activos']    = (int)$row['c'];
            else                               $stats['archivados'] = (int)$row['c'];
        }
        $stats['total'] = $stats['activos'] + $stats['archivados'];
    } catch (Throwable) {}

} catch (Throwable $e) {
    log_event('inventario/exportar', 'ERROR', $e->getMessage());
    header('Location: /auth/login.php?err=' . urlencode('Error interno'));
    exit();
}

$csrf_activos    = generateCsrfToken('export_inventario');
// Reusar el mismo token (ambos botones comparten el scope, no importa cuál se use primero)
$csrf_archivados = $csrf_activos;

// ── Variables de layout ─────────────────────────────────────────────────────
$page_title      = 'Exportar — Asset Vault';
$page_section    = 'exportar';
$topbar_title    = 'Exportar a CSV';
$topbar_subtitle = 'Bajar el inventario como planilla, para abrirlo en Excel o en Google Sheets';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = hub_volver('datos');

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php /* ── Qué hay para bajar ───────────────────────────────────────────
             Los números van arriba porque son la decisión: bajar 1.200 activos
             o 1.260 no es lo mismo que bajar 12, y si alguien se equivocó de
             empresa lo ve acá antes de descargar nada. */ ?>
    <div class="ih-stats ih-mb-5">
      <div class="ih-stat">
        <div class="ih-stat__label">En operación <i class="bi bi-box-seam"></i></div>
        <div class="ih-stat__value"><?= number_format($stats['activos']) ?></div>
        <div class="ih-stat__delta">Los que están en el inventario</div>
      </div>
      <div class="ih-stat">
        <div class="ih-stat__label">Archivados <i class="bi bi-archive"></i></div>
        <div class="ih-stat__value"><?= number_format($stats['archivados']) ?></div>
        <div class="ih-stat__delta">Los que están en la papelera</div>
      </div>
      <div class="ih-stat">
        <div class="ih-stat__label">Total <i class="bi bi-database"></i></div>
        <div class="ih-stat__value"><?= number_format($stats['total']) ?></div>
        <div class="ih-stat__delta">Todo lo que hay cargado</div>
      </div>
    </div>

    <?php /* Las dos descargas. El icono de las dos es el mismo y del mismo
             color: la diferencia entre ellas está en el texto, no en el color,
             y el verde del sistema significa «esto salió bien» — gastarlo para
             distinguir dos botones de descarga le saca ese significado. */ ?>
    <div class="ih-opciones">

      <div class="ih-opcion">
        <div class="ih-opcion__icono"><i class="bi bi-file-earmark-spreadsheet"></i></div>
        <h2 class="ih-opcion__titulo">Sólo lo que está en uso</h2>
        <p class="ih-opcion__texto">
          Los <strong><?= number_format($stats['activos']) ?> activos</strong> que están en el
          inventario, sin los archivados. Es la planilla que se usa para una revisión o para
          pasarle el inventario a alguien: lo que hay hoy, sin lo que ya salió de circulación.
        </p>
        <div class="ih-opcion__accion">
          <form method="POST" action="/backend/export_csv.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_activos) ?>">
            <input type="hidden" name="incluir_archivados" value="0">
            <button type="submit" class="ih-btn ih-btn--primary">
              <i class="bi bi-download"></i> Descargar planilla
            </button>
          </form>
        </div>
      </div>

      <div class="ih-opcion">
        <div class="ih-opcion__icono"><i class="bi bi-file-earmark-spreadsheet"></i></div>
        <h2 class="ih-opcion__titulo">Todo, incluidos los archivados</h2>
        <p class="ih-opcion__texto">
          Los <strong><?= number_format($stats['total']) ?> registros</strong>, con los
          <?= number_format($stats['archivados']) ?> que están en la papelera y el motivo por el
          que se archivaron. Sirve cuando hace falta el historial completo y no sólo la foto de
          hoy.
        </p>
        <div class="ih-opcion__accion">
          <form method="POST" action="/backend/export_csv.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_archivados) ?>">
            <input type="hidden" name="incluir_archivados" value="1">
            <button type="submit" class="ih-btn ih-btn--outline">
              <i class="bi bi-download"></i> Descargar todo
            </button>
          </form>
        </div>
      </div>

    </div>

    <?php /* ── Las columnas ──────────────────────────────────────────────────
             Están acá para que quien va a editar la planilla afuera sepa con
             qué se va a encontrar, y para que quien la va a volver a subir
             sepa qué encabezados tiene que respetar. */ ?>
    <div class="ih-card">
      <div class="ih-card__header">
        <h2 class="ih-card__title"><i class="bi bi-table"></i> Qué columnas trae la planilla</h2>
      </div>
      <div class="ih-card__body">
        <div class="ih-etiquetas">
          <?php
          $cols = [
            'ID', 'Asignado a', 'Nombre/Modelo', 'Marca', 'Descripción', 'S/N Service Tag',
            'Cantidad', 'Valor USD', 'Estado', 'Categoría', 'Subcategoría',
            'Garantía (meses)', 'Inicio garantía', 'Fecha recepción', 'Fecha compra',
            'Ubicación', 'Fecha carga', 'Patchcord tipo', 'Patchcord longitud',
            'Switch gestión', 'Antenas tipo', 'Bobina categoría', 'Bobina ubicación',
            'Bobina longitud', 'Conectores tipo', 'Detalle conectores', 'Cintas generación',
            'Detalle repuesto', 'Detalle otros', 'Software', 'Software detalle',
          ];
          foreach ($cols as $col): ?>
            <span class="ih-etiqueta"><?= htmlspecialchars($col) ?></span>
          <?php endforeach; ?>
        </div>

        <div class="ih-alert ih-alert--info ih-mt-4">
          <i class="bi bi-info-circle-fill"></i>
          <div>
            <strong>Si lo abrís con Excel en Windows</strong> y las tildes se ven mal, no es que
            el archivo esté roto: está en UTF-8 y Excel asume otra codificación. Se arregla
            abriéndolo desde <em>Datos → Obtener datos → Desde un archivo de texto</em> y
            eligiendo UTF-8. En Google Sheets y en LibreOffice se abre bien de una.
          </div>
        </div>
      </div>
    </div>

    <?php if (user_can('asset.import_csv', $user)): ?>
      <?php /* El camino de vuelta. La planilla que baja de acá se puede editar y
               volver a subir tal cual, así que el enlace a importar va en esta
               pantalla y no sólo en el índice de Datos. */ ?>
      <div class="ih-alert ih-alert--info ih-alert--accion ih-mt-4">
        <i class="bi bi-upload"></i>
        <div class="ih-alert__cuerpo">
          <strong>Editaste la planilla y la querés volver a subir</strong><br>
          El archivo que baja de acá se puede importar tal cual, con los mismos encabezados.
        </div>
        <div class="ih-alert__accion">
          <a href="/inventario/importar.php" class="ih-btn ih-btn--ghost ih-btn--sm">
            <i class="bi bi-upload"></i> Ir a Importar
          </a>
        </div>
      </div>
    <?php endif; ?>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
