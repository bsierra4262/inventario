<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/backend/db.php';
require_once __DIR__ . '/logs/logger.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

// Determina pestaña activa (GET['tab'], por defecto inventario)
$tab = $_GET['tab'] ?? 'inventario';

// Si viene del botón/submit del Ranking por Cantidad, debe seguir en la pestaña "valor"
if (isset($_GET['ranking_cantidad']))  $tab = 'valor';

// (Opcional) este también puede apuntar a "valor", pero es redundante si usas el hidden input
if (isset($_GET['ranking_valor']))     $tab = 'valor';

if (isset($_GET['amortizacion']))      $tab = 'amortizacion';
if (isset($_GET['trazabilidad']))      $tab = 'trazabilidad';
if (isset($_GET['ap']))                $tab = 'ap';
if (isset($_GET['filtro_valor']))      $tab = 'valor';
if (isset($_GET['filtro_amorti']))     $tab = 'amortizacion';

function is_tab($needle, $tab) { return $needle === $tab ? 'show active' : ''; }
function is_tab_btn($needle, $tab) { return $needle === $tab ? 'active' : ''; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reportes de Activos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      body {
        font-family: 'Saira', sans-serif;
        background: #f5f5f5;
        padding: 0;
        margin: 0;
        color: #333;
      }
      h1, h2, h3 {
        color: #4E247A;
        font-weight: 700;
        letter-spacing: 0.03em;
      }
      .nav-tabs {
        border-bottom: 2.5px solid #e7e3fa;
        margin-bottom: 20px;
        background: #fff;
        border-radius: 16px 16px 0 0;
        overflow: hidden;
        box-shadow: 0 2px 16px 0 rgba(102,51,153,0.06);
      }
      .nav-tabs .nav-link {
        color: #4E247A !important;
        font-weight: 600;
        border: none;
        border-radius: 0;
        padding: 0.9rem 1.7rem;
        transition: background .19s, color .19s;
        background: none !important;
      }
      .nav-tabs .nav-link.active, .nav-tabs .nav-link:focus {
        background: #86B329 !important;
        color: #fff !important;
        border: none;
        box-shadow: 0 0 4px #c8ffc3a8;
        font-weight: 700;
      }
      .tab-content {
        background: #fff;
        border-radius: 0 0 16px 16px;
        box-shadow: 0 2px 16px 0 rgba(102,51,153,0.07);
        padding: 2rem 2.5rem 2.2rem 2.5rem;
        min-height: 350px;
        margin-bottom: 40px;
      }
      /* HEADER igual que index.php */
        header {
          background-color: #663399;
          color: #fff;
          padding: 1rem;
          display: flex;
          justify-content: space-between;
          align-items: center;
        }
        
        header h1 {
          font-size: 1.5rem;
          margin: 0;
          font-weight: 600;
          display: flex;
          align-items: center;
          gap: 8px;
          letter-spacing: 0.01em;
        }
        .btn-volver {
          background-color: #4E247A;
          color: #fff;
          border: none;
          padding: 0.5rem 1rem;
          border-radius: 5px;
          text-decoration: none;
          font-weight: 500;
          display: flex;
          align-items: center;
          gap: 4px;
          transition: background 0.19s, color 0.19s;
        }
        .btn-volver:hover, .btn-volver:focus {
          background-color: #86B329;
          color: #fff;
        }
      /* Formularios de filtros */
      form.mb-3 {
        background: #F8F7FC;
        padding: 18px 18px 10px 18px;
        border-radius: 13px;
        margin-bottom: 22px !important;
        box-shadow: 0 2px 8px 0 rgba(102,51,153,0.04);
        border: 1.5px solid #ece5fb;
      }
      .form-label {
        color: #4E247A;
        font-weight: 600;
        letter-spacing: 0.01em;
        font-size: 0.97rem;
      }
      .form-select, .form-control {
        border-radius: 13px;
        border: 1.5px solid #cfc4f9;
        background: #fff;
        font-size: 1rem;
        transition: border-color .18s;
      }
      .form-select:focus, .form-control:focus {
        border-color: #86B329;
        box-shadow: 0 0 0 2px #e6f3d2;
      }
      .btn, .btn-primary, .btn-outline-secondary {
        border-radius: 22px;
        padding: 0.5rem 1.3rem;
        font-weight: 600;
        font-family: 'Saira', sans-serif;
        transition: background .15s, color .15s, border .15s;
      }
      .btn-primary, .btn-primary:active {
        background: #86B329 !important;
        border: none !important;
        color: #fff !important;
        font-weight: 700;
      }
      .btn-primary:hover {
        background: #4E247A !important;
        color: #fff !important;
      }
      .btn-outline-secondary {
        border: 1.5px solid #663399 !important;
        color: #663399 !important;
        background: none;
      }
      .btn-outline-secondary:hover, .btn-outline-secondary:active {
        background: #663399 !important;
        color: #fff !important;
      }
      /* Tablas */
      .table-responsive {
        background: #fff;
        border-radius: 13px;
        box-shadow: 0 2px 12px 0 rgba(102,51,153,0.06);
        padding: 1.3rem 1rem 1.2rem 1rem;
        margin-bottom: 2.5rem;
      }
      table.table {
        background: #fff;
        font-size: 0.97rem;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 12px 0 rgba(102,51,153,0.06);
      }
      thead, .table-striped > tbody > tr:nth-of-type(odd) {
        background: #663399;
        color: #fff;
      }
      thead th {
        background: #663399;
        color: #fff;
        border-bottom: 2px solid #ece5fb !important;
        font-size: 1.01rem;
        font-weight: 700;
        letter-spacing: 0.01em;
      }
      .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #F8F7FC !important;
        color: #4E247A;
      }
      .table-striped > tbody > tr:nth-of-type(even) {
        background-color: #fff !important;
      }
      tbody tr:hover {
        background-color: #ede6fa !important;
        transition: background .12s;
      }
      td, th {
        vertical-align: middle !important;
      }
      /* Export btns y paginador DataTables */
      .dt-buttons .btn, .dt-buttons .btn-primary, .dt-buttons .btn-outline-secondary {
        border-radius: 13px !important;
        font-size: 0.97rem;
        font-weight: 600;
        margin-right: 7px;
      }
      .dataTables_filter input[type="search"] {
        border-radius: 13px;
        border: 1.5px solid #cfc4f9;
        padding: 0.37rem 1.1rem;
        font-size: 1rem;
        background: #fff;
        margin-left: 0.6em;
      }
      .dataTables_filter label {
        color: #663399;
        font-weight: 600;
      }
      .pagination .page-link {
        border-radius: 22px !important;
        background: #fff;
        color: #663399 !important;
        font-weight: 600;
        border: 1.5px solid #ece5fb;
        margin: 0 2px;
        transition: background .14s, color .14s;
      }
      .pagination .page-link.active, .pagination .active > .page-link {
        background: #86B329 !important;
        color: #fff !important;
        border: 1.5px solid #86B329 !important;
      }
      .pagination .page-link:hover, .pagination .page-link:focus {
        background: #4E247A !important;
        color: #fff !important;
        border: 1.5px solid #4E247A !important;
      }
      /* Botón volver y pie de página */
      .btn-secondary {
        background: #fff !important;
        border: 1.5px solid #663399 !important;
        color: #4E247A !important;
        font-weight: 600;
        border-radius: 22px;
        padding: 0.5rem 1.6rem;
        margin-top: 30px;
      }
      .btn-secondary:hover {
        background: #4E247A !important;
        color: #fff !important;
      }
      .footer {
        text-align: center;
        padding: 1.2rem;
        background-color: #663399;
        color: #fff;
        width: 100%;
        position: fixed;
        bottom: 0;
        left: 0;
        z-index: 999;
        font-size: 1rem;
        letter-spacing: 0.01em;
        box-shadow: 0 -2px 8px 0 rgba(102,51,153,0.09);
      }
      @media (max-width:900px) {
        .tab-content { padding: 1rem 0.7rem 1.2rem 0.7rem; }
        .table-responsive { padding: 0.7rem 0.1rem 0.6rem 0.1rem; }
        form.mb-3 { padding: 8px 6px 6px 6px; }
      }
      @media (max-width:600px) {
        h1 { font-size: 1.25rem;}
        .tab-content { padding: 0.5rem 0 0.6rem 0;}
      }
        .paginador-container {
          display: flex;
          align-items: center;
          justify-content: space-between; /* <--- CLAVE: esto alinea uno a la izq y otro a la der */
          gap: 2rem;
          margin-top: 12px;
          margin-bottom: 8px;
          flex-wrap: wrap;
        }
        @media (max-width: 700px) {
          .paginador-container {
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            gap: 0.8rem;
          }
        }
        .select-perpage-label {
          color: #4E247A;
          font-family: 'Saira', sans-serif;
          font-size: 0.97rem;
          font-weight: 600;
          text-transform: uppercase;
          margin-bottom: 0;
          letter-spacing: 0.01em;
          display: flex;
          align-items: center;
          gap: 0.65em;
        }
        .select-perpage {
          appearance: none;
          -webkit-appearance: none;
          border: 2px solid #86B329;
          border-radius: 20px;
          font-family: 'Saira', sans-serif;
          color: #4E247A;
          font-size: 1rem;
          font-weight: 700;
          background: #fff;
          padding: 0.25em 1.2em 0.25em 1.2em;
          min-width: 80px;
          width: 80px;
          text-align: center;
          transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
          outline: none;
          box-shadow: none;
          margin: 0 0.2em;
        }
        .select-perpage:focus,
        .select-perpage:active,
        .select-perpage option:checked {
          background: #86B329;
          color: #fff;
          border-color: #663399;
        }
        .select-perpage option {
          font-family: 'Saira', sans-serif;
          color: #4E247A;
          font-size: 1rem;
          font-weight: 600;
          background: #fff;
        }
        .select-perpage option:checked {
          background: #86B329 !important;
          color: #fff !important;
        }
        .pagination-cool {
          margin-bottom: 0;
          display: flex;
          gap: 0.15em;
          list-style: none;
          padding-left: 0;
        }
        .pagination-cool .page-link,
        .pagination-cool .page-link:visited {
          border-radius: 50px !important;
          color: #4E247A;
          background: #fff;
          border: 2px solid #f0e8fd;
          font-family: 'Saira',sans-serif;
          font-weight: 700;
          padding: 0.25em 0.85em;
          margin: 0 2px;
          box-shadow: none;
          transition: background .2s, color .2s, border .2s;
        }
        .pagination-cool .page-link:focus {
          outline: none;
          border-color: #86B329;
          box-shadow: 0 0 0 0.09rem #86B32950;
        }
        .pagination-cool .page-link:hover:not(.disabled):not(.active) {
          background: #663399;
          color: #fff;
          border-color: #663399;
        }
        .pagination-cool .page-item.active .page-link {
          background: #86B329 !important;
          color: #fff !important;
          border-color: #86B329 !important;
          font-weight: bold;
        }
        .pagination-cool .page-item.disabled .page-link {
          color: #999 !important;
          background: #f2f2f2 !important;
          border-color: #f2f2f2 !important;
          cursor: not-allowed;
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f5f5f5;">
  <link rel="stylesheet" href="/assets/css/intellihelp.css">
  <style>
    header.ih-topbar-tenant { display: flex; align-items: center; padding: 0 16px; height: 64px; background: #fff; border-bottom: 1px solid #E4E4E7; position: sticky; top: 0; z-index: 900; box-shadow: 0 1px 3px rgba(17,20,24,.04); }
    header.ih-topbar-tenant .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
    header.ih-topbar-tenant .brand__logo { width: 36px; height: 36px; background: #663399; color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; position: relative; }
    header.ih-topbar-tenant .brand__logo::after { content: ''; position: absolute; right: 4px; bottom: 4px; width: 7px; height: 7px; background: #9CC930; border-radius: 50%; }
    header.ih-topbar-tenant .brand__text strong { font-size: 15px; color: #18181B; font-weight: 700; letter-spacing: -.3px; display: block; line-height: 1.1; }
    header.ih-topbar-tenant .brand__text span { font-size: 11px; color: #71717A; }
    header.ih-topbar-tenant .topbar-spacer { flex: 1; }
    header.ih-topbar-tenant a.back { color: #663399; font-weight: 600; font-size: 13px; text-decoration: none; padding: 8px 14px; border-radius: 10px; border: 1px solid #D0BFEA; background: #fff; transition: all .15s; }
    header.ih-topbar-tenant a.back:hover { background: #F4F1F9; }
  </style>
  <header class="ih-topbar-tenant">
    <a class="brand" href="index.php">
      <div class="brand__logo">i</div>
      <div class="brand__text">
        <strong>Asset Vault</strong>
        <span>INTELLIHELP · Reportes</span>
      </div>
    </a>
    <div class="topbar-spacer"></div>
    <a class="back" href="index.php"><i class="bi bi-arrow-left"></i> Volver al inventario</a>
  </header>
  <div style="height: 24px;"></div>

  <!-- NAV TABS -->
  <ul class="nav nav-tabs mb-3" id="reporteTabs" role="tablist">
    <li class="nav-item"><a class="nav-link <?=is_tab_btn('inventario',$tab)?>" href="?tab=inventario">Inventario General</a></li>
    <li class="nav-item"><a class="nav-link <?=is_tab_btn('valor',$tab)?>" href="?tab=valor">Ranking por Cantidad</a></li>
    <li class="nav-item"><a class="nav-link <?=is_tab_btn('amortizacion',$tab)?>" href="?tab=amortizacion">Amortización</a></li>
    <li class="nav-item"><a class="nav-link <?=is_tab_btn('trazabilidad',$tab)?>" href="?tab=trazabilidad">Trazabilidad</a></li>
    <li class="nav-item"><a class="nav-link <?=is_tab_btn('archivados',$tab)?>" href="?tab=archivados">Archivados</a></li>
  </ul>

  <div class="tab-content">
<!-- INVENTARIO GENERAL -->
<div class="tab-pane fade <?=is_tab('inventario',$tab)?>" id="inventario" role="tabpanel">
  <form method="GET" class="mb-3" id="form-inventario">
    <input type="hidden" name="tab" value="inventario">
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Categoría</label>
        <select name="categoria" id="categoria-reporte" class="form-select">
          <option value="">Todas</option>
          <?php foreach($categorias as $cat): ?>
            <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subcategoría</label>
        <select name="subcategoria" id="subcategoria-reporte" class="form-select">
          <option value="">Todas</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Marca</label>
        <input type="text" name="marca" class="form-control" value="<?=htmlspecialchars($_GET['marca']??'')?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Modelo</label>
        <input type="text" name="modelo" class="form-control" value="<?=htmlspecialchars($_GET['modelo']??'')?>">
      </div>
    </div>
    <div class="row g-2 mt-2">
      <div class="col-md-3">
        <label class="form-label">Estado</label>
        <select name="estado" class="form-select">
          <option value="">Todos</option>
          <option value="Nuevo" <?=($_GET['estado']??'')==='Nuevo'?'selected':''?>>Nuevo</option>
          <option value="Usado" <?=($_GET['estado']??'')==='Usado'?'selected':''?>>Usado</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Locación</label>
        <input type="text" name="ubicacion" class="form-control" value="<?=htmlspecialchars($_GET['ubicacion']??'')?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Desde Fecha de Carga</label>
        <input type="date" name="fecha_desde" class="form-control" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Hasta Fecha de Carga</label>
        <input type="date" name="fecha_hasta" class="form-control" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
      </div>
    </div>
    <div class="row g-2 mt-2">
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit">Filtrar</button>
      </div>
    </div>
  </form>
  <div class="table-responsive">
    <div class="export-btns">
      <button class="btn btn-outline-secondary export-pdf-btn" data-table="tabla-inventario">
        <i class="bi bi-filetype-pdf"></i> Exportar PDF
      </button>
      <button class="btn btn-outline-secondary export-csv-btn" data-table="tabla-inventario">
        <i class="bi bi-filetype-csv"></i> Exportar CSV
      </button>
    </div>
    <table class="table table-striped" id="tabla-inventario">
      <thead>
        <tr>
          <th>ID</th>
          <th>Modelo</th>
          <th>Marca</th>
          <th>Descripción</th>
          <th>Categoría</th>
          <th>Subcategoría</th>
          <th>Cantidad</th>
          <th>Asignado a</th>
          <th>Locación</th>
          <th>Estado</th>
          <th>Valor de Compra en USD</th>
        </tr>
      </thead>
      <tbody>
<?php
        $f = [];

        // Filtros opcionales
        if (!empty($_GET['categoria']))    $f[] = "categoria = '".$conn->real_escape_string($_GET['categoria'])."'";
        if (!empty($_GET['subcategoria'])) $f[] = "subcategoria = '".$conn->real_escape_string($_GET['subcategoria'])."'";
        if (!empty($_GET['marca']))        $f[] = "LOWER(marca) LIKE LOWER('%".$conn->real_escape_string($_GET['marca'])."%')";
        if (!empty($_GET['modelo']))       $f[] = "LOWER(modelo) LIKE LOWER('%".$conn->real_escape_string($_GET['modelo'])."%')";
        if (!empty($_GET['estado']))       $f[] = "estado = '".$conn->real_escape_string($_GET['estado'])."'";
        if (!empty($_GET['ubicacion']))    $f[] = "LOWER(ubicacion) LIKE LOWER('%".$conn->real_escape_string($_GET['ubicacion'])."%')";
        // Filtro por Fecha de Carga (created_at / fecha_carga automática)
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

        // SIEMPRE excluir archivados
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
    <div class="paginador-container">
      <form method="get" id="form-per-page-<?= $tab ?>" class="d-flex align-items-center gap-2">
        <?php
          foreach ($_GET as $key => $value) {
            if ($key === 'per_page') continue;
            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
          }
          $perPageOptions = [20, 50, 100];
          $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 20;
          $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
        ?>
        <label for="per-page-<?= $tab ?>" class="select-perpage-label">
          Mostrar:
          <select name="per_page" id="per-page-<?= $tab ?>" class="select-perpage" onchange="document.getElementById('form-per-page-<?= $tab ?>').submit()">
            <?php foreach($perPageOptions as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          por página
        </label>
      </form>
      <nav aria-label="Paginador de <?= htmlspecialchars($tab) ?>">
        <ul class="pagination-cool">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>1])) ?>" aria-label="Primero">&laquo;</a>
          </li>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>max(1, $page-1)])) ?>" aria-label="Anterior">&lsaquo;</a>
          </li>
          <?php
          // Calcula el total de páginas
          $totalRows = $res->num_rows ?? 0;
          $totalPages = max(1, ceil($totalRows / $per_page));
          $start = max(1, $page-2); $end = min($totalPages, $page+2);
          for($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>min($totalPages, $page+1)])) ?>" aria-label="Siguiente">&rsaquo;</a>
          </li>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$totalPages])) ?>" aria-label="Último">&raquo;</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>
