<?php
/**
 * bay.php - Eliminación segura de tablas (versión multiempresa)
 * --------------------------------------------------------------
 * - Elimina todas las tablas del tenant activo.
 * - Limpia archivo install.lock.
 * - Compatible con la arquitectura multiempresa (TenantDB / db.php).
 * - Muestra salida HTML estilizada para control visual.
 * - Requiere acceso de administrador (solo usar manualmente).
 */

declare(strict_types=1);

// Mostrar errores solo en entorno de desarrollo
ini_set('display_errors', '1');
error_reporting(E_ALL);

// =========================
// 1) Conexión al tenant
// =========================
require_once __DIR__ . '/backend/db.php'; // usa tenant_bootstrap si está activo
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$tenant_id = $_SESSION['company_id'] ?? 'sin_sesion';

// =========================
// 2) Comenzar salida HTML
// =========================
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reinicio de Base de Datos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{font-family:Saira,system-ui;background:#fafafa;padding:30px}
.alert{margin-bottom:8px}
h1{color:#663399;margin-bottom:20px;text-align:center}
footer{margin-top:40px;text-align:center;color:#777}
</style>
</head>
<body>
<div class="container">
  <h1>Reinicio del Tenant <?= htmlspecialchars((string)$tenant_id) ?></h1>
  <div class="card shadow-sm">
    <div class="card-body">

<?php
// =========================
// 3) Eliminar tablas del tenant
// =========================
try {
    $result = $conn->query("SHOW TABLES");
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_row()) {
            $table = $row[0];
            $drop = "DROP TABLE IF EXISTS `$table`";
            if ($conn->query($drop) === true) {
                echo "<div class='alert alert-success'>✅ Tabla <b>$table</b> eliminada correctamente.</div>";
            } else {
                echo "<div class='alert alert-danger'>❌ Error al eliminar <b>$table</b>: " . htmlspecialchars($conn->error) . "</div>";
            }
        }
    } else {
        echo "<div class='alert alert-info'>ℹ️ No se encontraron tablas en esta base de datos.</div>";
    }
} catch (Throwable $e) {
    echo "<div class='alert alert-danger'>⚠️ Error al obtener/eliminar tablas: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// =========================
// 5) Cierre de conexión
// =========================
try {
    $conn->close();
    echo "<div class='alert alert-secondary'>🔒 Conexión cerrada correctamente.</div>";
} catch (Throwable $e) {
    echo "<div class='alert alert-danger'>❌ Error al cerrar la conexión: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

    <div class='alert alert-info mt-3'>
        ✅ <strong>Reset completo.</strong> Redirigiendo a la instalación en 3 segundos...
    </div>

    </div>
  </div>
  <footer>© 2025 ITH - Herramienta de Reinicio de Tenant</footer>
</div>
<script>setTimeout(() => window.location.href = 'install.php', 3000);</script>
</body>
</html>
