<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}
require __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../logs/logger.php';
// infocom_amortizacion_anios(): el plazo de amortización guardado por activo,
// con el del reporte como respaldo.
require_once __DIR__ . '/../core/tenants/infocom.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit();
}
$user_id     = $_SESSION['user_id'];
$usersTable  = tn('users');
$prodTable   = tn('productos');
$movTable    = tn('producto_movimientos');

$sql_user  = "SELECT role FROM `{$usersTable}` WHERE id = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param('i', $user_id);
$stmt_user->execute();
$user      = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();
$is_admin  = ($user && $user['role'] === 'admin');

// --- CATEGORÍAS Y SUBCATEGORÍAS FIJAS ---
$categorias_full = [
    'Informática' => [
        'Desktop (PC de escritorio)','Laptop','Notebook','Monitor','Mouse','Teclado',
        'Webcam','Micrófono','Auriculares','Parlantes','Escáner','Numpad','Tableta gráfica',
        'Tablet','All-in-One','Docking station','Cables de alimentación','Soportes / Bases para notebook','Otros'
    ],
    'Redes' => [
        'Patchcord','Switch','Router','Access Point','Módem','Firewall',
        'Repetidor / Extensor Wi-Fi','Antenas','Rack / Gabinete de comunicaciones',
        'Panel de parcheo (Patch panel)','Organizadores de cables','Puntos de red (RJ45)',
        'Bobina UTP','Conectores','Tester de red','POE Injector / POE Switch',
        'Convertidores de medios','Otros'
    ],
    'Servidores y almacenamiento' => [
        'Servidor físico','NAS','SAN','Disco rígido (HDD)','Unidad de estado sólido (SSD)',
        'Cintas de respaldo','Gabinetes de almacenamiento externo','RAID Controller',
        'UPS para servidores','Fuentes redundantes','Otros'
    ],
    'Periféricos y accesorios' => [
        'Impresora térmica','Impresora de tickets','Impresora multifunción',
        'Lector de código de barras','Lector biométrico','Tarjeta de video externa',
        'Adaptadores USB / HDMI / VGA','Hub USB','Cables (HDMI, VGA, DisplayPort, USB, etc.)',
        'Fundas / Mochilas / Maletines','Kit de limpieza','Cargadores y fuentes',
        'Adaptadores eléctricos','Otros'
    ],
    'Herramientas y mantenimiento' => [
        'Destornilladores','Kits de precisión','Pinzas / Alicates','Tester eléctrico',
        'Multímetro','Crimpadoras','Pelacables','Termofusora / Pistola de calor',
        'Etiquetadora','Cepillos antiestáticos','Espuma Antiestática','Aire comprimido',
        'Alcohol isopropílico','Otros'
    ],
    'Seguridad' => [
        'Cámara IP','CCTV','DVR','NVR','Sensores de movimiento','Control de acceso',
        'Cerraduras electrónicas','Alarmas','Detectores de humo / temperatura',
        'UPS para CCTV','Cables coaxiales','Monitores de vigilancia','Otros'
    ],
    'Software y licencias' => [
        'Sistemas operativos','Licencias Office','Antivirus','Software de backup',
        'Software contable / ERP','Licencias de servidores','Herramientas de virtualización',
        'Licencias en la nube','Otros'
    ],
    'Telefonía' => [
        'Teléfonos IP','Teléfonos analógicos','Teléfonos inalámbricos (DECT)',
        'Central telefónica / PBX','Adaptadores ATA','Auriculares con micrófono (headset)',
        'Softphones / Aplicaciones de telefonía','Módulos FXO / FXS','VoIP Gateway',
        'Cables telefónicos','Baterías para teléfonos inalámbricos','Otros'
    ],
    'Electricidad' => [
        'Tomas y enchufes','Zapatillas eléctricas / Múltiples','Cables eléctricos',
        'Disyuntores / térmicas','Luces de emergencia','Lámparas / iluminación LED',
        'Transformadores','Regletas con protección','Estabilizadores de tensión',
        'UPS','Baterías','Pilas','Otros'
    ],
    'Inmobiliario / Muebles' => [
        'Escritorios','Sillas ergonómicas','Sillas de oficina','Mesas de trabajo',
        'Archiveros / Cajas de almacenamiento','Estanterías','Racks','Soportes para monitor',
        'Carros para equipos','Otros'
    ],
    'Otros' => ['Otros']
];
$categorias = array_keys($categorias_full);

function tipo_activo_pasivo($cat, $subcat) {
    $activos = [
        'Informática', 'Redes', 'Servidores y almacenamiento', 'Periféricos y accesorios',
        'Seguridad', 'Telefonía', 'Electricidad', 'Software y licencias'
    ];
    $pasivos_especificos = [
        'Patchcord','Bobina UTP','Conectores','Cables de alimentación','Cables eléctricos','Cables telefónicos','Panel de parcheo (Patch panel)','Organizadores de cables',
        'Puntos de red (RJ45)','Tester de red','Soportes / Bases para notebook','Fundas / Mochilas / Maletines','Estanterías','Racks','Soportes para monitor','Carros para equipos',
        'Destornilladores','Pinzas / Alicates','Crimpadoras','Pelacables','Cepillos antiestáticos','Espuma Antiestática','Aire comprimido','Alcohol isopropílico',
        'Otros'
    ];
    if (in_array($subcat, $pasivos_especificos)) return 'Pasivo';
    if (in_array($cat, $activos)) return 'Activo';
    return 'Pasivo';
}

$tab = $_GET['tab'] ?? 'inventario';
if (isset($_GET['ranking_cantidad']))  $tab = 'valor';
if (isset($_GET['ranking_valor']))     $tab = 'valor';
if (isset($_GET['amortizacion']))      $tab = 'amortizacion';
if (isset($_GET['trazabilidad']))      $tab = 'trazabilidad';
if (isset($_GET['ap']))                $tab = 'ap';
if (isset($_GET['filtro_valor']))      $tab = 'valor';
if (isset($_GET['filtro_amorti']))     $tab = 'amortizacion';

/**
 * Ejecuta una consulta con parámetros y devuelve el mysqli_result.
 *
 * Tres de las cinco pestañas (inventario, valor, amortización) armaban su
 * cláusula WHERE concatenando `real_escape_string()`, mientras SECURITY.md dice
 * "todo el acceso a la base usa sentencias preparadas" y CONTRIBUTING.md "SQL:
 * siempre preparado". Las otras dos (trazabilidad, archivados) ya usaban `?`:
 * el mismo archivo hacía las dos cosas.
 *
 * No era explotable —la conexión fija utf8mb4 con set_charset(), así que el
 * escapado es consciente del charset y no hay bypass por multibyte—, pero la
 * regla existe para no depender de que eso siga siendo cierto.
 *
 * Se define acá arriba, y no dentro del bloque de la primera pestaña, porque
 * hoy todas se renderizan siempre (se muestran y ocultan por CSS con
 * is_tab()). Si mañana alguna se envuelve en un condicional, el helper tiene
 * que seguir existiendo para las demás.
 */
function rp_consultar(mysqli $conn, string $sql, string $types = '', array $params = []) {
    if ($types === '') { return $conn->query($sql); }
    $st = $conn->prepare($sql);
    if (!$st) { return false; }
    $st->bind_param($types, ...$params);
    $st->execute();
    return $st->get_result();
}

function is_tab($needle, $tab) { return $needle === $tab ? 'show active' : ''; }
function is_tab_btn($needle, $tab) { return $needle === $tab ? 'active' : ''; }

$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cs = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $cr = $cs->get_result()->fetch_assoc();
        if ($cr) $tenant_name = $cr['nombre'];
        $cs->close();
    }
} catch (Throwable) {}

