<?php
/**
 * backend/db.php — Conexión al tenant activo (multiempresa)
 * Expone $conn (mysqli) y tn() para todas las páginas del inventario.
 * Logger opcional: si no existe logs/logger.php no rompe.
 */

declare(strict_types=1);

// ---------- Rutas robustas ----------
defined('BACKEND_PATH') || define('BACKEND_PATH', __DIR__);
defined('ROOT_PATH')    || define('ROOT_PATH', dirname(BACKEND_PATH));

// ---------- Logger opcional ----------
$loggerPath = ROOT_PATH . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') {}
    }
}

// ---------- Detección de script actual ----------
$current_file = basename($_SERVER['PHP_SELF'] ?? '');

// ============================================================
// MODO NORMAL: bootstrap multiempresa
// ============================================================
try {
    require_once ROOT_PATH . '/core/tenant_bootstrap.php';
    // tenant_bootstrap expone: $tenantDb (TenantDB) y $conn (mysqli)
    log_event("db.php", "APP", "Conexión tenant activa (multiempresa)", "bootstrap");
} catch (Throwable $e) {
    log_event("db.php", "ERROR", "Fallo al inicializar tenant: " . $e->getMessage(), "bootstrap");
    error_log("Error en bootstrap multiempresa: " . $e->getMessage());
    // Si falla el bootstrap, derivamos a install por si falta preparar entorno
    header('Location: install.php');
    exit();
}

// ============================================================
// Guard: cambio de contraseña obligatorio (solo check de sesión)
// ============================================================
$_excluded_from_force = ['login.php', 'select_empresa.php', 'change_password.php', 'logout.php'];
if (!in_array($current_file, $_excluded_from_force, true) && !empty($_SESSION['force_password_change'])) {
    header('Location: /change_password.php');
    exit();
}

// ============================================================
// Verificación de estructura básica (fuera de install/login/select_empresa)
// ============================================================
if (!in_array($current_file, ['login.php', 'select_empresa.php'], true)) {
    try {
        if (!$conn || !$conn->ping()) {
            log_event("db.php", "ERROR", "Ping a la DB del tenant fallido", "bootstrap");
            header('Location: install.php');
            exit();
        }

        // En modo prefix la tabla real es t{N}_users, por eso usamos tn('users')
        $usersTable = tn('users');
        $safeTable  = $conn->real_escape_string($usersTable);
        $check      = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

        if (!$check) {
            log_event("db.php", "ERROR", "Error al verificar tablas: " . $conn->error, "bootstrap");
            throw new Exception("Error al verificar tablas: " . $conn->error);
        }
        if ($check->num_rows === 0) {
            log_event("db.php", "ERROR", "Tabla `{$usersTable}` no encontrada en tenant", "bootstrap");
            header('Location: install.php');
            exit();
        }
        log_event("db.php", "APP", "Tabla `{$usersTable}` OK en tenant", "bootstrap");

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn->set_charset("utf8mb4");
        log_event("db.php", "APP", "Charset utf8mb4 aplicado (tenant)", "bootstrap");

        // Migraciones silenciosas idempotentes
        try {
            @$conn->query("ALTER TABLE `{$usersTable}` MODIFY COLUMN `role` ENUM('admin','manager','user','viewer') NOT NULL DEFAULT 'user'");
            @$conn->query("ALTER TABLE `{$usersTable}` ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1");
            @$conn->query("ALTER TABLE `{$usersTable}` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $eAlter) {
            // Silencioso
        }

    } catch (Throwable $e) {
        log_event("db.php", "ERROR", "Verificación fallida: " . $e->getMessage(), "bootstrap");
        error_log("Error verificación DB (tenant): " . $e->getMessage());
        header('Location: install.php');
        exit();
    }
}
