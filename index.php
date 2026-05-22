<?php
declare(strict_types=1);

// Sesión y conexión multiempresa
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Router por rol: si el usuario tiene sesión de superadmin, su lugar es el panel SaaS, no el inventario.
require_once __DIR__ . '/core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/backend/db.php';    // $conn + tn() del tenant activo
require_once __DIR__ . '/logs/logger.php';   // log_event()
require_once __DIR__ . '/backend/csrf.php';  // generateCsrfToken() — debe cargarse antes del output HTML

try {
    // Aseguramos charset correcto (por si acaso)
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    // Debe haber usuario logueado
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }

    $user_id    = (int)$_SESSION['user_id'];
    $usersTable = tn('users');      // respeta modo prefix / external

    // Cargar usuario actual
    $sqlUser = "SELECT * FROM `{$usersTable}` WHERE id = ?";
    $stmt    = $conn->prepare($sqlUser);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        log_event("index.php", "ERROR", "Usuario ID {$user_id} no encontrado en {$usersTable}", "auth");
        session_destroy();
        header('Location: login.php');
        exit();
    }

    // Si sigue teniendo pass "admin", forzar cambio de contraseña
    if ($user['role'] === 'admin' && password_verify('admin', $user['password'])) {
        echo "<script>
                alert('Por favor, cambia tu contraseña de administrador.');
                window.location='change_password.php';
              </script>";
        exit();
    }

    // -----------------------------
    // Paginación e inventario
    // -----------------------------
    $sortable = [
        'id'              => 'id',
        'asignado_a'      => 'asignado_a',
        'modelo'          => 'modelo',
        'marca'           => 'marca',
        'descripcion'     => 'descripcion',
        'servis_tag'      => 'servis_tag',
        'cantidad'        => 'cantidad',
        'valor_usd'       => 'valor_usd',
        'estado'          => 'estado',
        'categoria'       => 'categoria',
        'periodo_garantia'=> 'periodo_garantia',
        'fecha_garantia'  => 'fecha_garantia',
        'fecha_ingreso'   => 'fecha_ingreso',
        // 'fecha_egreso' => 'fecha_egreso',
        'ubicacion'       => 'ubicacion'
    ];

    $sort  = $_GET['sort']  ?? 'id';
    $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';
    if (!isset($sortable[$sort])) {
        $sort = 'id';
    }
    $sort_sql = $sortable[$sort];

    // --- Paginación ---
    $perPageOptions = [20, 50, 100];
    $per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions, true)
        ? (int)$_GET['per_page']
        : 20;
    $page = (isset($_GET['page']) && (int)$_GET['page'] > 0) ? (int)$_GET['page'] : 1;

    $productosTable = tn('productos');

    // Total de productos (no archivados)
    $countSql  = "SELECT COUNT(*) AS total FROM `{$productosTable}` WHERE archivado = 0";
    $countRes  = $conn->query($countSql);
    $totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $per_page));
    $offset = ($page - 1) * $per_page;

    // Consulta paginada
    $listSql = "SELECT *, subcategoria
                FROM `{$productosTable}`
                WHERE archivado = 0
                ORDER BY {$sort_sql} {$order}
                LIMIT {$per_page} OFFSET {$offset}";
    $result = $conn->query($listSql);

    // -----------------------------
    // KPIs del inventario
    // -----------------------------
    $kpi_total       = $totalRows;
    $kpi_valor_usd   = 0.0;
    $kpi_archivados  = 0;
    $kpi_garantia_vence_30d = 0;
    $kpi_assets_sin_asignar = 0;
    try {
        $r = $conn->query("SELECT COALESCE(SUM(valor_usd * cantidad), 0) AS v FROM `{$productosTable}` WHERE archivado = 0");
        if ($r) { $kpi_valor_usd = (float)($r->fetch_assoc()['v'] ?? 0); }

        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$productosTable}` WHERE archivado = 1");
        if ($r) { $kpi_archivados = (int)($r->fetch_assoc()['c'] ?? 0); }

        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$productosTable}`
                           WHERE archivado = 0
                             AND fecha_garantia IS NOT NULL
                             AND fecha_garantia >= CURDATE()
                             AND fecha_garantia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        if ($r) { $kpi_garantia_vence_30d = (int)($r->fetch_assoc()['c'] ?? 0); }

        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$productosTable}`
                           WHERE archivado = 0
                             AND (asignado_a IS NULL OR asignado_a = '')");
        if ($r) { $kpi_assets_sin_asignar = (int)($r->fetch_assoc()['c'] ?? 0); }
    } catch (Throwable $eKpi) {
        log_event("index.php", "WARN", "No se pudieron calcular KPIs: " . $eKpi->getMessage(), "app");
    }

} catch (Throwable $e) {
    log_event("sistema", "ERROR", "Error en index.php: " . $e->getMessage(), "app");
    echo "<script>alert('Error de base de datos. El administrador debe revisar el log.');window.location='login.php';</script>";
    exit();
}

// Token CSRF para exportar CSV — generado antes de cualquier output HTML
$csrf_export = generateCsrfToken('export_inventario');

// -----------------------------
// Mapa de iconos por subcategoría
// -----------------------------
$iconMap = [
    //
    // Subcategorías Informática
    //
    'Desktop (PC de escritorio)'         => 'bi bi-pc-display',
    'Laptop'                             => 'bi bi-laptop',
    'Notebook'                           => 'bi bi-laptop',
    'Monitor'                            => 'bi bi-display',
    'Mouse'                              => 'bi bi-mouse',
    'Teclado'                            => 'bi bi-keyboard',
    'Webcam'                             => 'bi bi-webcam',
    'Micrófono'                          => 'bi bi-mic',
    'Auriculares'                        => 'bi bi-headset',
    'Parlantes'                          => 'bi bi-speaker',
    'Escáner'                            => 'bi bi-scanner',
    'Numpad'                             => 'bi bi-calculator',
    'Tableta gráfica'                    => 'bi bi-tablet',
    'Tablet'                             => 'bi bi-tablet',
    'All-in-One'                         => 'bi bi-pc',
    'Docking station'                    => 'bi bi-usb-c',
    'Cables de alimentación'             => 'bi bi-plug',
    'Soportes / Bases para notebook'     => 'bi bi-easel',

    //
    // Subcategorías Redes
    //
    'Patchcord'                          => 'bi bi-diagram-3',
    'Switch'                             => 'bi bi-hdd-network',
    'Router'                             => 'bi bi-router',
    'Access Point'                       => 'bi bi-wifi',
    'Módem'                              => 'bi bi-modem',
    'Firewall'                           => 'bi bi-shield-lock',
    'Repetidor / Extensor Wi-Fi'         => 'bi bi-wifi-fine',
    'Antenas'                            => 'bi bi-signal',
    'Rack'                               => 'bi bi-box-seam',
    'Patch panel'                        => 'bi bi-grid-3x2-gap',
    'Organizadores de cables'            => 'bi bi-arrows-move',
    'Puntos de red (RJ45)'               => 'bi bi-ethernet',
    'Bobina UTP'                         => 'bi bi-collection',
    'Conectores'                         => 'bi bi-plug',
    'Tester de red'                      => 'bi bi-wrench',
    'POE Injector / POE Switch'          => 'bi bi-lightning-charge',
    'Convertidores de medios'            => 'bi bi-arrow-left-right',

    //
    // Subcategorías Servidores
    //
    'Servidor físico'                    => 'bi bi-server',
    'NAS'                                => 'bi bi-hdd-stack',
    'SAN'                                => 'bi bi-hdd-stack',
    'HDD'                                => 'bi bi-hdd',
    'SSD'                                => 'bi bi-cpu',
    'Cintas de respaldo'                 => 'bi bi-folder2-open',
    'Gabinetes externos'                 => 'bi bi-house',
    'RAID Controller'                    => 'bi bi-diagram-3',
    'UPS servidores'                     => 'bi bi-battery-charging',
    'Fuentes redundantes'                => 'bi bi-battery',

    //
    // Subcategorías Periféricos
    //
    'Impresora térmica'                  => 'bi bi-printer',
    'Impresora de tickets'               => 'bi bi-ticket-perforated',
    'Impresora multifunción'             => 'bi bi-printer',
    'Lector de código de barras'         => 'bi bi-upc-scan',
    'Lector biométrico'                  => 'bi bi-fingerprint',
    'Tarjeta de video externa'           => 'bi bi-display',
    'Adaptadores USB / HDMI / VGA'       => 'bi bi-usb-c',
    'Hub USB'                            => 'bi bi-plug',
    'Cables (HDMI, VGA, ...)'            => 'bi bi-cable',
    'Fundas / Mochilas'                  => 'bi bi-bag',
    'Kit limpieza'                       => 'bi bi-brush',
    'Cargadores y fuentes'               => 'bi bi-bolt',
    'Adaptadores eléctricos'             => 'bi bi-plug',

    //
    // Subcategorías Herramientas
    //
    'Destornilladores'                   => 'bi bi-screwdriver',
    'Kits de precisión'                  => 'bi bi-tools',
    'Pinzas / Alicates'                  => 'bi bi-scissors',
    'Tester eléctrico'                   => 'bi bi-lightning-charge',
    'Multímetro'                         => 'bi bi-speedometer',
    'Crimpadoras'                        => 'bi bi-scissors',
    'Pelacables'                         => 'bi bi-scissors',
    'Termofusora'                        => 'bi bi-droplet',
    'Etiquetadora'                       => 'bi bi-tag',
    'Cepillos antiestáticos'             => 'bi bi-brush',
    'Espuma Antiestática'                => 'bi bi-clouds',
    'Aire comprimido'                    => 'bi bi-wind',
    'Alcohol isopropílico'               => 'bi bi-droplet',

    //
    // Subcategorías Seguridad
    //
    'Cámara IP'                          => 'bi bi-camera-video',
    'CCTV'                               => 'bi bi-camera-video',
    'DVR'                                => 'bi bi-cpu',
    'NVR'                                => 'bi bi-cpu',
    'Sensores'                           => 'bi bi-thermometer',
    'Control de acceso'                  => 'bi bi-door-closed',
    'Cerraduras electrónicas'            => 'bi bi-lock',
    'Alarmas'                            => 'bi bi-bell',
    'Detectores'                         => 'bi bi-bell',
    'UPS CCTV'                           => 'bi bi-battery-charging',
    'Cables coaxiales'                   => 'bi bi-cable',
    'Monitores'                          => 'bi bi-display',

    //
    // Subcategorías Software
    //
    'SO'                                 => 'bi bi-window',
    'Licencias Office'                   => 'bi bi-file-earmark-spreadsheet',
    'Antivirus'                          => 'bi bi-shield-check',
    'Software de backup'                 => 'bi bi-cloud-arrow-up',
    'Software contable / ERP'            => 'bi bi-calculator',
    'LicServ'                            => 'bi bi-server',
    'Virt'                               => 'bi bi-diagram-3',
    'Nube'                               => 'bi bi-cloud',

    //
    // Subcategorías Telefonía
    //
    'Teléfonos IP'                       => 'bi bi-telephone-forward',
    'Tel analógicos'                     => 'bi bi-telephone',
    'DECT'                               => 'bi bi-phone',
    'PBX'                                => 'bi bi-hdd-network',
    'ATA'                                => 'bi bi-phone-plus',
    'Headset'                            => 'bi bi-headset',
    'Softphones'                         => 'bi bi-phone',
    'Modulos'                            => 'bi bi-puzzle',
    'VoIP Gateway'                       => 'bi bi-telephone-outbound',
    'Cables telefónicos'                 => 'bi bi-cable',
    'Baterías inalámbricos'              => 'bi bi-battery',

    //
    // Subcategorías Electricidad
    //
    'Tomas y enchufes'                   => 'bi bi-plug',
    'Zapatillas'                         => 'bi bi-plug',
    'Cables eléctricos'                  => 'bi bi-cable',
    'Disyuntores'                        => 'bi bi-lightning-charge',
    'Luces emergencia'                   => 'bi bi-lightbulb',
    'LED'                                => 'bi bi-lightbulb',
    'Transformadores'                    => 'bi bi-battery-charging',
    'Regletas'                           => 'bi bi-plug',
    'Estabilizadores'                    => 'bi bi-battery-half',
    'UPS'                                => 'bi bi-battery-charging',
    'Baterías'                           => 'bi bi-battery',
    'Pilas'                              => 'bi bi-battery',

    //
    // Subcategorías Inmobiliario
    //
    'Escritorios'                        => 'bi bi-table',
    'Sillas ergonómicas'                 => 'bi bi-chair',
    'Sillas oficina'                     => 'bi bi-chair',
    'Mesas trabajo'                      => 'bi bi-table',
    'Archiveros'                         => 'bi bi-file-earmark',
    'Estanterías'                        => 'bi bi-box-seam',
    'Racks'                              => 'bi bi-box-seam',
    'Soportes monitor'                   => 'bi bi-easel',
    'Carros equipos'                     => 'bi bi-truck',

    //
    // Stock de Repuestos y Otros
    //
    'Repuestos'                          => 'bi bi-gear',
    'Otros'                              => 'bi bi-three-dots'
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Asset Vault</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
      html, body {
        height: 100%;
        margin: 0;
        padding: 0;
        font-family: 'Saira', sans-serif;
        background-color: #F5F5F5;
        color: #333;
      }
    
      body {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        overflow-x: hidden;
      }
    
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
      }
    
      .logout-btn {
        background-color: #4E247A;
        color: #fff;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 5px;
        text-decoration: none;
        font-weight: 500;
      }
    
      .logout-btn:hover {
        background-color: #86B329;
        color: #fff;
      }
    
      .container {
        flex: 1;
        padding: 2rem 1rem 4rem 1rem; /* Padding inferior extra para evitar que el footer lo tape */
        max-width: 100%;
        overflow-x: auto;
        background-color: #fff;
        box-shadow: 0 0 10px rgba(0,0,0,0.05);
        border-radius: 0;
      }
    
      .nav-tabs .nav-link.active {
        background-color: #86B329;
        color: #fff;
        border: none;
      }
    
      .nav-tabs .nav-link {
        color: #4E247A;
        font-weight: 600;
      }
    
      .tab-content {
        margin-top: 2rem;
      }
    
      table {
        font-size: 0.9rem;
        min-width: 1200px;
      }
    
      thead {
        background-color: #4E247A;
        color: #fff;
      }
    
      .btn-primary {
        background-color: #86B329;
        border: none;
      }
    
      .btn-primary:hover {
        background-color: #4E247A;
      }
    
      .footer {
        text-align: center;
        padding: 1rem;
        background-color: #663399;
        color: #fff;
        width: 100%;
        position: fixed;
        bottom: 0;
        left: 0;
        z-index: 999;
      }
    
      .table th, .table td {
        vertical-align: middle !important;
      }
    
      .btn-sm {
        font-size: 0.75rem;
      }
        .acciones-btns {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 4px;
        height: 78px;
        }
        
    .sortable-col {
      cursor: pointer;
      user-select: none;
      transition: background 0.2s;
      border-radius: 6px;
      padding: 0 2px;
      min-width: 80px;
      justify-content: flex-end;
    }
    .sort-icons {
      font-size: 1.1em;
      color: #fff;
      margin-left: 2px;
      transition: color 0.2s, filter 0.2s;
      vertical-align: middle;
    }
    .sort-active {
      color: #86B329 !important;
      filter: drop-shadow(0 0 3px #86B329a0);
    }
    .sortable-col:hover {
      background: #eee2ff21;
    }
    /* -- PAGINADOR CUSTOM Y SELECT "MOSTRAR X POR PÁGINA" -- */
    .paginador-container {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 2rem;
      margin-bottom: 8px;
      flex-wrap: wrap;
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
      /* Cambiá esto: */
      padding: 0.25em 1.2em 0.25em 1.2em;
      min-width: 80px; /* o el valor que prefieras */
      width: 80px;
      text-align: center;  /* AGREGADO: centra el texto dentro del select */
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
    @media (max-width:700px) {
      .paginador-container { flex-direction: column; gap: 10px;}
    }
    .pagination-cool {
      margin-bottom: 0;
      display: flex;
      gap: 0.15em;
      list-style: none;      /* <-- AGREGADO */
      padding-left: 0;       /* <-- AGREGADO, quita padding default */
    }
    .pagination-cool .page-link, .pagination-cool .page-link:visited {
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
<body>
<!-- TOPBAR INTELLIHELP -->
<link rel="stylesheet" href="/assets/css/intellihelp.css">
<style>
  /* Override del header viejo para no chocar con el topbar nuevo */
  header.ih-topbar-tenant { display: flex; align-items: center; padding: 0 16px; height: 64px; background: #fff; border-bottom: 1px solid #E4E4E7; position: sticky; top: 0; z-index: 900; box-shadow: 0 1px 3px rgba(17,20,24,.04); }
  header.ih-topbar-tenant .brand { display: flex; align-items: center; gap: 12px; }
  header.ih-topbar-tenant .brand__logo { width: 36px; height: 36px; background: #663399; color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; position: relative; }
  header.ih-topbar-tenant .brand__logo::after { content: ''; position: absolute; right: 4px; bottom: 4px; width: 7px; height: 7px; background: #9CC930; border-radius: 50%; }
  header.ih-topbar-tenant .brand__text strong { font-size: 15px; color: #18181B; font-weight: 700; letter-spacing: -.3px; display: block; line-height: 1.1; }
  header.ih-topbar-tenant .brand__text span { font-size: 11px; color: #71717A; }
  header.ih-topbar-tenant h1, header.ih-topbar-tenant .h1 { font-size: 15px; color: #18181B; font-weight: 700; margin: 0; }
  header.ih-topbar-tenant .topbar-spacer { flex: 1; }
  header.ih-topbar-tenant .menu-btn { background: transparent; border: 1px solid #E4E4E7; color: #663399; padding: 7px 12px; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; text-decoration: none; margin-left: 6px; font-family: 'Saira', sans-serif; }
  header.ih-topbar-tenant .menu-btn:hover { background: #F4F1F9; border-color: #663399; color: #4E247A; }
  header.ih-topbar-tenant .menu-btn--logout { background: #663399; color: #fff; border-color: #663399; }
  header.ih-topbar-tenant .menu-btn--logout:hover { background: #4E247A; border-color: #4E247A; color: #fff; }
  .tenant-dropdown { position: relative; display: inline-block; }
  .tenant-dropdown__menu { display: none; position: absolute; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid #E4E4E7; border-radius: 12px; box-shadow: 0 12px 40px rgba(102,51,153,.12); min-width: 220px; z-index: 1000; padding: 6px; overflow: hidden; }
  .tenant-dropdown.is-open .tenant-dropdown__menu { display: block; animation: ih-fade-in 200ms ease; }
  .tenant-dropdown__item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #3F3F46; font-size: 13px; font-weight: 500; border-radius: 8px; text-decoration: none; transition: all .15s; }
  .tenant-dropdown__item:hover { background: #F4F1F9; color: #663399; }
  .tenant-dropdown__item i { font-size: 16px; color: #663399; opacity: .85; }
  .tenant-dropdown__divider { height: 1px; background: #E4E4E7; margin: 4px 0; }
  .tenant-dropdown__section { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #A1A1AA; padding: 8px 12px 4px; font-weight: 600; }
  @media (max-width: 640px) {
    header.ih-topbar-tenant .brand__text { display: none; }
    header.ih-topbar-tenant .menu-btn span { display: none; }
  }
</style>
<header class="ih-topbar-tenant">
  <div class="brand">
    <div class="brand__logo">i</div>
    <div class="brand__text">
      <strong>Asset Vault</strong>
      <span>INTELLIHELP · Inventario</span>
    </div>
  </div>
  <div class="topbar-spacer"></div>

  <a href="reportes.php" class="menu-btn">
    <i class="bi bi-graph-up"></i> <span>Reportes</span>
  </a>

  <div class="tenant-dropdown" id="tenantDropdown">
    <button type="button" class="menu-btn" onclick="document.getElementById('tenantDropdown').classList.toggle('is-open')">
      <i class="bi bi-person-circle"></i> <span><?= htmlspecialchars(($user['username'] ?? 'Cuenta')) ?></span>
      <i class="bi bi-chevron-down" style="font-size:11px"></i>
    </button>
    <div class="tenant-dropdown__menu">
      <div class="tenant-dropdown__section">Cuenta</div>
      <a class="tenant-dropdown__item" href="change_password.php"><i class="bi bi-key"></i> Cambiar contraseña</a>

      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <div class="tenant-dropdown__divider"></div>
        <div class="tenant-dropdown__section">Administración</div>
        <a class="tenant-dropdown__item" href="usuarios.php"><i class="bi bi-people"></i> Usuarios del tenant</a>
        <a class="tenant-dropdown__item" href="configurar_integracion.php"><i class="bi bi-cloud-arrow-up"></i> Integración Drive</a>
        <a class="tenant-dropdown__item" href="importar.php"><i class="bi bi-arrow-left-right"></i> Backup &amp; migración</a>
      <?php endif; ?>

      <div class="tenant-dropdown__divider"></div>
      <a class="tenant-dropdown__item" href="logout.php" style="color:#DC2626"><i class="bi bi-box-arrow-right" style="color:#DC2626"></i> Cerrar sesión</a>
    </div>
  </div>
</header>
<!-- Cerrar dropdown al click fuera -->
<script>
document.addEventListener('click', function(e){
  var d = document.getElementById('tenantDropdown');
  if (d && !d.contains(e.target)) d.classList.remove('is-open');
});
</script>

<div class="container">
    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="inventario-tab" data-bs-toggle="tab" data-bs-target="#ver-inventario" type="button" role="tab">
              <i class="bi bi-box-seam"></i> Ver Activos
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="agregar-tab" data-bs-toggle="tab" data-bs-target="#agregar-producto" type="button" role="tab">
              <i class="bi bi-plus-square"></i> Agregar Activo
            </button>
        </li>
    </ul>
    
<!-- Lista de Activos -->
<div class="tab-content" id="myTabContent">
  <div class="tab-pane fade show active" id="ver-inventario" role="tabpanel">

    <!-- KPIs del inventario -->
    <div class="ih-stats" style="margin-top:24px;margin-bottom:24px;">
      <div class="ih-stat">
        <div class="ih-stat__label">Total activos <i class="bi bi-box-seam"></i></div>
        <div class="ih-stat__value"><?= number_format((int)$kpi_total, 0, ',', '.') ?></div>
        <div class="ih-stat__delta">En operación (no archivados)</div>
      </div>
      <div class="ih-stat ih-stat--green">
        <div class="ih-stat__label">Valor del inventario <i class="bi bi-currency-dollar"></i></div>
        <div class="ih-stat__value">USD <?= number_format((float)$kpi_valor_usd, 0, ',', '.') ?></div>
        <div class="ih-stat__delta">valor_usd × cantidad</div>
      </div>
      <div class="ih-stat ih-stat--warn">
        <div class="ih-stat__label">Garantía vence <i class="bi bi-shield-exclamation"></i></div>
        <div class="ih-stat__value"><?= (int)$kpi_garantia_vence_30d ?></div>
        <div class="ih-stat__delta">Próximos 30 días</div>
      </div>
      <div class="ih-stat ih-stat--info">
        <div class="ih-stat__label">Sin asignar <i class="bi bi-person-dash"></i></div>
        <div class="ih-stat__value"><?= (int)$kpi_assets_sin_asignar ?></div>
        <div class="ih-stat__delta">Activos disponibles</div>
      </div>
      <div class="ih-stat ih-stat--danger">
        <div class="ih-stat__label">Archivados <i class="bi bi-archive"></i></div>
        <div class="ih-stat__value"><?= (int)$kpi_archivados ?></div>
        <div class="ih-stat__delta">
          <a href="reportes.php?tab=archivados" style="color:inherit;font-weight:600;text-decoration:underline">Ver detalle →</a>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap mt-4 mb-3">
      <h3 class="fw-bold text-uppercase" style="color: #4E247A; font-family: 'Saira', sans-serif;">Lista de Activos</h3>
      <div class="d-flex flex-wrap gap-2">
        <a href="backend/export_csv.php?csrf_token=<?= urlencode($csrf_export) ?>" class="btn btn-sm" style="background-color: #86B329; color: white;"><i class="bi bi-file-earmark-arrow-down"></i> Exportar CSV</a>
        <form action="backend/import_csv.php" method="POST" enctype="multipart/form-data" class="d-inline">
          <label class="btn btn-sm btn-outline-secondary mb-0">
            <i class="bi bi-upload"></i> Importar CSV
            <input type="file" name="csv_file" accept=".csv" onchange="this.form.submit()" hidden>
          </label>
        </form>
      </div>
    </div>
      <div class="text-center mb-3">
        <div class="btn-group" role="group">
          <button class="btn btn-sm" style="background-color: #4E247A; color: white;" onclick="filtrarPorEstado('todos')"><i class="bi bi-list"></i> Todos</button>
          <button class="btn btn-sm" style="background-color: #86B329; color: white;" onclick="filtrarPorEstado('vigente')"><i class="bi bi-check-circle"></i> Vigentes</button>
          <button class="btn btn-sm" style="background-color: #F5A623; color: white;" onclick="filtrarPorEstado('por-caducar')"><i class="bi bi-exclamation-triangle"></i> Por caducar</button>
          <button class="btn btn-sm" style="background-color: #D9534F; color: white;" onclick="filtrarPorEstado('caducado')"><i class="bi bi-x-circle"></i> Caducados</button>
          <button class="btn btn-sm" style="background-color: #6c757d; color: white;" onclick="filtrarPorEstado('no-aplica')"><i class="bi bi-dash-circle"></i> No aplica</button>
        </div>
      </div>
    <!--Codigo de filtro de busqueda Poner Acá o donde sea aca estaba:       <div class="row mb-3">
        <div class="col-md-4">
          <label for="filtroCampo" class="form-label fw-bold" style="font-family: 'Saira', sans-serif;">Buscar por:</label>
          <select id="filtroCampo" class="form-select">
            <option value="id">ID</option>
            <option value="asignado">Asignado a</option>
            <option value="marca">Marca</option>
            <option value="servis_tag">S/N Service Tag</option>
            <option value="ubicacion">Ubicación</option>
            <option value="fecha_garantia">Fecha de Garantía</option>
            <option value="fecha_ingreso">Fecha de Ingreso</option>
            <option value="fecha_egreso">Fecha de Egreso</option>
          </select>
        </div>
        <div class="col-md-8">
          <label for="busquedaAvanzada" class="form-label fw-bold" style="font-family: 'Saira', sans-serif;">Buscar:</label>
          <input type="text" id="busquedaAvanzada" class="form-control" placeholder="Ingrese valor a buscar...">
        </div>
      </div>-->
      <div class="table-responsive">
        <table class="table table-bordered align-middle table-hover text-center" style="background-color: #FFFFFF; font-family: 'Saira', sans-serif;">
<thead style="background-color: #663399; color: white;">
  <tr>
    <?php
      $columnas = [
        'id'=>'ID',
        'asignado_a'=>'Asignado a',
        'modelo'=>'Nombre/Modelo',
        'marca'=>'Marca',
        'descripcion'=>'Descripción',
        'servis_tag'=>'S/N Service Tag',
        'cantidad'=>'Cantidad',
        'valor_usd'=>'Valor de Compra en USD',
        'estado'=>'Estado',
        'categoria'=>'Categoría',
        'periodo_garantia'=>'Garantía',
        'fecha_garantia'=>'Fecha Garantía',
        'fecha_ingreso'=>'Fecha de Recepción',
        //'fecha_egreso'=>'Egreso',
        'ubicacion'=>'Ubicación'
      ];
      $sort = $_GET['sort'] ?? 'id';
      $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';
      foreach($columnas as $clave => $nombre):
        $isActive = ($sort == $clave);
        $isAsc = $isActive && $order == 'ASC';
        $isDesc = $isActive && $order == 'DESC';
        // Flex para texto+flechas
        echo "<th>";
        echo "<span class='sortable-col d-inline-flex align-items-center gap-1' data-sort='$clave' style='min-width: 80px;'>";
        echo "<span class='me-1'>$nombre</span>";
        // Solo una flecha activa
        echo "<i class='bi bi-caret-up-fill sort-icons ".($isAsc?'sort-active':'')."' id='sort-up-$clave'></i>";
        echo "<i class='bi bi-caret-down-fill sort-icons ".($isDesc?'sort-active':'')."' id='sort-down-$clave'></i>";
        echo "</span>";
        echo "</th>";
      endforeach;
    ?>
    <th>Detalles Técnicos</th>
    <th>Archivos</th>
    <th>Acciones</th>
  </tr>
</thead>

  <?php if($result && $result->num_rows > 0): ?>
    <?php while($row = $result->fetch_assoc()): 
      // 3) Icono exclusivamente según subcategoría (o por defecto carpeta)
      $subcat   = $row['subcategoria']; 
      $iconClass = isset($iconMap[$subcat])
        ? $iconMap[$subcat]
        : 'bi bi-folder';
    ?>
      <tr>
        <td><?= htmlspecialchars($row['id']) ?></td>
        <td><?= htmlspecialchars($row['asignado_a']) ?></td>
        <td><?= htmlspecialchars($row['modelo']) ?></td>
        <td><?= htmlspecialchars($row['marca']) ?></td>
        <td><?= htmlspecialchars($row['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($row['servis_tag']) ?></td>
        <td><?= htmlspecialchars($row['cantidad']) ?></td>
        <td>$<?= number_format($row['valor_usd'], 2) ?></td>
        <td><?= htmlspecialchars($row['estado']) ?></td>
        <td><?= htmlspecialchars($row['categoria']) ?></td>
        <td data-original="<?= $row['periodo_garantia'] ?>"><?= htmlspecialchars($row['periodo_garantia']) ?> meses</td>
        <td><?= htmlspecialchars($row['fecha_garantia']) ?></td>
        <td><?= htmlspecialchars($row['fecha_ingreso']) ?></td>
      <!-- <td><?= htmlspecialchars($row['fecha_egreso']) ?></td> -->
        <td><?= htmlspecialchars($row['ubicacion']) ?></td>
        <td>
        <?php
          $detalles = [];
          switch ($row['subcategoria']) {
            // --- Redes ---
            case 'Patchcord':
              if (!empty($row['patchcord_tipo']))     $detalles[] = "Tipo: " . htmlspecialchars($row['patchcord_tipo']);
              if (!empty($row['patchcord_longitud'])) $detalles[] = "Longitud: " . htmlspecialchars($row['patchcord_longitud']) . " m";
              break;
            case 'Switch':
              if (!empty($row['switch_gestion']))     $detalles[] = "Gestión: " . htmlspecialchars($row['switch_gestion']);
              break;
            case 'Antenas':
              if (!empty($row['antenas_tipo']))       $detalles[] = "Tipo: " . htmlspecialchars($row['antenas_tipo']);
              break;
            case 'Bobina UTP':
              if (!empty($row['bobina_categoria']))   $detalles[] = "Cat: " . htmlspecialchars($row['bobina_categoria']);
              if (!empty($row['bobina_ubicacion']))   $detalles[] = "Ubicación: " . htmlspecialchars($row['bobina_ubicacion']);
              if (!empty($row['bobina_longitud']))    $detalles[] = "Longitud: " . htmlspecialchars($row['bobina_longitud']) . " m";
              break;
            case 'Conectores':
              if (!empty($row['conectores_tipo']))    $detalles[] = "Tipo: " . htmlspecialchars($row['conectores_tipo']);
              if (!empty($row['conectores_tipo']) && $row['conectores_tipo'] === 'Otros' && !empty($row['detalle_conectores']))
                  $detalles[] = "Detalle: " . htmlspecialchars($row['detalle_conectores']);
              break;
            case 'Cintas de respaldo':
              if (!empty($row['cintas_generacion']))  $detalles[] = "Generación: " . htmlspecialchars($row['cintas_generacion']);
              break;
            case 'Repuestos':
              if (!empty($row['detalle_repuesto']))   $detalles[] = htmlspecialchars($row['detalle_repuesto']);
              break;
            case 'Otros':
              if (!empty($row['detalle_otros']))      $detalles[] = htmlspecialchars($row['detalle_otros']);
              break;
        
            // --- Informática ---
            case 'Desktop (PC de escritorio)':
            case 'Laptop':
            case 'Notebook':
            case 'All-in-One':
              if (!empty($row['equipo_marca']))   $detalles[] = "Marca: " . htmlspecialchars($row['equipo_marca']);
              if (!empty($row['equipo_modelo']))  $detalles[] = "Modelo: " . htmlspecialchars($row['equipo_modelo']);
              if (!empty($row['equipo_cpu']))     $detalles[] = "CPU: " . htmlspecialchars($row['equipo_cpu']);
              if (!empty($row['equipo_ram']))     $detalles[] = "RAM: " . htmlspecialchars($row['equipo_ram']) . " GB";
              if (!empty($row['equipo_almacenamiento'])) $detalles[] = "Disco: " . htmlspecialchars($row['equipo_almacenamiento']);
              if (!empty($row['equipo_os']))      $detalles[] = "OS: " . htmlspecialchars($row['equipo_os']);
              break;
            case 'Monitor':
              if (!empty($row['monitor_marca']))   $detalles[] = "Marca: " . htmlspecialchars($row['monitor_marca']);
              if (!empty($row['monitor_modelo']))  $detalles[] = "Modelo: " . htmlspecialchars($row['monitor_modelo']);
              if (!empty($row['monitor_tamanio'])) $detalles[] = "Tamaño: " . htmlspecialchars($row['monitor_tamanio']) . "''";
              if (!empty($row['monitor_resolucion'])) $detalles[] = "Resolución: " . htmlspecialchars($row['monitor_resolucion']);
              break;
            case 'Tablet':
              if (!empty($row['tablet_marca']))    $detalles[] = "Marca: " . htmlspecialchars($row['tablet_marca']);
              if (!empty($row['tablet_modelo']))   $detalles[] = "Modelo: " . htmlspecialchars($row['tablet_modelo']);
              if (!empty($row['tablet_tamanio']))  $detalles[] = "Tamaño: " . htmlspecialchars($row['tablet_tamanio']);
              if (!empty($row['tablet_sistema']))  $detalles[] = "Sistema: " . htmlspecialchars($row['tablet_sistema']);
              break;
            case 'Teclado':
            case 'Mouse':
            case 'Webcam':
            case 'Micrófono':
            case 'Auriculares':
            case 'Parlantes':
            case 'Escáner':
            case 'Numpad':
            case 'Tableta gráfica':
            case 'Docking station':
            case 'Cables de alimentación':
            case 'Soportes / Bases para notebook':
              if (!empty($row['detalle_periferico'])) $detalles[] = htmlspecialchars($row['detalle_periferico']);
              break;
        
            // --- Muebles ---
            case 'Sillas ergonómicas':
            case 'Sillas de oficina':
            case 'Escritorios':
            case 'Mesas de trabajo':
              if (!empty($row['mueble_marca']))    $detalles[] = "Marca: " . htmlspecialchars($row['mueble_marca']);
              if (!empty($row['mueble_modelo']))   $detalles[] = "Modelo: " . htmlspecialchars($row['mueble_modelo']);
              if (!empty($row['mueble_color']))    $detalles[] = "Color: " . htmlspecialchars($row['mueble_color']);
              if (!empty($row['mueble_material'])) $detalles[] = "Material: " . htmlspecialchars($row['mueble_material']);
              if (!empty($row['mueble_medidas']))  $detalles[] = "Medidas: " . htmlspecialchars($row['mueble_medidas']);
              break;
        
            // --- Servidores y almacenamiento ---
            case 'Servidor físico (Rack / Tower)':
            case 'NAS (Network Attached Storage)':
            case 'SAN (Storage Area Network)':
              if (!empty($row['servidor_marca']))    $detalles[] = "Marca: " . htmlspecialchars($row['servidor_marca']);
              if (!empty($row['servidor_modelo']))   $detalles[] = "Modelo: " . htmlspecialchars($row['servidor_modelo']);
              if (!empty($row['servidor_cpu']))      $detalles[] = "CPU: " . htmlspecialchars($row['servidor_cpu']);
              if (!empty($row['servidor_ram']))      $detalles[] = "RAM: " . htmlspecialchars($row['servidor_ram']) . " GB";
              if (!empty($row['servidor_almacenamiento'])) $detalles[] = "Almacenamiento: " . htmlspecialchars($row['servidor_almacenamiento']);
              break;
        
            // --- Electricidad ---
            case 'Tomas y enchufes':
            case 'Zapatillas eléctricas / Múltiples':
            case 'Cables eléctricos':
            case 'Disyuntores / térmicas':
            case 'Luces de emergencia':
            case 'Lámparas / iluminación LED':
            case 'Transformadores':
            case 'Regletas con protección':
            case 'Estabilizadores de tensión':
            case 'UPS':
            case 'Baterías':
            case 'Pilas':
              if (!empty($row['detalle_electricidad'])) $detalles[] = htmlspecialchars($row['detalle_electricidad']);
              break;
        
            // --- Default: mostrar detalle general si existe ---
            default:
              if (!empty($row['detalle_general'])) {
                $detalles[] = htmlspecialchars($row['detalle_general']);
              }
              break;
          }
          echo $detalles ? implode("<br>", $detalles) : '<span class="text-muted">-</span>';
        ?>
        </td>
        <td>
          <?php if (!empty($row['carpeta_raiz_drive'])): ?>
            <a href="https://drive.google.com/drive/folders/<?= $row['carpeta_raiz_drive'] ?>"
               target="_blank"
               class="btn btn-outline-success btn-sm rounded-pill px-3">
             <i class="<?= $iconClass ?>"></i> Carpeta
            </a>
          <?php else: ?>
            <span class="text-muted">Sin archivos</span>
          <?php endif; ?>
        </td>
        <td class="acciones-btns">
          <a href="backend/view_details.php?id=<?= $row['id'] ?>" class="btn btn-outline-info btn-sm"><i class="bi bi-eye"></i></a>
          <a href="backend/edit.php?id=<?= $row['id'] ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil"></i></a>
          <a href="backend/delete.php?id=<?= $row['id'] ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></a>
        </td>
      </tr>
    <?php endwhile; ?>
  <?php else: ?>
    <tr>
      <td colspan="17" class="text-center">No hay productos en el inventario</td>
    </tr>
  <?php endif; ?>
</tbody>
        </table>
      </div>
    
        <div class="paginador-container">
      <form method="get" id="form-per-page" class="d-flex align-items-center gap-2">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
        <input type="hidden" name="page" value="<?= $page ?>">
        <label for="per-page" class="select-perpage-label">Mostrar:
          <select name="per_page" id="per-page" class="select-perpage" onchange="document.getElementById('form-per-page').submit()">
            <?php foreach($perPageOptions as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          por página
        </label>
      </form>
      <nav aria-label="Paginador de inventario">
        <ul class="pagination-cool">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&per_page=<?= $per_page ?>&page=1" aria-label="Primero">&laquo;</a>
          </li>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&per_page=<?= $per_page ?>&page=<?= max(1, $page-1) ?>" aria-label="Anterior">&lsaquo;</a>
          </li>
          <?php
          $start = max(1, $page-2); $end = min($totalPages, $page+2);
          for($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="?sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&per_page=<?= $per_page ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="?sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&per_page=<?= $per_page ?>&page=<?= min($totalPages, $page+1) ?>" aria-label="Siguiente">&rsaquo;</a>
          </li>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="?sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>&per_page=<?= $per_page ?>&page=<?= $totalPages ?>" aria-label="Último">&raquo;</a>
          </li>
        </ul>
      </nav>
    </div>
    
</div>
<footer class="footer">© <?= date('Y') ?> INTELLIHELP S.A. — Asset Vault</footer>
<!-- Agregar Activo -->
  <div class="tab-pane fade" id="agregar-producto" role="tabpanel"
       style="background-color: #F5F5F5; padding: 2rem; border-radius: 8px;">
    <h3 class="fw-bold text-uppercase mb-4" style="color: #4E247A;">Agregar Activo</h3>
    <form id="form-agregar"
        action="backend/upload_to_drive.php"
        method="POST"
        enctype="multipart/form-data">
      <div class="row g-3">

      <!-- Campos básicos -->
      <div class="col-md-6">
        <label for="asignado_a" class="form-label text-uppercase" style="color: #663399;">
          Asignado a
        </label>
        <input type="text" class="form-control" name="asignado_a">
      </div>
      <div class="col-md-6">
        <label for="nombre" class="form-label text-uppercase" style="color: #663399;">
          Nombre/Modelo *
        </label>
        <input type="text" class="form-control" name="nombre" required>
      </div>
      <div class="col-md-6">
        <label for="marca" class="form-label text-uppercase" style="color: #663399;">
          Marca
        </label>
        <input type="text" class="form-control" name="marca">
      </div>
      <div class="col-md-6">
        <label for="servis_tag" class="form-label text-uppercase" style="color: #663399;">
          S/N Service Tag
        </label>
        <input type="text" class="form-control" name="servis_tag" id="servis_tag">
      </div>

    <!-- NUEVO: Cantidad, solo si NO hay S/N Service Tag -->
    <div class="col-md-6" id="cantidad-container">
      <label for="cantidad" class="form-label text-uppercase" style="color: #663399;">
        Cantidad *
      </label>
      <input type="number" class="form-control" name="cantidad" id="cantidad" min="1" placeholder="Ej. 5">
    </div>

        <!-- Categoría con icono dinámico -->
    <div class="col-md-6">
      <label for="categoria-select" class="form-label text-uppercase" style="color: #663399;">
        Categoría
        <i id="icon-categoria" class="bi bi-folder ms-2"></i>
      </label>
      <select class="form-select" name="categoria" id="categoria-select">
        <option value="">Seleccione...</option>
        <option value="Informática">Informática</option>
        <option value="Redes">Redes</option>
        <option value="Servidores y almacenamiento">Servidores y almacenamiento</option>
        <option value="Periféricos y accesorios">Periféricos y accesorios</option>
        <option value="Herramientas y mantenimiento">Herramientas y mantenimiento</option>
        <option value="Seguridad">Seguridad</option>
        <option value="Software y licencias">Software y licencias</option>
        <option value="Telefonía">Telefonía</option>
        <option value="Electricidad">Electricidad</option>
        <option value="Inmobiliario / Muebles">Inmobiliario / Muebles</option>
        <option value="Stock de Repuestos">Stock de Repuestos</option>
        <option value="Otros">Otros</option>
      </select>
    </div>

    <!-- Subcategoría dinámica -->
    <div class="col-md-6" id="subcategoria-container" style="display:none;">
      <label for="subcategoria-select" class="form-label text-uppercase" style="color: #663399;">
        Subcategoría
        <i id="icon-subcategoria" class="bi bi-folder ms-2"></i>
      </label>
      <select id="subcategoria-select" name="subcategoria" class="form-select">
        <option value="">Seleccione...</option>
      </select>
    </div>
    <!-- Aquí añadimos T O D O S los bloques extra, ocultos por defecto -->
    <!-- Patchcord: primero seleccionas tipo, luego longitud -->
    
    <!-- Sub-subcategoría Software: SOLO para Software y Licencias -->
    <div class="col-md-6" id="subsoftware-container" style="display:none;">
      <label for="subsoftware-select" class="form-label text-uppercase" style="color: #663399;">Detalle Licencia/Plataforma</label>
      <select id="subsoftware-select" name="subsoftware" class="form-select">
        <option value="">Seleccione...</option>
      </select>
    </div>
    <!-- Campo Otros (solo si se elige “Otro”) -->
    <div class="col-md-6" id="subsoftware-otros-container" style="display:none;">
      <label for="subsoftware-otros-input" class="form-label text-uppercase" style="color: #663399;">Detalle "Otros"</label>
      <input type="text" name="subsoftware_otros" id="subsoftware-otros-input"
             class="form-control"
             placeholder="Escribí aquí el detalle...">
    </div>
    
    <div id="patchcord-extra" class="row g-3 mt-3" style="display:none;">
      <!-- 1. Tipo de cable -->
      <div id="patchcord-categoria" class="col-md-4" style="display:none;">
        <label class="form-label">Tipo de patchcord</label>
        <select name="patchcord_tipo" id="patchcord-categoria-select" class="form-select">
          <option value="">— Seleccione tipo —</option>
          <option value="UTP">UTP</option>
          <option value="STP">STP</option>
          <option value="FTP">FTP</option>
        </select>
      </div>
      <!-- 2. Longitud -->
      <div id="patchcord-longitud" class="col-md-4" style="display:none;">
        <label class="form-label">Longitud</label>
        <select name="patchcord_longitud" id="patchcord-longitud-select" class="form-select">
          <option value="">— Seleccione longitud —</option>
          <option value="1">1 m</option>
          <option value="3">3 m</option>
          <option value="5">5 m</option>
          <option value="10">10 m</option>
          <option value=">10">&gt;10 m</option>
        </select>
      </div>
    </div>
    
    <!-- Switch -->
    <div id="switch-extra" class="row g-3 mt-3" style="display:none;">
      <div class="col-md-6">
        <label class="form-label">Gestión</label>
        <select name="switch_gestion" class="form-select">
          <option value="">Seleccione...</option>
          <option value="No gestionado">No gestionado</option>
          <option value="Gestionado">Gestionado</option>
        </select>
      </div>
    </div>
    
    <!-- Antenas -->
    <div id="antenas-extra" class="row g-3 mt-3" style="display:none;">
      <div class="col-md-6">
        <label class="form-label">Tipo de antena</label>
        <select name="antenas_tipo" class="form-select">
          <option value="">Seleccione...</option>
          <option value="Omnidireccional">Omnidireccional</option>
          <option value="Direccional">Direccional</option>
        </select>
      </div>
    </div>
        
    <!-- Bobina UTP -->
    <div id="bobina-extra" class="row g-3 mt-3" style="display:none;">
      <div id="bobina-categoria" class="col-md-4" style="display:none;">
        <label class="form-label">Categoría cable</label>
        <select name="bobina_categoria" id="bobina-categoria-select" class="form-select">
          <option value="">— Seleccione categoría —</option>
          <option value="CAT5e">CAT5</option>
          <option value="CAT6e">CAT6</option>
          <option value="CAT5e">CAT5e</option>
          <option value="CAT6e">CAT6e</option>
        </select>
      </div>
      <div id="bobina-ubicacion" class="col-md-4" style="display:none;">
        <label class="form-label">Tipo</label>
        <select name="bobina_ubicacion" id="bobina-ubicacion-select" class="form-select">
          <option value="">— Interior/Exterior —</option>
          <option value="Interior">Interior</option>
          <option value="Exterior">Exterior</option>
        </select>
      </div>
      <div id="bobina-longitud" class="col-md-4" style="display:none;">
        <label class="form-label">Longitud</label>
        <select name="bobina_longitud" id="bobina-longitud-select" class="form-select">
          <option value="">— Seleccione longitud —</option>
          <option value="10">10 m</option>
          <option value="25">25 m</option>
          <option value="50">50 m</option>
          <option value="100">100 m</option>
          <option value=">100">&gt;100 m</option>
        </select>
      </div>
    </div>
    
    <!-- Conectores -->
    <div id="conectores-extra" class="row g-3 mt-3" style="display:none;">
      <div class="col-md-6">
        <label class="form-label">Tipo de conector</label>
        <select id="conectores-tipo-select" name="conectores_tipo" class="form-select">
          <option value="">Seleccione...</option>
          <option value="RJ45">RJ45</option>
          <option value="SC">SC</option>
          <option value="LC">LC</option>
          <option value="Otros">Otros</option>
        </select>
      </div>
      <!-- Este campo sólo se muestra si seleccionas "Otros" -->
      <div id="conectores-otros" class="col-12" style="display:none;">
        <label class="form-label">Especifique otro conector</label>
        <input type="text" name="detalle_conectores" class="form-control" placeholder="Describa aquí…">
      </div>
    </div>
    
    <!-- Cintas de respaldo -->
    <div id="cintas-extra" class="row g-3 mt-3" style="display:none;">
      <div id="cintas-generacion" class="col-md-6" style="display:none;">
        <label class="form-label">Generación LTO</label>
        <select name="cintas_generacion" class="form-select">
          <option value="">Seleccione...</option>
          <option value="LTO-1">LTO-1</option>
          <option value="LTO-2">LTO-2</option>
          <option value="LTO-3">LTO-3</option>
          <option value="LTO-4">LTO-4</option>
          <option value="LTO-5">LTO-5</option>
        </select>
      </div>
    </div>
    
    <!-- Repuestos -->
    <div id="subcategoria-repuestos" class="row g-3 mt-3" style="display:none;">
      <div class="col-12">
        <label class="form-label">Detalle Repuesto</label>
        <input type="text" name="detalle_repuesto" class="form-control" placeholder="Especifique">
      </div>
    </div>
    
    <!-- Otros -->
    <div id="subcategoria-otros" class="row g-3 mt-3" style="display:none;">
      <div class="col-12">
        <input type="text" name="detalle_otros" class="form-control" placeholder="Especifique">
      </div>
    </div>
    
    <!-- Extras Repuestos y Otros -->
    <div id="subcategoria-repuestos" style="display:none;" class="row g-3 mt-3">
      <div class="col-12">
        <label class="form-label">Detalle de Repuesto</label>
        <input type="text" name="detalle_repuesto" class="form-control" placeholder="Especifique">
      </div>
    </div>
    <div id="subcategoria-otros" style="display:none;" class="row g-3 mt-3">
      <div class="col-12">
        <label class="form-label">Detalle Otro</label>
        <input type="text" name="detalle_otros" class="form-control" placeholder="Especifique">
      </div>
    </div>

      <!-- Descripción -->
      <div class="col-12">
        <label for="descripcion" class="form-label text-uppercase" style="color: #663399;">
          Descripción
        </label>
        <textarea class="form-control" name="descripcion" rows="3"
                  placeholder="Añade una descripción del producto"></textarea>
      </div>

      <!-- Estado -->
      <div class="col-md-6">
        <label for="estado" class="form-label text-uppercase" style="color: #663399;">
          Estado
        </label>
        <select class="form-select" name="estado">
          <option value="Nuevo">Nuevo</option>
          <option value="Usado">Usado</option>
        </select>
      </div>
      
      <!-- (valor_usd, garantia, fechas, archivos, botón) -->
      <div class="col-md-6">
        <label for="valor_usd" class="form-label text-uppercase" style="color: #663399;">Valor de Compra en USD</label>
        <input type="number" class="form-control" name="valor_usd" step="0.01" min="0" placeholder="Ej. 1234.56">
      </div>
      <div class="col-md-4">
        <label for="periodo_garantia" class="form-label text-uppercase" style="color: #663399;">Período de Garantía (meses)</label>
        <input type="number" class="form-control" name="periodo_garantia" placeholder="Ej. 12">
      </div>
      <div class="col-md-4">
        <label for="fecha_garantia" class="form-label text-uppercase" style="color: #663399;">Inicio Garantía</label>
        <input type="date" class="form-control" name="fecha_garantia">
      </div>
      <div class="col-md-4">
        <label for="ubicacion" class="form-label text-uppercase" style="color: #663399;">Ubicación</label>
        <input type="text" class="form-control" name="ubicacion">
      </div>
      <div class="col-md-6">
        <label for="fecha_ingreso" class="form-label text-uppercase" style="color: #663399;">Fecha de Recepción <span style="font-size:0.8em; text-transform:none; font-weight:400;">(Fecha de remito)</span></label>
        <input type="date" class="form-control" name="fecha_ingreso">
      </div>
      <div class="col-md-6">
        <label for="fecha_compra" class="form-label text-uppercase" style="color: #663399;">Fecha de Compra <span style="font-size:0.8em; text-transform:none; font-weight:400;">(Orden de compra – Opcional)</span></label>
        <input type="date" class="form-control" name="fecha_compra">
      </div>
      <div class="col-12">
        <label for="archivo" class="form-label text-uppercase" style="color: #663399;">Subir Archivos</label>
        <input type="file" class="form-control" name="archivo[]" multiple id="file-input">
        <div class="form-text" style="color: #4E247A;">.jpg, .png, .pdf, .docx | máx 5MB</div>
      </div>
      <div class="col-12 d-flex justify-content-center mt-3">
        <button type="submit" class="btn" style="background-color: #86B329; color: #fff; border-radius: 30px; padding: 0.6rem 2rem;">
          Agregar Activo
        </button>
      </div>
    </div>
  </form>
    <footer class="footer">© <?= date('Y') ?> INTELLIHELP S.A. — Asset Vault</footer>
        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
        <!-- Validación de archivos -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
  // --- ORDENAMIENTO CLIENTE ---
  let currentSort = 'id';
  let currentOrder = 'asc';

  const ths = document.querySelectorAll('.sortable-col');
  const tbody = document.querySelector('table tbody');

  ths.forEach(col => {
    col.addEventListener('click', function(e) {
      e.preventDefault();
      const sort = col.getAttribute('data-sort');
      if (sort === currentSort) {
        currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
      } else {
        currentSort = sort;
        currentOrder = 'asc';
      }
      ordenarTabla(sort, currentOrder);

      // Quitar estado de todas las flechas
      document.querySelectorAll('.sort-icons').forEach(i => i.classList.remove('sort-active'));
      // Activar flecha correcta
      ths.forEach(th=>{
        const up = th.querySelector('.bi-caret-up-fill');
        const down = th.querySelector('.bi-caret-down-fill');
        if (th.getAttribute('data-sort') === sort) {
          (currentOrder === 'asc' ? up : down).classList.add('sort-active');
        }
      });
    });
  });
  function obtenerValorCelda(tr, idx) {
    let txt = tr.children[idx].textContent.trim();
    // Si es número, parsear como número; si es fecha, parsear fecha
    // Si es "$", lo parseamos también
    if (txt.match(/^\$\d+(\.\d+)?$/)) return parseFloat(txt.replace('$',''));
    if (txt.match(/^\d+(\.\d+)?$/)) return parseFloat(txt);
    if (txt.match(/^\d{4}-\d{2}-\d{2}$/)) return new Date(txt);
    return txt.toLowerCase();
  }

  function ordenarTabla(columna, orden) {
    const colIndex = Array.from(ths).findIndex(th => th.getAttribute('data-sort') === columna);
    if (colIndex < 0) return;

    const filas = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.querySelectorAll('td').length);
    filas.sort((a, b) => {
      let valA = obtenerValorCelda(a, colIndex);
      let valB = obtenerValorCelda(b, colIndex);
      if (valA < valB) return orden === 'asc' ? -1 : 1;
      if (valA > valB) return orden === 'asc' ? 1 : -1;
      return 0;
    });
    // Reagregar filas ordenadas al DOM
    filas.forEach(tr => tbody.appendChild(tr));
  }

  // --- FIN ORDENAMIENTO ---
  // --- Funciones utilitarias ---
  function convertirFecha(fecha) {
    if (!fecha || fecha.startsWith('0000')) return null;
    const partes = fecha.split('-');
    if (partes.length !== 3) return null;
    const [anio, mes, dia] = partes.map(n => parseInt(n, 10));
    if (isNaN(anio) || isNaN(mes) || isNaN(dia)) return null;
    return new Date(anio, mes - 1, dia);
  }

  function convertirFechaTexto(fecha) {
    if (!fecha || fecha.startsWith('0000')) return '';
    const [anio, mes, dia] = fecha.split('-');
    return `${dia}/${mes}/${anio}`;
  }

  // --- Cálculo de garantía ---
    function calcularGarantia() {
      const filas = document.querySelectorAll('table tbody tr');
      const hoy = Date.now();
    
      filas.forEach(fila => {
        const celdas = fila.querySelectorAll('td');
        // ¡Evitar filas que no sean de datos reales!
        if (celdas.length < 12) return; // <--- Este valor es seguro: 12 o 13
        const celdaPeriodo = celdas[10];
        const celdaFechaGarantia = celdas[11];
        const celdaCategoria = celdas[9];

      const original = celdaPeriodo.getAttribute('data-original') || celdaPeriodo.innerText.trim().split(' ')[0];
      const meses = parseInt(original, 10);
      const fechaInicio = convertirFecha(celdaFechaGarantia.innerText.trim());

      if (!meses || !fechaInicio || isNaN(fechaInicio)) {
        celdaPeriodo.innerHTML = '<span class="text-muted">No aplica</span>';
        fila.setAttribute('data-estado-garantia', 'no-aplica');
        if (!celdaCategoria.innerText.trim()) celdaCategoria.innerText = 'No aplica';
        return;
      }

      const fechaFinal = new Date(fechaInicio);
      fechaFinal.setMonth(fechaFinal.getMonth() + meses);
      const diff = fechaFinal.getTime() - hoy;

      if (diff <= 0) {
        celdaPeriodo.innerHTML = '<span class="text-danger fw-bold">Caducado</span>';
        fila.setAttribute('data-estado-garantia', 'caducado');
      } else {
        const dias = Math.floor(diff / (1000*60*60*24));
        const horas = Math.floor((diff/(1000*60*60)) % 24);
        const minutos = Math.floor((diff/(1000*60)) % 60);
        const segundos = Math.floor((diff/1000) % 60);
        if (dias <= 7) {
          celdaPeriodo.innerHTML = `<span class="text-warning fw-bold">Por caducar: ${dias}d ${horas}h ${minutos}m ${segundos}s</span>`;
          fila.setAttribute('data-estado-garantia', 'por-caducar');
        } else {
          celdaPeriodo.innerHTML = `<span class="text-success">${dias}d ${horas}h ${minutos}m ${segundos}s</span>`;
          fila.setAttribute('data-estado-garantia', 'vigente');
        }
      }
    });
  }

  // --- Filtrado avanzado ---
  function aplicarFiltro() {
    const filtro = inputFiltro.value.toLowerCase().replace(/-/g,'/');
    const campo = campoFiltro.value;
    document.querySelectorAll('table tbody tr').forEach(fila => {
      const c = fila.querySelectorAll('td');
      const vals = {
        id: c[0]?.innerText.toLowerCase(),
        asignado: c[1]?.innerText.toLowerCase(),
        marca: c[3]?.innerText.toLowerCase(),
        servis_tag: c[5]?.innerText.toLowerCase(),
        ubicacion: c[12]?.innerText.toLowerCase(),
        fecha_garantia: convertirFechaTexto(c[9]?.innerText),
        fecha_ingreso: convertirFechaTexto(c[10]?.innerText),
    //    fecha_egreso: convertirFechaTexto(c[11]?.innerText),
        garantia_estado: fila.getAttribute('data-estado-garantia')||''
      };
      fila.style.display = (vals[campo]?.includes(filtro) ? '' : 'none');
    });
  }

 function filtrarPorEstado(estado) {
   document.querySelectorAll('#ver-inventario table tbody tr').forEach(fila => {
     const est = fila.getAttribute('data-estado-garantia');
     fila.style.display = (estado === 'todos' || est === estado) ? '' : 'none';
   });
 }
 window.filtrarPorEstado = filtrarPorEstado;

  // --- Validación de archivos ---
  function validarArchivos() {
    const archivos = document.getElementById('file-input').files;
    const tipos = [
      'image/jpeg','image/png','image/webp','image/gif',
      'application/pdf','application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.ms-powerpoint',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];
    for (let f of archivos) {
      if (f.size > 5*1024*1024) {
        alert(`El archivo "${f.name}" excede 5MB.`);
        return false;
      }
      if (!tipos.includes(f.type)) {
        alert(`El formato de "${f.name}" no está permitido.`);
        return false;
      }
    }
    return true;
  }

  // --- Mapa de íconos y opciones ---
  const iconMapJS = <?= json_encode($iconMap, JSON_UNESCAPED_UNICODE) ?>;
  const opcionesPorCategoria = {
    'Informática': [
      'Desktop (PC de escritorio)','Laptop','Notebook','Monitor','Mouse','Teclado',
      'Webcam','Micrófono','Auriculares','Parlantes','Escáner','Numpad','Tableta gráfica',
      'Tablet','All-in-One','Docking station','Cables de alimentación',
      'Soportes / Bases para notebook','Otros'
    ],
    'Redes': [
      'Patchcord','Switch','Router','Access Point','Módem','Firewall',
      'Repetidor / Extensor Wi-Fi','Antenas','Rack / Gabinete de comunicaciones',
      'Panel de parcheo (Patch panel)','Organizadores de cables','Puntos de red (RJ45)',
      'Bobina UTP','Conectores','Tester de red','POE Injector / POE Switch',
      'Convertidores de medios','Otros'
    ],
    'Servidores y almacenamiento': [
      'Servidor físico','NAS','SAN','Disco rígido (HDD)','Unidad de estado sólido (SSD)',
      'Cintas de respaldo','Gabinetes de almacenamiento externo','RAID Controller',
      'UPS para servidores','Fuentes redundantes','Otros'
    ],
    'Periféricos y accesorios': [
      'Impresora térmica','Impresora de tickets','Impresora multifunción',
      'Lector de código de barras','Lector biométrico','Tarjeta de video externa',
      'Adaptadores USB / HDMI / VGA','Hub USB','Cables (HDMI, VGA, DisplayPort, USB, etc.)',
      'Fundas / Mochilas / Maletines','Kit de limpieza','Cargadores y fuentes',
      'Adaptadores eléctricos','Otros'
    ],
    'Herramientas y mantenimiento': [
      'Destornilladores','Kits de precisión','Pinzas / Alicates','Tester eléctrico',
      'Multímetro','Crimpadoras','Pelacables','Termofusora / Pistola de calor',
      'Etiquetadora','Cepillos antiestáticos','Espuma Antiestática','Aire comprimido',
      'Alcohol isopropílico','Otros'
    ],
    'Seguridad': [
      'Cámara IP','CCTV','DVR','NVR','Sensores de movimiento','Control de acceso',
      'Cerraduras electrónicas','Alarmas','Detectores de humo / temperatura',
      'UPS para CCTV','Cables coaxiales','Monitores de vigilancia','Otros'
    ],
    'Software y licencias': [
      'Sistemas operativos','Licencias Office','Antivirus','Software de backup',
      'Software contable / ERP','Licencias de servidores','Herramientas de virtualización',
      'Licencias en la nube','Otros'
    ],
    'Telefonía': [
      'Teléfonos IP','Teléfonos analógicos','Teléfonos inalámbricos (DECT)',
      'Central telefónica / PBX','Adaptadores ATA','Auriculares con micrófono (headset)',
      'Softphones / Aplicaciones de telefonía','Módulos FXO / FXS','VoIP Gateway',
      'Cables telefónicos','Baterías para teléfonos inalámbricos','Otros'
    ],
    'Electricidad': [
      'Tomas y enchufes','Zapatillas eléctricas / Múltiples','Cables eléctricos',
      'Disyuntores / térmicas','Luces de emergencia','Lámparas / iluminación LED',
      'Transformadores','Regletas con protección','Estabilizadores de tensión',
      'UPS','Baterías','Pilas','Otros'
    ],
    'Inmobiliario / Muebles': [
      'Escritorios','Sillas ergonómicas','Sillas de oficina','Mesas de trabajo',
      'Archiveros / Cajas de almacenamiento','Estanterías','Racks','Soportes para monitor',
      'Carros para equipos','Otros'
    ],
    'Stock de Repuestos': ['Campo a completar'],
    'Otros': ['Otros']
  };

  // Sub-subcategoría Software/Licencias
  const subOpcionesSoftware = {
    'Sistemas operativos': ['Windows', 'Linux', 'macOS', 'Otro'],
    'Licencias Office': ['Microsoft Office', 'Office 365', 'Otro'],
    'Antivirus': ['ESET', 'Kaspersky', 'Norton', 'Avast', 'Otro'],
    'Software de backup': ['Acronis', 'Veeam', 'Backup Exec', 'Otro'],
    'Software contable / ERP': ['Tango', 'Bejerman', 'SAP', 'Otro'],
    'Licencias de servidores': ['Windows Server', 'CALs', 'Linux Server', 'Otro'],
    'Herramientas de virtualización': ['VMware', 'Hyper-V', 'Proxmox', 'VirtualBox', 'Otro'],
    'Licencias en la nube': ['Google Workspace', 'Microsoft 365', 'AWS', 'Otro']
  };

  // ---- DOM Elements ----
  const formAgregar = document.getElementById('form-agregar');
 // const inputFiltro = document.getElementById('busquedaAvanzada');
 // const campoFiltro = document.getElementById('filtroCampo');
  const catSel = document.getElementById('categoria-select');
  const subCont = document.getElementById('subcategoria-container');
  const subSel = document.getElementById('subcategoria-select');
  const iconCat = document.getElementById('icon-categoria');
  const iconSub = document.getElementById('icon-subcategoria');
  const cantidadContainer = document.getElementById('cantidad-container');
  const servisTagInput = document.getElementById('servis_tag');
  const inputRepuestos = document.getElementById('subcategoria-repuestos');
  const inputOtros     = document.getElementById('subcategoria-otros');
  const subSoftwareContainer = document.getElementById('subsoftware-container');
  const subSoftwareSelect = document.getElementById('subsoftware-select');
  const subSoftwareOtrosContainer = document.getElementById('subsoftware-otros-container');
  const subSoftwareOtrosInput = document.getElementById('subsoftware-otros-input');

  // Extras
  const extras = {
    'Patchcord':     document.getElementById('patchcord-extra'),
    'Switch':        document.getElementById('switch-extra'),
    'Antenas':       document.getElementById('antenas-extra'),
    'Bobina UTP':    document.getElementById('bobina-extra'),
    'Conectores':    document.getElementById('conectores-extra'),
    'POE Injector / POE Switch': document.getElementById('poe-extra'),
    'Cintas de respaldo': document.getElementById('cintas-extra'),
    'Adaptadores USB / HDMI / VGA': document.getElementById('adaptadores-extra'),
    'Cables (HDMI, VGA, ...)': document.getElementById('cables-extra'),
    'SO':            document.getElementById('so-extra'),
    'LicServ':       document.getElementById('licserv-extra'),
    'Virt':          document.getElementById('virt-extra'),
    'Nube':          document.getElementById('nube-extra'),
    'Repuestos':     document.getElementById('subcategoria-repuestos'),
    'Otros':         document.getElementById('subcategoria-otros')
  };

  // ---- Funciones para mostrar sub-subcategoría Software/Licencias ----
  function mostrarSubOpcionesSoftware(subcat) {
    if (catSel.value === 'Software y licencias' && subOpcionesSoftware[subcat]) {
      subSoftwareContainer.style.display = 'block';
      subSoftwareSelect.innerHTML = '<option value="">Seleccione...</option>';
      subOpcionesSoftware[subcat].forEach(opt => {
        subSoftwareSelect.innerHTML += `<option value="${opt}">${opt}</option>`;
      });
      subSoftwareOtrosContainer.style.display = 'none';
      subSoftwareOtrosInput.value = '';
    } else {
      subSoftwareContainer.style.display = 'none';
      subSoftwareSelect.value = '';
      subSoftwareOtrosContainer.style.display = 'none';
      subSoftwareOtrosInput.value = '';
    }
  }

  // ---- Listeners ----
  // Validación archivos
  formAgregar.addEventListener('submit', function(e){
    if (!validarArchivos()) e.preventDefault();
  });

  // Garantía
    document.querySelectorAll('table tbody tr').forEach(fila => {
      const td = fila.querySelectorAll('td')[10];
      if (td) {
        td.setAttribute('data-original', td.innerText.trim().split(' ')[0]);
      }
    });

  calcularGarantia();
  setInterval(calcularGarantia, 1000);

  // Filtros
  //inputFiltro.addEventListener('input', aplicarFiltro);
  //campoFiltro.addEventListener('change', aplicarFiltro);
  window.filtrarPorEstado = filtrarPorEstado;

  // Cantidad/ServiceTag lógica
    function toggleCantidad() {
      const tieneServiceTag = servisTagInput.value.trim().length > 0;
      if (tieneServiceTag) {
        document.getElementById('cantidad').value = 1; // Siempre deja 1 si hay S/N
        cantidadContainer.style.display = 'none';
      } else {
        cantidadContainer.style.display = 'block';
        document.getElementById('cantidad').value = '';
      }
    }
    servisTagInput.addEventListener('input', toggleCantidad);
    toggleCantidad(); // Para el caso inicial cuando carga la página

  // Cambios de categoría
  catSel.addEventListener('change', () => {
    resetAll();
    mostrarSubOpcionesSoftware('');
    iconCat.className = iconMapJS[catSel.value] || 'bi bi-folder ms-2';

    if (catSel.value === 'Stock de Repuestos') {
      inputRepuestos.style.display = 'block';
      subCont.style.display = 'none';
      return;
    }
    if (catSel.value === 'Otros') {
      inputOtros.style.display = 'block';
      subCont.style.display = 'none';
      return;
    }
    // Resto
    const lista = opcionesPorCategoria[catSel.value] || [];
    subSel.innerHTML = '<option value="">Seleccione…</option>';
    lista.forEach(opt => subSel.append(new Option(opt, opt)));
    subCont.style.display = lista.length ? 'block' : 'none';
    iconSub.className = 'bi bi-folder ms-2';
    subSel.value = '';
  });

  // Cambios de subcategoría
  subSel.addEventListener('change', () => {
    const val = subSel.value;
    iconSub.className = iconMapJS[val] || 'bi bi-folder ms-2';
    resetAll();
    mostrarSubOpcionesSoftware(val);

    // Patchcord
    if (val === 'Patchcord') {
      extras['Patchcord'].style.display = 'flex';
      document.getElementById('patchcord-categoria').style.display = 'block';
      document.getElementById('patchcord-categoria-select')
        .addEventListener('change', function(e){
          const show = !!e.target.value;
          document.getElementById('patchcord-longitud').style.display = show ? 'block' : 'none';
        });
    }
    // Conectores
    document.getElementById('conectores-tipo-select')
      ?.addEventListener('change', e => {
        const show = e.target.value === 'Otros';
        document.getElementById('conectores-otros').style.display = show ? 'block' : 'none';
      });

    // Bobina UTP
    if (val === 'Bobina UTP') {
      extras['Bobina UTP'].style.display = 'flex';
      document.getElementById('bobina-categoria').style.display = 'block';
    }
    document.getElementById('bobina-categoria-select')
      ?.addEventListener('change', e => {
        document.getElementById('bobina-ubicacion').style.display = !!e.target.value ? 'block' : 'none';
      });
    document.getElementById('bobina-ubicacion-select')
      ?.addEventListener('change', e => {
        document.getElementById('bobina-longitud').style.display = !!e.target.value ? 'block' : 'none';
      });

    if (val === 'Repuestos')   return void (inputRepuestos.style.display = 'block');
    if (val === 'Otros')       return void (inputOtros.style.display = 'block');
    const cont = extras[val];
    if (!cont) return;
    cont.style.display = 'block';
    if (val === 'Cintas de respaldo') document.getElementById('cintas-generacion').style.display = 'block';
  });

  // Sub-subcategoría "Otros" en Software/Licencias
  subSoftwareSelect.addEventListener('change', function() {
    if (subSoftwareSelect.value === "Otro") {
      subSoftwareOtrosContainer.style.display = 'block';
      subSoftwareOtrosInput.value = '';
      subSoftwareOtrosInput.focus();
    } else {
      subSoftwareOtrosContainer.style.display = 'none';
      subSoftwareOtrosInput.value = '';
    }
  });

  // ResetAll
  function resetAll() {
    Object.values(extras).forEach(div => div && (div.style.display = 'none'));
    [inputRepuestos, inputOtros].forEach(i => i && (i.style.display = 'none'));
    [
      'patchcord-tipo','patchcord-categoria','patchcord-longitud','patchcord-longitud-otro',
      'bobina-categoria','bobina-ubicacion','bobina-longitud','bobina-longitud-otro',
      'cintas-generacion','conectores-otros'
    ].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    subSoftwareContainer.style.display = 'none';
    subSoftwareOtrosContainer.style.display = 'none';
    subSoftwareOtrosInput.value = '';
  }

  // Flujos internos Patchcord, Bobina y Cintas
  document.getElementById('patchcord-tipo')?.addEventListener('change', e => {
    document.getElementById('patchcord-categoria').style.display = !!e.target.value ? 'block' : 'none';
  });
  document.getElementById('patchcord-categoria')?.addEventListener('change', e => {
    document.getElementById('patchcord-longitud').style.display = !!e.target.value ? 'block' : 'none';
  });
  document.getElementById('patchcord-longitud')?.addEventListener('change', e => {
    document.getElementById('patchcord-longitud-otro').style.display = (e.target.value==='>5') ? 'block' : 'none';
  });

  document.getElementById('bobina-categoria')?.addEventListener('change', e => {
    document.getElementById('bobina-ubicacion').style.display = !!e.target.value ? 'block' : 'none';
  });
  document.getElementById('bobina-ubicacion')?.addEventListener('change', e => {
    document.getElementById('bobina-longitud').style.display = !!e.target.value ? 'block' : 'none';
  });
  document.getElementById('bobina-longitud')?.addEventListener('change', e => {
    document.getElementById('bobina-longitud-otro').style.display = (e.target.value==='>5') ? 'block' : 'none';
  });
});
</script>
</body>
</html>