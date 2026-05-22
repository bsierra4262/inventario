<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';

superadmin_require_login();
if (!platform_can('logs.view')) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}

$logFile    = LOG_PATH;
$rows       = [];
$parseError = '';

if (is_file($logFile)) {
    $handle = @fopen($logFile, 'r');
    if ($handle) {
        fgetcsv($handle); // skip header line
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) >= 7) {
                $rows[] = [
                    'fecha'   => $line[0],
                    'hora'    => $line[1],
                    'origen'  => $line[2],
                    'tipo'    => $line[3],
                    'mensaje' => $line[4],
                    'tag'     => $line[5],
                    'ip'      => $line[6],
                ];
            }
        }
        fclose($handle);
        $rows = array_reverse($rows); // más reciente primero
    } else {
        $parseError = 'No se pudo abrir el archivo de auditoría.';
    }
} else {
    $parseError = 'El archivo de auditoría aún no existe. Se creará con el primer evento registrado.';
}

$allTags  = array_values(array_unique(array_column($rows, 'tag')));
$allTypes = array_values(array_unique(array_column($rows, 'tipo')));
sort($allTags);
sort($allTypes);

$filterTag  = trim($_GET['tag']  ?? '');
$filterTipo = trim($_GET['tipo'] ?? '');
$filterDate = trim($_GET['date'] ?? '');
$filterQ    = trim($_GET['q']    ?? '');

$filtered = $rows;
if ($filterTag  !== '') $filtered = array_filter($filtered, fn($r) => $r['tag']  === $filterTag);
if ($filterTipo !== '') $filtered = array_filter($filtered, fn($r) => $r['tipo'] === $filterTipo);
if ($filterDate !== '') $filtered = array_filter($filtered, fn($r) => $r['fecha'] === $filterDate);
if ($filterQ    !== '') $filtered = array_filter($filtered, fn($r) =>
    stripos($r['mensaje'] . ' ' . $r['origen'], $filterQ) !== false
);

$filtered   = array_values($filtered);
$totalMatch = count($filtered);
$filtered   = array_slice($filtered, 0, 500);

function logTypeBadge(string $tipo): string {
    $t = strtoupper($tipo);
    if (str_contains($t, 'ERROR'))                              return 'ih-badge--danger';
    if (str_contains($t, 'FALLIDO'))                            return 'ih-badge--warning';
    if (str_contains($t, 'LOGIN') && !str_contains($t, 'FALLIDO')) return 'ih-badge--success';
    if (str_contains($t, 'ARCHIVO'))                            return 'ih-badge--info';
    if (str_contains($t, 'CAMBIO') || str_contains($t, 'INSTALACION')) return 'ih-badge--purple';
    return '';
}

function logTagBadge(string $tag): string {
    return match($tag) {
        'auth'        => 'ih-badge--purple',
        'archivo'     => 'ih-badge--info',
        'instalacion' => 'ih-badge--warning',
        'bootstrap'   => 'ih-badge--warning',
        default       => '',
    };
}

$page_title      = 'Auditoría';
$page_section    = 'logs';
$topbar_title    = 'Auditoría del sistema';
$topbar_subtitle = count($rows) . ' eventos registrados';
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

    <!-- Filtros -->
    <div class="ih-card ih-mb-4">
      <div class="ih-card__body" style="padding-top:1rem;padding-bottom:1rem;">
        <form method="get" class="ih-flex ih-gap-2" style="flex-wrap:wrap;align-items:flex-end;">
          <div class="ih-field" style="flex:1;min-width:160px;margin-bottom:0">
            <label class="ih-field__label" style="font-size:11px">Buscar texto</label>
            <input class="ih-input" name="q" placeholder="Mensaje u origen…" value="<?= htmlspecialchars($filterQ) ?>">
          </div>
          <div class="ih-field" style="min-width:130px;margin-bottom:0">
            <label class="ih-field__label" style="font-size:11px">Tag</label>
            <select class="ih-select" name="tag">
              <option value="">Todos</option>
              <?php foreach ($allTags as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTag === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field" style="min-width:160px;margin-bottom:0">
            <label class="ih-field__label" style="font-size:11px">Tipo</label>
            <select class="ih-select" name="tipo">
              <option value="">Todos</option>
              <?php foreach ($allTypes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTipo === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field" style="min-width:140px;margin-bottom:0">
            <label class="ih-field__label" style="font-size:11px">Fecha</label>
            <input class="ih-input" type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
          </div>
          <button class="ih-btn ih-btn--primary ih-btn--sm" type="submit" style="align-self:flex-end;margin-bottom:0">
            <i class="bi bi-funnel"></i> Filtrar
          </button>
          <?php if ($filterTag !== '' || $filterTipo !== '' || $filterDate !== '' || $filterQ !== ''): ?>
            <a href="/dashboard/superadmin_logs.php" class="ih-btn ih-btn--ghost ih-btn--sm" style="align-self:flex-end;margin-bottom:0">
              <i class="bi bi-x"></i> Limpiar
            </a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Tabla de eventos -->
    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-list-ul"></i> Eventos de auditoría</h2>
          <div class="ih-card__subtitle">
            <?php if ($filterTag !== '' || $filterTipo !== '' || $filterDate !== '' || $filterQ !== ''): ?>
              <?= $totalMatch ?> resultados (mostrando <?= count($filtered) ?>)
            <?php else: ?>
              Últimos <?= count($filtered) ?> de <?= count($rows) ?> eventos
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (empty($filtered) && !$parseError): ?>
        <div class="ih-empty">
          <div class="ih-empty__icon"><i class="bi bi-journal-x"></i></div>
          <div class="ih-empty__title">Sin resultados</div>
          <div class="ih-empty__text">No hay eventos que coincidan con los filtros.</div>
        </div>
      <?php elseif (!empty($filtered)): ?>
        <div class="ih-table-wrap">
          <table class="ih-table" style="font-size:13px;">
            <thead>
              <tr>
                <th style="width:130px">Fecha / Hora</th>
                <th>Origen</th>
                <th style="width:170px">Tipo</th>
                <th>Mensaje</th>
                <th style="width:100px">Tag</th>
                <th style="width:110px">IP</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($filtered as $r): ?>
                <tr>
                  <td class="ih-text-xs ih-text-muted" style="white-space:nowrap">
                    <?= htmlspecialchars($r['fecha']) ?><br>
                    <strong><?= htmlspecialchars($r['hora']) ?></strong>
                  </td>
                  <td class="ih-text-sm" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($r['origen']) ?>
                  </td>
                  <td>
                    <span class="ih-badge <?= logTypeBadge($r['tipo']) ?>" style="white-space:nowrap">
                      <?= htmlspecialchars($r['tipo']) ?>
                    </span>
                  </td>
                  <td class="ih-text-sm" style="max-width:360px;word-break:break-word">
                    <?= htmlspecialchars($r['mensaje']) ?>
                  </td>
                  <td>
                    <span class="ih-badge <?= logTagBadge($r['tag']) ?>" style="font-size:10px">
                      <?= htmlspecialchars($r['tag']) ?>
                    </span>
                  </td>
                  <td class="ih-text-xs ih-text-muted"><?= htmlspecialchars($r['ip']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalMatch > 500): ?>
          <div class="ih-text-sm ih-text-muted" style="padding:12px 20px;">
            <i class="bi bi-info-circle"></i> Se muestran los 500 resultados más recientes. Usá los filtros para acotar la búsqueda.
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
