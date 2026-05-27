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
<style>
/* ─── Tab nav ──────────────────────────────────────────────────────── */
.rp-tabnav {
  display: flex; flex-wrap: wrap; gap: 0;
  background: var(--ih-white);
  border-bottom: 2px solid var(--ih-gray-200);
  margin-bottom: 20px;
  border-radius: var(--ih-radius-lg) var(--ih-radius-lg) 0 0;
}
.rp-tab {
  padding: 11px 20px;
  font-size: var(--ih-text-sm);
  font-weight: 600;
  color: var(--ih-gray-600);
  text-decoration: none;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  white-space: nowrap;
  transition: color .15s, border-color .15s;
}
.rp-tab:hover { color: var(--ih-primary); }
.rp-tab--active { color: var(--ih-green); border-bottom-color: var(--ih-green); font-weight: 700; }

/* ─── Filtros ────────────────────────────────────────────────────── */
.rp-filter {
  background: var(--ih-gray-50);
  padding: 16px;
  border-radius: var(--ih-radius-md);
  margin-bottom: 20px;
  border: 1px solid var(--ih-gray-200);
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 10px 12px;
  align-items: end;
}
.rp-label {
  font-size: 12px; font-weight: 600; color: var(--ih-gray-600);
  text-transform: uppercase; letter-spacing: .04em;
  display: block; margin-bottom: 4px;
}

/* ─── Export buttons ─────────────────────────────────────────────── */
.export-btns { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }

/* ─── Tablas ─────────────────────────────────────────────────────── */
.rp-table-wrap { overflow-x: auto; border-radius: var(--ih-radius-md); border: 1px solid var(--ih-gray-200); margin-bottom: 16px; }
.rp-table { width: 100%; border-collapse: collapse; font-size: var(--ih-text-sm); background: #fff; }
.rp-table thead th {
  background: var(--ih-primary, #663399) !important;
  color: #ffffff !important;
  padding: 10px 12px;
  text-align: left; font-weight: 700; white-space: nowrap; font-size: 12px;
  letter-spacing: .03em;
  text-transform: uppercase;
}
.rp-table tbody tr:nth-child(even) { background: var(--ih-gray-50, #f7f7fa); }
.rp-table tbody tr:hover { background: #ede6fa; transition: background .12s; }
.rp-table tbody td {
  padding: 8px 12px;
  border-bottom: 1px solid var(--ih-gray-100, #ececf0);
  vertical-align: middle;
  color: var(--ih-gray-800, #232337);
}
.rp-table tfoot th {
  background: var(--ih-gray-100, #ececf0) !important;
  color: var(--ih-gray-800, #232337) !important;
  padding: 10px 12px;
  font-weight: 700;
  text-align: right;
}
.rp-table tfoot th:last-child { text-align: left; }

/* ─── Paginador ──────────────────────────────────────────────────── */
.paginador-container { display: flex; align-items: center; justify-content: space-between; gap: 2rem; margin-top: 12px; flex-wrap: wrap; }
@media(max-width:700px) { .paginador-container { flex-direction: column; align-items: stretch; gap: .8rem; } }
.select-perpage-label { color: var(--ih-primary); font-size: var(--ih-text-sm); font-weight: 600; display: flex; align-items: center; gap: .65em; }
.select-perpage {
  appearance: none; -webkit-appearance: none;
  border: 2px solid var(--ih-green); border-radius: 20px;
  color: var(--ih-primary); font-size: var(--ih-text-sm); font-weight: 700;
  background: #fff; padding: .2em 1.2em; width: 80px; text-align: center; outline: none;
}
.pagination-cool { display: flex; gap: .15em; list-style: none; padding-left: 0; margin: 0; }
.pagination-cool .page-link {
  border-radius: 50px; color: var(--ih-primary); background: #fff;
  border: 2px solid var(--ih-gray-200); font-weight: 700; font-size: 13px;
  padding: .2em .8em; text-decoration: none; display: block;
  transition: background .2s, color .2s, border-color .2s;
}
.pagination-cool .page-link:hover { background: var(--ih-primary); color: #fff; border-color: var(--ih-primary); }
.pagination-cool .page-item.active .page-link { background: var(--ih-green); color: #fff; border-color: var(--ih-green); }
.pagination-cool .page-item.disabled .page-link { color: #aaa; background: #f5f5f5; border-color: #f0f0f0; pointer-events: none; }
@media(max-width:640px) { .rp-filter { grid-template-columns: 1fr; } }
</style>

<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

<!-- ── Tab nav ──────────────────────────────────────── -->
<nav class="rp-tabnav">
  <a class="rp-tab <?= $tab === 'inventario'  ? 'rp-tab--active' : '' ?>" href="?tab=inventario">Inventario General</a>
  <a class="rp-tab <?= $tab === 'valor'        ? 'rp-tab--active' : '' ?>" href="?tab=valor">Ranking por Cantidad</a>
  <a class="rp-tab <?= $tab === 'amortizacion' ? 'rp-tab--active' : '' ?>" href="?tab=amortizacion">Amortización</a>
  <a class="rp-tab <?= $tab === 'trazabilidad' ? 'rp-tab--active' : '' ?>" href="?tab=trazabilidad">Trazabilidad</a>
  <a class="rp-tab <?= $tab === 'archivados'   ? 'rp-tab--active' : '' ?>" href="?tab=archivados">Archivados</a>
</nav>

<!-- ════════ INVENTARIO GENERAL ════════ -->
<div style="display:<?= $tab === 'inventario' ? 'block' : 'none' ?>">
  <form method="GET" class="rp-filter" id="form-inventario">
    <input type="hidden" name="tab" value="inventario">
    <div>
      <label class="rp-label">Categoría</label>
      <select name="categoria" id="categoria-reporte" class="ih-select">
        <option value="">Todas</option>
        <?php foreach($categorias as $cat): ?>
          <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="rp-label">Subcategoría</label>
      <select name="subcategoria" id="subcategoria-reporte" class="ih-select">
        <option value="">Todas</option>
      </select>
    </div>
    <div>
      <label class="rp-label">Marca</label>
      <input type="text" name="marca" class="ih-input" value="<?=htmlspecialchars($_GET['marca']??'')?>">
    </div>
    <div>
      <label class="rp-label">Modelo</label>
      <input type="text" name="modelo" class="ih-input" value="<?=htmlspecialchars($_GET['modelo']??'')?>">
    </div>
    <div>
      <label class="rp-label">Estado</label>
      <select name="estado" class="ih-select">
        <option value="">Todos</option>
        <option value="Nuevo" <?=($_GET['estado']??'')==='Nuevo'?'selected':''?>>Nuevo</option>
        <option value="Usado" <?=($_GET['estado']??'')==='Usado'?'selected':''?>>Usado</option>
      </select>
    </div>
    <div>
      <label class="rp-label">Locación</label>
      <input type="text" name="ubicacion" class="ih-input" value="<?=htmlspecialchars($_GET['ubicacion']??'')?>">
    </div>
    <div>
      <label class="rp-label">Desde Fecha de Carga</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="rp-label">Hasta Fecha de Carga</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div>
      <button class="ih-btn ih-btn--accent" type="submit">Filtrar</button>
    </div>
  </form>

  <div class="export-btns">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-inventario"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-inventario"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="rp-table-wrap">
  <table class="rp-table" id="tabla-inventario">
    <thead>
      <tr>
        <th>ID</th><th>Modelo</th><th>Marca</th><th>Descripción</th>
        <th>Categoría</th><th>Subcategoría</th><th>Cantidad</th>
        <th>Asignado a</th><th>Locación</th><th>Estado</th><th>Valor USD</th>
      </tr>
    </thead>
    <tbody>
<?php
      $f = [];
      if (!empty($_GET['categoria']))    $f[] = "categoria = '".$conn->real_escape_string($_GET['categoria'])."'";
      if (!empty($_GET['subcategoria'])) $f[] = "subcategoria = '".$conn->real_escape_string($_GET['subcategoria'])."'";
      if (!empty($_GET['marca']))        $f[] = "LOWER(marca) LIKE LOWER('%".$conn->real_escape_string($_GET['marca'])."%')";
      if (!empty($_GET['modelo']))       $f[] = "LOWER(modelo) LIKE LOWER('%".$conn->real_escape_string($_GET['modelo'])."%')";
      if (!empty($_GET['estado']))       $f[] = "estado = '".$conn->real_escape_string($_GET['estado'])."'";
      if (!empty($_GET['ubicacion']))    $f[] = "LOWER(ubicacion) LIKE LOWER('%".$conn->real_escape_string($_GET['ubicacion'])."%')";
      $col_carga = "IF(fecha_carga IS NOT NULL AND fecha_carga != '0000-00-00 00:00:00', DATE(fecha_carga), DATE(fecha_modificacion))";
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
          $fd = $conn->real_escape_string($_GET['fecha_desde']);
          $fh = $conn->real_escape_string($_GET['fecha_hasta']);
          $f[] = "($col_carga) BETWEEN '$fd' AND '$fh'";
      } elseif (!empty($_GET['fecha_desde'])) {
          $fd = $conn->real_escape_string($_GET['fecha_desde']);
          $f[] = "($col_carga) >= '$fd'";
      } elseif (!empty($_GET['fecha_hasta'])) {
          $fh = $conn->real_escape_string($_GET['fecha_hasta']);
          $f[] = "($col_carga) <= '$fh'";
      }
      $f[] = "archivado = 0";
      $hay_filtros = count($f) > 0;
      $sql = "SELECT * FROM `{$prodTable}`"
           . ($hay_filtros ? " WHERE ".implode(" AND ", $f) : "")
           . " ORDER BY id DESC "
           . (empty($_GET) ? "LIMIT 100" : "");
      $res = $conn->query($sql);
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

  <div class="paginador-container">
    <form method="get" id="form-per-page-inventario" class="select-perpage-label">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">'; }
        $perPageOptions = [20, 50, 100];
        $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 20;
        $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
      ?>
      Mostrar:
      <select name="per_page" class="select-perpage" onchange="document.getElementById('form-per-page-inventario').submit()">
        <?php foreach($perPageOptions as $opt): ?><option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?>
      </select>
      por página
    </form>
    <nav><ul class="pagination-cool">
      <?php $totalRows = $res->num_rows ?? 0; $totalPages = max(1, ceil($totalRows / $per_page)); $start = max(1, $page-2); $end = min($totalPages, $page+2); ?>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a></li><?php endfor; ?>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>">&rsaquo;</a></li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ RANKING POR CANTIDAD ════════ -->
<div style="display:<?= $tab === 'valor' ? 'block' : 'none' ?>">
  <form method="GET" class="rp-filter" id="form-valor">
    <input type="hidden" name="tab" value="valor">
    <div>
      <label class="rp-label">Categoría</label>
      <select name="categoria" id="categoria-reporte4" class="ih-select">
        <option value="">Todas</option>
        <?php foreach($categorias as $cat): ?>
          <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="rp-label">Subcategoría</label>
      <select name="subcategoria" id="subcategoria-reporte4" class="ih-select">
        <option value="">Todas</option>
      </select>
    </div>
    <div>
      <label class="rp-label">Desde Fecha de Carga</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="rp-label">Hasta Fecha de Carga</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end;">
      <button class="ih-btn ih-btn--accent" type="submit" name="ranking_cantidad">Filtrar</button>
      <button class="ih-btn ih-btn--ghost ih-btn--sm" type="button" onclick="resetRanking()">Todo</button>
    </div>
  </form>

  <div class="export-btns">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-valor"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-valor"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="rp-table-wrap">
  <table class="rp-table" id="tabla-valor">
    <thead>
      <tr><th>Modelo</th><th>Marca</th><th>Categoría</th><th>Subcategoría</th><th>Total Cantidad</th></tr>
    </thead>
    <tbody>
    <?php
      $f = [];
      if (!empty($_GET['categoria']))    $f[] = "categoria = '".$conn->real_escape_string($_GET['categoria'])."'";
      if (!empty($_GET['subcategoria'])) $f[] = "subcategoria = '".$conn->real_escape_string($_GET['subcategoria'])."'";
      $col_carga_r = "IF(fecha_carga IS NOT NULL AND fecha_carga != '0000-00-00 00:00:00', DATE(fecha_carga), DATE(fecha_modificacion))";
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
        $fd = $conn->real_escape_string($_GET['fecha_desde']); $fh = $conn->real_escape_string($_GET['fecha_hasta']);
        $f[] = "($col_carga_r) BETWEEN '$fd' AND '$fh'";
      } elseif (!empty($_GET['fecha_desde'])) {
        $fd = $conn->real_escape_string($_GET['fecha_desde']); $f[] = "($col_carga_r) >= '$fd'";
      } elseif (!empty($_GET['fecha_hasta'])) {
        $fh = $conn->real_escape_string($_GET['fecha_hasta']); $f[] = "($col_carga_r) <= '$fh'";
      }
      $f[] = "archivado = 0";
      $where = count($f) ? "WHERE ".implode(" AND ", $f) : "";
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
        ".(empty($_GET) ? "LIMIT 100" : "")."
      ";
      $res = $conn->query($sql);
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

  <div class="paginador-container">
    <form method="get" id="form-per-page-valor" class="select-perpage-label">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">'; }
        $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), [20,50,100]) ? intval($_GET['per_page']) : 20;
        $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
      ?>
      Mostrar: <select name="per_page" class="select-perpage" onchange="document.getElementById('form-per-page-valor').submit()">
        <?php foreach([20,50,100] as $opt): ?><option value="<?=$opt?>" <?=$per_page==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="pagination-cool">
      <?php $totalRows = $res->num_rows ?? 0; $totalPages = max(1, ceil($totalRows / $per_page)); $start = max(1, $page-2); $end = min($totalPages, $page+2); ?>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>">&rsaquo;</a></li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ AMORTIZACIÓN ════════ -->
<div style="display:<?= $tab === 'amortizacion' ? 'block' : 'none' ?>">
  <form method="GET" class="rp-filter" id="form-amorti">
    <input type="hidden" name="tab" value="amortizacion">
    <div>
      <label class="rp-label">Años Amortización</label>
      <select name="anios" class="ih-select">
        <?php for($i=1;$i<=10;$i++): ?>
          <option value="<?=$i?>" <?=((isset($_GET['anios'])?$_GET['anios']:4)==$i)?'selected':''?>><?=$i?> año<?=$i>1?'s':''?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <button class="ih-btn ih-btn--accent" type="submit" name="filtro_amorti">Ver</button>
    </div>
  </form>

  <div class="export-btns">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-amortizacion"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-amortizacion"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="rp-table-wrap">
  <table class="rp-table" id="tabla-amortizacion">
    <thead>
      <tr>
        <th>ID</th><th>Modelo</th><th>Marca</th><th>Categoría</th><th>Subcategoría</th>
        <th>Fecha Base Amortiz.</th><th>Valor Compra USD</th><th>Años Amortiz.</th><th>Valor Actual</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $anios = isset($_GET['anios']) ? intval($_GET['anios']) : 4;
      $filtros = ["archivado = 0","(fecha_egreso IS NULL OR fecha_egreso = '0000-00-00')"];
      $where = "WHERE ".implode(" AND ", $filtros);
      $limit = empty($_GET) ? "LIMIT 100" : "";
      $sql = "SELECT * FROM `{$prodTable}` $where ORDER BY id DESC $limit";
      $res = $conn->query($sql);
      $totalActual = 0; $totalCompra = 0;
      while($row = $res->fetch_assoc()):
        $fecha_base = null;
        if (!empty($row['fecha_compra']) && $row['fecha_compra'] !== '0000-00-00') {
            $fecha_base = new DateTime($row['fecha_compra']);
        } elseif (!empty($row['fecha_ingreso']) && $row['fecha_ingreso'] !== '0000-00-00') {
            $fecha_base = new DateTime($row['fecha_ingreso']);
        }
        $hoy = new DateTime();
        $valor_compra = floatval($row['valor_usd']) * intval($row['cantidad']);
        $totalCompra += $valor_compra;
        if ($fecha_base && $anios > 0) {
          $interval = $fecha_base->diff($hoy);
          $aniosTrans = ($interval->y) + ($interval->m/12);
          $depreciacion = min(1, $aniosTrans/$anios);
          $valor_actual = round($valor_compra * (1 - $depreciacion), 2);
          if ($valor_actual < 0) $valor_actual = 0;
        } else {
          $valor_actual = $valor_compra;
        }
        $totalActual += $valor_actual;
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
        <td><?=$anios?></td>
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

  <div class="paginador-container">
    <form method="get" id="form-per-page-amortizacion" class="select-perpage-label">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">'; }
        $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), [20,50,100]) ? intval($_GET['per_page']) : 20;
        $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
      ?>
      Mostrar: <select name="per_page" class="select-perpage" onchange="document.getElementById('form-per-page-amortizacion').submit()">
        <?php foreach([20,50,100] as $opt): ?><option value="<?=$opt?>" <?=$per_page==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="pagination-cool">
      <?php $totalRows = $res->num_rows ?? 0; $totalPages = max(1, ceil($totalRows / $per_page)); $start = max(1, $page-2); $end = min($totalPages, $page+2); ?>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>">&rsaquo;</a></li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ TRAZABILIDAD ════════ -->
<div style="display:<?= $tab === 'trazabilidad' ? 'block' : 'none' ?>">
  <form method="GET" class="rp-filter" id="form-trazabilidad">
    <input type="hidden" name="tab" value="trazabilidad">
    <div>
      <label class="rp-label">Desde Fecha</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="rp-label">Hasta Fecha</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div>
      <button class="ih-btn ih-btn--accent" type="submit" name="trazabilidad">Buscar</button>
    </div>
  </form>

  <div class="export-btns">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-trazabilidad"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-trazabilidad"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="rp-table-wrap">
  <table class="rp-table" id="tabla-trazabilidad">
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
      $where = "pm.accion IN ('modificado','Archivado','Alta','Agregado','Importado') AND pm.realizado_por != 0";
      $params = []; $types = "";
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
        $where .= " AND pm.fecha BETWEEN ? AND ?";
        $params[] = $_GET['fecha_desde']; $params[] = $_GET['fecha_hasta']; $types .= "ss";
      } elseif (!empty($_GET['fecha_desde'])) {
        $where .= " AND pm.fecha >= ?"; $params[] = $_GET['fecha_desde']; $types .= "s";
      } elseif (!empty($_GET['fecha_hasta'])) {
        $where .= " AND pm.fecha <= ?"; $params[] = $_GET['fecha_hasta']; $types .= "s";
      }
      $limit = (empty($_GET['fecha_desde']) && empty($_GET['fecha_hasta'])) ? "LIMIT 100" : "";
      $sql = "SELECT pm.fecha,pm.accion,u.username,u.email,
              pm.modelo AS modelo_mod, pm.marca AS marca_mod, pm.servis_tag AS tag_mod, pm.cantidad AS cant_mod,
              pm.valor_usd AS valor_mod, pm.estado AS estado_mod, pm.fecha_garantia AS garant_mod,
              pm.fecha_ingreso AS ingreso_mod, pm.fecha_egreso AS egreso_mod, pm.ubicacion AS ubic_mod,
              pm.detalle_tecnico AS detec_mod, pm.patchcord_tipo AS pctipo_mod, pm.patchcord_longitud AS pclong_mod,
              pm.categoria AS cat_mod, pm.subcategoria AS sub_mod
              FROM `{$movTable}` pm
              LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id
              WHERE $where ORDER BY pm.fecha DESC $limit";
      $stmt = $conn->prepare($sql);
      if (!empty($params)) $stmt->bind_param($types, ...$params);
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

  <div class="paginador-container">
    <form method="get" id="form-per-page-trazabilidad" class="select-perpage-label">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">'; }
        $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), [20,50,100]) ? intval($_GET['per_page']) : 20;
        $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
      ?>
      Mostrar: <select name="per_page" class="select-perpage" onchange="document.getElementById('form-per-page-trazabilidad').submit()">
        <?php foreach([20,50,100] as $opt): ?><option value="<?=$opt?>" <?=$per_page==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="pagination-cool">
      <?php $totalRows = $res->num_rows ?? 0; $totalPages = max(1, ceil($totalRows / $per_page)); $start = max(1, $page-2); $end = min($totalPages, $page+2); ?>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>">&rsaquo;</a></li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>">&raquo;</a></li>
    </ul></nav>
  </div>
</div>

<!-- ════════ ARCHIVADOS ════════ -->
<div style="display:<?= $tab === 'archivados' ? 'block' : 'none' ?>">
  <form method="GET" class="rp-filter" id="form-archivados">
    <input type="hidden" name="tab" value="archivados">
    <div>
      <label class="rp-label">Motivo</label>
      <select name="motivo" class="ih-select">
        <option value="">Todos</option>
        <?php foreach(['Venta','Donación','Scrapping','Desguace','Perdida','Robo'] as $motivo): ?>
          <option value="<?=$motivo?>" <?=($_GET['motivo']??'')===$motivo?'selected':''?>><?=$motivo?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="rp-label">Responsable</label>
      <input type="text" name="usuario" class="ih-input" value="<?=htmlspecialchars($_GET['usuario']??'')?>" placeholder="Nombre o Email">
    </div>
    <div>
      <label class="rp-label">Desde Fecha</label>
      <input type="date" name="fecha_desde" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
    </div>
    <div>
      <label class="rp-label">Hasta Fecha</label>
      <input type="date" name="fecha_hasta" class="ih-input" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
    </div>
    <div>
      <button class="ih-btn ih-btn--accent" type="submit">Filtrar</button>
    </div>
  </form>

  <div class="export-btns">
    <button class="ih-btn ih-btn--ghost export-pdf-btn" data-table="tabla-archivados"><i class="bi bi-filetype-pdf"></i> Exportar PDF</button>
    <button class="ih-btn ih-btn--ghost export-csv-btn" data-table="tabla-archivados"><i class="bi bi-filetype-csv"></i> Exportar CSV</button>
  </div>

  <div class="rp-table-wrap">
  <table class="rp-table" id="tabla-archivados">
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
      $f = ["pm.accion = 'Archivado'"];
      $params = []; $types = "";
      if (!empty($_GET['motivo'])) { $f[] = "pm.descripcion LIKE ?"; $params[] = "%Motivo: ".$_GET['motivo']."%"; $types .= "s"; }
      if (!empty($_GET['usuario'])) { $f[] = "(u.username LIKE ? OR u.email LIKE ?)"; $params[] = "%".$_GET['usuario']."%"; $params[] = "%".$_GET['usuario']."%"; $types .= "ss"; }
      if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
        $f[] = "pm.fecha BETWEEN ? AND ?"; $params[] = $_GET['fecha_desde']; $params[] = $_GET['fecha_hasta']; $types .= "ss";
      } elseif (!empty($_GET['fecha_desde'])) {
        $f[] = "pm.fecha >= ?"; $params[] = $_GET['fecha_desde']; $types .= "s";
      } elseif (!empty($_GET['fecha_hasta'])) {
        $f[] = "pm.fecha <= ?"; $params[] = $_GET['fecha_hasta']; $types .= "s";
      }
      $hay_filtros = count($f) > 1;
      $limit = !$hay_filtros ? "LIMIT 100" : "";
      $sql = "SELECT pm.*, u.username, u.email
              FROM `{$movTable}` pm
              LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id
              ".(count($f) ? "WHERE ".implode(" AND ",$f) : "")."
              ORDER BY pm.fecha DESC $limit";
      $stmt = $conn->prepare($sql);
      if (!empty($params)) $stmt->bind_param($types, ...$params);
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

  <div class="paginador-container">
    <form method="get" id="form-per-page-archivados" class="select-perpage-label">
      <?php foreach ($_GET as $key => $value) { if ($key === 'per_page') continue; echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">'; }
        $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), [20,50,100]) ? intval($_GET['per_page']) : 20;
        $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
      ?>
      Mostrar: <select name="per_page" class="select-perpage" onchange="document.getElementById('form-per-page-archivados').submit()">
        <?php foreach([20,50,100] as $opt): ?><option value="<?=$opt?>" <?=$per_page==$opt?'selected':''?>><?=$opt?></option><?php endforeach; ?>
      </select> por página
    </form>
    <nav><ul class="pagination-cool">
      <?php $totalRows = $res->num_rows ?? 0; $totalPages = max(1, ceil($totalRows / $per_page)); $start = max(1, $page-2); $end = min($totalPages, $page+2); ?>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a></li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>">&lsaquo;</a></li>
      <?php for($i=$start;$i<=$end;$i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?=$i?></a></li><?php endfor; ?>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>">&rsaquo;</a></li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>">&raquo;</a></li>
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
    const TEXT        = '#1a1a2e';
    const TEXT_MUTED  = '#6b6b7b';
    const STRIPE      = '#f7f5fb';
    const BORDER      = '#e5e1ec';

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