$page_title      = 'Reportes — Asset Vault';
$page_section    = 'reportes';
$topbar_title    = 'Reportes';
$topbar_subtitle = '';
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>
<?php /* Esta pantalla tenía 69 líneas de CSS propio: sus pestañas, su barra
         de filtros y su paginador, con nombres que no usaba ninguna otra
         (`rp-tab`, `pagination-cool`). Por eso la pestaña activa de acá
         salía verde y la del menú violeta. Ahora son los componentes del
         sistema: .ih-subtabs, .ih-filtros y .ih-pager, en componentes.css. */ ?>


<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

<!-- ── Tab nav ──────────────────────────────────────── -->
<nav class="ih-subtabs">
  <a class="ih-subtab <?= $tab === 'inventario'  ? 'is-active' : '' ?>" href="?tab=inventario">Inventario General</a>
  <a class="ih-subtab <?= $tab === 'valor'        ? 'is-active' : '' ?>" href="?tab=valor">Ranking por Cantidad</a>
  <a class="ih-subtab <?= $tab === 'amortizacion' ? 'is-active' : '' ?>" href="?tab=amortizacion">Amortización</a>
  <a class="ih-subtab <?= $tab === 'trazabilidad' ? 'is-active' : '' ?>" href="?tab=trazabilidad">Trazabilidad</a>
  <a class="ih-subtab <?= $tab === 'archivados'   ? 'is-active' : '' ?>" href="?tab=archivados">Archivados</a>
</nav>

<!-- ════════ INVENTARIO GENERAL ════════ -->
<div style="display:<?= $tab === 'inventario' ? 'block' : 'none' ?>">
  <form method="GET" class="ih-filtros" id="form-inventario">
    <input type="hidden" name="tab" value="inventario">
    <div>
      <label class="ih-label">Categoría</label>
      <select name="categoria" id="categoria-reporte" class="ih-select">
        <option value="">Todas</option>
        <?php foreach($categorias as $cat): ?>
          <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Subcategoría</label>
      <select name="subcategoria" id="subcategoria-reporte" class="ih-select">
        <option value="">Todas</option>
      </select>
    </div>
    <div>
      <label class="ih-label">Marca</label>
      <input type="text" name="marca" class="ih-input" value="<?=htmlspecialchars($_GET['marca']??'')?>">
    </div>
    <div>
      <label class="ih-label">Modelo</label>
      <input type="text" name="modelo" class="ih-input" value="<?=htmlspecialchars($_GET['modelo']??'')?>">
    </div>
    <div>
      <label class="ih-label">Estado</label>
      <select name="estado" class="ih-select">
        <option value="">Todos</option>
        <option value="Nuevo" <?=($_GET['estado']??'')==='Nuevo'?'selected':''?>>Nuevo</option>
        <option value="Usado" <?=($_GET['estado']??'')==='Usado'?'selected':''?>>Usado</option>
      </select>
    </div>
    <div>
      <label class="ih-label">Ubicación</label>
      <input type="text" name="ubicacion" class="ih-input" value="<?=htmlspecialchars($_GET['ubicacion']??'')?>">
    </div>
    <div>
      <label class="ih-label">Desde Fecha de Carga</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="ih-label">Hasta Fecha de Carga</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div style="display:flex;align-items:end;gap:8px;">
      <label class="ih-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
        <input type="checkbox" name="incluir_archivados" value="1" <?= !empty($_GET['incluir_archivados']) ? 'checked' : '' ?>>
        Incluir archivados
      </label>
    </div>
    <div>
      <button class="ih-btn ih-btn--primary" type="submit">Filtrar</button>
      <a href="?tab=inventario" class="ih-btn ih-btn--ghost" style="margin-left:6px;">Limpiar</a>
    </div>
  </form>

  <div class="ih-filtros__acciones">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-inventario"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-inventario"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="ih-table-wrap">
  <table class="ih-table" id="tabla-inventario">
    <thead>
      <tr>
        <th>ID</th><th>Modelo</th><th>Marca</th><th>Descripción</th>
        <th>Categoría</th><th>Subcategoría</th><th>Cantidad</th>
        <th>Asignado a</th><th>Ubicación</th><th>Estado</th><th>Valor USD</th>
      </tr>
    </thead>
    <tbody>
<?php
      $f = []; $pInv = []; $tInv = '';
      if (!empty($_GET['categoria']))    { $f[] = "categoria = ?";                   $pInv[] = $_GET['categoria'];              $tInv .= 's'; }
      if (!empty($_GET['subcategoria'])) { $f[] = "subcategoria = ?";                $pInv[] = $_GET['subcategoria'];           $tInv .= 's'; }
      if (!empty($_GET['marca']))        { $f[] = "LOWER(marca) LIKE LOWER(?)";      $pInv[] = '%'.$_GET['marca'].'%';          $tInv .= 's'; }
      if (!empty($_GET['modelo']))       { $f[] = "LOWER(modelo) LIKE LOWER(?)";     $pInv[] = '%'.$_GET['modelo'].'%';         $tInv .= 's'; }
      if (!empty($_GET['estado']))       { $f[] = "estado = ?";                      $pInv[] = $_GET['estado'];                 $tInv .= 's'; }
      if (!empty($_GET['ubicacion']))    { $f[] = "LOWER(ubicacion) LIKE LOWER(?)";  $pInv[] = '%'.$_GET['ubicacion'].'%';      $tInv .= 's'; }
      $col_carga = "IF(fecha_carga IS NOT NULL AND fecha_carga != '0000-00-00 00:00:00', DATE(fecha_carga), DATE(fecha_modificacion))";
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
          $f[] = "($col_carga) BETWEEN ? AND ?";
          $pInv[] = $_GET['fecha_desde']; $pInv[] = $_GET['fecha_hasta']; $tInv .= 'ss';
      } elseif (!empty($_GET['fecha_desde'])) {
          $f[] = "($col_carga) >= ?"; $pInv[] = $_GET['fecha_desde']; $tInv .= 's';
      } elseif (!empty($_GET['fecha_hasta'])) {
          $f[] = "($col_carga) <= ?"; $pInv[] = $_GET['fecha_hasta']; $tInv .= 's';
      }
      // Permitir incluir archivados opcionalmente
      if (empty($_GET['incluir_archivados'])) { $f[] = "archivado = 0"; }
      $whereClause = count($f) > 0 ? " WHERE ".implode(" AND ", $f) : "";

      // Paginacion REAL con COUNT + OFFSET/LIMIT
      $per_page_opts = [20, 50, 100, 200];
      $per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $per_page_opts, true) ? (int)$_GET['per_page'] : 20;
      $page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
      $countSql = "SELECT COUNT(*) AS total FROM `{$prodTable}`{$whereClause}";
      $countRes = rp_consultar($conn, $countSql, $tInv, $pInv);
      $totalRows = $countRes ? (int)($countRes->fetch_assoc()['total'] ?? 0) : 0;
      $totalPages = max(1, (int)ceil($totalRows / $per_page));
      if ($page > $totalPages) $page = $totalPages;
      // LIMIT/OFFSET van interpolados: son enteros derivados de una whitelist
      // (per_page) y de un cast (page), no texto del request.
      $offset = ($page - 1) * $per_page;
      $sql = "SELECT * FROM `{$prodTable}`{$whereClause} ORDER BY id DESC LIMIT $per_page OFFSET $offset";
      $res = rp_consultar($conn, $sql, $tInv, $pInv);
      while ($row = $res->fetch_assoc()):
