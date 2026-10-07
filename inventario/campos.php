<?php
/**
 * Campos específicos por categoría: la pantalla donde se definen.
 *
 * Antes, cada campo que sólo aplicaba a una subcategoría era una columna en
 * `productos` — catorce en total: `patchcord_tipo`, `bobina_longitud`,
 * `cintas_generacion`… Agregar uno significaba un `ALTER TABLE` y tocar el alta,
 * la edición, el detalle, los dos export y los dos import.
 *
 * Desde acá se define un campo nuevo y aparece solo en el formulario. Los
 * catorce heredados se muestran marcados y no se pueden borrar: siguen viviendo
 * en su columna, que es lo que mantiene funcionando a los export y al respaldo.
 *
 * Permiso: catalog.manage (por defecto, sólo admin del tenant).
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
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
require_once __DIR__ . '/../core/tenants/campos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$user = require_tenant_permission('catalog.manage');

$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = $crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

$defs   = campos_definiciones($conn, true);
$okMsg  = (string)($_GET['ok']  ?? '');
$errMsg = (string)($_GET['err'] ?? '');
$csrf   = generateCsrfToken('campos_abm');

$page_title      = 'Campos por categoría — Asset Vault';
$page_section    = 'campos';
$page_portal     = 'tenant';
$topbar_title    = 'Campos por categoría';
$topbar_subtitle = 'Qué se pregunta en el alta según la categoría del activo';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = hub_volver('configuracion');

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

  <?php if ($okMsg !== ''): ?>
    <div class="ih-alert ih-alert--success ih-mb-4"><i class="bi bi-check-circle-fill"></i>
      <div><?= htmlspecialchars($okMsg, ENT_QUOTES, 'UTF-8') ?></div></div>
  <?php endif; ?>
  <?php if ($errMsg !== ''): ?>
    <div class="ih-alert ih-alert--danger ih-mb-4"><i class="bi bi-exclamation-triangle-fill"></i>
      <div><?= htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') ?></div></div>
  <?php endif; ?>

  <div class="ih-card">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title">Campos por categoría</h2>
        <p class="ih-card__subtitle">
          Un campo aparece en el formulario sólo cuando el activo es de esa categoría o
          subcategoría. Dejalas vacías para que el campo aplique a todos los activos.
        </p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($defs) ?> campos</span>
    </div>

    <div class="ih-card__body">

      <!-- Alta -->
      <form method="post" action="/backend/campos_crud.php" class="ih-form-grid ih-mb-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="accion" value="agregar">
        <div class="ih-field">
          <label class="ih-label" for="n-etiqueta">Nombre del campo</label>
          <input type="text" id="n-etiqueta" name="etiqueta" class="ih-input" required maxlength="120"
                 placeholder="Ej: Color del capuchón">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="n-clave">Identificador</label>
          <input type="text" id="n-clave" name="clave" class="ih-input" required maxlength="60"
                 placeholder="color_capuchon">
          <span class="ih-field__hint">Sin espacios ni acentos. No cambia después.</span>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="n-tipo">Tipo</label>
          <select id="n-tipo" name="tipo" class="ih-select">
            <?php foreach (CAMPO_TIPOS as $k => $lbl): ?>
              <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="n-opciones">Opciones</label>
          <input type="text" id="n-opciones" name="opciones" class="ih-input"
                 placeholder="Azul|Rojo|Verde">
          <span class="ih-field__hint">Sólo para «Lista». Separadas con <code>|</code></span>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="n-categoria">Categoría</label>
          <input type="text" id="n-categoria" name="categoria" class="ih-input" maxlength="100"
                 placeholder="(todas)">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="n-subcategoria">Subcategoría</label>
          <input type="text" id="n-subcategoria" name="subcategoria" class="ih-input" maxlength="100"
                 placeholder="(todas)">
        </div>
        <div class="ih-field ih-field--al-pie">
          <button type="submit" class="ih-btn ih-btn--accent ih-btn--block">
            <i class="bi bi-plus-lg"></i> Agregar campo
          </button>
        </div>
      </form>

      <?php if (!$defs): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-sliders"></i></div>
          <div class="ih-empty__title">Todavía no hay campos definidos</div>
        </div>
      <?php else: ?>
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead>
              <tr>
                <th>Campo</th>
                <th class="ih-table__angosta">Tipo</th>
                <th>Se muestra en</th>
                <th class="ih-table__angosta">En uso</th>
                <th class="ih-table__acciones">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($defs as $d):
              $heredado = (string)($d['columna_legacy'] ?? '') !== '';
              $usos     = campo_definicion_uso($conn, $d);
              $activo   = (int)$d['activo'] === 1;
              $ambito   = trim((string)($d['categoria'] ?? '') . ' › ' . (string)($d['subcategoria'] ?? ''), ' ›');
            ?>
              <tr<?= $activo ? '' : ' class="es-inactiva"' ?>>
                <td>
                  <strong><?= htmlspecialchars((string)$d['etiqueta'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <div class="ih-text-sm ih-text-muted"><code><?= htmlspecialchars((string)$d['clave']) ?></code></div>
                  <?php if ($heredado): ?>
                    <span class="ih-badge ih-badge--info" title="Guarda en la columna <?= htmlspecialchars((string)$d['columna_legacy']) ?> de productos">
                      columna propia
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= htmlspecialchars(CAMPO_TIPOS[(string)$d['tipo']] ?? (string)$d['tipo']) ?>
                  <?php if ($d['opciones']): ?>
                    <div class="ih-text-sm ih-text-muted"><?= htmlspecialchars(implode(' · ', $d['opciones'])) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= $ambito !== '' ? htmlspecialchars($ambito) : '<span class="ih-text-muted">Todos los activos</span>' ?></td>
                <td>
                  <?php if ($usos > 0): ?>
                    <span class="ih-badge ih-badge--info"><?= $usos ?></span>
                  <?php else: ?>
                    <span class="ih-text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="ih-flex ih-gap-2">
                    <form method="post" action="/backend/campos_crud.php">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="accion" value="<?= $activo ? 'desactivar' : 'activar' ?>">
                      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                      <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm">
                        <i class="bi bi-<?= $activo ? 'eye-slash' : 'eye' ?>"></i>
                        <?= $activo ? 'Ocultar' : 'Mostrar' ?>
                      </button>
                    </form>
                    <?php if (!$heredado): ?>
                      <form method="post" action="/backend/campos_crud.php"
                            onsubmit="return confirm('¿Eliminar «<?= htmlspecialchars(addslashes((string)$d['etiqueta'])) ?>» definitivamente?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" <?= $usos > 0 ? 'disabled title="Hay activos con este campo cargado"' : '' ?>>
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="ih-alert ih-alert--info ih-mt-4">
        <i class="bi bi-info-circle-fill"></i>
        <div>
          Los campos marcados como <strong>columna propia</strong> son los que ya existían antes de
          que esto fuera configurable. Siguen guardándose en su columna de la tabla de activos
          —por eso las exportaciones y el respaldo no cambiaron— y no se pueden eliminar desde acá.
        </div>
      </div>

    </div>
  </div>

</main>
</div>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
