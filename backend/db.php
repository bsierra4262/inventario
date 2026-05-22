<?php
/**
 * backend/db.php (compat multiempresa + install)
 * - Si el script actual es install.php => usa conexión legacy directa (sin bootstrap).
 * - Para todo lo demás => usa tenant_bootstrap.php y expone $conn (mysqli) como siempre.
 * - Logger opcional: si no existe logs/logger.php no rompe.
 */

declare(strict_types=1);

// ---------- Rutas robustas ----------
defined('BACKEND_PATH') || define('BACKEND_PATH', __DIR__);
defined('ROOT_PATH')    || define('ROOT_PATH', dirname(BACKEND_PATH));

// ---------- Logger opcional ----------
$loggerPath = ROOT_PATH . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath; // define log_event($origin,$type,$msg,$tag)
} else {
    // Fallback para no romper si falta el logger
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') {
            // Silencioso; si querés, podés loguear a error_log:
            // error_log("[$origin][$type][$tag] $msg");
        }
    }
}

// ---------- Detección de script actual ----------
$current_file = basename($_SERVER['PHP_SELF'] ?? '');

// ============================================================
// MODO INSTALL: conexión legacy al servidor (sin bootstrap)
// ============================================================
if ($current_file === 'install.php') {
    // Credenciales legacy (como tu versión original)
    $servername = "localhost";
    $username   = "u566451014_desarrollo";
    $password   = "***SECRETO-PURGADO***";
    $dbname     = "u566451014_desarrollo";

    try {
        $conn = @new mysqli($servername, $username, $password);
        if ($conn->connect_error) {
            log_event("db.php", "ERROR", "Conexión fallida: " . $conn->connect_error, "instalacion");
            throw new Exception("Conexión fallida: " . $conn->connect_error);
        }
        log_event("db.php", "INSTALACION", "Conexión establecida con el servidor de base de datos (LEGACY)", "instalacion");

        // No selecciono DB aquí; el propio install.php decidirá si crearla/seleccionarla.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn->set_charset("utf8mb4");
        log_event("db.php", "INSTALACION", "Charset utf8mb4 aplicado (LEGACY)", "instalacion");

    } catch (Throwable $e) {
        log_event("db.php", "ERROR", "Error en modo install: " . $e->getMessage(), "instalacion");
        error_log("Error en db.php (install): " . $e->getMessage());
        die("Error al conectar con la base de datos (install). Revisá credenciales o logs.");
    }

    // IMPORTANTE: en install.php no hacemos más verificaciones ni redirecciones.
    return;
}

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

    } catch (Throwable $e) {
        log_event("db.php", "ERROR", "Verificación fallida: " . $e->getMessage(), "bootstrap");
        error_log("Error verificación DB (tenant): " . $e->getMessage());
        header('Location: install.php');
        exit();
    }
}
