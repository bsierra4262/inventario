<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Dependencias
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

// Config PHP debug (podés bajar esto en prod)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Usuario actual (para logs / producto_movimientos)
$user_id = $_SESSION['user_id'] ?? 'usuario_desconocido';

// Helper seguro para imprimir valores en HTML
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Si es GET mostramos la pantalla de confirmación
// Si es POST procesamos el archivado
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Archivar Activo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body {
      font-family: 'Saira', sans-serif;
      background-color: #F5F5F5;
      color: #333;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    header {
      background-color: #663399;
      color: #fff;
      padding: 1rem 2rem;
    }
    .container {
      flex: 1;
      padding: 3rem 1rem;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .card {
      background: #fff;
      border-radius: 10px;
      padding: 2.5rem;
      max-width: 700px;
      width: 100%;
      box-shadow: 0 0 15px rgba(0,0,0,0.1);
    }
    .footer {
      text-align: center;
      padding: 1rem;
      background-color: #663399;
      color: #fff;
    }
    .btn-main {
      background-color: #86B329;
      color: white;
      border: none;
    }
    .btn-main:hover {
      background-color: #6FA027;
    }
    .btn-cancel {
      background-color: #6c757d;
      color: white;
    }
    .btn-cancel:hover {
      background-color: #5a6268;
    }
  </style>
</head>
<body>
<header>
  <h1><i class="bi bi-archive"></i> Archivar Activo</h1>
</header>

<div class="container">
  <div class="card">
<?php
// ======================================================
// FLUJO GET (mostrar confirmación)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && !isset($_GET['confirm'])) {

    // Sanitizamos el ID recibido
    $producto_id = intval($_GET['id']);

    // Generar token CSRF específico de archivado
    // Scope aislado: 'archivar_activo'
    $csrf_token = generateCsrfToken('archivar_activo');

    log_event(
        $user_id,
        "CONFIRMACION",
        "Confirmación solicitada para archivo de activo ID $producto_id",
        "archivo"
    );
    ?>
    <h2 class="text-warning">
      <i class="bi bi-exclamation-triangle"></i>
      ¿Por qué vas a archivar este Activo?
    </h2>

    <form method="post" action="delete.php">
      <!-- Token CSRF -->
      <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
      <!-- ID del producto -->
      <input type="hidden" name="id" value="<?= e((string)$producto_id) ?>">

      <div class="mb-3">
        <label class="form-label">Motivo:</label>
        <select name="motivo" class="form-select" required>
          <option value="" selected disabled>Seleccionar motivo</option>
          <option>Venta</option>
          <option>Donación</option>
          <option>Scrapping</option>
          <option>Desguace</option>
          <option>Perdida</option>
          <option>Robo</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Fecha de Baja <span class="text-muted">(opcional)</span></label>
        <input type="date" name="fecha_baja" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">Descripción adicional (opcional):</label>
        <textarea
          name="detalle"
          class="form-control"
          rows="3"
          placeholder="Ej: Activo vendido al área de contabilidad..."
        ></textarea>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-main">
          <i class="bi bi-check-circle"></i> Confirmar archivado
        </button>
        <a href="../index.php" class="btn btn-cancel">
          <i class="bi bi-x-circle"></i> Cancelar
        </a>
      </div>
    </form>
    <?php
}
// ======================================================
// FLUJO POST (confirmado archivado)
// ======================================================
elseif (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['id'], $_POST['motivo']) &&
    filter_var($_POST['id'], FILTER_VALIDATE_INT)
) {
    // 1. Validar CSRF del scope 'archivar_activo'
    csrf_or_fail('archivar_activo', true); // true = usa y rota, no reutilizable

    // 2. Sanitizar entradas de usuario
    $producto_id = intval($_POST['id']);
    $motivo  = trim((string)($_POST['motivo'] ?? ''));
    $detalle = trim((string)($_POST['detalle'] ?? ''));
    $fecha_baja = trim((string)($_POST['fecha_baja'] ?? ''));
    if ($fecha_baja && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_baja)) {
        $fecha_baja = date('Y-m-d');
    }
    if (!$fecha_baja) {
        $fecha_baja = date('Y-m-d');
    }

    $motivo_clean  = htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8');
    $detalle_clean = htmlspecialchars($detalle, ENT_QUOTES, 'UTF-8');

    // Iniciar transacción explícita
    $conn->begin_transaction();

    try {
        // Marca el producto como archivado y guarda fecha de baja
        $stmt_update = $conn->prepare("
            UPDATE productos
            SET archivado = 1, fecha_baja = ?
            WHERE id = ?
        ");
        $stmt_update->bind_param('si', $fecha_baja, $producto_id);
        $stmt_update->execute();
        $stmt_update->close();

        // Obtenemos el producto completo para loguear snapshot en producto_movimientos
        $stmt_prod = $conn->prepare("SELECT * FROM productos WHERE id = ?");
        $stmt_prod->bind_param("i", $producto_id);
        $stmt_prod->execute();
        $result_prod = $stmt_prod->get_result();
        $producto = $result_prod->fetch_assoc();
        $stmt_prod->close();

        if (!$producto) {
            throw new Exception("No se encontró el activo con ID $producto_id.");
        }

        // Armamos descripción para guardar en producto_movimientos.descripcion
        // NOTA IMPORTANTE:
        // Según tu decisión de negocio:
        // - `accion` debe ser "Archivado"
        // - `descripcion` debe guardar motivo + detalle
        $accion = "Archivado";
        $descripcion_mov = "Motivo: " . $motivo_clean;
        if ($detalle_clean !== '') {
            $descripcion_mov .= " | Detalle: " . $detalle_clean;
        }

        /**
         * Insertamos snapshot completo del activo en producto_movimientos.
         * Esta parte RESPETA tu bind actual y tu estructura actual:
         *  producto_movimientos (
         *    producto_id, accion, descripcion, modelo, marca, servis_tag, cantidad, valor_usd,
         *    estado, fecha_garantia, fecha_ingreso, fecha_egreso, ubicacion, asignado_a,
         *    categoria, subcategoria, detalle_tecnico, patchcord_tipo, patchcord_longitud,
         *    subsoftware, realizado_por
         *  )
         *
         * Muy importante: mantenemos tus tipos en bind_param.
         */
        $stmt_mov = $conn->prepare("
            INSERT INTO producto_movimientos 
            (
                producto_id,
                accion,
                descripcion,
                modelo,
                marca,
                servis_tag,
                cantidad,
                valor_usd,
                estado,
                fecha_garantia,
                fecha_ingreso,
                fecha_egreso,
                ubicacion,
                asignado_a,
                categoria,
                subcategoria,
                detalle_tecnico,
                patchcord_tipo,
                patchcord_longitud,
                subsoftware,
                realizado_por
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        /**
         * Tipos:
         * producto_id           i
         * accion                s
         * descripcion           s
         * modelo                s
         * marca                 s
         * servis_tag            s
         * cantidad              s  <- en tu versión actual lo tratás como 's' aunque sea numérico; no toco eso
         * valor_usd             s
         * estado                s
         * fecha_garantia        s
         * fecha_ingreso         s
         * fecha_egreso          s
         * ubicacion             s
         * asignado_a            s
         * categoria             s
         * subcategoria          s
         * detalle_tecnico       s
         * patchcord_tipo        s
         * patchcord_longitud    s
         * subsoftware           s
         * realizado_por         i
         *
         * NOTA:
         * Si en tu DB 'realizado_por' es VARCHAR, cambialo a 's'.
         */
        $stmt_mov->bind_param(
            'issssssissssssssssssi',
            $producto['id'],
            $accion,
            $descripcion_mov,
            $producto['modelo'],
            $producto['marca'],
            $producto['servis_tag'],
            $producto['cantidad'],
            $producto['valor_usd'],
            $producto['estado'],
            $producto['fecha_garantia'],
            $producto['fecha_ingreso'],
            $producto['fecha_egreso'],
            $producto['ubicacion'],
            $producto['asignado_a'],
            $producto['categoria'],
            $producto['subcategoria'],
            $producto['detalle_tecnico'],
            $producto['patchcord_tipo'],
            $producto['patchcord_longitud'],
            $producto['subsoftware'],
            $user_id // cuidado: si user_id es string en tu sesión y la columna es INT, castealo a int
        );

        $stmt_mov->execute();
        $stmt_mov->close();

        // Commit final si todo ok
        $conn->commit();

        log_event(
            $user_id,
            "ARCHIVO",
            "Activo ID $producto_id archivado con motivo '$motivo_clean'",
            "archivo"
        );

        echo "<h2 class='text-success'><i class='bi bi-check-circle'></i> Activo archivado correctamente</h2>";
        echo "<p>Motivo: <strong>" . e($motivo_clean) . "</strong><br>Fecha de Baja: <strong>" . e($fecha_baja) . "</strong><br>Detalle: " . ($detalle_clean !== '' ? e($detalle_clean) : 'Sin detalle adicional.') . "</p>";
        echo "<a href='../index.php' class='btn btn-main mt-3'><i class='bi bi-arrow-left'></i> Volver al Panel</a>";

    } catch (Exception $e) {
        $conn->rollback();

        log_event(
            $user_id,
            "ERROR",
            "Error al archivar activo ID $producto_id: " . $e->getMessage(),
            "archivo"
        );

        echo "<h2 class='text-danger'><i class='bi bi-x-circle'></i> Error</h2>";
        echo "<p>No se pudo archivar el activo:</p>";
        echo "<pre class='text-danger'>" . e($e->getMessage()) . "</pre>";
        echo "<a href='../index.php' class='btn btn-danger mt-3'><i class='bi bi-arrow-left'></i> Volver al Panel</a>";
    } finally {
        $conn->close();
    }
}
// ======================================================
// FLUJO REQUEST INVÁLIDO
// ======================================================
else {
    echo "<h2 class='text-danger'><i class='bi bi-x-circle'></i> Error</h2>";
    echo "<p>Parámetros inválidos.</p>";
    echo "<a href='../index.php' class='btn btn-danger mt-3'><i class='bi bi-arrow-left'></i> Volver al Panel</a>";
}
?>
  </div>
</div>

<footer class="footer">© 2025 ITH - Todos los derechos reservados</footer>
</body>
</html>
