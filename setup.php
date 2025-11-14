<?php
declare(strict_types=1);

// ==============================
// SETUP - CONFIGURACIÓN INICIAL
// ==============================

// Mostrar errores solo durante instalación
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/core/config/platform.php';
require_once __DIR__ . '/core/db/master_connection.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? '');
    $username = trim($_POST['db_user'] ?? '');
    $password = $_POST['db_pass'] ?? '';
    $dbname = trim($_POST['db_name'] ?? '');
    $empresa_nombre = trim($_POST['company_name'] ?? '');
    $modo = 'external'; // Modo default para tu entorno actual

    if (!$host || !$username || !$dbname || !$empresa_nombre) {
        $error = "Todos los campos son obligatorios (excepto la contraseña).";
    } else {
        try {
            // Conexión directa para validar credenciales
            $conn = @new mysqli($host, $username, $password);
            if ($conn->connect_error) {
                throw new Exception("Error de conexión: " . $conn->connect_error);
            }

            // Crear base si no existe
            if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                throw new Exception("Error al crear la base: " . $conn->error);
            }

            log_event("setup.php", "INSTALACION", "Base de datos `$dbname` creada o ya existente.", "instalacion");
            $conn->close();

            // Insertar empresa en MASTER
            $master = new MasterDB();

            // Verificar si ya existe la empresa
            $stmt = $master->pquery("SELECT id FROM companies WHERE nombre = ?", 's', [$empresa_nombre]);
            $exists = $stmt->get_result()->fetch_assoc();

            if ($exists) {
                $error = "Ya existe una empresa registrada con ese nombre.";
            } else {
                $sql = "INSERT INTO companies (nombre, tenant_mode, db_host, db_name, db_user, db_pass)
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $master->conn->prepare($sql);
                $stmt->bind_param('ssssss', $empresa_nombre, $modo, $host, $dbname, $username, $password);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $success = "✅ Empresa registrada correctamente en la base master.";
                    log_event("setup.php", "INSTALACION", "Empresa `$empresa_nombre` registrada (modo $modo)", "instalacion");
                    header('Refresh: 3; URL=install.php');
                } else {
                    throw new Exception("No se pudo registrar la empresa en la base master.");
                }
            }
        } catch (Throwable $e) {
            $error = htmlspecialchars($e->getMessage());
            log_event("setup.php", "ERROR", "Error durante setup: ".$e->getMessage(), "instalacion");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de la Plataforma - Asset Vault</title>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{
            display:flex;flex-direction:column;justify-content:center;align-items:center;
            min-height:100vh;font-family:'Saira',sans-serif;background-color:#f5f5f5;animation:fadeIn 1s ease-in-out
        }
        .container{opacity:0;transform:translateY(30px);animation:fadeInUp 1s ease-out forwards}
        .form-control,.btn{border-radius:30px;transition:transform .3s ease,box-shadow .3s ease}
        .form-control:focus{transform:translateY(-4px);box-shadow:0 4px 8px rgba(0,0,0,0.1)}
        .btn-primary{background-color:#9cc930;border-color:#9cc930}
        .btn-primary:hover{background-color:#86b329;border-color:#86b329;transform:translateY(-4px)}
        footer{text-align:center;padding:20px 0;width:100%;background-color:#663399;color:#fff;position:fixed;bottom:0}
        @keyframes fadeInUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <h2 class="text-center mb-4">Configuración Inicial</h2>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success"><?= $success ?> Redirigiendo a <b>install.php</b>...</div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <div class="mb-3">
                        <label for="company_name" class="form-label">Nombre de la Empresa</label>
                        <input type="text" class="form-control" id="company_name" name="company_name" required placeholder="Ej: Intellihelp S.A.">
                    </div>
                    <div class="mb-3">
                        <label for="db_host" class="form-label">Host de Base de Datos</label>
                        <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                    </div>
                    <div class="mb-3">
                        <label for="db_user" class="form-label">Usuario de Base de Datos</label>
                        <input type="text" class="form-control" id="db_user" name="db_user" required>
                    </div>
                    <div class="mb-3">
                        <label for="db_pass" class="form-label">Contraseña de Base de Datos</label>
                        <input type="password" class="form-control" id="db_pass" name="db_pass">
                    </div>
                    <div class="mb-3">
                        <label for="db_name" class="form-label">Nombre de Base de Datos</label>
                        <input type="text" class="form-control" id="db_name" name="db_name" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Registrar Empresa y Continuar</button>
                </form>
            </div>
        </div>
    </div>
    <footer>© 2025 ITH - Todos los derechos reservados</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