?>
      <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['modelo']) ?></td>
        <td><?= htmlspecialchars($row['marca']) ?></td>
        <td><?= htmlspecialchars($row['descripcion']) ?></td>
        <td><?= htmlspecialchars($row['categoria']) ?></td>
        <td><?= htmlspecialchars($row['subcategoria']) ?></td>
        <td><?= htmlspecialchars($row['cantidad']) ?></td>
        <td><?= htmlspecialchars($row['asignado_a']) ?></td>
        <td><?= htmlspecialchars($row['ubicacion']) ?></td>
        <td><?= htmlspecialchars($row['estado']) ?></td>
        <td>$<?= number_format($row['valor_usd'], 2) ?></td>
      </tr>
<?php endwhile; ?>
    </tbody>
  </table>
  </div>

  <div class="ih-pager">
    <form method="get" id="form-per-page-inventario" class="ih-pager__info">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page' || $key === 'page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars(is_array($value)?'':$value).'">'; } ?>
      <span class="ih-text-sm" style="color:var(--ih-gray-600);margin-right:8px;">
        <?= number_format($totalRows) ?> registro<?= $totalRows === 1 ? '' : 's' ?>
      </span>
      Mostrar:
      <select name="per_page" class="ih-select" onchange="document.getElementById('form-per-page-inventario').submit()">
        <?php foreach($per_page_opts as $opt): ?><option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?>
      </select>
      por página
    </form>
    <nav><ul class="ih-pager__paginas">
      <?php $start = max(1, $page-2); $end = min($totalPages, $page+2); ?>
      <li class="ih-pager__item <?= $page<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="ih-pager__item <?= $page<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="ih-pager__item <?= $i==$page?'is-active':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a></li><?php endfor; ?>
      <li class="ih-pager__item <?= $page>=$totalPages?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>">&rsaquo;</a></li>
      <li class="ih-pager__item <?= $page>=$totalPages?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ RANKING POR CANTIDAD ════════ -->
<div style="display:<?= $tab === 'valor' ? 'block' : 'none' ?>">
  <form method="GET" class="ih-filtros" id="form-valor">
    <input type="hidden" name="tab" value="valor">
    <div>
      <label class="ih-label">Categoría</label>
      <select name="categoria" id="categoria-reporte4" class="ih-select">
        <option value="">Todas</option>
        <?php foreach($categorias as $cat): ?>
          <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Subcategoría</label>
      <select name="subcategoria" id="subcategoria-reporte4" class="ih-select">
        <option value="">Todas</option>
      </select>
    </div>
    <div>
      <label class="ih-label">Desde Fecha de Carga</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="ih-label">Hasta Fecha de Carga</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end;">
      <button class="ih-btn ih-btn--primary" type="submit" name="ranking_cantidad">Filtrar</button>
      <button class="ih-btn ih-btn--ghost ih-btn--sm" type="button" onclick="resetRanking()">Todo</button>
    </div>
  </form>

  <div class="ih-filtros__acciones">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-valor"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-valor"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="ih-table-wrap">
  <table class="ih-table" id="tabla-valor">
    <thead>
      <tr><th>Modelo</th><th>Marca</th><th>Categoría</th><th>Subcategoría</th><th>Total Cantidad</th></tr>
    </thead>
    <tbody>
    <?php
      $f = []; $pVal = []; $tVal = '';
      if (!empty($_GET['categoria']))    { $f[] = "categoria = ?";    $pVal[] = $_GET['categoria'];    $tVal .= 's'; }
      if (!empty($_GET['subcategoria'])) { $f[] = "subcategoria = ?"; $pVal[] = $_GET['subcategoria']; $tVal .= 's'; }
      $col_carga_r = "IF(fecha_carga IS NOT NULL AND fecha_carga != '0000-00-00 00:00:00', DATE(fecha_carga), DATE(fecha_modificacion))";
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
        $f[] = "($col_carga_r) BETWEEN ? AND ?";
        $pVal[] = $_GET['fecha_desde']; $pVal[] = $_GET['fecha_hasta']; $tVal .= 'ss';
      } elseif (!empty($_GET['fecha_desde'])) {
        $f[] = "($col_carga_r) >= ?"; $pVal[] = $_GET['fecha_desde']; $tVal .= 's';
      } elseif (!empty($_GET['fecha_hasta'])) {
        $f[] = "($col_carga_r) <= ?"; $pVal[] = $_GET['fecha_hasta']; $tVal .= 's';
      }
      $f[] = "archivado = 0";
      $where = count($f) ? "WHERE ".implode(" AND ", $f) : "";

      // Paginacion REAL
      $per_page_opts_v = [20, 50, 100, 200];
      $per_page_v = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $per_page_opts_v, true) ? (int)$_GET['per_page'] : 20;
      $page_v = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
      $countSqlV = "
        SELECT COUNT(*) AS total FROM (
          SELECT 1
          FROM `{$prodTable}` $where
          GROUP BY UPPER(REPLACE(TRIM(REPLACE(modelo,CHAR(160),' ')),' ','')),
                   UPPER(REPLACE(TRIM(REPLACE(marca,CHAR(160),' ')),' ','')),
                   UPPER(REPLACE(TRIM(REPLACE(categoria,CHAR(160),' ')),' ','')),
                   UPPER(REPLACE(TRIM(REPLACE(subcategoria,CHAR(160),' ')),' ',''))
        ) c";
      $countResV = rp_consultar($conn, $countSqlV, $tVal, $pVal);
      $totalRowsV = $countResV ? (int)($countResV->fetch_assoc()['total'] ?? 0) : 0;
      $totalPagesV = max(1, (int)ceil($totalRowsV / $per_page_v));
      if ($page_v > $totalPagesV) $page_v = $totalPagesV;
      $offsetV = ($page_v - 1) * $per_page_v;

      $sql = "
        SELECT MAX(modelo_mostrar) AS modelo, MAX(marca_mostrar) AS marca,
               MAX(categoria_mostrar) AS categoria, MAX(subcategoria_mostrar) AS subcategoria,
               SUM(cantidad) AS total_cant
        FROM (
          SELECT
            UPPER(REPLACE(TRIM(REPLACE(modelo,      CHAR(160),' ')), ' ', '')) AS modelo_key,
            UPPER(REPLACE(TRIM(REPLACE(marca,       CHAR(160),' ')), ' ', '')) AS marca_key,
            UPPER(REPLACE(TRIM(REPLACE(categoria,   CHAR(160),' ')), ' ', '')) AS categoria_key,
            UPPER(REPLACE(TRIM(REPLACE(subcategoria,CHAR(160),' ')), ' ', '')) AS subcategoria_key,
            TRIM(REPLACE(modelo,      CHAR(160),' ')) AS modelo_mostrar,
            TRIM(REPLACE(marca,       CHAR(160),' ')) AS marca_mostrar,
            TRIM(REPLACE(categoria,   CHAR(160),' ')) AS categoria_mostrar,
            TRIM(REPLACE(subcategoria,CHAR(160),' ')) AS subcategoria_mostrar,
            cantidad
          FROM `{$prodTable}` $where
        ) t
        GROUP BY modelo_key, marca_key, categoria_key, subcategoria_key
        ORDER BY SUM(cantidad) DESC, marca ASC, modelo ASC
        LIMIT $per_page_v OFFSET $offsetV
      ";
      $res = rp_consultar($conn, $sql, $tVal, $pVal);
      $granTotal = 0;
      while ($row = $res->fetch_assoc()):
        $granTotal += (int)$row['total_cant'];
    ?>
      <tr>
        <td><?= htmlspecialchars($row['modelo']) ?></td>
        <td><?= htmlspecialchars($row['marca']) ?></td>
        <td><?= htmlspecialchars($row['categoria']) ?></td>
        <td><?= htmlspecialchars($row['subcategoria']) ?></td>
        <td><?= (int)$row['total_cant'] ?></td>
      </tr>
    <?php endwhile; ?>
    </tbody>
    <tfoot><tr><th colspan="4">TOTAL GENERAL:</th><th style="text-align:left"><?= (int)$granTotal ?></th></tr></tfoot>
  </table>
  </div>

  <div class="ih-pager">
    <form method="get" id="form-per-page-valor" class="ih-pager__info">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page' || $key === 'page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars(is_array($value)?'':$value).'">'; } ?>
      <span class="ih-text-sm" style="color:var(--ih-gray-600);margin-right:8px;">
        <?= number_format($totalRowsV) ?> registro<?= $totalRowsV === 1 ? '' : 's' ?>
      </span>
      Mostrar: <select name="per_page" class="ih-select" onchange="document.getElementById('form-per-page-valor').submit()">
        <?php foreach($per_page_opts_v as $opt): ?><option value="<?=$opt?>" <?=$per_page_v==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="ih-pager__paginas">
      <?php $start = max(1, $page_v-2); $end = min($totalPagesV, $page_v+2); ?>
      <li class="ih-pager__item <?= $page_v<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="ih-pager__item <?= $page_v<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page_v-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="ih-pager__item <?= $i==$page_v?'is-active':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="ih-pager__item <?= $page_v>=$totalPagesV?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPagesV,$page_v+1)])) ?>">&rsaquo;</a></li>
      <li class="ih-pager__item <?= $page_v>=$totalPagesV?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPagesV])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ AMORTIZACIÓN ════════ -->