<!-- RANKING POR CANTIDAD (Unificar Modelo+Marca y Categoría+Subcategoría) -->
<div class="tab-pane fade <?=is_tab('valor',$tab)?>" id="valor" role="tabpanel">
  <form method="GET" class="mb-3" id="form-valor">
    <input type="hidden" name="tab" value="valor">
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Categoría</label>
        <select name="categoria" id="categoria-reporte4" class="form-select">
          <option value="">Todas</option>
          <?php foreach($categorias as $cat): ?>
            <option value="<?=htmlspecialchars($cat)?>" <?=($_GET['categoria']??'')===$cat?'selected':''?>><?=htmlspecialchars($cat)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subcategoría</label>
        <select name="subcategoria" id="subcategoria-reporte4" class="form-select">
          <option value="">Todas</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Desde Fecha de Carga</label>
        <input type="date" name="fecha_desde" class="form-control" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Hasta Fecha de Carga</label>
        <input type="date" name="fecha_hasta" class="form-control" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit" name="ranking_cantidad">Filtrar</button>
      </div>
    </div>

    <!-- solo botón "Todo" para limpiar filtros -->
    <div class="row g-2 mt-2">
      <div class="col-md-12">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="resetRanking()">Todo</button>
      </div>
    </div>
  </form>

  <div class="table-responsive">
    <div class="export-btns">
      <button class="btn btn-outline-secondary export-pdf-btn" data-table="tabla-valor">
        <i class="bi bi-filetype-pdf"></i> Exportar PDF
      </button>
      <button class="btn btn-outline-secondary export-csv-btn" data-table="tabla-valor">
        <i class="bi bi-filetype-csv"></i> Exportar CSV
      </button>
    </div>

    <table class="table table-striped" id="tabla-valor">
      <thead>
        <tr>
          <th>Modelo</th>
          <th>Marca</th>
          <th>Categoría</th>
          <th>Subcategoría</th>
          <th>Total Cantidad</th>
        </tr>
      </thead>
      <tbody>
      <?php
        // ===== Filtros =====
        $f = [];
        if (!empty($_GET['categoria']))    $f[] = "categoria = '".$conn->real_escape_string($_GET['categoria'])."'";
        if (!empty($_GET['subcategoria'])) $f[] = "subcategoria = '".$conn->real_escape_string($_GET['subcategoria'])."'";
        $col_carga_r = "IF(fecha_carga IS NOT NULL AND fecha_carga != '0000-00-00 00:00:00', DATE(fecha_carga), DATE(fecha_modificacion))";
        if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
          $fd = $conn->real_escape_string($_GET['fecha_desde']);
          $fh = $conn->real_escape_string($_GET['fecha_hasta']);
          $f[] = "($col_carga_r) BETWEEN '$fd' AND '$fh'";
        } elseif (!empty($_GET['fecha_desde'])) {
          $fd = $conn->real_escape_string($_GET['fecha_desde']);
          $f[] = "($col_carga_r) >= '$fd'";
        } elseif (!empty($_GET['fecha_hasta'])) {
          $fh = $conn->real_escape_string($_GET['fecha_hasta']);
          $f[] = "($col_carga_r) <= '$fh'";
        }
        // Excluir archivados SIEMPRE
        $f[] = "archivado = 0";
        $where = count($f) ? "WHERE ".implode(" AND ", $f) : "";

        // ===== Normalización fuerte para claves de agrupación =====
        $sql = "
          SELECT
            MAX(modelo_mostrar)       AS modelo,
            MAX(marca_mostrar)        AS marca,
            MAX(categoria_mostrar)    AS categoria,
            MAX(subcategoria_mostrar) AS subcategoria,
            SUM(cantidad)             AS total_cant
          FROM (
            SELECT
              UPPER(REPLACE(TRIM(REPLACE(modelo,      CHAR(160),' ')), ' ', ''))      AS modelo_key,
              UPPER(REPLACE(TRIM(REPLACE(marca,       CHAR(160),' ')), ' ', ''))      AS marca_key,
              UPPER(REPLACE(TRIM(REPLACE(categoria,   CHAR(160),' ')), ' ', ''))      AS categoria_key,
              UPPER(REPLACE(TRIM(REPLACE(subcategoria,CHAR(160),' ')), ' ', ''))      AS subcategoria_key,

              TRIM(REPLACE(modelo,      CHAR(160),' '))      AS modelo_mostrar,
              TRIM(REPLACE(marca,       CHAR(160),' '))      AS marca_mostrar,
              TRIM(REPLACE(categoria,   CHAR(160),' '))      AS categoria_mostrar,
              TRIM(REPLACE(subcategoria,CHAR(160),' '))      AS subcategoria_mostrar,
              cantidad
            FROM `{$prodTable}`
            $where
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
      <tfoot>
        <tr>
          <th colspan="4" style="text-align:right">TOTAL GENERAL:</th>
          <th><?= (int)$granTotal ?></th>
        </tr>
      </tfoot>
    </table>

    <div class="paginador-container">
      <form method="get" id="form-per-page-<?= $tab ?>" class="d-flex align-items-center gap-2">
        <?php
          foreach ($_GET as $key => $value) {
            if ($key === 'per_page') continue;
            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
          }
          $perPageOptions = [20, 50, 100];
          $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 20;
          $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
        ?>
        <label for="per-page-<?= $tab ?>" class="select-perpage-label">
          Mostrar:
          <select name="per_page" id="per-page-<?= $tab ?>" class="select-perpage" onchange="document.getElementById('form-per-page-<?= $tab ?>').submit()">
            <?php foreach($perPageOptions as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          por página
        </label>
      </form>
      <nav aria-label="Paginador de <?= htmlspecialchars($tab) ?>">
        <ul class="pagination-cool">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>1])) ?>" aria-label="Primero">&laquo;</a>
          </li>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>max(1, $page-1)])) ?>" aria-label="Anterior">&lsaquo;</a>
          </li>
          <?php
            $totalRows = $res->num_rows ?? 0;
            $totalPages = max(1, ceil($totalRows / $per_page));
            $start = max(1, $page-2); $end = min($totalPages, $page+2);
            for($i = $start; $i <= $end; $i++): ?>
              <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
              </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>min($totalPages, $page+1)])) ?>" aria-label="Siguiente">&rsaquo;</a>
          </li>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$totalPages])) ?>" aria-label="Último">&raquo;</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>

    <!-- AMORTIZACIÓN -->
    <div class="tab-pane fade <?=is_tab('amortizacion',$tab)?>" id="amortizacion" role="tabpanel">
      <form method="GET" class="mb-3" id="form-amorti">
        <input type="hidden" name="tab" value="amortizacion">
        <div class="row g-2">
          <div class="col-md-3">
            <label class="form-label">Años Amortización</label>
            <select name="anios" class="form-select">
              <?php for($i=1;$i<=10;$i++): ?>
                <option value="<?=$i?>" <?=((isset($_GET['anios'])?$_GET['anios']:4)==$i)?'selected':''?>><?=$i?> año<?=$i>1?'s':''?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit" name="filtro_amorti">Ver</button>
          </div>
        </div>
      </form>
      <div class="table-responsive">
        <div class="export-btns">
          <button class="btn btn-outline-secondary export-pdf-btn" data-table="tabla-amortizacion">
            <i class="bi bi-filetype-pdf"></i> Exportar PDF
          </button>
          <button class="btn btn-outline-secondary export-csv-btn" data-table="tabla-amortizacion">
            <i class="bi bi-filetype-csv"></i> Exportar CSV
          </button>
        </div>
        <table class="table table-striped" id="tabla-amortizacion">
          <thead>
            <tr>
              <th>ID</th>
              <th>Modelo</th>
              <th>Marca</th>
              <th>Categoría</th>
              <th>Subcategoría</th>
              <th>Fecha Base Amortiz.</th>
              <th>Valor de Compra en USD</th>
              <th>Años Amortiz.</th>
              <th>Valor Actual</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $anios = isset($_GET['anios']) ? intval($_GET['anios']) : 4;
            // Excluir archivados y productos con fecha_egreso (dados de baja)
            $filtros = [
                "archivado = 0",
                "(fecha_egreso IS NULL OR fecha_egreso = '0000-00-00')"
            ];
            $where = "WHERE ".implode(" AND ", $filtros);
            $hay_filtros = !empty($_GET);
            $limit = !$hay_filtros ? "LIMIT 100" : "";
            $sql = "SELECT * FROM `{$prodTable}` $where ORDER BY id DESC $limit";
            $res = $conn->query($sql);

            $totalActual = 0; $totalCompra = 0;
            while($row = $res->fetch_assoc()):
               // Prioridad: fecha_compra si está, sino fecha_ingreso
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
                      <br><small class="text-muted">Compra</small>
                    <?php elseif (!empty($row['fecha_ingreso']) && $row['fecha_ingreso'] !== '0000-00-00'): ?>
                      <br><small class="text-muted">Ingreso</small>
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
              <th colspan="6" style="text-align:right">TOTAL Compra:</th>
              <th>$<?= number_format($totalCompra,2) ?></th>
              <th style="text-align:right">TOTAL Actual:</th>
              <th>$<?= number_format($totalActual,2) ?></th>
            </tr>
          </tfoot>
        </table>
            <div class="paginador-container">
      <form method="get" id="form-per-page-<?= $tab ?>" class="d-flex align-items-center gap-2">
        <?php
          foreach ($_GET as $key => $value) {
            if ($key === 'per_page') continue;
            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
          }
          $perPageOptions = [20, 50, 100];
          $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 20;
          $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
        ?>
        <label for="per-page-<?= $tab ?>" class="select-perpage-label">
          Mostrar:
          <select name="per_page" id="per-page-<?= $tab ?>" class="select-perpage" onchange="document.getElementById('form-per-page-<?= $tab ?>').submit()">
            <?php foreach($perPageOptions as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          por página
        </label>
      </form>
      <nav aria-label="Paginador de <?= htmlspecialchars($tab) ?>">
        <ul class="pagination-cool">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>1])) ?>" aria-label="Primero">&laquo;</a>
          </li>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>max(1, $page-1)])) ?>" aria-label="Anterior">&lsaquo;</a>
          </li>
          <?php
          // Calcula el total de páginas
          $totalRows = $res->num_rows ?? 0;
          $totalPages = max(1, ceil($totalRows / $per_page));
          $start = max(1, $page-2); $end = min($totalPages, $page+2);
          for($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>min($totalPages, $page+1)])) ?>" aria-label="Siguiente">&rsaquo;</a>
          </li>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$totalPages])) ?>" aria-label="Último">&raquo;</a>
          </li>
        </ul>
      </nav>
    </div>
   </div>
