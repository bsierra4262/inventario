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
        require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
        log_event('index.php', 'AUTH_FAIL', 'user_id vacío en sesión', 'auth');
        header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
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
        require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
        $cid_notfound = (int)($_SESSION['company_id'] ?? 0);
        log_event('index.php', 'AUTH_FAIL', "user_id={$user_id} no encontrado en tabla={$usersTable}", 'auth');
        session_destroy();
        header('Location: ' . tenant_login_url($cid_notfound, ['err' => 'Tu sesión expiró o tu usuario fue eliminado. Volvé a iniciar sesión.']));
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
    $topbar_actions .= '<a href="/inventario/agregar.php"  class="ih-btn ih-btn--primary ih-btn--sm"><i class="bi bi-plus-lg"></i> <span class="ih-hidden-mobile">Nuevo activo</span></a> ';
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
      <button class="ih-flash__close" type="button" aria-label="Cerrar el aviso"><i class="bi bi-x"></i></button>
    </div>
    <?php endif; ?>
    <?php if ($flash_warn): ?>
    <div class="ih-flash ih-flash--warn" id="flash-warn">
      <i class="bi bi-exclamation-triangle-fill"></i> <?= $flash_warn ?>
      <button class="ih-flash__close" type="button" aria-label="Cerrar el aviso"><i class="bi bi-x"></i></button>
    </div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
    <div class="ih-flash ih-flash--danger" id="flash-err">
      <i class="bi bi-x-circle-fill"></i> <?= $flash_err ?>
      <button class="ih-flash__close" type="button" aria-label="Cerrar el aviso"><i class="bi bi-x"></i></button>
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
        <div class="ih-stat__delta"><a class="ih-stat__enlace" href="/inventario/reportes.php?tab=archivados">Ver el detalle</a></div>
      </div>
    </div>

    <!-- ── Filtros y búsqueda ─────────────────────────────────────── -->
    <?php /* La barra de filtros y la tabla se leen como una sola cosa: van
             pegadas (.ih-pegada-arriba / .ih-pegada-abajo). Antes eso estaba
             hecho con cuatro `border-radius: 0` sueltos, uno por esquina. */ ?>
    <div class="ih-card ih-pegada-arriba">
      <div class="ih-card__body ih-card__body--compacto">
        <form method="get" id="form-filtros" class="ih-filtros-linea">
          <label class="ih-sr-only" for="q">Buscar un activo</label>
          <div class="ih-buscador__campo">
            <i class="bi bi-search ih-buscador__lupa" aria-hidden="true"></i>
            <input type="search" id="q" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Ej.: Latitude 5420, Dell, Brian Sierra, 7KJ2M13"
                   class="ih-input"
                   oninput="debounceSubmit()">
          </div>

          <select name="cat" class="ih-select" aria-label="Filtrar por categoría" onchange="this.form.submit()">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCat === $cat ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="gar" class="ih-select" aria-label="Filtrar por garantía" onchange="this.form.submit()">
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
    <div class="ih-barra-fina">
      <div class="ih-menu-ancla" id="cols-menu-wrap">
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
        <div id="cols-menu" class="ih-menu" hidden>
          <h3 class="ih-menu__titulo">Qué columnas se ven</h3>
          <?php foreach ($colsPersonalizables as $key => $label): ?>
            <label class="ih-menu__opcion">
              <input type="checkbox" class="col-toggle" data-col-toggle="<?= $key ?>" checked>
              <span><?= htmlspecialchars($label) ?></span>
            </label>
          <?php endforeach; ?>
          <div class="ih-menu__pie">
            <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" id="cols-reset">
              <i class="bi bi-arrow-counterclockwise"></i> Restaurar todas
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tabla de activos ───────────────────────────────────────── -->
    <div class="ih-card ih-pegada-abajo">
      <div class="ih-card__body ih-card__body--tabla">
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
                <th class="ih-text-center">Acciones</th>
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
                  <div class="ih-celda">
                    <i class="<?= $iconSub ?> ih-celda__icono" aria-hidden="true"></i>
                    <div class="ih-celda__texto">
                      <div class="ih-celda__nombre"><?= htmlspecialchars($row['modelo'] ?? '—') ?></div>
                      <?php if (!empty($row['marca'])): ?>
                        <div class="ih-text-sm ih-text-muted"><?= htmlspecialchars($row['marca']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($row['subcategoria'])): ?>
                        <div><span class="ih-badge ih-badge--purple"><?= htmlspecialchars($row['subcategoria']) ?></span></div>
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
                  <span class="ih-text-sm ih-con-icono">
                    <i class="<?= $iconCat ?>" aria-hidden="true"></i>
                    <?= htmlspecialchars($row['categoria'] ?? '—') ?>
                  </span>
                  <?php if (!empty($row['subcategoria'])): ?>
                    <div class="ih-text-xs ih-text-muted ih-colgado">
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
                    <span class="ih-text-semibold">USD <?= number_format((float)$row['valor_usd'], 2, ',', '.') ?></span>
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
                       target="_blank" rel="noopener" class="ih-btn ih-btn--ghost ih-btn--sm" title="Abrir en Drive">
                      <?= $cloudIconCached ?>
                    </a>
                  <?php else: ?>
                    <span class="ih-text-muted ih-text-sm">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="ih-acciones ih-justify-center">
                    <a href="/backend/view_details.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--ghost ih-btn--sm" title="Ver detalle"><i class="bi bi-eye"></i></a>
                    <a href="/inventario/ficha.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--ghost ih-btn--sm" title="Ficha técnica"><i class="bi bi-clipboard-data"></i></a>
                    <?php if ($_canEdit): ?>
                      <a href="/backend/edit.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--ghost ih-btn--sm" title="Editar"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                    <?php if ($_canArchive): ?>
                      <a href="/backend/delete.php?id=<?= $row['id'] ?>" class="ih-btn ih-btn--danger ih-btn--sm" title="Archivar">
                        <i class="bi bi-archive"></i><span class="ih-sr-only">Archivar este activo</span>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
              <?php else: ?>
              <tr>
                <td colspan="10" class="ih-table__vacio">
                  <i class="bi bi-inbox" aria-hidden="true"></i>
                  <?= $search || $filterCat || $filterGar ? 'No hay activos que coincidan con los filtros.' : 'No hay activos en el inventario.' ?>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php /* ── Pie de tabla ──────────────────────────────────────────────
               El paginador es el del sistema (.ih-pager, en componentes.css),
               el mismo que usa reportes. Antes acá estaba armado con botones y
               estilos sueltos y allá con otras clases: dos paginadores que
               hacían lo mismo y se veían distinto. */ ?>
      <div class="ih-card__footer">
        <div class="ih-pager">
          <div class="ih-pager__info">
            <span>
              Mostrando <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $totalRows)) ?></strong>
              de <strong><?= number_format($totalRows) ?></strong> <?= $totalRows === 1 ? 'activo' : 'activos' ?>
            </span>
            <label class="ih-sr-only" for="per-page">Activos por página</label>
            <select class="ih-select" id="per-page"
                    onchange="window.location='?<?= http_build_query(['q'=>$search,'cat'=>$filterCat,'gar'=>$filterGar,'sort'=>$sort,'order'=>$order,'page'=>1]) ?>&per_page='+this.value">
              <?php foreach ($perPageOpts as $opt): ?>
                <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?> por página</option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($totalPages > 1):
            $pBase = ['q'=>$search,'cat'=>$filterCat,'gar'=>$filterGar,'sort'=>$sort,'order'=>$order,'per_page'=>$per_page];
            $pLink = fn($p) => '?' . http_build_query(array_merge($pBase, ['page' => $p]));
            $pStart = max(1, $page - 2);
            $pEnd   = min($totalPages, $page + 2);
          ?>
          <nav aria-label="Páginas del listado">
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
    </div>

  </main>
</div>

<?php /* Esta pantalla redefinía los avisos con #16a34a, #d97706 y #b91c1c:
         ninguno es un color de este proyecto, y como la hoja de la página
         carga después, le ganaba a la del sistema. `.ih-flash` ya existe en
         assets/css/app.css con el verde, el ámbar y el rojo de la marca. */ ?>

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

/* Esconder y mostrar columnas va por el atributo `hidden`, igual que el resto
   del proyecto. Antes era `style.display`, que tambien funciona; la razon de
   unificarlo es que mezclar los dos SI rompe: la hoja del sistema declara
   `[hidden] { display: none !important }`, asi que si una celda llegara con
   `hidden` puesto, un `style.display = ''` no la volveria a mostrar. */
function aplicarColumnasVisibles(hiddenKeys) {
  document.querySelectorAll('[data-col-key]').forEach(el => {
    el.hidden = hiddenKeys.includes(el.dataset.colKey);
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