<div style="display:<?= $tab === 'amortizacion' ? 'block' : 'none' ?>">
  <form method="GET" class="ih-filtros" id="form-amorti">
    <input type="hidden" name="tab" value="amortizacion">
    <div>
      <label class="ih-label">Años Amortización</label>
      <select name="anios" class="ih-select">
        <?php for($i=1;$i<=10;$i++): ?>
          <option value="<?=$i?>" <?=((isset($_GET['anios'])?(int)$_GET['anios']:4)===$i)?'selected':''?>><?=$i?> año<?=$i>1?'s':''?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Categoría</label>
      <select name="categoria" id="categoria-reporte3" class="ih-select" onchange="this.form.submit()">
        <option value="">Todas</option>
        <?php foreach($categorias as $cat): ?>
          <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Subcategoría</label>
      <select name="subcategoria" id="subcategoria-reporte3" class="ih-select">
        <option value="">Todas</option>
        <?php if (!empty($_GET['categoria']) && isset($categorias_full[$_GET['categoria']])): ?>
          <?php foreach($categorias_full[$_GET['categoria']] as $sub): ?>
            <option value="<?=htmlspecialchars($sub)?>" <?=($_GET['subcategoria']??'')===$sub?'selected':''?>><?=htmlspecialchars($sub)?></option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Marca</label>
      <input type="text" name="marca" class="ih-input" value="<?=htmlspecialchars($_GET['marca']??'')?>">
    </div>
    <div>
      <label class="ih-label">Modelo</label>
      <input type="text" name="modelo" class="ih-input" value="<?=htmlspecialchars($_GET['modelo']??'')?>">
    </div>
    <div>
      <label class="ih-label">Desde Fecha Compra/Ingreso</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="ih-label">Hasta Fecha Compra/Ingreso</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div style="display:flex;align-items:end;gap:8px;">
      <label class="ih-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
        <input type="checkbox" name="incluir_archivados" value="1" <?= !empty($_GET['incluir_archivados']) ? 'checked' : '' ?>>
        Incluir archivados
      </label>
    </div>
    <div>
      <button class="ih-btn ih-btn--primary" type="submit" name="filtro_amorti">Ver</button>
      <a href="?tab=amortizacion" class="ih-btn ih-btn--ghost" style="margin-left:6px;">Limpiar</a>
    </div>
  </form>

  <div class="ih-filtros__acciones">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-amortizacion"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-amortizacion"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="ih-table-wrap">
  <table class="ih-table" id="tabla-amortizacion">
    <thead>
      <tr>
        <th>ID</th><th>Modelo</th><th>Marca</th><th>Categoría</th><th>Subcategoría</th>
        <th>Fecha Base Amortiz.</th><th>Valor Compra USD</th><th>Años Amortiz.</th><th>Valor Actual</th>
      </tr>
    </thead>
    <tbody>
    <?php
      // Plazo general del reporte. Deja de ser LA amortizacion de todo el
      // inventario y pasa a ser el valor por defecto: si el activo tiene su
      // propio plazo cargado (Infocom), manda el del activo. Un servidor y un
      // patchcord no se amortizan en el mismo tiempo.
      $anios   = isset($_GET['anios']) ? max(1, (int)$_GET['anios']) : 4;
      $icTable = tn('activo_infocom');
      $filtrosA = ["(fecha_egreso IS NULL OR fecha_egreso = '0000-00-00')"];
      $pAmo = []; $tAmo = '';
      if (empty($_GET['incluir_archivados'])) { $filtrosA[] = "archivado = 0"; }
      if (!empty($_GET['categoria']))    { $filtrosA[] = "categoria = ?";                  $pAmo[] = $_GET['categoria'];         $tAmo .= 's'; }
      if (!empty($_GET['subcategoria'])) { $filtrosA[] = "subcategoria = ?";               $pAmo[] = $_GET['subcategoria'];      $tAmo .= 's'; }
      if (!empty($_GET['marca']))        { $filtrosA[] = "LOWER(marca) LIKE LOWER(?)";     $pAmo[] = '%'.$_GET['marca'].'%';     $tAmo .= 's'; }
      if (!empty($_GET['modelo']))       { $filtrosA[] = "LOWER(modelo) LIKE LOWER(?)";    $pAmo[] = '%'.$_GET['modelo'].'%';    $tAmo .= 's'; }
      // Filtro por fecha de compra (o ingreso como fallback)
      $col_amort_date = "COALESCE(NULLIF(fecha_compra,'0000-00-00'), NULLIF(fecha_ingreso,'0000-00-00'))";
      if (!empty($_GET['fecha_desde'])) {
        $filtrosA[] = "$col_amort_date >= ?"; $pAmo[] = $_GET['fecha_desde']; $tAmo .= 's';
      }
      if (!empty($_GET['fecha_hasta'])) {
        $filtrosA[] = "$col_amort_date <= ?"; $pAmo[] = $_GET['fecha_hasta']; $tAmo .= 's';
      }
      $whereA = "WHERE ".implode(" AND ", $filtrosA);

      // Paginacion REAL
      $per_page_opts_a = [20, 50, 100, 200];
      $per_page_a = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $per_page_opts_a, true) ? (int)$_GET['per_page'] : 20;
      $page_a = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
      $countResA = rp_consultar($conn, "SELECT COUNT(*) AS total FROM `{$prodTable}` pr $whereA", $tAmo, $pAmo);
      $totalRowsA = $countResA ? (int)($countResA->fetch_assoc()['total'] ?? 0) : 0;
      $totalPagesA = max(1, (int)ceil($totalRowsA / $per_page_a));
      if ($page_a > $totalPagesA) $page_a = $totalPagesA;
      $offsetA = ($page_a - 1) * $per_page_a;

      // Para los totales generales (de TODOS los registros filtrados, no solo la pagina)
      $totalCompra = 0; $totalActual = 0;
      $resTotales = rp_consultar($conn,
        "SELECT pr.valor_usd, pr.cantidad, pr.fecha_compra, pr.fecha_ingreso, ic.amortizacion_anios
           FROM `{$prodTable}` pr LEFT JOIN `{$icTable}` ic ON ic.producto_id = pr.id $whereA", $tAmo, $pAmo);
      if ($resTotales) {
        $hoyTot = new DateTime();
        while ($rt = $resTotales->fetch_assoc()) {
          $vc = (float)$rt['valor_usd'] * (int)$rt['cantidad'];
          $totalCompra += $vc;
          $fbT = null;
          if (!empty($rt['fecha_compra']) && $rt['fecha_compra'] !== '0000-00-00')    $fbT = new DateTime($rt['fecha_compra']);
          elseif (!empty($rt['fecha_ingreso']) && $rt['fecha_ingreso'] !== '0000-00-00') $fbT = new DateTime($rt['fecha_ingreso']);
          if ($fbT) {
            $diasTrans = (int)$hoyTot->diff($fbT)->days;
            $aniosTrans = $diasTrans / 365.25;
            $aniosAct = infocom_amortizacion_anios($rt, $anios);
            $depr = min(1, $aniosTrans / $aniosAct);
            $va = round($vc * (1 - $depr), 2);
            if ($va < 0) $va = 0;
          } else { $va = $vc; }
          $totalActual += $va;
        }
        $resTotales->close();
      }

      $sql = "SELECT pr.*, ic.amortizacion_anios, ic.amortizacion_tipo
                FROM `{$prodTable}` pr LEFT JOIN `{$icTable}` ic ON ic.producto_id = pr.id
               $whereA ORDER BY pr.id DESC LIMIT $per_page_a OFFSET $offsetA";
      $res = rp_consultar($conn, $sql, $tAmo, $pAmo);
      while($row = $res->fetch_assoc()):
        $fecha_base = null;
        if (!empty($row['fecha_compra']) && $row['fecha_compra'] !== '0000-00-00') {
            $fecha_base = new DateTime($row['fecha_compra']);
        } elseif (!empty($row['fecha_ingreso']) && $row['fecha_ingreso'] !== '0000-00-00') {
            $fecha_base = new DateTime($row['fecha_ingreso']);
        }
        $hoy = new DateTime();
        $valor_compra = (float)$row['valor_usd'] * (int)$row['cantidad'];
        $anios_activo = infocom_amortizacion_anios($row, $anios);
        if ($fecha_base && $anios_activo > 0) {
          // Calculo basado en dias para mas precision
          $diasTrans = (int)$hoy->diff($fecha_base)->days;
          $aniosTrans = $diasTrans / 365.25;
          $depreciacion = min(1, $aniosTrans / $anios_activo);
          $valor_actual = round($valor_compra * (1 - $depreciacion), 2);
          if ($valor_actual < 0) $valor_actual = 0;
        } else {
          $valor_actual = $valor_compra;
        }
        $fecha_base_str = $fecha_base ? $fecha_base->format('Y-m-d') : '—';
    ?>
      <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['modelo']) ?></td>
        <td><?= htmlspecialchars($row['marca']) ?></td>
        <td><?= htmlspecialchars($row['categoria']) ?></td>
        <td><?= htmlspecialchars($row['subcategoria']) ?></td>
        <td><?= htmlspecialchars($fecha_base_str) ?>
          <?php if (!empty($row['fecha_compra']) && $row['fecha_compra'] !== '0000-00-00'): ?>
            <br><small style="color:var(--ih-gray-400)">Compra</small>
          <?php elseif (!empty($row['fecha_ingreso']) && $row['fecha_ingreso'] !== '0000-00-00'): ?>
            <br><small style="color:var(--ih-gray-400)">Ingreso</small>
          <?php endif; ?>
        </td>
        <td>$<?= number_format($valor_compra,2) ?></td>
        <td><?=$anios_activo?><?= $anios_activo !== $anios ? ' <span class="ih-badge ih-badge--info">propio</span>' : '' ?></td>
        <td>$<?= number_format($valor_actual,2) ?></td>
      </tr>
    <?php endwhile; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="6">TOTAL Compra:</th>
        <th style="text-align:left">$<?= number_format($totalCompra,2) ?></th>
        <th>TOTAL Actual:</th>
        <th style="text-align:left">$<?= number_format($totalActual,2) ?></th>
      </tr>
    </tfoot>
  </table>
  </div>

  <div class="ih-pager">
    <form method="get" id="form-per-page-amortizacion" class="ih-pager__info">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page' || $key === 'page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars(is_array($value)?'':$value).'">'; } ?>
      <span class="ih-text-sm" style="color:var(--ih-gray-600);margin-right:8px;">
        <?= number_format($totalRowsA) ?> registro<?= $totalRowsA === 1 ? '' : 's' ?>
      </span>
      Mostrar: <select name="per_page" class="ih-select" onchange="document.getElementById('form-per-page-amortizacion').submit()">
        <?php foreach($per_page_opts_a as $opt): ?><option value="<?=$opt?>" <?=$per_page_a==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="ih-pager__paginas">
      <?php $start = max(1, $page_a-2); $end = min($totalPagesA, $page_a+2); ?>
      <li class="ih-pager__item <?= $page_a<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="ih-pager__item <?= $page_a<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page_a-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="ih-pager__item <?= $i==$page_a?'is-active':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="ih-pager__item <?= $page_a>=$totalPagesA?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPagesA,$page_a+1)])) ?>">&rsaquo;</a></li>
      <li class="ih-pager__item <?= $page_a>=$totalPagesA?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPagesA])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ TRAZABILIDAD ════════ -->