</div>
<!-- TRAZABILIDAD -->
<div class="tab-pane fade <?=is_tab('trazabilidad',$tab)?>" id="trazabilidad" role="tabpanel">
  <form method="GET" class="mb-3" id="form-trazabilidad">
    <input type="hidden" name="tab" value="trazabilidad">
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Desde Fecha de Carga</label>
        <input type="date" name="fecha_desde" class="form-control" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Hasta Fecha de Carga</label>
        <input type="date" name="fecha_hasta" class="form-control" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit" name="trazabilidad">Buscar</button>
      </div>
    </div>
  </form>
  <div class="table-responsive">
    <div class="export-btns">
      <button class="btn btn-outline-secondary export-pdf-btn" data-table="tabla-trazabilidad">
        <i class="bi bi-filetype-pdf"></i> Exportar PDF
      </button>
      <button class="btn btn-outline-secondary export-csv-btn" data-table="tabla-trazabilidad">
        <i class="bi bi-filetype-csv"></i> Exportar CSV
      </button>
    </div>
    <table class="table table-striped" id="tabla-trazabilidad">
      <thead>
        <tr>
          <th>Fecha</th><th>Acción</th>
          <th>Modelo</th><th>Marca</th><th>S/N Tag</th>
          <th>Cantidad</th><th>Valor de Compra en USD</th><th>Estado</th>
          <th>Garantía</th><th>Ingreso</th><th>Egreso</th>
          <th>Ubicación</th><th>Detalle Técnico</th>
          <th>Categoría</th><th>Subcategoría</th>
          <th>Usuario</th>
        </tr>
      </thead>
        <tbody>
        <?php
        // --- Lógica del reporte de trazabilidad SOLO por fechas ---
        $where = "pm.accion IN ('modificado','Archivado','Alta','Agregado','Importado') AND pm.realizado_por != 0";
        $params = [];
        $types = "";
        
        if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
          $where .= " AND pm.fecha BETWEEN ? AND ?";
          $params[] = $_GET['fecha_desde'];
          $params[] = $_GET['fecha_hasta'];
          $types .= "ss";
        } elseif (!empty($_GET['fecha_desde'])) {
          $where .= " AND pm.fecha >= ?";
          $params[] = $_GET['fecha_desde'];
          $types .= "s";
        } elseif (!empty($_GET['fecha_hasta'])) {
          $where .= " AND pm.fecha <= ?";
          $params[] = $_GET['fecha_hasta'];
          $types .= "s";
        }
        
        // Por defecto, muestra hasta 100 modificaciones si no hay filtro de fechas
        $limit = (empty($_GET['fecha_desde']) && empty($_GET['fecha_hasta'])) ? "LIMIT 100" : "";
        
        $sql = "SELECT pm.fecha,pm.accion,u.username,u.email,
                pm.modelo AS modelo_mod, pm.marca AS marca_mod, pm.servis_tag AS tag_mod, pm.cantidad AS cant_mod,
                pm.valor_usd AS valor_mod, pm.estado AS estado_mod, pm.fecha_garantia AS garant_mod,
                pm.fecha_ingreso AS ingreso_mod, pm.fecha_egreso AS egreso_mod, pm.ubicacion AS ubic_mod,
                pm.detalle_tecnico AS detec_mod, pm.patchcord_tipo AS pctipo_mod, pm.patchcord_longitud AS pclong_mod,
                pm.categoria AS cat_mod, pm.subcategoria AS sub_mod
                FROM `{$movTable}` pm
                LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id
                WHERE $where
                ORDER BY pm.fecha DESC
                $limit";
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
          $usr = htmlspecialchars($row['username'], ENT_QUOTES) . "<br><small class='text-muted'>".htmlspecialchars($row['email'], ENT_QUOTES)."</small>";
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
          <tr>
            <td colspan="16" class="text-center text-muted">No hay datos disponibles</td>
          </tr>
        <?php endif; ?>
        </tbody>
    </table>
        <div class="paginador-container">
      <form method="get" id="form-per-page-<?= $tab ?>" class="d-flex align-items-center gap-2">
        <?php
          foreach ($_GET as $key => $value) {
            if ($key === 'per_page') continue;
            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
          }
          $perPageOptions = [20, 50, 100];
          $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 20;
          $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
        ?>
        <label for="per-page-<?= $tab ?>" class="select-perpage-label">
          Mostrar:
          <select name="per_page" id="per-page-<?= $tab ?>" class="select-perpage" onchange="document.getElementById('form-per-page-<?= $tab ?>').submit()">
            <?php foreach($perPageOptions as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          por página
        </label>
      </form>
      <nav aria-label="Paginador de <?= htmlspecialchars($tab) ?>">
        <ul class="pagination-cool">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>1])) ?>" aria-label="Primero">&laquo;</a>
          </li>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>max(1, $page-1)])) ?>" aria-label="Anterior">&lsaquo;</a>
          </li>
          <?php
          // Calcula el total de páginas
          $totalRows = $res->num_rows ?? 0;
          $totalPages = max(1, ceil($totalRows / $per_page));
          $start = max(1, $page-2); $end = min($totalPages, $page+2);
          for($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>min($totalPages, $page+1)])) ?>" aria-label="Siguiente">&rsaquo;</a>
          </li>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$totalPages])) ?>" aria-label="Último">&raquo;</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- ARCHIVADOS -->
