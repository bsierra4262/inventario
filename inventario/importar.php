<?php
/**
 * Respaldo y migración: bajar una copia de todo, y volver a subirla.
 *
 * Se llamaba «Backup & Migración» —con ampersand y con una palabra en inglés— y
 * además exportaba, aunque «Exportar» era otra pestaña. Esa confusión es la
 * razón por la que existe inventario/datos.php, que agrupa las dos cosas por lo
 * que resuelven. Esta pantalla quedó con lo que de verdad hace: la copia
 * completa, que es otra cosa que la planilla de trabajo.
 *
 * La diferencia con «Exportar a CSV» no es el formato, es para qué sirve:
 *
 *   Exportar   → la planilla de trabajo. Las columnas que una persona lee y
 *                edita, sin los identificadores internos.
 *   Respaldo   → todo, incluidos los enlaces a las carpetas de Drive. No se
 *                edita a mano: se guarda, o se sube a otra instalación.
 *
 * Al importar, una fila que trae un número de activo que ya existe lo
 * actualiza; una que no, lo crea. Los archivos de Drive no se descargan ni se
 * vuelven a subir: viaja la referencia, y los archivos se quedan donde están.
 *
 * Cada activo que entra queda anotado en su historial como `IMPORT_BACKUP_NEW`
 * o `IMPORT_BACKUP_UPDATE` (ver backend/import_full_backup.php). Esos dos
 * códigos estaban escritos en la pantalla, a la vista de cualquiera: son para
 * quien va a buscar en la base por qué cambió un activo, no para quien sube un
 * archivo. Están acá por eso.
 *
 * Permisos: asset.backup_full para la copia, asset.import_csv para subirla.
 * Se pide cualquiera de los dos para entrar y después se habilita cada mitad
 * por separado — antes la pantalla exigía asset.backup_full para entrar, pero
 * la tarjeta de Datos dejaba pasar a quien tenía sólo asset.import_csv: la
 * persona hacía clic y se chocaba con «acceso denegado».
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';

$me = require_tenant_permission_any(['asset.backup_full', 'asset.import_csv']);

$puedeBajar = user_can('asset.backup_full', $me);
$puedeSubir = user_can('asset.import_csv', $me);

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

require_once __DIR__ . '/../core/db/empresa_cache.php';
$tenant_name = empresa_nombre();

// ── Variables de layout ─────────────────────────────────────────────────────
$page_title      = 'Respaldo y migración — Asset Vault';
$page_section    = 'importar';
$page_portal     = 'tenant';
$topbar_title    = 'Respaldo y migración';
$topbar_subtitle = 'Una copia completa del inventario de ' . $tenant_name . ', y el camino de vuelta';
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = hub_volver('datos');

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main ih-anim-fade">

  <?php if ($msg !== ''): ?>
    <div class="ih-alert ih-alert--<?= $alertClass ?>">
      <i class="bi bi-<?= $icon ?>"></i>
      <div><?= $msg ?></div>
      <button class="ih-alert__close" type="button" aria-label="Cerrar el aviso"><i class="bi bi-x"></i></button>
    </div>
  <?php endif; ?>

  <div class="ih-opciones">

    <?php /* ── Bajar la copia ───────────────────────────────────────────── */ ?>
    <div class="ih-opcion<?= $puedeBajar ? '' : ' es-bloqueada' ?>" id="backup">
      <div class="ih-opcion__icono"><i class="bi bi-shield-check"></i></div>
      <h2 class="ih-opcion__titulo">Bajar una copia de todo</h2>
      <p class="ih-opcion__texto">
        Todo el inventario de <strong><?= htmlspecialchars($tenant_name) ?></strong> en un
        archivo: los activos que están en uso, los archivados, todas las columnas y los enlaces
        a las carpetas de Google&nbsp;Drive. Guardalo fuera del sistema — en un pendrive, en otro
        Drive, donde no dependa de que esto siga funcionando.
      </p>
      <div class="ih-opcion__accion">
        <?php if ($puedeBajar): ?>
          <form method="POST" action="/backend/export_full_backup.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button class="ih-btn ih-btn--primary" type="submit">
              <i class="bi bi-download"></i> Bajar la copia
            </button>
          </form>
        <?php else: ?>
          <button class="ih-btn ih-btn--primary" type="button" disabled>
            <i class="bi bi-download"></i> Bajar la copia
          </button>
          <p class="ih-opcion__nota">Tu rol no tiene el permiso para bajar la copia completa.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ── Volver a subirla ─────────────────────────────────────────────
             El campo de archivo es el del sistema (componentes.js lo
             reemplaza). Antes esta pantalla dibujaba su propia zona para
             arrastrar, con el <input> adentro — y como el sistema mejora
             *todos* los campos de archivo, quedaban dos zonas, una dentro de
             la otra. */ ?>
    <div class="ih-opcion<?= $puedeSubir ? '' : ' es-bloqueada' ?>">
      <div class="ih-opcion__icono"><i class="bi bi-upload"></i></div>
      <h2 class="ih-opcion__titulo">Volver a subir una copia</h2>
      <p class="ih-opcion__texto">
        Para traer el inventario de otra instalación, o para restaurar una copia que bajaste de
        acá. Cada fila que trae un número de activo que ya existe <strong>actualiza</strong> ese
        activo; la que no, <strong>crea</strong> uno nuevo. Los archivos de Drive no se mueven:
        viaja el enlace y los archivos se quedan donde están.
      </p>
      <div class="ih-opcion__accion">
        <?php if ($puedeSubir): ?>
          <form method="POST" action="/backend/import_full_backup.php"
                enctype="multipart/form-data" id="form-importar">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="ih-field">
              <label class="ih-field__label" for="csv-file">El archivo de la copia</label>
              <input type="file" id="csv-file" name="csv_file" accept=".csv,text/csv" required
                     data-ayuda="Un archivo .csv, el que baja «Bajar una copia de todo»">
            </div>
            <button class="ih-btn ih-btn--primary" type="submit" id="btn-importar" disabled>
              <i class="bi bi-upload"></i> Importar la copia
            </button>
          </form>
        <?php else: ?>
          <button class="ih-btn ih-btn--primary" type="button" disabled>
            <i class="bi bi-upload"></i> Importar la copia
          </button>
          <p class="ih-opcion__nota">Tu rol no tiene el permiso para importar.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <?php /* ── Cómo se pasa de una instalación a otra ─────────────────────────
           Es el caso que justifica que esta pantalla exista aparte de
           «Exportar»: la planilla de trabajo no trae los enlaces a Drive, y sin
           ellos la mudanza pierde los archivos de todos los activos. */ ?>
  <div class="ih-card ih-ancho-lectura">
    <div class="ih-card__header">
      <h2 class="ih-card__title"><i class="bi bi-signpost-2"></i> Pasar el inventario de una instalación a otra</h2>
    </div>
    <div class="ih-card__body">
      <ol class="ih-pasos">
        <li>Entrá a la instalación vieja con un usuario administrador.</li>
        <li>En esta misma pantalla, <strong>Bajar una copia de todo</strong>, y guardá el archivo.</li>
        <li>Entrá a la instalación nueva y subí ese archivo en <strong>Volver a subir una copia</strong>.</li>
        <li>
          Los activos llegan con su carpeta de Drive ya enlazada: los archivos siguen donde
          estaban y se ven igual. Nada se descarga ni se vuelve a subir.
        </li>
        <li>
          Cada activo que se crea o se actualiza queda anotado en su historial, así que después
          se puede ver qué entró por la importación y qué ya estaba.
        </li>
      </ol>

      <div class="ih-alert ih-alert--info ih-mt-4">
        <i class="bi bi-shield-check"></i>
        <div>
          También sirve el archivo de <strong>Exportar a CSV</strong>, el de la planilla de
          trabajo. Entra igual: lo que no trae —los enlaces a Drive— queda vacío en lugar de
          hacer fallar la importación.
        </div>
      </div>
    </div>
  </div>

</main>
</div><!-- /ih-app -->

<?php if ($puedeSubir): ?>
<script>
/* El botón arranca apagado hasta que haya un archivo elegido: un «Importar» que
   se puede apretar sin archivo manda un formulario vacío y vuelve con un error
   que no hacía falta. El campo de archivo lo dibuja componentes.js, pero el
   `change` lo sigue emitiendo el <input> de verdad, que es el que guarda el
   valor — así que esto funciona igual si el JavaScript del componente no corre. */
(function () {
  var campo = document.getElementById('csv-file');
  var boton = document.getElementById('btn-importar');
  if (!campo || !boton) { return; }
  campo.addEventListener('change', function () {
    boton.disabled = campo.files.length === 0;
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
