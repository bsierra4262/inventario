<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Router por rol: superadmin no entra al inventario tenant
require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }
require_once __DIR__ . '/../includes/cloud_icons.php';
require_once __DIR__ . '/../core/security/permissions.php';
$iconMap = require __DIR__ . '/../includes/iconMap.php';

try {
    $conn->set_charset('utf8mb4');

    if (empty($_SESSION['user_id'])) {
        $cid_empty = (int)($_SESSION['company_id'] ?? 0);
        log_event('index.php', 'AUTH_FAIL', 'user_id vacío en sesión', 'auth');
        $redir = '/auth/login.php';
        if ($cid_empty > 0) $redir .= '?company=' . $cid_empty;
        header('Location: ' . $redir);
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
        $cid_notfound = (int)($_SESSION['company_id'] ?? 0);
        log_event('index.php', 'AUTH_FAIL', "user_id={$user_id} no encontrado en tabla={$usersTable}", 'auth');
        session_destroy();
        $redir = '/auth/login.php?err=' . urlencode('Tu sesión expiró o tu usuario fue eliminado. Volvé a iniciar sesión.');
        if ($cid_notfound > 0) $redir .= '&company=' . $cid_notfound;
        header('Location: ' . $redir);
        exit();
    }

    // ── Nombre de la empresa (desde master DB) ─────────────────────────────
    $tenant_name = 'Mi empresa';
    try {
        require_once __DIR__ . '/../core/db/master_connection.php';
        $masterDb = new MasterDB();
        $companyId = (int)($_SESSION['company_id'] ?? 0);
        if ($companyId > 0) {
            $cstmt = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
            $crow = $cstmt->get_result()->fetch_assoc();
            if ($crow) $tenant_name = $crow['nombre'];
            $cstmt->close();
        }
    } catch (Throwable) {}

    // ── Parámetros de búsqueda y paginación ────────────────────────────────
    $search  = trim($_GET['q']     ?? '');
    $filterCat = trim($_GET['cat'] ?? '');
    $filterGar = trim($_GET['gar'] ?? '');  // vigente|por-caducar|caducado|no-aplica
    $sort    = $_GET['sort']  ?? 'id';
    $order   = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';

    $sortable = [
        'id' => 'id', 'modelo' => 'modelo', 'marca' => 'marca',
        'asignado_a' => 'asignado_a', 'categoria' => 'categoria',
        'estado' => 'estado', 'valor_usd' => 'valor_usd',
        'fecha_garantia' => 'fecha_garantia', 'fecha_ingreso' => 'fecha_ingreso',
        'ubicacion' => 'ubicacion',
    ];
    if (!isset($sortable[$sort])) $sort = 'id';
    $sort_sql = $sortable[$sort];

    $perPageOpts = [20, 50, 100];
    $per_page = in_array((int)($_GET['per_page'] ?? 0), $perPageOpts, true) ? (int)$_GET['per_page'] : 20;
    $page     = max(1, (int)($_GET['page'] ?? 1));

    $productosTable = tn('productos');

    // ── Construir WHERE dinámico ───────────────────────────────────────────
    $where  = ['p.archivado = 0'];
    $params = [];
    $types  = '';

    if ($search !== '') {
        $like = '%' . $conn->real_escape_string($search) . '%';
        $where[] = "(p.modelo LIKE ? OR p.marca LIKE ? OR p.asignado_a LIKE ? OR p.servis_tag LIKE ? OR p.descripcion LIKE ? OR p.ubicacion LIKE ?)";
        for ($i = 0; $i < 6; $i++) { $params[] = '%' . $search . '%'; $types .= 's'; }
    }
    if ($filterCat !== '') {
        $where[] = 'p.categoria = ?';
        $params[] = $filterCat;
        $types   .= 's';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // ── KPIs ──────────────────────────────────────────────────────────────
    $kpi_total = $kpi_valor = $kpi_archivados = $kpi_sin_asignar = $kpi_garantia_30d = 0;
    try {
        $r = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(valor_usd * cantidad),0) AS v FROM `{$productosTable}` WHERE archivado = 0");
        if ($r) { $row0 = $r->fetch_assoc(); $kpi_total = (int)$row0['c']; $kpi_valor = (float)$row0['v']; }

        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$productosTable}` WHERE archivado = 1");
        if ($r) $kpi_archivados = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$productosTable}` WHERE archivado = 0 AND (asignado_a IS NULL OR asignado_a = '')");
        if ($r) $kpi_sin_asignar = (int)$r->fetch_assoc()['c'];

        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$productosTable}` WHERE archivado = 0 AND fecha_garantia IS NOT NULL AND fecha_garantia >= CURDATE() AND DATE_ADD(fecha_garantia, INTERVAL periodo_garantia MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        if ($r) $kpi_garantia_30d = (int)$r->fetch_assoc()['c'];
    } catch (Throwable $eKpi) { log_event('inventario/index', 'WARN', $eKpi->getMessage()); }

    // ── Count con filtros ─────────────────────────────────────────────────
    $countSql = "SELECT COUNT(*) AS total FROM `{$productosTable}` p {$whereSql}";
    if ($types !== '') {
        $cstmt2 = $conn->prepare($countSql);
        $cstmt2->bind_param($types, ...$params);
        $cstmt2->execute();
        $totalRows = (int)$cstmt2->get_result()->fetch_assoc()['total'];
        $cstmt2->close();
    } else {
        $totalRows = (int)$conn->query($countSql)->fetch_assoc()['total'];
    }

    $totalPages = max(1, (int)ceil($totalRows / $per_page));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $per_page;

    // ── Fetch activos ─────────────────────────────────────────────────────
    $listSql = "SELECT p.id, p.modelo, p.marca, p.asignado_a, p.categoria, p.subcategoria,
                       p.estado, p.valor_usd, p.cantidad, p.ubicacion, p.servis_tag,
                       p.periodo_garantia, p.fecha_garantia, p.carpeta_raiz_drive
                FROM `{$productosTable}` p
                {$whereSql}
                ORDER BY {$sort_sql} {$order}
                LIMIT {$per_page} OFFSET {$offset}";

    if ($types !== '') {
        $lstmt = $conn->prepare($listSql);
        $lstmt->bind_param($types, ...$params);
        $lstmt->execute();
        $result = $lstmt->get_result();
    } else {
        $result = $conn->query($listSql);
    }

    // ── Categorías para filtro ────────────────────────────────────────────
    $categorias = [];
    $catRes = $conn->query("SELECT DISTINCT categoria FROM `{$productosTable}` WHERE archivado = 0 AND categoria != '' ORDER BY categoria");
    if ($catRes) while ($cr = $catRes->fetch_assoc()) $categorias[] = $cr['categoria'];

} catch (Throwable $e) {
    log_event('inventario/index', 'ERROR', $e->getMessage());
    header('Location: /auth/login.php?err=' . urlencode('Error interno'));
    exit();
}

// ── Función para calcular estado de garantía ───────────────────────────
function garantiaFormatear(int $dias, bool $vencido = false): string {
    if ($vencido) {
        if ($dias < 30)   return "Venció hace {$dias} día" . ($dias === 1 ? '' : 's');
        if ($dias < 365) {
            $m = (int)floor($dias / 30);
            return "Venció hace {$m} mes" . ($m === 1 ? '' : 'es');
        }
        $a = (int)floor($dias / 365);
        return "Venció hace {$a} año" . ($a === 1 ? '' : 's');
    }
    if ($dias === 0)  return 'Vence hoy';
    if ($dias < 30)   return "Vence en {$dias} día" . ($dias === 1 ? '' : 's');
    if ($dias < 365) {
        $m = (int)floor($dias / 30);
        $d = $dias - ($m * 30);
        return $d > 0 ? "Vence en {$m} mes" . ($m === 1 ? '' : 'es') . " y {$d} día" . ($d === 1 ? '' : 's')
                      : "Vence en {$m} mes" . ($m === 1 ? '' : 'es');
    }
    $a = (int)floor($dias / 365);
    $m = (int)floor(($dias - $a * 365) / 30);
    return $m > 0 ? "Vence en {$a} año" . ($a === 1 ? '' : 's') . " y {$m} mes" . ($m === 1 ? '' : 'es')
                  : "Vence en {$a} año" . ($a === 1 ? '' : 's');
}

function garantiaInfo(mixed $fechaInicioRaw, mixed $mesesRaw): array {
    $meses = (int)$mesesRaw;
    if (!$meses || !$fechaInicioRaw || str_starts_with((string)$fechaInicioRaw, '0000')) {
        return ['status' => 'no-aplica', 'text' => 'N/A', 'badge' => '', 'fin_iso' => ''];
    }
    try {
        $inicio = new DateTime((string)$fechaInicioRaw);
        $fin    = (clone $inicio)->modify("+{$meses} months");
        $hoy    = new DateTime();
        $finIso = $fin->format('Y-m-d');
        if ($fin < $hoy) {
            $dias = (int)$hoy->diff($fin)->days;
            return ['status' => 'caducado', 'text' => garantiaFormatear($dias, true), 'badge' => 'ih-badge--danger', 'fin_iso' => $finIso];
        }
        $dias = (int)$hoy->diff($fin)->days;
        $badge = $dias <= 30 ? 'ih-badge--warning' : 'ih-badge--success';
        $status = $dias <= 30 ? 'por-caducar' : 'vigente';
        return ['status' => $status, 'text' => garantiaFormatear($dias, false), 'badge' => $badge, 'fin_iso' => $finIso];
    } catch (Throwable) {
        return ['status' => 'no-aplica', 'text' => 'N/A', 'badge' => '', 'fin_iso' => ''];
    }
}

$csrf_export = generateCsrfToken('export_inventario');

// Mensaje de resultado de agregar/editar
$flash_ok   = isset($_GET['ok'])   ? htmlspecialchars($_GET['msg']  ?? 'Operación exitosa.')   : '';
$flash_warn = isset($_GET['ok'])   ? htmlspecialchars($_GET['warn'] ?? '') : '';
$flash_err  = isset($_GET['err'])  ? htmlspecialchars($_GET['err'])                              : '';

// ── Variables de layout ────────────────────────────────────────────────
$page_title      = 'Activos — Asset Vault';
$page_section    = 'inventario';
$topbar_title    = 'Activos';
$topbar_subtitle = number_format($kpi_total) . ' en inventario';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$_canCreate  = user_can('asset.create', $user);
$_canExport  = user_can('asset.export', $user);
$_canEdit    = user_can('asset.edit', $user);
$_canArchive = user_can('asset.archive', $user);

$topbar_actions = '';
if ($_canCreate) {
    $topbar_actions .= '<a href="/inventario/agregar.php"  class="ih-btn ih-btn--accent ih-btn--sm"><i class="bi bi-plus-lg"></i> <span class="ih-hidden-mobile">Nuevo activo</span></a> ';
}
if ($_canExport) {
    $topbar_actions .= '<a href="/inventario/exportar.php" class="ih-btn ih-btn--outline ih-btn--sm"><i class="bi bi-download"></i> <span class="ih-hidden-mobile">Exportar</span></a>';
}

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <!-- ── Flash messages ──────────────────────────────────────── -->
    <?php if ($flash_ok): ?>
    <div class="ih-flash ih-flash--success" id="flash-ok">
      <i class="bi bi-check-circle-fill"></i> <?= $flash_ok ?>
      <button class="ih-flash__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>
    <?php if ($flash_warn): ?>
    <div class="ih-flash ih-flash--warn" id="flash-warn">
      <i class="bi bi-exclamation-triangle-fill"></i> <?= $flash_warn ?>
      <button class="ih-flash__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
    <div class="ih-flash ih-flash--danger" id="flash-err">
      <i class="bi bi-x-circle-fill"></i> <?= $flash_err ?>
      <button class="ih-flash__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <!-- ── KPIs ──────────────────────────────────────────────────── -->
    <div class="ih-stats">
      <div class="ih-stat">
        <div class="ih-stat__label">Total activos <i class="bi bi-box-seam"></i></div>
        <div class="ih-stat__value"><?= number_format($kpi_total) ?></div>
        <div class="ih-stat__delta">En operación</div>
      </div>
      <div class="ih-stat ih-stat--green">
        <div class="ih-stat__label">Valor inventario <i class="bi bi-currency-dollar"></i></div>
        <div class="ih-stat__value">USD <?= number_format($kpi_valor, 0, ',', '.') ?></div>
        <div class="ih-stat__delta">valor × cantidad</div>
      </div>
      <div class="ih-stat ih-stat--warn">
        <div class="ih-stat__label">Garantía próxima <i class="bi bi-shield-exclamation"></i></div>
        <div class="ih-stat__value"><?= $kpi_garantia_30d ?></div>
        <div class="ih-stat__delta">Vencen en 30 días</div>
      </div>
      <div class="ih-stat ih-stat--info">
        <div class="ih-stat__label">Sin asignar <i class="bi bi-person-dash"></i></div>
        <div class="ih-stat__value"><?= $kpi_sin_asignar ?></div>
        <div class="ih-stat__delta">Disponibles</div>
      </div>
      <div class="ih-stat ih-stat--danger">
        <div class="ih-stat__label">Archivados <i class="bi bi-archive"></i></div>
        <div class="ih-stat__value"><?= $kpi_archivados ?></div>
        <div class="ih-stat__delta"><a href="/inventario/reportes.php?tab=archivados" style="color:inherit;text-decoration:underline">Ver detalle →</a></div>
      </div>
    </div>

    <!-- ── Filtros y búsqueda ─────────────────────────────────────── -->
    <div class="ih-card" style="margin-bottom:0;border-bottom-left-radius:0;border-bottom-right-radius:0;">
      <div class="ih-card__body" style="padding:14px 20px;">
        <form method="get" id="form-filtros" class="ih-flex ih-gap-2" style="flex-wrap:wrap;align-items:center;">
          <div style="flex:1;min-width:200px;position:relative;">
            <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--ih-gray-400);pointer-events:none;"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Buscar por modelo, marca, asignado, S/N…"
                   class="ih-input" style="padding-left:34px;"
                   oninput="debounceSubmit()">
          </div>

          <select name="cat" class="ih-select" style="min-width:160px;" onchange="this.form.submit()">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCat === $cat ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="gar" class="ih-select" style="min-width:140px;" onchange="this.form.submit()">
            <option value="">Toda garantía</option>
            <option value="vigente"     <?= $filterGar === 'vigente'     ? 'selected' : '' ?>>Vigente</option>
            <option value="por-caducar" <?= $filterGar === 'por-caducar' ? 'selected' : '' ?>>Por caducar</option>
            <option value="caducado"    <?= $filterGar === 'caducado'    ? 'selected' : '' ?>>Caducado</option>
            <option value="no-aplica"   <?= $filterGar === 'no-aplica'   ? 'selected' : '' ?>>Sin garantía</option>
          </select>

          <input type="hidden" name="sort"     value="<?= htmlspecialchars($sort) ?>">
          <input type="hidden" name="order"    value="<?= htmlspecialchars($order) ?>">
          <input type="hidden" name="per_page" value="<?= $per_page ?>">

          <?php if ($search !== '' || $filterCat !== '' || $filterGar !== ''): ?>
            <a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-x"></i> Limpiar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- ── Barra de acciones de tabla (columnas, etc) ────────────── -->
    <div style="display:flex;justify-content:flex-end;padding:8px 20px;background:var(--ih-gray-50);border-top:1px solid var(--ih-gray-100);position:relative;z-index:50;">
      <div style="position:relative;" id="cols-menu-wrap">
        <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" id="cols-menu-btn" title="Personalizar columnas visibles">
          <i class="bi bi-layout-three-columns"></i> Columnas
        </button>
        <?php
        $colsPersonalizables = [
          'id' => '#',
          'modelo' => 'Activo',
          'asignado_a' => 'Asignado a',
          'categoria' => 'Categoría',
          'estado' => 'Estado',
          'fecha_garantia' => 'Garantía',
          'valor_usd' => 'Valor USD',
          'ubicacion' => 'Ubicación',
          'drive' => 'Drive',
        ];
        ?>
        <div id="cols-menu" hidden style="position:absolute;right:0;top:calc(100% + 6px);z-index:1000;background:#fff;border:1px solid var(--ih-gray-200);border-radius:var(--ih-radius-md);box-shadow:0 12px 32px rgba(0,0,0,.18);min-width:240px;padding:12px 16px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--ih-gray-500);letter-spacing:.04em;margin-bottom:10px;">Mostrar columnas</div>
          <?php foreach ($colsPersonalizables as $key => $label): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;color:var(--ih-gray-800);cursor:pointer;user-select:none;">
              <input type="checkbox" class="col-toggle" data-col-toggle="<?= $key ?>" checked>
              <span><?= htmlspecialchars($label) ?></span>
            </label>
          <?php endforeach; ?>
          <div style="border-top:1px solid var(--ih-gray-100);margin-top:10px;padding-top:10px;display:flex;gap:6px;">
            <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" id="cols-reset" style="flex:1;font-size:11px;">
              <i class="bi bi-arrow-counterclockwise"></i> Restaurar todas
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tabla de activos ───────────────────────────────────────── -->
    <div class="ih-card" style="border-top-left-radius:0;border-top-right-radius:0;margin-top:0;">
      <div class="ih-card__body" style="padding:0;">
        <div class="ih-table-wrap">
          <table class="ih-table">
            <thead>
              <tr>
                <?php
                $cols = [
                    'id'         => '#',
                    'modelo'     => 'Activo',
                    'asignado_a' => 'Asignado a',
                    'categoria'  => 'Categoría',
                    'estado'     => 'Estado',
                    'fecha_garantia' => 'Garantía',
                    'valor_usd'  => 'Valor USD',
                    'ubicacion'  => 'Ubicación',
                ];
                foreach ($cols as $col => $label):
                    $isActive = $sort === $col;
                    $nextOrder = ($isActive && $order === 'ASC') ? 'desc' : 'asc';
                    $icon = $isActive ? ($order === 'ASC' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand';
                    $q = http_build_query(['q' => $search, 'cat' => $filterCat, 'gar' => $filterGar, 'per_page' => $per_page, 'page' => 1, 'sort' => $col, 'order' => $nextOrder]);
                ?>
                <th data-col-key="<?= htmlspecialchars($col) ?>"><a href="?<?= $q ?>" class="ih-sort-link <?= $isActive ? 'is-active' : '' ?>">
                  <?= $label ?> <i class="bi <?= $icon ?>"></i>
                </a></th>
                <?php endforeach; ?>
                <th data-col-key="drive">Drive</th>
                <th style="text-align:center;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php
              // Cacheamos el icono SVG del provider una sola vez (no por fila)
              $cloudProviderIndex = cloud_provider_actual($conn);
              $cloudIconCached    = cloud_icon_svg($cloudProviderIndex, 18);
              if ($result && $result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
                  $gar = garantiaInfo($row['fecha_garantia'], $row['periodo_garantia']);
                  // Filtrar por garantía si se seleccionó
                  if ($filterGar !== '' && $gar['status'] !== $filterGar) continue;
              ?>
              <?php
              $iconSub = $iconMap[$row['subcategoria'] ?? ''] ?? 'bi bi-box';
              $iconCat = $iconMap[$row['categoria'] ?? '']    ?? 'bi bi-folder';
              ?>
              <tr>
                <td data-col-key="id"><span class="ih-text-muted ih-text-sm">#<?= $row['id'] ?></span></td>
                <td data-col-key="modelo">
                  <div style="display:flex;align-items:center;gap:8px;">
                    <i class="<?= $iconSub ?>" style="font-size:18px;color:var(--ih-primary);flex-shrink:0;"></i>
                    <div style="min-width:0;">
                      <div style="font-weight:600;color:var(--ih-gray-900);"><?= htmlspecialchars($row['modelo'] ?? '—') ?></div>
                      <?php if (!empty($row['marca'])): ?>
                        <div class="ih-text-sm ih-text-muted"><?= htmlspecialchars($row['marca']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($row['subcategoria'])): ?>
                        <div><span class="ih-badge ih-badge--purple" style="font-size:10px;"><?= htmlspecialchars($row['subcategoria']) ?></span></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td data-col-key="asignado_a">
                  <?php if (!empty($row['asignado_a'])): ?>
                    <span><?= htmlspecialchars($row['asignado_a']) ?></span>
                  <?php else: ?>
                    <span class="ih-badge">Sin asignar</span>
                  <?php endif; ?>
                </td>
                <td data-col-key="categoria">
                  <span class="ih-text-sm" style="display:inline-flex;align-items:center;gap:6px;">
                    <i class="<?= $iconCat ?>" style="color:var(--ih-gray-500);"></i>
                    <?= htmlspecialchars($row['categoria'] ?? '—') ?>
                  </span>
                  <?php if (!empty($row['subcategoria'])): ?>
                    <div class="ih-text-xs ih-text-muted" style="margin-top:2px;padding-left:20px;">
                      <?= htmlspecialchars($row['subcategoria']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td data-col-key="estado">
                  <?php
                  $estadoBadge = match(strtolower($row['estado'] ?? '')) {
                    'nuevo'  => 'ih-badge--success',
                    'usado'  => 'ih-badge--info',
                    'reparar','a reparar' => 'ih-badge--warning',
                    'dado de baja','baja' => 'ih-badge--danger',
                    default  => '',
                  };
                  ?>
                  <span class="ih-badge <?= $estadoBadge ?>"><?= htmlspecialchars($row['estado'] ?? '—') ?></span>
                </td>
                <td data-col-key="fecha_garantia">
                  <?php if ($gar['text'] !== 'N/A' && $gar['fin_iso'] !== ''): ?>
                    <span class="ih-badge <?= $gar['badge'] ?>"
                          data-warranty-end="<?= htmlspecialchars($gar['fin_iso']) ?>"
                          title="Fin de garantía: <?= htmlspecialchars($gar['fin_iso']) ?>">
                      <?= htmlspecialchars($gar['text']) ?>
                    </span>
                  <?php else: ?>
                    <span class="ih-text-muted ih-text-sm">—</span>
                  <?php endif; ?>
                </td>
                <td data-col-key="valor_usd">
                  <?php if ($row['valor_usd'] > 0): ?>
                    <span style="font-weight:600;">USD <?= number_format((float)$row['valor_usd'], 2, ',', '.') ?></span>
                    <?php if ((int)$row['cantidad'] > 1): ?>
                      <div class="ih-text-sm ih-text-muted">× <?= $row['cantidad'] ?> = USD <?= number_format((float)$row['valor_usd'] * (int)$row['cantidad'], 2, ',', '.') ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="ih-text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td data-col-key="ubicacion">
                  <span class="ih-text-sm"><?= htmlspecialchars($row['ubicacion'] ?? '—') ?></span>
                </td>
                <td data-col-key="drive">
                  <?php if (!empty($row['carpeta_raiz_drive'])): ?>
                    <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($row['carpeta_raiz_drive']) ?>"
                       target="_blank" class="ih-btn ih-btn--ghost ih-btn--sm" title="Abrir en Drive" style="padding:6px 10px;">
                      <?= $cloudIconCached ?>
                    </a>
                  <?php else: ?>
                    <span class="ih-text-muted ih-text-sm">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="ih-flex ih-gap-1" style="justify-content:center;">
                    <a href="/backend/view_details.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--ghost ih-btn--sm" title="Ver detalle"><i class="bi bi-eye"></i></a>
                    <?php if ($_canEdit): ?>
                      <a href="/backend/edit.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--ghost ih-btn--sm" title="Editar"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                    <?php if ($_canArchive): ?>
                      <a href="/backend/delete.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--ghost ih-btn--sm" title="Archivar"
                         style="color:var(--ih-danger);"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
              <?php else: ?>
              <tr>
                <td colspan="10" style="text-align:center;padding:48px;color:var(--ih-gray-500);">
                  <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                  <?= $search || $filterCat || $filterGar ? 'No hay activos que coincidan con los filtros.' : 'No hay activos en el inventario.' ?>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── Pie de tabla: contador y paginador ─────────────────── -->
      <div class="ih-card__footer" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:12px 20px;border-top:1px solid var(--ih-gray-200);">
        <div class="ih-text-sm ih-text-muted">
          Mostrando <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $totalRows)) ?> de <?= number_format($totalRows) ?> activos
          &nbsp;|&nbsp;
          <select class="ih-select" style="display:inline-block;width:auto;padding:2px 8px;font-size:12px;"
                  onchange="window.location='?<?= http_build_query(['q'=>$search,'cat'=>$filterCat,'gar'=>$filterGar,'sort'=>$sort,'order'=>$order,'page'=>1]) ?>&per_page='+this.value">
            <?php foreach ($perPageOpts as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?> por página</option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav style="display:flex;gap:4px;align-items:center;">
          <?php
          $pBase = ['q'=>$search,'cat'=>$filterCat,'gar'=>$filterGar,'sort'=>$sort,'order'=>$order,'per_page'=>$per_page];
          $pLink = fn($p) => '?'.http_build_query(array_merge($pBase,['page'=>$p]));
          ?>
          <a href="<?= $pLink(1) ?>"           class="ih-btn ih-btn--ghost ih-btn--sm <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-double-left"></i></a>
          <a href="<?= $pLink($page-1) ?>"      class="ih-btn ih-btn--ghost ih-btn--sm <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
          <?php
          $pStart = max(1, $page - 2); $pEnd = min($totalPages, $page + 2);
          for ($i = $pStart; $i <= $pEnd; $i++):
          ?>
            <a href="<?= $pLink($i) ?>" class="ih-btn ih-btn--sm <?= $i===$page?'ih-btn--primary':'ih-btn--ghost' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a href="<?= $pLink($page+1) ?>"      class="ih-btn ih-btn--ghost ih-btn--sm <?= $page>=$totalPages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
          <a href="<?= $pLink($totalPages) ?>"   class="ih-btn ih-btn--ghost ih-btn--sm <?= $page>=$totalPages?'disabled':'' ?>"><i class="bi bi-chevron-double-right"></i></a>
        </nav>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div>

<style>
.ih-sort-link {
  display: inline-flex; align-items: center; gap: 4px;
  color: inherit; text-decoration: none; white-space: nowrap;
}
.ih-sort-link:hover { color: var(--ih-green); }
.ih-sort-link.is-active { color: var(--ih-green); }
.ih-sort-link.is-active i { color: var(--ih-green); }
.disabled { pointer-events: none; opacity: .4; }

/* Flash messages */
.ih-flash {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
  font-size: 14px; animation: fadeInDown .3s ease;
}
.ih-flash--success { background: #f0faf0; color: #16a34a; border: 1px solid #bbf7d0; }
.ih-flash--warn    { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.ih-flash--danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.ih-flash__close {
  margin-left: auto; background: none; border: none; cursor: pointer;
  font-size: 18px; color: inherit; opacity: .6; padding: 0 4px;
}
.ih-flash__close:hover { opacity: 1; }
@keyframes fadeInDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:none; } }
</style>

<script>
// Búsqueda con debounce (evita submit en cada tecla)
let _dbt;
function debounceSubmit() {
  clearTimeout(_dbt);
  _dbt = setTimeout(() => document.getElementById('form-filtros').submit(), 500);
}

// Auto-dismiss flash messages
document.querySelectorAll('.ih-flash').forEach(el => {
  setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 4000);
});

// ── Personalizar columnas visibles ───────────────────────────────────
const COLS_STORAGE_KEY = 'av_cols_visibles_v1';
const colsMenuBtn  = document.getElementById('cols-menu-btn');
const colsMenu     = document.getElementById('cols-menu');
const colsReset    = document.getElementById('cols-reset');
const colToggles   = document.querySelectorAll('.col-toggle');

function aplicarColumnasVisibles(hiddenKeys) {
  document.querySelectorAll('[data-col-key]').forEach(el => {
    const k = el.dataset.colKey;
    el.style.display = hiddenKeys.includes(k) ? 'none' : '';
  });
}
function leerColumnasOcultas() {
  try {
    const raw = localStorage.getItem(COLS_STORAGE_KEY);
    if (!raw) return [];
    const arr = JSON.parse(raw);
    return Array.isArray(arr) ? arr : [];
  } catch { return []; }
}
function guardarColumnasOcultas(hiddenKeys) {
  try { localStorage.setItem(COLS_STORAGE_KEY, JSON.stringify(hiddenKeys)); } catch {}
}

// Estado inicial
const hidden = leerColumnasOcultas();
colToggles.forEach(cb => { cb.checked = !hidden.includes(cb.dataset.colToggle); });
aplicarColumnasVisibles(hidden);

// Toggle dropdown (usando hidden attribute para mas robustez)
colsMenuBtn?.addEventListener('click', (e) => {
  e.preventDefault(); e.stopPropagation();
  if (colsMenu) colsMenu.hidden = !colsMenu.hidden;
});
document.addEventListener('click', (e) => {
  if (colsMenu && !colsMenu.hidden && !colsMenu.contains(e.target) && !colsMenuBtn.contains(e.target)) {
    colsMenu.hidden = true;
  }
});

// Cambios en checkboxes
colToggles.forEach(cb => {
  cb.addEventListener('change', () => {
    const hiddenNow = Array.from(colToggles).filter(c => !c.checked).map(c => c.dataset.colToggle);
    guardarColumnasOcultas(hiddenNow);
    aplicarColumnasVisibles(hiddenNow);
  });
});

// Restaurar todas
colsReset?.addEventListener('click', () => {
  colToggles.forEach(cb => cb.checked = true);
  guardarColumnasOcultas([]);
  aplicarColumnasVisibles([]);
});

// ── Garantia dinamica — actualiza badges en tiempo real ────────────
function pluralES(n, sing, plu) { return n === 1 ? sing : plu; }
function formatGarantia(dias, vencido) {
  if (vencido) {
    if (dias < 30)  return `Venció hace ${dias} ${pluralES(dias,'día','días')}`;
    if (dias < 365) { const m = Math.floor(dias/30); return `Venció hace ${m} ${pluralES(m,'mes','meses')}`; }
    const a = Math.floor(dias/365); return `Venció hace ${a} ${pluralES(a,'año','años')}`;
  }
  if (dias === 0)  return 'Vence hoy';
  if (dias < 30)   return `Vence en ${dias} ${pluralES(dias,'día','días')}`;
  if (dias < 365) {
    const m = Math.floor(dias/30), d = dias - m*30;
    return d > 0
      ? `Vence en ${m} ${pluralES(m,'mes','meses')} y ${d} ${pluralES(d,'día','días')}`
      : `Vence en ${m} ${pluralES(m,'mes','meses')}`;
  }
  const a = Math.floor(dias/365), m = Math.floor((dias - a*365)/30);
  return m > 0
    ? `Vence en ${a} ${pluralES(a,'año','años')} y ${m} ${pluralES(m,'mes','meses')}`
    : `Vence en ${a} ${pluralES(a,'año','años')}`;
}
function actualizarGarantias() {
  const ahora = new Date();
  document.querySelectorAll('[data-warranty-end]').forEach(el => {
    const finStr = el.dataset.warrantyEnd;
    if (!finStr) return;
    const fin = new Date(finStr + 'T23:59:59');
    const diffMs = fin - ahora;
    const dias = Math.floor(Math.abs(diffMs) / 86400000);
    const vencido = diffMs < 0;
    el.textContent = formatGarantia(dias, vencido);
    // Reasignar clase de badge segun estado actual (puede cambiar con el paso del tiempo)
    el.classList.remove('ih-badge--success','ih-badge--warning','ih-badge--danger');
    if (vencido)        el.classList.add('ih-badge--danger');
    else if (dias <= 30) el.classList.add('ih-badge--warning');
    else                 el.classList.add('ih-badge--success');
  });
}
actualizarGarantias();
setInterval(actualizarGarantias, 60000); // refresca cada minuto
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