<div class="tab-pane fade <?=is_tab('archivados',$tab)?>" id="archivados" role="tabpanel">
  <form method="GET" class="mb-3" id="form-archivados">
    <input type="hidden" name="tab" value="archivados">
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Motivo</label>
        <select name="motivo" class="form-select">
          <option value="">Todos</option>
          <?php foreach(['Venta','Donación','Scrapping','Desguace','Perdida','Robo'] as $motivo): ?>
            <option value="<?=$motivo?>" <?=($_GET['motivo']??'')===$motivo?'selected':''?>><?=$motivo?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Responsable</label>
        <input type="text" name="usuario" class="form-control" value="<?=htmlspecialchars($_GET['usuario']??'')?>" placeholder="Nombre o Email">
      </div>
      <div class="col-md-3">
        <label class="form-label">Desde Fecha de Carga</label>
        <input type="date" name="fecha_desde" class="form-control" value="<?=htmlspecialchars($_GET['fecha_desde']??'')?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Hasta Fecha de Carga</label>
        <input type="date" name="fecha_hasta" class="form-control" value="<?=htmlspecialchars($_GET['fecha_hasta']??'')?>">
      </div>
    </div>
    <div class="row mt-2">
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit">Filtrar</button>
      </div>
    </div>
  </form>
  <div class="table-responsive">
    <div class="export-btns">
      <button class="btn btn-outline-secondary export-pdf-btn" data-table="tabla-archivados">
        <i class="bi bi-filetype-pdf"></i> Exportar PDF
      </button>
      <button class="btn btn-outline-secondary export-csv-btn" data-table="tabla-archivados">
        <i class="bi bi-filetype-csv"></i> Exportar CSV
      </button>
    </div>
    <table class="table table-striped" id="tabla-archivados">
      <thead>
        <tr>
          <th>Fecha Archivo</th><th>Motivo</th><th>Detalle</th><th>Responsable</th>
          <th>ID</th><th>Modelo</th><th>Marca</th><th>S/N Tag</th>
          <th>Categoría</th><th>Subcategoría</th><th>Estado</th>
          <th>Garantía</th><th>Ingreso</th><th>Egreso</th>
          <th>Ubicación</th><th>Asignado a</th><th>Valor de Compra en USD</th><th>Cantidad</th>
          <th>Detalle Técnico</th>
        </tr>
      </thead>
        <tbody>
        <?php
        $f = ["pm.accion = 'Archivado'"];
        $params = []; $types = "";
        
        if (!empty($_GET['motivo'])) {
          $f[] = "pm.descripcion LIKE ?";
          $params[] = "%Motivo: ".$_GET['motivo']."%";
          $types .= "s";
        }
        if (!empty($_GET['usuario'])) {
          $f[] = "(u.username LIKE ? OR u.email LIKE ?)";
          $params[] = "%".$_GET['usuario']."%";
          $params[] = "%".$_GET['usuario']."%";
          $types .= "ss";
        }
        if (!empty($_GET['fecha_desde']) && !empty($_GET['fecha_hasta'])) {
          $f[] = "pm.fecha BETWEEN ? AND ?";
          $params[] = $_GET['fecha_desde'];
          $params[] = $_GET['fecha_hasta'];
          $types .= "ss";
        } elseif (!empty($_GET['fecha_desde'])) {
          $f[] = "pm.fecha >= ?";
          $params[] = $_GET['fecha_desde'];
          $types .= "s";
        } elseif (!empty($_GET['fecha_hasta'])) {
          $f[] = "pm.fecha <= ?";
          $params[] = $_GET['fecha_hasta'];
          $types .= "s";
        }
        $hay_filtros = count($f) > 1; // 'Archivado' siempre está
        $limit = !$hay_filtros ? "LIMIT 100" : "";
        
        $sql = "SELECT pm.*, u.username, u.email
                FROM `{$movTable}` pm
                LEFT JOIN `{$usersTable}` u ON pm.realizado_por = u.id
                ".(count($f) ? "WHERE ".implode(" AND ",$f) : "")."
                ORDER BY pm.fecha DESC
                $limit";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $hay_datos = false;
        while ($row = $res->fetch_assoc()):
            $hay_datos = true;
            // Extraer motivo y detalle del campo descripcion de cada movimiento archivado
            $motivo = '—';
            $detalle_txt = '—';
            if (isset($row['descripcion'])) {
                if (preg_match('/Motivo:\s*([^\|]+)/', $row['descripcion'], $matches)) {
                    $motivo = trim($matches[1]);
                }
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
          <td><?= htmlspecialchars($row['username']) ?><br><small class="text-muted"><?=htmlspecialchars($row['email'])?></small></td>
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
          <tr>
            <td colspan="19" class="text-center text-muted">No hay datos disponibles</td>
          </tr>
        <?php endif; ?>
        </tbody>
    </table>
        <div class="paginador-container">
      <form method="get" id="form-per-page-<?= $tab ?>" class="d-flex align-items-center gap-2">
        <?php
          // Conserva todos los filtros actuales de la pestaña
          foreach ($_GET as $key => $value) {
            if ($key === 'per_page') continue;
            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
          }
          $perPageOptions = [20, 50, 100];
          $per_page = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 20;
          $page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;
        ?>
        <label for="per-page-<?= $tab ?>" class="select-perpage-label">
          Mostrar:
          <select name="per_page" id="per-page-<?= $tab ?>" class="select-perpage" onchange="document.getElementById('form-per-page-<?= $tab ?>').submit()">
            <?php foreach($perPageOptions as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          por página
        </label>
      </form>
      <nav aria-label="Paginador de <?= htmlspecialchars($tab) ?>">
        <ul class="pagination-cool">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>1])) ?>" aria-label="Primero">&laquo;</a>
          </li>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>max(1, $page-1)])) ?>" aria-label="Anterior">&lsaquo;</a>
          </li>
          <?php
          // Calcula el total de páginas
          $totalRows = $res->num_rows ?? 0; // Si ya calculaste el total, usalo acá!
          $totalPages = max(1, ceil($totalRows / $per_page));
          $start = max(1, $page-2); $end = min($totalPages, $page+2);
          for($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>min($totalPages, $page+1)])) ?>" aria-label="Siguiente">&rsaquo;</a>
          </li>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= http_build_query(array_merge($_GET, ['page'=>$totalPages])) ?>" aria-label="Último">&raquo;</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>
