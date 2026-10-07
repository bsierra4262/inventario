<?php
/**
 * Papelera — activos archivados, con opción de restaurar.
 *
 * Antes archivar era un camino de ida: el activo desaparecía del listado y la
 * única forma de recuperarlo era editarlo a mano y destildar `archivado`. Acá
 * están todos los archivados, con quién los archivó, cuándo y por qué, y un
 * botón para devolverlos al inventario (también mueve la carpeta de Drive de
 * vuelta desde «Archivados»).
 *
 * Y, para quien tenga el permiso, el otro extremo: eliminarlos de verdad. Esa
 * mitad faltaba — `asset.delete` se podía tildar en la pantalla de roles y no
 * hacía nada, así que la papelera se llenaba y nunca se vaciaba. Lo hace
 * backend/eliminar_definitivo.php, pidiendo que se escriba el número del
 * activo: un sí/no se acepta sin leerlo.
 *
 * Permisos: asset.trash.view para ver, asset.restore para restaurar,
 * asset.delete para eliminar.
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
require_once __DIR__ . '/../includes/cloud_icons.php';
require_once __DIR__ . '/../includes/volver.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

if (empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$usersTable = tn('users');
$stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { session_destroy(); header('Location: /auth/login.php'); exit; }

require_permission('asset.trash.view', $user);
$puedeRestaurar = user_can('asset.restore', $user);
$puedeBorrar    = user_can('asset.delete', $user);

// Nombre de la empresa
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst  = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = $crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

// ── Búsqueda y paginación ────────────────────────────────────────────────────
$q           = trim((string)($_GET['q'] ?? ''));
$perPageOpts = [20, 50, 100];
$perPage     = in_array((int)($_GET['per_page'] ?? 0), $perPageOpts, true) ? (int)$_GET['per_page'] : 20;
$page        = max(1, (int)($_GET['page'] ?? 1));
$tProd       = tn('productos');

$where  = ['archivado = 1'];
$params = [];
$types  = '';
if ($q !== '') {
    $where[] = '(modelo LIKE ? OR marca LIKE ? OR servis_tag LIKE ? OR asignado_a LIKE ? OR archivado_motivo LIKE ?)';
    for ($i = 0; $i < 5; $i++) { $params[] = '%' . $q . '%'; $types .= 's'; }
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = 0;
try {
    $sqlC = "SELECT COUNT(*) t FROM `{$tProd}` {$whereSql}";
    if ($types !== '') {
        $c = $conn->prepare($sqlC);
        $c->bind_param($types, ...$params);
        $c->execute();
        $total = (int)$c->get_result()->fetch_assoc()['t'];
        $c->close();
    } else {
        $total = (int)$conn->query($sqlC)->fetch_assoc()['t'];
    }
} catch (Throwable $e) {
    log_event('inventario/archivados', 'ERROR', $e->getMessage());
}

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$filas = [];
try {
    $sqlL = "SELECT id, modelo, marca, servis_tag, categoria, asignado_a, ubicacion,
                    valor_usd, cantidad, fecha_baja, archivado_en, archivado_por,
                    archivado_motivo, carpeta_raiz_drive
               FROM `{$tProd}` {$whereSql}
              ORDER BY COALESCE(archivado_en, fecha_baja) DESC, id DESC
              LIMIT {$perPage} OFFSET {$offset}";
    if ($types !== '') {
        $l = $conn->prepare($sqlL);
        $l->bind_param($types, ...$params);
        $l->execute();
        $rs = $l->get_result();
    } else {
        $rs = $conn->query($sqlL);
    }
    while ($rs && ($r = $rs->fetch_assoc())) { $filas[] = $r; }
} catch (Throwable $e) {
    log_event('inventario/archivados', 'ERROR', $e->getMessage());
}

$okMsg  = (string)($_GET['ok']  ?? '');
$errMsg = (string)($_GET['err'] ?? '');

$page_title      = 'Papelera';
$page_section    = 'archivados';
$topbar_title    = 'Papelera';
$topbar_subtitle = $total . ' activo' . ($total === 1 ? '' : 's') . ' archivado' . ($total === 1 ? '' : 's');
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($okMsg): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i><div><?= htmlspecialchars($okMsg) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($errMsg): ?>
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($errMsg) ?></div>
      </div>
    <?php endif; ?>

    <div class="ih-alert ih-alert--info">
      <i class="bi bi-info-circle-fill"></i>
      <div>
        Archivar no borra: el activo sale del listado principal y de los totales,
        pero conserva su historial y sus archivos. Restaurarlo lo devuelve al
        inventario y trae su carpeta de Drive de vuelta desde «Archivados».
        <?php if ($puedeBorrar): ?>
          Eliminar, en cambio, sí borra el activo — y eso no se puede deshacer.
          Queda el historial y queda la carpeta de Drive; el activo no.
        <?php endif; ?>
      </div>
    </div>

    <div class="ih-card">
      <div class="ih-card__header">
        <h2 class="ih-card__title">Activos archivados</h2>
        <?php /* El buscador del encabezado es el del sistema (.ih-buscador):
                 conserva el ancho que necesita un texto de verdad y en pantalla
                 angosta pasa a ocupar la fila completa. */ ?>
        <form method="get" class="ih-buscador" role="search">
          <label class="ih-sr-only" for="q">Buscar en la papelera</label>
          <input type="search" id="q" name="q" class="ih-input" value="<?= htmlspecialchars($q) ?>"
                 placeholder="Ej.: Latitude 5420, Dell, 7KJ2M13, «se quemó la fuente»">
          <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
          <button class="ih-btn ih-btn--ghost ih-btn--sm" type="submit">
            <i class="bi bi-search"></i><span class="ih-sr-only">Buscar</span>
          </button>
          <?php if ($q !== ''): ?>
            <a class="ih-btn ih-btn--ghost ih-btn--sm" href="/inventario/archivados.php">Limpiar</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="ih-card__body <?= $filas ? 'ih-card__body--tabla' : '' ?>">
        <?php if (!$filas): ?>
          <div class="ih-empty">
            <div class="ih-empty__icon"><i class="bi bi-archive"></i></div>
            <div class="ih-empty__title"><?= $q !== '' ? 'Sin resultados' : 'La papelera está vacía' ?></div>
            <p class="ih-empty__text">
              <?= $q !== ''
                  ? 'Ningún activo archivado coincide con esa búsqueda.'
                  : 'Cuando archives un activo desde el inventario va a aparecer acá, y desde acá lo podés devolver al inventario.' ?>
            </p>
          </div>
        <?php else: ?>
          <div class="ih-table-wrap">
            <table class="ih-table">
              <thead>
                <tr>
                  <th>Activo</th>
                  <th>Service tag</th>
                  <th>Archivado</th>
                  <th>Motivo</th>
                  <th class="ih-table__acciones">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($filas as $f):
                    $nombre = trim((string)($f['modelo'] ?? ''));
                    if (!empty($f['marca'])) { $nombre .= ' — ' . $f['marca']; }
                    if ($nombre === '') { $nombre = 'Activo #' . (int)$f['id']; }
                    $cuando = $f['archivado_en'] ?: $f['fecha_baja'];
              ?>
                <tr>
                  <td>
                    <div class="ih-text-semibold"><?= htmlspecialchars($nombre) ?></div>
                    <div class="ih-celda__sub">
                      #<?= (int)$f['id'] ?>
                      <?php if (!empty($f['categoria'])): ?> · <?= htmlspecialchars($f['categoria']) ?><?php endif; ?>
                      <?php if (!empty($f['asignado_a'])): ?> · <?= htmlspecialchars($f['asignado_a']) ?><?php endif; ?>
                    </div>
                  </td>
                  <td><?= $f['servis_tag'] ? '<code>' . htmlspecialchars($f['servis_tag']) . '</code>' : '<span class="ih-text-muted">—</span>' ?></td>
                  <td>
                    <div class="ih-text-sm"><?= $cuando ? htmlspecialchars(date('d/m/Y', strtotime((string)$cuando))) : '—' ?></div>
                    <?php if (!empty($f['archivado_por'])): ?>
                      <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars($f['archivado_por']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= !empty($f['archivado_motivo'])
                        ? '<span class="ih-badge ih-badge--warning">' . htmlspecialchars($f['archivado_motivo']) . '</span>'
                        : '<span class="ih-text-muted">—</span>' ?>
                  </td>
                  <td class="ih-table__acciones">
                    <div class="ih-acciones">
                      <a class="ih-btn ih-btn--ghost ih-btn--sm" title="Ver el detalle"
                         href="/backend/view_details.php?id=<?= (int)$f['id'] ?>&amp;<?= volver_aqui() ?>">
                        <i class="bi bi-eye"></i>
                        <span class="ih-sr-only">Ver el detalle de <?= htmlspecialchars($nombre) ?></span>
                      </a>
                      <?php if ($puedeRestaurar): ?>
                        <form method="post" action="/backend/restaurar.php"
                              onsubmit="return confirm('¿Restaurar «<?= htmlspecialchars(addslashes($nombre)) ?>» al inventario?')">
                          <?php csrfInput('restaurar_activo'); ?>
                          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                          <button type="submit" class="ih-btn ih-btn--accent ih-btn--sm">
                            <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                          </button>
                        </form>
                      <?php endif; ?>
                      <?php if ($puedeBorrar): ?>
                        <?php /* Eliminar no se puede deshacer, así que no alcanza con un
                                 sí/no: el formulario se envía recién cuando la persona
                                 escribe el número del activo. Lo pide el diálogo del
                                 sistema (ihPedirTexto con `esperado`), y el endpoint lo
                                 vuelve a verificar del lado del servidor, por si alguien
                                 postea a mano. */ ?>
                        <form method="post" action="/backend/eliminar_definitivo.php"
                              data-eliminar="<?= (int)$f['id'] ?>"
                              data-nombre="<?= htmlspecialchars($nombre) ?>">
                          <?php csrfInput('eliminar_activo'); ?>
                          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                          <input type="hidden" name="confirmacion" value="">
                          <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm"
                                  title="Eliminar definitivamente">
                            <i class="bi bi-trash3"></i>
                            <span class="ih-sr-only">Eliminar definitivamente <?= htmlspecialchars($nombre) ?></span>
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
      </div>

    <?php if ($filas): ?>
      <?php /* ── Pie de tabla ──────────────────────────────────────────────
               El paginador es el del sistema (.ih-pager), el mismo de Activos y
               de Reportes. Acá antes se dibujaba un botón por página: con cien
               activos archivados eran cinco botones, con tres mil eran ciento
               cincuenta y la tarjeta se desarmaba. */ ?>
      <div class="ih-card__footer">
        <div class="ih-pager">
          <div class="ih-pager__info">
            <span>
              Mostrando <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?></strong>
              de <strong><?= number_format($total) ?></strong> <?= $total === 1 ? 'activo archivado' : 'activos archivados' ?>
            </span>
            <label class="ih-sr-only" for="per-page">Activos por página</label>
            <select class="ih-select" id="per-page"
                    onchange="window.location='?<?= http_build_query(['q' => $q, 'page' => 1]) ?>&per_page='+this.value">
              <?php foreach ($perPageOpts as $opt): ?>
                <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?> por página</option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($totalPages > 1):
            $pLink  = fn(int $pg): string => '?' . http_build_query(['q' => $q, 'per_page' => $perPage, 'page' => $pg]);
            $pStart = max(1, $page - 2);
            $pEnd   = min($totalPages, $page + 2);
          ?>
          <nav aria-label="Páginas de la papelera">
            <ul class="ih-pager__paginas">
              <li class="ih-pager__item <?= $page <= 1 ? 'is-disabled' : '' ?>">
                <a class="ih-pager__link" href="<?= $pLink(1) ?>" aria-label="Primera página"><i class="bi bi-chevron-double-left"></i></a>
              </li>
              <li class="ih-pager__item <?= $page <= 1 ? 'is-disabled' : '' ?>">
                <a class="ih-pager__link" href="<?= $pLink($page - 1) ?>" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></a>
              </li>
              <?php for ($i = $pStart; $i <= $pEnd; $i++): ?>
                <li class="ih-pager__item <?= $i === $page ? 'is-active' : '' ?>">
                  <a class="ih-pager__link" href="<?= $pLink($i) ?>"
                     <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="ih-pager__item <?= $page >= $totalPages ? 'is-disabled' : '' ?>">
                <a class="ih-pager__link" href="<?= $pLink($page + 1) ?>" aria-label="Página siguiente"><i class="bi bi-chevron-right"></i></a>
              </li>
              <li class="ih-pager__item <?= $page >= $totalPages ? 'is-disabled' : '' ?>">
                <a class="ih-pager__link" href="<?= $pLink($totalPages) ?>" aria-label="Última página"><i class="bi bi-chevron-double-right"></i></a>
              </li>
            </ul>
          </nav>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    </div>

  </main>
</div><!-- /ih-app -->

<?php if ($puedeBorrar): ?>
<script>
/* ── La confirmación escrita para eliminar ───────────────────────────────────
   Eliminar un activo de la papelera no se deshace, así que no alcanza con un
   sí/no: hay que escribir el número del activo. No es burocracia — es que un
   «¿está seguro?» se acepta sin leerlo, y copiar un número obliga a mirar cuál
   es el que se está borrando.

   El servidor lo vuelve a verificar: si alguien postea el formulario a mano sin
   pasar por acá, backend/eliminar_definitivo.php lo rechaza igual. Esto es la
   cortesía, no la seguridad.

   Si el JavaScript no corre, el botón envía el formulario con la confirmación
   vacía y el servidor contesta que falta escribir el número. Feo, pero nunca
   borra algo sin confirmar. */
(function () {
  document.querySelectorAll('form[data-eliminar]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.confirmado === '1') { return; }   // ya se confirmó: dejalo pasar
      ev.preventDefault();

      var id     = form.dataset.eliminar;
      var nombre = form.dataset.nombre || ('el activo #' + id);

      window.ihPedirTexto({
        titulo:   'Eliminar «' + nombre + '» definitivamente',
        detalle:  'Esto borra el activo, su sistema operativo, sus componentes, su red, '
                + 'sus notas y sus campos propios. No se puede deshacer. Queda el historial '
                + 'y queda la carpeta de Drive; el activo no. '
                + 'Si solo querés sacarlo del inventario, ya está archivado: dejalo así.',
        etiqueta: 'Escribí el número del activo (' + id + ') para confirmar',
        esperado: id,
        aceptar:  'Eliminar definitivamente',
        cancelar: 'No eliminar',
        peligro:  true,
        max:      12
      }).then(function (escrito) {
        if (escrito === null) { return; }                // canceló
        form.elements['confirmacion'].value = escrito;
        form.dataset.confirmado = '1';
        form.submit();
      });
    });
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