<div style="display:<?= $tab === 'trazabilidad' ? 'block' : 'none' ?>">
  <?php
  // Lista dinamica de acciones existentes en producto_movimientos
  $accionesT = [];
  $acRes = $conn->query("SELECT DISTINCT accion FROM `{$movTable}` WHERE accion IS NOT NULL AND accion != '' ORDER BY accion ASC");
  if ($acRes) { while ($ar = $acRes->fetch_assoc()) $accionesT[] = $ar['accion']; }
  ?>
  <form method="GET" class="ih-filtros" id="form-trazabilidad">
    <input type="hidden" name="tab" value="trazabilidad">
    <div>
      <label class="ih-label">Acción</label>
      <select name="accion" class="ih-select">
        <option value="">Todas</option>
        <?php foreach($accionesT as $ac): ?>
          <option value="<?=htmlspecialchars($ac)?>" <?=($_GET['accion']??'')===$ac?'selected':''?>><?=htmlspecialchars($ac)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Usuario</label>
      <input type="text" name="usuario" class="ih-input" value="<?=htmlspecialchars($_GET['usuario']??'')?>" placeholder="Nombre o Email">
    </div>
    <div>
      <label class="ih-label">Modelo</label>
      <input type="text" name="modelo" class="ih-input" value="<?=htmlspecialchars($_GET['modelo']??'')?>">
    </div>
    <div>
      <label class="ih-label">Marca</label>
      <input type="text" name="marca" class="ih-input" value="<?=htmlspecialchars($_GET['marca']??'')?>">
    </div>
    <div>
      <label class="ih-label">S/N Tag</label>
      <input type="text" name="servis_tag" class="ih-input" value="<?=htmlspecialchars($_GET['servis_tag']??'')?>">
    </div>
    <div>
      <label class="ih-label">Desde Fecha</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="ih-label">Hasta Fecha</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div>
      <button class="ih-btn ih-btn--primary" type="submit" name="trazabilidad">Buscar</button>
      <a href="?tab=trazabilidad" class="ih-btn ih-btn--ghost" style="margin-left:6px;">Limpiar</a>
    </div>
  </form>

  <div class="ih-filtros__acciones">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-trazabilidad"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-trazabilidad"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="ih-table-wrap">
  <table class="ih-table" id="tabla-trazabilidad">
    <thead>
      <tr>
        <th>Fecha</th><th>Acción</th><th>Modelo</th><th>Marca</th><th>S/N Tag</th>
        <th>Cantidad</th><th>Valor USD</th><th>Estado</th>
        <th>Garantía</th><th>Ingreso</th><th>Egreso</th>
        <th>Ubicación</th><th>Detalle Técnico</th>
        <th>Categoría</th><th>Subcategoría</th><th>Usuario</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $whereT = ["pm.realizado_por != 0"];
      $paramsT = []; $typesT = "";
      if (!empty($_GET['accion']))     { $whereT[] = "pm.accion = ?";          $paramsT[] = $_GET['accion'];                $typesT .= "s"; }
      if (!empty($_GET['usuario']))    { $whereT[] = "(u.username LIKE ? OR u.email LIKE ?)"; $paramsT[] = "%".$_GET['usuario']."%"; $paramsT[] = "%".$_GET['usuario']."%"; $typesT .= "ss"; }
      if (!empty($_GET['modelo']))     { $whereT[] = "LOWER(pm.modelo) LIKE LOWER(?)";   $paramsT[] = "%".$_GET['modelo']."%";    $typesT .= "s"; }
      if (!empty($_GET['marca']))      { $whereT[] = "LOWER(pm.marca) LIKE LOWER(?)";    $paramsT[] = "%".$_GET['marca']."%";     $typesT .= "s"; }
      if (!empty($_GET['servis_tag'])) { $whereT[] = "LOWER(pm.servis_tag) LIKE LOWER(?)"; $paramsT[] = "%".$_GET['servis_tag']."%"; $typesT .= "s"; }
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
        $whereT[] = "DATE(pm.fecha) BETWEEN ? AND ?";
        $paramsT[] = $_GET['fecha_desde']; $paramsT[] = $_GET['fecha_hasta']; $typesT .= "ss";
      } elseif (!empty($_GET['fecha_desde'])) {
        $whereT[] = "DATE(pm.fecha) >= ?"; $paramsT[] = $_GET['fecha_desde']; $typesT .= "s";
      } elseif (!empty($_GET['fecha_hasta'])) {
        $whereT[] = "DATE(pm.fecha) <= ?"; $paramsT[] = $_GET['fecha_hasta']; $typesT .= "s";
      }
      $whereSqlT = "WHERE " . implode(" AND ", $whereT);

      // Paginacion REAL
      $per_page_opts_t = [20, 50, 100, 200];
      $per_page_t = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $per_page_opts_t, true) ? (int)$_GET['per_page'] : 50;
      $page_t = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
      $countSqlT = "SELECT COUNT(*) AS total FROM `{$movTable}` pm LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id $whereSqlT";
      $stmtCT = $conn->prepare($countSqlT);
      if (!empty($paramsT)) $stmtCT->bind_param($typesT, ...$paramsT);
      $stmtCT->execute();
      $totalRowsT = (int)($stmtCT->get_result()->fetch_assoc()['total'] ?? 0);
      $stmtCT->close();
      $totalPagesT = max(1, (int)ceil($totalRowsT / $per_page_t));
      if ($page_t > $totalPagesT) $page_t = $totalPagesT;
      $offsetT = ($page_t - 1) * $per_page_t;

      $sql = "SELECT pm.fecha,pm.accion,u.username,u.email,
              pm.modelo AS modelo_mod, pm.marca AS marca_mod, pm.servis_tag AS tag_mod, pm.cantidad AS cant_mod,
              pm.valor_usd AS valor_mod, pm.estado AS estado_mod, pm.fecha_garantia AS garant_mod,
              pm.fecha_ingreso AS ingreso_mod, pm.fecha_egreso AS egreso_mod, pm.ubicacion AS ubic_mod,
              pm.detalle_tecnico AS detec_mod, pm.patchcord_tipo AS pctipo_mod, pm.patchcord_longitud AS pclong_mod,
              pm.categoria AS cat_mod, pm.subcategoria AS sub_mod
              FROM `{$movTable}` pm
              LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id
              $whereSqlT ORDER BY pm.fecha DESC LIMIT $per_page_t OFFSET $offsetT";
      $stmt = $conn->prepare($sql);
      if (!empty($paramsT)) $stmt->bind_param($typesT, ...$paramsT);
      $stmt->execute();
      $res = $stmt->get_result();
      $hay_datos = false;
      while ($row = $res->fetch_assoc()):
        $hay_datos = true;
        $dt = trim($row['detec_mod']);
        if ($dt == '' && $row['pctipo_mod']) $dt = "{$row['pctipo_mod']} – {$row['pclong_mod']} m";
        $dt = htmlspecialchars($dt, ENT_QUOTES);
        $usr = htmlspecialchars($row['username'], ENT_QUOTES) . '<br><small style="color:var(--ih-gray-400)">'.htmlspecialchars($row['email'], ENT_QUOTES)."</small>";
    ?>
      <tr>
        <td><?= $row['fecha'] ?></td>
        <td><?= htmlspecialchars($row['accion']) ?></td>
        <td><?= htmlspecialchars($row['modelo_mod']) ?></td>
        <td><?= htmlspecialchars($row['marca_mod']) ?></td>
        <td><?= htmlspecialchars($row['tag_mod']) ?></td>
        <td><?= htmlspecialchars($row['cant_mod']) ?></td>
        <td>$<?= number_format($row['valor_mod'],2) ?></td>
        <td><?= htmlspecialchars($row['estado_mod']) ?></td>
        <td><?= htmlspecialchars($row['garant_mod']) ?></td>
        <td><?= htmlspecialchars($row['ingreso_mod']) ?></td>
        <td><?= htmlspecialchars($row['egreso_mod']) ?></td>
        <td><?= htmlspecialchars($row['ubic_mod']) ?></td>
        <td><?= $dt ?></td>
        <td><?= htmlspecialchars($row['cat_mod']) ?></td>
        <td><?= htmlspecialchars($row['sub_mod']) ?></td>
        <td><?= $usr ?></td>
      </tr>
    <?php endwhile; ?>
    <?php if (!$hay_datos): ?>
      <tr><td colspan="16" style="text-align:center;color:var(--ih-gray-400);padding:24px;">No hay datos disponibles</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="ih-pager">
    <form method="get" id="form-per-page-trazabilidad" class="ih-pager__info">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page' || $key === 'page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars(is_array($value)?'':$value).'">'; } ?>
      <span class="ih-text-sm" style="color:var(--ih-gray-600);margin-right:8px;">
        <?= number_format($totalRowsT) ?> registro<?= $totalRowsT === 1 ? '' : 's' ?>
      </span>
      Mostrar: <select name="per_page" class="ih-select" onchange="document.getElementById('form-per-page-trazabilidad').submit()">
        <?php foreach($per_page_opts_t as $opt): ?><option value="<?=$opt?>" <?=$per_page_t==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="ih-pager__paginas">
      <?php $start = max(1, $page_t-2); $end = min($totalPagesT, $page_t+2); ?>
      <li class="ih-pager__item <?= $page_t<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="ih-pager__item <?= $page_t<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page_t-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="ih-pager__item <?= $i==$page_t?'is-active':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="ih-pager__item <?= $page_t>=$totalPagesT?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPagesT,$page_t+1)])) ?>">&rsaquo;</a></li>
      <li class="ih-pager__item <?= $page_t>=$totalPagesT?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPagesT])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ ARCHIVADOS ════════ -->