<!-- JS de selects dependientes y botones rápidos de fechas -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
    
    document.querySelectorAll('#tabla-trazabilidad thead th').forEach((th, i) => {
  console.log(`Columna ${i}: ${th.textContent}`);
});

  // Objeto de categorías/subcategorías desde PHP
  const categoriasFull = <?= json_encode($categorias_full, JSON_UNESCAPED_UNICODE) ?>;

  // Selects dependientes (categoría -> subcategoría)
  const selectPairs = [
    {cat:'#categoria-reporte', sub:'#subcategoria-reporte'},
    {cat:'#categoria-reporte2', sub:'#subcategoria-reporte2'},
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
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Todas';
        subSel.appendChild(opt);
        subSel.setAttribute('disabled', 'disabled');
        return;
      }
      subSel.removeAttribute('disabled');
      const subcats = categoriasFull[cat] || [];
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Todas';
      subSel.appendChild(opt);
      subcats.forEach(sub => {
        const option = document.createElement('option');
        option.value = sub;
        option.textContent = sub;
        subSel.appendChild(option);
      });
    }

    catSel.addEventListener('change', function(){
      subSel.value = '';
      loadSubcategorias();
    });
    loadSubcategorias();
  });

// Limpia todos los filtros del Ranking por Cantidad y muestra todo
function resetRanking() {
  // Opción simple: recargar solo con ?tab=valor (sin ningún filtro)
  const url = new URL(window.location.href);
  url.search = '';                  // limpia todos los parámetros
  url.searchParams.set('tab','valor');
  window.location.href = url.toString();

  /*  Si prefieres enviar el formulario limpiando campos en vez de cambiar la URL,
      descomenta este bloque y comenta el de arriba.

  const form = document.getElementById('form-valor');
  if (!form) return;
  const setEmpty = name => {
    const el = form.querySelector(`[name="${name}"]`);
    if (el) el.value = '';
  };
  setEmpty('categoria');
  setEmpty('subcategoria');
  setEmpty('fecha_desde');
  setEmpty('fecha_hasta');
  form.submit();
  */
}

