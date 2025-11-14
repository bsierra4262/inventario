<?php
/**
 * authenticate.php - Versión multiempresa
 * ---------------------------------------
 * - Autenticación local (usuario + contraseña)
 * - Compatible con TenantDB / backend/db.php
 * - Logger opcional (logs/logger.php)
 * - Usa sesión segura y token CSRF
 */

declare(strict_types=1);

// -----------------------------
// Iniciar sesión
// -----------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// -----------------------------
// Incluir dependencias
// -----------------------------
require_once __DIR__ . '/backend/db.php'; // conexión del tenant activo
$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'auth') { /* silencioso */ }
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (Throwable $e) { /* ya seteado */ }

// -----------------------------
// Lógica de autenticación
// -----------------------------
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $companyId = $_SESSION['company_id'] ?? 'sin_empresa';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

        // Validaciones básicas
        if ($username === '' || $password === '') {
            log_event("Usuario: $username", "LOGIN FALLIDO", "Campos vacíos (empresa $companyId)", "login");
            throw new Exception("Por favor completá todos los campos.");
        }

        // Consulta preparada
        $stmt = $conn->prepare("SELECT id, password, role, email FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            // Login OK
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Auditoría
            log_event(
                $user['email'] ?? "Usuario: $username",
                "LOGIN LOCAL",
                "Inicio exitoso desde IP: $ip (empresa $companyId)",
                "auth"
            );

            // Redirigir al dashboard principal
            header('Location: index.php');
            exit;
        }

        // Fallo de autenticación
        log_event("Usuario: $username", "LOGIN FALLIDO", "Credenciales inválidas desde IP: $ip (empresa $companyId)", "auth");
        throw new Exception("Credenciales inválidas. Verificá tu usuario y contraseña.");
    }

} catch (Throwable $e) {
    // Error controlado → alerta + redirect
    $msg = addslashes($e->getMessage());
    echo "<script>alert('$msg'); window.location.href='login.php';</script>";
} finally {
    // Liberar memoria y cerrar recursos
    if (isset($result) && $result instanceof mysqli_result) {
        $result->free();
    }
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