<div style="display:<?= $tab === 'archivados' ? 'block' : 'none' ?>">
  <?php
  // Motivos: union de fijos + los detectados en DB historica
  $motivosDef = ['Venta','Donación','Scrapping','Desguace','Perdida','Robo'];
  $motivosDB = [];
  $mvRes = $conn->query("SELECT DISTINCT descripcion FROM `{$movTable}` WHERE accion = 'Archivado' AND descripcion LIKE 'Motivo:%'");
  if ($mvRes) {
    while ($mr = $mvRes->fetch_assoc()) {
      if (preg_match('/Motivo:\s*([^|]+)/', (string)$mr['descripcion'], $mm)) {
        $m = trim($mm[1]);
        if ($m !== '' && !in_array($m, $motivosDB, true)) $motivosDB[] = $m;
      }
    }
  }
  $motivosTodos = array_unique(array_merge($motivosDef, $motivosDB));
  sort($motivosTodos);
  ?>
  <form method="GET" class="ih-filtros" id="form-archivados">
    <input type="hidden" name="tab" value="archivados">
    <div>
      <label class="ih-label">Motivo</label>
      <select name="motivo" class="ih-select">
        <option value="">Todos</option>
        <?php foreach($motivosTodos as $motivo): ?>
          <option value="<?=htmlspecialchars($motivo)?>" <?=($_GET['motivo']??'')===$motivo?'selected':''?>><?=htmlspecialchars($motivo)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="ih-label">Responsable</label>
      <input type="text" name="usuario" class="ih-input" value="<?=htmlspecialchars($_GET['usuario']??'')?>" placeholder="Nombre o Email">
    </div>
    <div>
      <label class="ih-label">Desde Fecha</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="ih-label">Hasta Fecha</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div>
      <button class="ih-btn ih-btn--primary" type="submit">Filtrar</button>
    </div>
  </form>

  <div class="ih-filtros__acciones">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-archivados"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-archivados"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="ih-table-wrap">
  <table class="ih-table" id="tabla-archivados">
    <thead>
      <tr>
        <th>Fecha Archivo</th><th>Motivo</th><th>Detalle</th><th>Responsable</th>
        <th>ID</th><th>Modelo</th><th>Marca</th><th>S/N Tag</th>
        <th>Categoría</th><th>Subcategoría</th><th>Estado</th>
        <th>Garantía</th><th>Ingreso</th><th>Egreso</th>
        <th>Ubicación</th><th>Asignado a</th><th>Valor USD</th><th>Cantidad</th>
        <th>Detalle Técnico</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $whereAR = ["pm.accion = 'Archivado'"];
      $paramsAR = []; $typesAR = "";
      if (!empty($_GET['motivo']))   { $whereAR[] = "pm.descripcion LIKE ?"; $paramsAR[] = "%Motivo: ".$_GET['motivo']."%"; $typesAR .= "s"; }
      if (!empty($_GET['usuario']))  { $whereAR[] = "(u.username LIKE ? OR u.email LIKE ?)"; $paramsAR[] = "%".$_GET['usuario']."%"; $paramsAR[] = "%".$_GET['usuario']."%"; $typesAR .= "ss"; }
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
        $whereAR[] = "DATE(pm.fecha) BETWEEN ? AND ?"; $paramsAR[] = $_GET['fecha_desde']; $paramsAR[] = $_GET['fecha_hasta']; $typesAR .= "ss";
      } elseif (!empty($_GET['fecha_desde'])) {
        $whereAR[] = "DATE(pm.fecha) >= ?"; $paramsAR[] = $_GET['fecha_desde']; $typesAR .= "s";
      } elseif (!empty($_GET['fecha_hasta'])) {
        $whereAR[] = "DATE(pm.fecha) <= ?"; $paramsAR[] = $_GET['fecha_hasta']; $typesAR .= "s";
      }
      $whereSqlAR = "WHERE " . implode(" AND ", $whereAR);

      // Paginacion REAL
      $per_page_opts_ar = [20, 50, 100, 200];
      $per_page_ar = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $per_page_opts_ar, true) ? (int)$_GET['per_page'] : 20;
      $page_ar = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
      $countSqlAR = "SELECT COUNT(*) AS total FROM `{$movTable}` pm LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id $whereSqlAR";
      $stmtCAR = $conn->prepare($countSqlAR);
      if (!empty($paramsAR)) $stmtCAR->bind_param($typesAR, ...$paramsAR);
      $stmtCAR->execute();
      $totalRowsAR = (int)($stmtCAR->get_result()->fetch_assoc()['total'] ?? 0);
      $stmtCAR->close();
      $totalPagesAR = max(1, (int)ceil($totalRowsAR / $per_page_ar));
      if ($page_ar > $totalPagesAR) $page_ar = $totalPagesAR;
      $offsetAR = ($page_ar - 1) * $per_page_ar;

      $sql = "SELECT pm.*, u.username, u.email
              FROM `{$movTable}` pm
              LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id
              $whereSqlAR
              ORDER BY pm.fecha DESC LIMIT $per_page_ar OFFSET $offsetAR";
      $stmt = $conn->prepare($sql);
      if (!empty($paramsAR)) $stmt->bind_param($typesAR, ...$paramsAR);
      $stmt->execute();
      $res = $stmt->get_result();
      $hay_datos = false;
      while ($row = $res->fetch_assoc()):
        $hay_datos = true;
        $motivo = '—'; $detalle_txt = '—';
        if (isset($row['descripcion'])) {
            if (preg_match('/Motivo:\s*([^\|]+)/', $row['descripcion'], $matches)) $motivo = trim($matches[1]);
            if (str_contains($row['descripcion'], 'Detalle:')) {
                $detalle_txt = explode('Detalle:', $row['descripcion'])[1] ?? '';
                $detalle_txt = trim($detalle_txt);
            }
        }
        $dt = isset($row['detalle_tecnico']) ? htmlspecialchars($row['detalle_tecnico']) : '—';
    ?>
      <tr>
        <td><?= $row['fecha'] ?></td>
        <td><?= htmlspecialchars($motivo) ?></td>
        <td><?= htmlspecialchars($detalle_txt) ?></td>
        <td><?= htmlspecialchars($row['username']) ?><br><small style="color:var(--ih-gray-400)"><?=htmlspecialchars($row['email'])?></small></td>
        <td><?= $row['producto_id'] ?></td>
        <td><?= htmlspecialchars($row['modelo']) ?></td>
        <td><?= htmlspecialchars($row['marca']) ?></td>
        <td><?= htmlspecialchars($row['servis_tag']) ?></td>
        <td><?= htmlspecialchars($row['categoria']) ?></td>
        <td><?= htmlspecialchars($row['subcategoria']) ?></td>
        <td><?= htmlspecialchars($row['estado']) ?></td>
        <td><?= htmlspecialchars($row['fecha_garantia']) ?></td>
        <td><?= htmlspecialchars($row['fecha_ingreso']) ?></td>
        <td><?= htmlspecialchars($row['fecha_egreso']) ?></td>
        <td><?= htmlspecialchars($row['ubicacion']) ?></td>
        <td><?= htmlspecialchars($row['asignado_a']) ?></td>
        <td>$<?= number_format($row['valor_usd'],2) ?></td>
        <td><?= $row['cantidad'] ?></td>
        <td><?= $dt ?></td>
      </tr>
    <?php endwhile; ?>
    <?php if (!$hay_datos): ?>
      <tr><td colspan="19" style="text-align:center;color:var(--ih-gray-400);padding:24px;">No hay datos disponibles</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="ih-pager">
    <form method="get" id="form-per-page-archivados" class="ih-pager__info">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page' || $key === 'page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars(is_array($value)?'':$value).'">'; } ?>
      <span class="ih-text-sm" style="color:var(--ih-gray-600);margin-right:8px;">
        <?= number_format($totalRowsAR) ?> registro<?= $totalRowsAR === 1 ? '' : 's' ?>
      </span>
      Mostrar: <select name="per_page" class="ih-select" onchange="document.getElementById('form-per-page-archivados').submit()">
        <?php foreach($per_page_opts_ar as $opt): ?><option value="<?=$opt?>" <?=$per_page_ar==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="ih-pager__paginas">
      <?php $start = max(1, $page_ar-2); $end = min($totalPagesAR, $page_ar+2); ?>
      <li class="ih-pager__item <?= $page_ar<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="ih-pager__item <?= $page_ar<=1?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page_ar-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="ih-pager__item <?= $i==$page_ar?'is-active':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="ih-pager__item <?= $page_ar>=$totalPagesAR?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPagesAR,$page_ar+1)])) ?>">&rsaquo;</a></li>
      <li class="ih-pager__item <?= $page_ar>=$totalPagesAR?'is-disabled':'' ?>"><a class="ih-pager__link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPagesAR])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