// ------------------------ EXPORTACIONES ------------------------

// Asegura que todas las columnas visibles se mantengan visibles (para PDF/CSV idénticos a la vista)
function ensureAllColumnsVisible(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const allTh = table.querySelectorAll('thead th');
    allTh.forEach(th => th.style.display = '');

    const allTd = table.querySelectorAll('tbody td');
    allTd.forEach(td => td.style.display = '');
}

// ---------- Exportación a PDF (tu lógica existente, intacta) ----------
const exportBtns = document.querySelectorAll('.export-pdf-btn');

function tableToPdf(tableId, tituloReporte) {
    ensureAllColumnsVisible(tableId);

    const table = document.getElementById(tableId);
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
    const totalColumns = headers.length;
    const rows = Array.from(table.querySelectorAll('tbody tr'));

    // Si hay más de 11 columnas: vertical adaptado 2 registros/hoja
    if (totalColumns > 11) {
        let contentBlocks = [];
        const filasPorHoja = 2;
        let currentPage = [];
        let registroEnHoja = 0;

        for (let r = 0; r < rows.length; r++) {
            const cells = rows[r].querySelectorAll('td');
            const transposedRow = headers.map((header, colIdx) => {
                const cleanText = cells[colIdx]?.innerText.replace(/\s+/g, ' ').trim() || '—';
                return [
                    {
                        text: header,
                        style: 'tableHeader',
                        fontSize: 8,
                        fillColor: '#663399',
                        color: '#fff',
                        margin: [2, 2, 2, 2],
                        alignment: 'left',
                        bold: true,
                        border: [false, false, false, false]
                    },
                    {
                        text: cleanText,
                        style: 'tableCell',
                        fontSize: 8,
                        color: '#4E247A',
                        fillColor: (r % 2 === 0 ? '#fff' : '#F8F7FC'),
                        margin: [2, 2, 2, 2],
                        alignment: 'left',
                        border: [false, false, false, false]
                    }
                ];
            });

            currentPage = currentPage.concat(transposedRow);

            registroEnHoja++;
            const esUltimoEnHoja = registroEnHoja === filasPorHoja;
            const esUltimoEnTotal = r === rows.length - 1;

            // Separador visual entre registros de la hoja (excepto el último)
            if (!esUltimoEnHoja && !esUltimoEnTotal) {
                currentPage.push([
                    { text: '', colSpan: 2, border: [false, false, false, false], margin: [0, 16, 0, 16], fillColor: '#fff' }, {}
                ]);
            }

            // Si es el último de la hoja o el último registro de todos
            if (esUltimoEnHoja || esUltimoEnTotal) {
                // Si NO se completa la hoja con dos registros, rellena el "espacio" para mantener simetría
                if (registroEnHoja < filasPorHoja) {
                    currentPage.push([
                        { text: '', colSpan: 2, border: [false, false, false, false], margin: [0, 120, 0, 120], fillColor: '#fff' }, {}
                    ]);
                }

                contentBlocks.push({
                    table: {
                        headerRows: 1,
                        widths: ['*', '*'],
                        body: currentPage
                    },
                    layout: {
                        hLineWidth: () => 0.25,
                        vLineWidth: () => 0.25,
                        hLineColor: () => '#ccc',
                        vLineColor: () => '#ccc'
                    },
                    pageBreak: (!esUltimoEnTotal) ? 'after' : undefined
                });
                currentPage = [];
                registroEnHoja = 0;
            }
        }

        // PDF vertical (portrait)
        const docDefinition = {
            pageOrientation: 'portrait',
            pageMargins: [20, 30, 20, 20],
            content: [
                {
                    columns: [
                        [
                            { text: tituloReporte, style: 'header', margin: [0, 0, 0, 4] },
                            { text: 'Generado: ' + new Date().toLocaleString(), style: 'subheader' }
                        ]
                    ]
                },
                { text: ' ', margin: [0, 8] },
                ...contentBlocks
            ],
            styles: {
                header: {
                    fontSize: 14,
                    bold: true,
                    color: '#4E247A'
                },
                subheader: {
                    fontSize: 9,
                    color: '#8B5EB7'
                },
                tableHeader: {
                    bold: true,
                    alignment: 'left',
                    color: '#fff'
                },
                tableCell: {
                    color: '#4E247A',
                    alignment: 'left'
                }
            },
            defaultStyle: {
                fontSize: 8
            }
        };

        pdfMake.createPdf(docDefinition).download(`${tituloReporte}.pdf`);
    }
    // Si es horizontal (11 columnas o menos), diseño clásico con headers y celdas centradas, landscape
    else {
        let body = [];
        const headerRow = headers.map(text => ({
            text,
            style: 'tableHeader',
            fontSize: 8,
            fillColor: '#663399',
            color: '#fff',
            bold: true,
            noWrap: false,
            margin: [2, 2, 2, 2],
            alignment: 'center'
        }));
        body.push(headerRow);

        const rows = Array.from(table.querySelectorAll('tbody tr'));
        rows.forEach((row, idx) => {
            const cells = row.querySelectorAll('td');
            const dataRow = headers.map((_, i) => {
                const cleanText = cells[i]?.innerText.replace(/\s+/g, ' ').trim() || '—';
                return {
                    text: cleanText,
                    style: 'tableCell',
                    fontSize: 8,
                    noWrap: false,
                    margin: [2, 2, 2, 2],
                    alignment: 'center',
                    fillColor: idx % 2 === 0 ? '#fff' : '#F8F7FC',
                    color: '#4E247A'
                };
            });
            body.push(dataRow);
        });

        const docDefinition = {
            pageOrientation: totalColumns > 8 ? 'landscape' : 'portrait',
            pageMargins: [20, 30, 20, 20],
            content: [
                {
                    columns: [
                        [
                            { text: tituloReporte, style: 'header', margin: [0, 0, 0, 4] },
                            { text: 'Generado: ' + new Date().toLocaleString(), style: 'subheader' }
                        ]
                    ]
                },
                { text: ' ', margin: [0, 8] },
                {
                    table: {
                        headerRows: 1,
                        widths: Array(totalColumns).fill('*'),
                        body: body
                    },
                    layout: {
                        hLineWidth: () => 0.25,
                        vLineWidth: () => 0.25,
                        hLineColor: () => '#ccc',
                        vLineColor: () => '#ccc'
                    }
                }
            ],
            styles: {
                header: {
                    fontSize: 14,
                    bold: true,
                    color: '#4E247A'
                },
                subheader: {
                    fontSize: 9,
                    color: '#8B5EB7'
                },
                tableHeader: {
                    bold: true,
                    alignment: 'center',
                    color: '#fff'
                },
                tableCell: {
                    color: '#4E247A',
                    alignment: 'center'
                }
            },
            defaultStyle: {
                fontSize: 8
            }
        };

        pdfMake.createPdf(docDefinition).download(`${tituloReporte}.pdf`);
    }
}

