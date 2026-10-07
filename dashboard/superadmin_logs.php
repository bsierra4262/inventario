<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';

superadmin_require_login();
if (!platform_can('logs.view')) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}

/* Antes acá se cargaba el archivo ENTERO en memoria, se parseaba completo y
   recién después se tomaban los últimos quinientos. El costo crecía con el
   tamaño del log y no con lo que se mira: con mil quinientos renglones la
   pantalla ya devolvía 522 KB de HTML, y el log sólo crece porque es el rastro
   de qué pasó. Ahora se lee desde el final y se corta apenas hay suficiente:
   abrir la primera página cuesta lo mismo con mil renglones que con un millón.
   Ver core/plataforma/auditoria.php. */
require_once __DIR__ . '/../core/plataforma/auditoria.php';

$logFile    = LOG_PATH;
$parseError = is_file($logFile)
    ? ''
    : 'El archivo de auditoría aún no existe. Se creará con el primer evento registrado.';

$filtros = [
    'tag'   => trim((string)($_GET['tag']   ?? '')),
    'tipo'  => trim((string)($_GET['tipo']  ?? '')),
    'fecha' => trim((string)($_GET['date']  ?? '')),
    'q'     => trim((string)($_GET['q']     ?? '')),
];
$hayFiltro = implode('', $filtros) !== '';

$porPaginaOpts = [25, 50, 100, 200];
$porPagina = in_array((int)($_GET['per_page'] ?? 0), $porPaginaOpts, true) ? (int)$_GET['per_page'] : 50;
$pagina    = max(1, (int)($_GET['page'] ?? 1));

$lectura  = auditoria_leer($logFile, $filtros, ($pagina - 1) * $porPagina, $porPagina);
$filtered = $lectura['filas'];

/* Los desplegables se llenan con lo que hay en lo reciente: un tipo de evento
   que no aparece hace cinco mil renglones tampoco se va a querer filtrar, y
   recorrer el archivo entero para armar un <select> es justo el costo que se
   sacó. */
$valores  = auditoria_valores($logFile);
$allTags  = $valores['tags'];
$allTypes = $valores['tipos'];

$filterTag  = $filtros['tag'];
$filterTipo = $filtros['tipo'];
$filterDate = $filtros['fecha'];
$filterQ    = $filtros['q'];

/** De qué color va cada evento. Vive en el módulo: lo comparte el resumen. */
function logTypeBadge(string $tipo): string { return auditoria_insignia($tipo); }

function logTagBadge(string $tag): string {
    return match($tag) {
        'auth'        => 'ih-badge--purple',
        'archivo'     => 'ih-badge--info',
        'instalacion' => 'ih-badge--warning',
        'bootstrap'   => 'ih-badge--warning',
        default       => 'ih-badge--neutral',
    };
}

