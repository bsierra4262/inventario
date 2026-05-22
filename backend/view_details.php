<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../logs/logger.php';
include 'db.php';

$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

if (!isset($_SESSION['user_id'])) {
    log_event($user_id, "ERROR", "Intento de acceso a view_details sin sesión activa", "consulta");
    header('Location: login.php');
    exit();
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $producto_id = intval($_GET['id']);

    $sql_producto = "SELECT * FROM `" . tn('productos') . "` WHERE id = ?";
    $stmt = $conn->prepare($sql_producto);
    if (!$stmt) {
        log_event($user_id, "ERROR", "Error en la consulta SQL: " . $conn->error, "consulta");
        die("Error en la consulta: " . $conn->error);
    }
    $stmt->bind_param('i', $producto_id);
    $stmt->execute();
    $result_producto = $stmt->get_result();

    if ($result_producto->num_rows > 0) {
        $producto = $result_producto->fetch_assoc();
        log_event($user_id, "CONSULTA", "Visualización de producto ID $producto_id", "consulta");
    } else {
        log_event($user_id, "ERROR", "Producto no encontrado ID: $producto_id", "consulta");
        echo "<script>Swal.fire('\u274c Error', 'Producto no encontrado.', 'error').then(() => window.location.href = '../index.php');</script>";
        $stmt->close();
        $conn->close();
        exit();
    }

    $stmt->close();
} else {
    log_event($user_id, "ERROR", "ID de producto no proporcionado o inválido", "consulta");
    echo "<script>Swal.fire('\u274c Error', 'ID de producto no proporcionado o inválido.', 'error').then(() => window.location.href = '../index.php');</script>";
    $conn->close();
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Detalles del Activo</title>
  <link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;600&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <style>
    html, body {
      height: 100%;
      margin: 0;
      font-family: 'Saira', sans-serif;
      background-color: #F5F5F5;
      color: #000;
    }

    body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
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
      margin: 2rem auto;
      max-width: 900px;
      background-color: #FFFFFF;
      padding: 2.5rem;
      border-radius: 18px;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
    }

    .table th {
      background-color: #4E247A;
      color: white;
      width: 40%;
    }

    .btn-primary {
      background-color: #86B329;
      border-color: #86B329;
      font-weight: 600;
    }

    .btn-primary:hover {
      background-color: #739b26;
      border-color: #739b26;
    }

    .btn-success {
      background-color: #FFFFFF;
      border: 2px solid #663399;
      color: #663399;
      font-weight: 600;
    }

    .btn-success:hover {
      background-color: #663399;
      color: #fff;
    }

    footer {
      width: 100%;
      text-align: center;
      padding: 1rem;
      background-color: #663399;
      color: #fff;
      position: relative;
    }

    @media (min-height: 700px) {
      footer {
        position: relative;
        bottom: 0;
        left: 0;
      }
    }
  </style>
</head>
<body>
<header>
  <h1><i class="bi bi-search"></i> Detalles del Activo</h1>
  <a href="logout.php" class="logout-btn"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
</header>
<div class="container">
  <h2 class="text-center mb-4"><i class="bi bi-info-circle"></i> Información del Activo</h2>
  <table class="table table-bordered text-center">

<?php
$labels = [
  'modelo' => 'Nombre/Modelo',
  'marca' => 'Marca',
  'detalle_tecnico' => 'Detalle Técnico',
  'asignado_a' => 'Asignado a',
  'categoria' => 'Categoría',
  'subcategoria' => 'Subcategoría',
  'servis_tag' => 'S/N Service Tag',
  'ubicacion' => 'Ubicación',
  'estado' => 'Estado',
  'fecha_ingreso' => 'Fecha de Ingreso',
  'fecha_egreso' => 'Fecha de Egreso',
  'fecha_garantia' => 'Fecha Garantía',
  'periodo_garantia' => 'Período de Garantía (meses)',
  'cantidad' => 'Cantidad',
  'valor_usd' => 'Valor USD',
  'patchcord_tipo' => 'Tipo de Patchcord',
  'patchcord_longitud' => 'Longitud Patchcord',
  'switch_gestion' => 'Switch Gestionado',
  'antenas_tipo' => 'Tipo de Antenas',
  'bobina_categoria' => 'Categoría de Bobina',
  'bobina_ubicacion' => 'Ubicación Bobina',
  'bobina_longitud' => 'Longitud de Bobina',
  'conectores_tipo' => 'Tipo de Conectores',
  'detalle_conectores' => 'Detalle de Conectores',
  'cintas_generacion' => 'Generación Cintas',
  'detalle_repuesto' => 'Detalle Repuesto',
  'detalle_otros' => 'Otros Detalles',
  'subsoftware' => 'Subsoftware',
  'subsoftware_otros' => 'Otros Subsoftwares',
];

foreach ($labels as $campo => $etiqueta) {
  if (!empty($producto[$campo]) && $producto[$campo] !== '0000-00-00') {
    $valor = $campo === 'valor_usd' ? '$' . number_format($producto[$campo], 2) : htmlspecialchars($producto[$campo]);
    echo "<tr><th>{$etiqueta}</th><td>{$valor}</td></tr>";
  }
}
?>

  </table>

  <div class="text-center mb-3">
    <?php if (!empty($producto['carpeta_raiz_drive'])): ?>
      <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($producto['carpeta_raiz_drive']); ?>" target="_blank" class="btn btn-success">
        <i class="bi bi-folder2-open"></i> Ver Carpeta en Drive
      </a>
    <?php else: ?>
      <p class="drive-msg text-danger fw-semibold"><i class="bi bi-x-circle"></i> No hay carpeta asociada en Google Drive.</p>
    <?php endif; ?>
  </div>

  <div class="text-center">
    <a href="../index.php" class="btn btn-primary"><i class="bi bi-arrow-left-circle"></i> Volver al Inventario</a>
  </div>
</div>
<footer>© 2025 ITH - Todos los derechos reservados</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