// Asocia el botón de exportación con la tabla correcta (PDF)
exportBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        const tableId = btn.getAttribute('data-table');
        let titulo = 'Reporte de Inventario';
        if (tableId === 'tabla-valor') titulo = 'Ranking por Cantidad';
        if (tableId === 'tabla-amortizacion') titulo = 'Amortización';
        if (tableId === 'tabla-trazabilidad') titulo = 'Trazabilidad';
        if (tableId === 'tabla-archivados') titulo = 'Archivados';
        tableToPdf(tableId, titulo);
    });
});

// ---------- Exportación a CSV (nuevo) ----------
function tableToCSV(tableId, filename) {
    ensureAllColumnsVisible(tableId);

    const table = document.getElementById(tableId);
    if (!table) return;

    function escapeCSV(value) {
        // Asegura CSV válido: comas, comillas, saltos de línea
        const v = (value ?? '').toString();
        if (/[",\n]/.test(v)) {
            return '"' + v.replace(/"/g, '""') + '"';
        }
        return v;
    }

    // Headers
    const headers = Array.from(table.querySelectorAll('thead th'))
        .map(th => th.textContent.trim());
    const headerLine = headers.map(escapeCSV).join(',');

    // Rows
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const lines = [headerLine];

    rows.forEach(tr => {
        const cells = Array.from(tr.querySelectorAll('td'))
            .map(td => td.innerText.replace(/\s+/g, ' ').trim() || '—')
            .map(escapeCSV);
        lines.push(cells.join(','));
    });

    const csvContent = lines.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename.endsWith('.csv') ? filename : filename + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Asocia el botón de exportación CSV a su tabla
const exportCsvBtns = document.querySelectorAll('.export-csv-btn');
exportCsvBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        const tableId = btn.getAttribute('data-table');
        let nombre = 'reporte';
        if (tableId === 'tabla-inventario') nombre = 'inventario_general';
        if (tableId === 'tabla-valor') nombre = 'ranking_por_cantidad';
        if (tableId === 'tabla-amortizacion') nombre = 'amortizacion';
        if (tableId === 'tabla-trazabilidad') nombre = 'trazabilidad';
        if (tableId === 'tabla-archivados') nombre = 'archivados';
        tableToCSV(tableId, `${nombre}_${new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')}.csv`);
    });
});
</script>
<footer class="footer">© <?= date('Y') ?> INTELLIHELP S.A. — Asset Vault</footer>
</body>
</html>