$page_title      = 'Auditoría';
$page_section    = 'logs';
$topbar_title    = 'Auditoría del sistema';
$topbar_subtitle = $hayFiltro ? 'Filtrando los eventos' : 'Lo último que pasó en la plataforma';
$topbar_user     = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email    = $_SESSION['superadmin_email'] ?? '';
$topbar_actions  = '';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($parseError): ?>
      <div class="ih-alert ih-alert--info">
        <i class="bi bi-info-circle-fill"></i>
        <div><?= htmlspecialchars($parseError) ?></div>
      </div>
    <?php endif; ?>

    <?php /* Filtros y tabla, dos tarjetas pegadas que se leen como una, con el
             paginador en el pie. Es la misma estructura que el listado de
             activos y que Reportes: una pantalla de tabla se arma igual en todo
             el sistema. */ ?>
    <div class="ih-card ih-pegada-arriba">
      <div class="ih-card__body ih-card__body--compacto">
        <form method="get" class="ih-filtros" id="form-logs">
          <div>
            <label class="ih-label">Buscar texto</label>
            <input class="ih-input" name="q" value="<?= htmlspecialchars($filterQ) ?>"
                   placeholder="Ej.: eliminado, bsierra, Activo ID 4">
          </div>
          <div>
            <label class="ih-label">Tipo</label>
            <select class="ih-select" name="tipo">
              <option value="">Todos</option>
              <?php foreach ($allTypes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTipo === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ih-label">Tag</label>
            <select class="ih-select" name="tag">
              <option value="">Todos</option>
              <?php foreach ($allTags as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTag === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ih-label">Fecha</label>
            <input class="ih-input" type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" placeholder="dd/mm/aaaa">
          </div>
          <div>
            <button class="ih-btn ih-btn--primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
            <?php if ($hayFiltro): ?>
              <a href="/dashboard/superadmin_logs.php" class="ih-btn ih-btn--ghost"><i class="bi bi-x-lg"></i> Limpiar</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="ih-card ih-pegada-abajo">
      <div class="ih-card__body ih-card__body--tabla">
      <?php if (empty($filtered)): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-journal-x"></i></div>
          <div class="ih-empty__title"><?= $hayFiltro ? 'Ningún evento coincide' : 'Todavía no hay eventos' ?></div>
          <div class="ih-empty__text">
            <?= $hayFiltro
                ? 'Probá con menos filtros, o con un rango de fechas más amplio.'
                : 'Acá va quedando lo que pasa en la plataforma: quién entra, qué se crea y qué se borra.' ?>
          </div>
        </div>
      <?php else: ?>
        <div class="ih-table-wrap">
          <table class="ih-table" id="tabla-logs">
            <thead>
              <tr>
                <th class="ih-table__nowrap">Cuándo</th>
                <th>Qué pasó</th>
                <th>Origen</th>
                <th>Tag</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($filtered as $r): ?>
                <tr>
                  <td class="ih-table__nowrap">
                    <div class="ih-celda__texto">
                      <div class="ih-celda__nombre"><?= htmlspecialchars($r['hora']) ?></div>
                      <div class="ih-celda__sub"><?= htmlspecialchars($r['fecha']) ?></div>
                    </div>
                  </td>
                  <td>
                    <div class="ih-celda__texto">
                      <div class="ih-celda__nombre">
                        <span class="ih-badge <?= logTypeBadge($r['tipo']) ?>"><?= htmlspecialchars($r['tipo']) ?></span>
                      </div>
                      <div class="ih-celda__sub" title="<?= htmlspecialchars($r['mensaje'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['mensaje']) ?></div>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($r['origen']) ?></td>
                  <td><span class="ih-badge <?= logTagBadge($r['tag']) ?>"><?= htmlspecialchars($r['tag']) ?></span></td>
                  <td class="ih-text-xs ih-text-muted"><?= htmlspecialchars($r['ip']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      </div>

      <?php if (!empty($filtered)): ?>
      <div class="ih-card__footer">
        <div class="ih-pager">
          <?php /* Sin número de página total: saberlo exigiría recorrer el
                   archivo entero, que es justo lo que se dejó de hacer.
                   «Más nuevos» y «más viejos» alcanzan para un historial, y no
                   inventan un total que nadie calculó. */ ?>
          <form method="get" id="form-per-page-logs" class="ih-pager__info">
            <?php foreach (['q' => $filterQ, 'tipo' => $filterTipo, 'tag' => $filterTag, 'date' => $filterDate] as $k => $v): ?>
              <?php if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            Página <?= $pagina ?> &middot; Mostrar:
            <select name="per_page" class="ih-select" onchange="document.getElementById('form-per-page-logs').submit()">
              <?php foreach ($porPaginaOpts as $op): ?>
                <option value="<?= $op ?>" <?= $porPagina === $op ? 'selected' : '' ?>><?= $op ?></option>
              <?php endforeach; ?>
            </select>
            por página
          </form>
          <?php
            $qs = function (int $pg) use ($filtros, $porPagina): string {
                $partes = [
                    'q' => $filtros['q'], 'tipo' => $filtros['tipo'], 'tag' => $filtros['tag'],
                    'date' => $filtros['fecha'], 'per_page' => (string)$porPagina, 'page' => (string)$pg,
                ];
                return '?' . http_build_query(array_filter($partes, fn($v) => $v !== ''));
            };
          ?>
          <div class="ih-pager__botones">
            <a class="ih-btn ih-btn--ghost ih-btn--sm<?= $pagina <= 1 ? ' is-disabled' : '' ?>"
               href="<?= $pagina <= 1 ? '#' : htmlspecialchars($qs($pagina - 1)) ?>"
               <?= $pagina <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
              <i class="bi bi-chevron-left"></i> Más nuevos
            </a>
            <a class="ih-btn ih-btn--ghost ih-btn--sm<?= $lectura['hay_mas'] ? '' : ' is-disabled' ?>"
               href="<?= $lectura['hay_mas'] ? htmlspecialchars($qs($pagina + 1)) : '#' ?>"
               <?= $lectura['hay_mas'] ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
              Más viejos <i class="bi bi-chevron-right"></i>
            </a>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
