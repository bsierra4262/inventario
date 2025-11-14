<?php
// Iniciar sesión si no está ya iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/backend/db.php';  // Usar ruta relativa para incluir la configuración de la base de datos
require_once __DIR__ . '/logs/logger.php';  // Incluir el logger para auditoría

// Manejo de la lógica de registro cuando el formulario se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = htmlspecialchars(trim($_POST['username']));
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verificar si las contraseñas coinciden
    if ($password !== $confirm_password) {
        log_event("Usuario: $username", "REGISTRO FALLIDO", "Contraseñas no coinciden");
        echo '<script>alert("Las contraseñas no coinciden."); window.location.href = "register.php";</script>';
        exit();
    }

    // Verificar si el nombre de usuario ya está en uso
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        log_event("Usuario: $username", "REGISTRO FALLIDO", "Nombre de usuario ya en uso");
        echo '<script>alert("El nombre de usuario ya está en uso."); window.location.href = "register.php";</script>';
        exit();
    }

    // Validar fortaleza de la contraseña (mínimo 8 caracteres, una letra y un número)
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        log_event("Usuario: $username", "REGISTRO FALLIDO", "Contraseña no cumple requisitos");
        echo '<script>alert("La contraseña debe tener al menos 8 caracteres, incluyendo letras y números."); window.location.href = "register.php";</script>';
        exit();
    }

    // Hashear la contraseña
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insertar el nuevo usuario en la base de datos
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
    $stmt->bind_param('ss', $username, $hashed_password);

    if ($stmt->execute()) {
        log_event("Usuario: $username", "REGISTRO EXITOSO", "Nuevo usuario registrado desde IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'));
        echo '<script>alert("Registro exitoso. Ahora puedes iniciar sesión."); window.location.href = "login.php";</script>';
        exit();
    } else {
        log_event("Usuario: $username", "REGISTRO FALLIDO", "Error en base de datos: " . $stmt->error);
        echo '<script>alert("Error en el registro. Por favor, intenta nuevamente."); window.location.href = "register.php";</script>';
        exit();
    }

    // Cerrar la conexión
    $stmt->close();
    $conn->close();
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuario - Intellihelp</title>
    <!-- Importar la fuente Saira -->
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Saira', sans-serif;
            background-color: #f5f5f5;
            animation: fadeIn 1s ease-in-out;
        }

        .container {
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 1s ease-out forwards;
        }

        .form-control, .btn {
            border-radius: 30px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-control:focus {
            transform: translateY(-4px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background-color: #9cc930;
            border-color: #9cc930;
        }

        .btn-primary:hover {
            background-color: #86b329;
            border-color: #86b329;
            transform: translateY(-4px);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        footer {
            text-align: center;
            padding: 20px 0;
            width: 100%;
            background-color: #663399;
            color: #ffffff;
            position: fixed;
            bottom: 0;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <h2 class="text-center mb-4">Registro de Usuario</h2>
                <form action="register.php" method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">Usuario</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirmar Contraseña</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Registrarse</button>
                </form>
            </div>
        </div>
    </div>

    <footer>
        © 2025 ITH - Todos los derechos reservados
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