</main>
</div><!-- /ih-app -->

<!-- pdfmake para exportación PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script>

  // Categorías para los selects dependientes
  const categoriasFull = <?= json_encode($categorias_full, JSON_UNESCAPED_UNICODE) ?>;

  const selectPairs = [
    {cat:'#categoria-reporte',  sub:'#subcategoria-reporte'},
    {cat:'#categoria-reporte3', sub:'#subcategoria-reporte3'},
    {cat:'#categoria-reporte4', sub:'#subcategoria-reporte4'}
  ];

  selectPairs.forEach(pair => {
    const catSel = document.querySelector(pair.cat);
    const subSel = document.querySelector(pair.sub);
    if (!catSel || !subSel) return;
    function loadSubcategorias() {
      const cat = catSel.value;
      subSel.innerHTML = '';
      if (!cat) {
        const opt = document.createElement('option'); opt.value = ''; opt.textContent = 'Todas';
        subSel.appendChild(opt); subSel.setAttribute('disabled','disabled'); return;
      }
      subSel.removeAttribute('disabled');
      const subcats = categoriasFull[cat] || [];
      const opt = document.createElement('option'); opt.value = ''; opt.textContent = 'Todas';
      subSel.appendChild(opt);
      subcats.forEach(sub => {
        const option = document.createElement('option'); option.value = sub; option.textContent = sub;
        subSel.appendChild(option);
      });
    }
    catSel.addEventListener('change', function(){ subSel.value = ''; loadSubcategorias(); });
    loadSubcategorias();
  });

  function resetRanking() {
    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('tab','valor');
    window.location.href = url.toString();
  }

  // ── Exportaciones ──────────────────────────────────────────────
  function ensureAllColumnsVisible(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    table.querySelectorAll('thead th, tbody td').forEach(el => el.style.display = '');
  }

  // ── Generador de PDF profesional ─────────────────────────────────
  function tableToPdf(tableId, tituloReporte) {
    ensureAllColumnsVisible(tableId);
    const table = document.getElementById(tableId);
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const totalColumns = headers.length;

    // Colores corporativos
    const PURPLE      = '#663399';
    const PURPLE_DARK = '#4E247A';
    const TEXT        = 'var(--ink)';
    const TEXT_MUTED  = 'var(--muted)';
    const STRIPE      = 'var(--soft)';
    const BORDER      = 'var(--line)';

    // Orientation segun cantidad de columnas
    const orientation = totalColumns > 6 ? 'landscape' : 'portrait';

    // Construir body con header + filas
    const headerRow = headers.map(h => ({
      text: h,
      bold: true,
      color: '#fff',
      fillColor: PURPLE,
      fontSize: 8.5,
      margin: [5, 7, 5, 7],
      alignment: 'left'
    }));
    const body = [headerRow];

    rows.forEach((row, idx) => {
      const cells = Array.from(row.querySelectorAll('td'));
      const dataRow = headers.map((_, i) => {
        const text = (cells[i]?.innerText || '').replace(/\s+/g, ' ').trim() || '—';
        return {
          text,
          fontSize: 8,
          color: TEXT,
          fillColor: idx % 2 === 0 ? '#ffffff' : STRIPE,
          margin: [5, 6, 5, 6],
          alignment: 'left'
        };
      });
      body.push(dataRow);
    });

    // Subtitulo con fecha y cantidad
    const fechaStr = new Date().toLocaleString('es-AR', { dateStyle: 'long', timeStyle: 'short' });
    const cantStr  = rows.length + ' ' + (rows.length === 1 ? 'registro' : 'registros');

    const docDef = {
      pageSize: 'A4',
      pageOrientation: orientation,
      pageMargins: [25, 75, 25, 45],

      header: function() {
        return {
          margin: [25, 22, 25, 0],
          stack: [
            {
              columns: [
                { text: 'ASSET VAULT', bold: true, color: PURPLE, fontSize: 11, letterSpacing: 1.5 },
                { text: 'INTELLIHELP S.A.', alignment: 'right', color: TEXT_MUTED, fontSize: 8 }
              ]
            },
            { canvas: [{ type: 'line', x1: 0, y1: 6, x2: orientation === 'landscape' ? 792 : 545, y2: 6, lineWidth: 1, lineColor: PURPLE }] }
          ]
        };
      },

      footer: function(currentPage, pageCount) {
        return {
          margin: [25, 5, 25, 0],
          columns: [
            { text: 'Generado: ' + fechaStr, fontSize: 7, color: TEXT_MUTED },
            { text: 'Pagina ' + currentPage + ' de ' + pageCount, fontSize: 7, color: TEXT_MUTED, alignment: 'right' }
          ]
        };
      },

      content: [
        { text: tituloReporte, fontSize: 18, bold: true, color: TEXT, margin: [0, 0, 0, 3] },
        { text: cantStr, fontSize: 9, color: TEXT_MUTED, margin: [0, 0, 0, 14] },
        {
          table: { headerRows: 1, widths: Array(totalColumns).fill('*'), body: body },
          layout: {
            hLineWidth: function(i, node) { return i === 0 || i === 1 || i === node.table.body.length ? 0.8 : 0.3; },
            vLineWidth: function() { return 0; },
            hLineColor: function(i) { return i === 0 || i === 1 ? PURPLE_DARK : BORDER; },
            paddingLeft: function() { return 0; },
            paddingRight: function() { return 0; },
            paddingTop: function() { return 0; },
            paddingBottom: function() { return 0; }
          }
        }
      ],

      defaultStyle: { fontSize: 8 }
    };

    pdfMake.createPdf(docDef).download(tituloReporte.replace(/\s+/g, '_') + '.pdf');
  }

  document.querySelectorAll('.export-pdf-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const tableId = btn.getAttribute('data-table');
      let titulo = 'Reporte de Inventario';
      if (tableId === 'tabla-valor')        titulo = 'Ranking por Cantidad';
      if (tableId === 'tabla-amortizacion') titulo = 'Amortización';
      if (tableId === 'tabla-trazabilidad') titulo = 'Trazabilidad';
      if (tableId === 'tabla-archivados')   titulo = 'Archivados';
      tableToPdf(tableId, titulo);
    });
  });

  function tableToCSV(tableId, filename) {
    ensureAllColumnsVisible(tableId);
    const table = document.getElementById(tableId);
    if (!table) return;
    function escapeCSV(value) {
      const v = (value ?? '').toString();
      if (/[",\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
      return v;
    }
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
    const lines = [headers.map(escapeCSV).join(',')];
    Array.from(table.querySelectorAll('tbody tr')).forEach(tr => {
      const cells = Array.from(tr.querySelectorAll('td')).map(td => td.innerText.replace(/\s+/g,' ').trim()||'—').map(escapeCSV);
      lines.push(cells.join(','));
    });
    const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', filename.endsWith('.csv') ? filename : filename + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  document.querySelectorAll('.export-csv-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const tableId = btn.getAttribute('data-table');
      let nombre = 'reporte';
      if (tableId === 'tabla-inventario')   nombre = 'inventario_general';
      if (tableId === 'tabla-valor')        nombre = 'ranking_por_cantidad';
      if (tableId === 'tabla-amortizacion') nombre = 'amortizacion';
      if (tableId === 'tabla-trazabilidad') nombre = 'trazabilidad';
      if (tableId === 'tabla-archivados')   nombre = 'archivados';
      tableToCSV(tableId, `${nombre}_${new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')}.csv`);
    });
  });
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
