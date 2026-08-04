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
} catch (Throwable $e) {
    log_event("db.php", "ERROR", "Fallo al inicializar tenant: " . $e->getMessage(), "bootstrap");
    error_log("Error en bootstrap multiempresa: " . $e->getMessage());
    // Si falla el bootstrap, derivamos a install por si falta preparar entorno
    header('Location: /install.php');
    exit();
}

// ============================================================
// Guard: cambio de contraseña obligatorio (solo check de sesión)
// ============================================================
$_excluded_from_force = ['login.php', 'select_empresa.php', 'change_password.php', 'logout.php'];
if (!in_array($current_file, $_excluded_from_force, true) && !empty($_SESSION['force_password_change'])) {
    header('Location: /auth/change_password.php');
    exit();
}

// ============================================================
// Verificación de esquema
//
// Antes acá corría DDL en CADA request: SHOW TABLES, dos ALTER TABLE ...
// ADD COLUMN IF NOT EXISTS, un SHOW COLUMNS y (hasta el commit baf64d82) un
// ALTER que revertía `role` a ENUM. Eran 5+ consultas de metadata antes de la
// primera consulta útil de cada página, y una de ellas rompía los roles
// personalizados.
//
// Ahora el esquema se versiona (core/db/migraciones.php) y se aplica una sola
// vez con `php bin/migrar.php`. Acá sólo se lee la versión: una consulta por
// clave primaria contra tenant_settings.
// ============================================================
if (!in_array($current_file, ['login.php', 'select_empresa.php'], true)) {
    try {
        if (!$conn || !$conn->ping()) {
            log_event("db.php", "ERROR", "Ping a la DB del tenant fallido", "bootstrap");
            header('Location: /install.php');
            exit();
        }

        $conn->set_charset('utf8mb4');

        require_once ROOT_PATH . '/core/db/migraciones.php';
        $_prefijo   = $tenantDb->mode === 'prefix' ? $tenantDb->prefix : '';
        $_versionOk = schema_version_actual($conn, $_prefijo);

        if ($_versionOk === 0) {
            // Tenant sin estructura (o sin migrar nunca) → mandar a instalar.
            $usersTable = tn('users');
            $safeTable  = $conn->real_escape_string($usersTable);
            $check      = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
            if (!$check || $check->num_rows === 0) {
                log_event("db.php", "ERROR", "Tabla `{$usersTable}` no encontrada en tenant", "bootstrap");
                header('Location: /install.php');
                exit();
            }
        }

        // Marca para que los helpers no repitan CREATE TABLE IF NOT EXISTS.
        if ($_versionOk >= schema_version_objetivo()) {
            defined('APP_SCHEMA_OK') || define('APP_SCHEMA_OK', true);
        } else {
            log_event('db.php', 'WARN',
                "Esquema del tenant en v{$_versionOk}, el código espera v" . schema_version_objetivo()
                . ' — correr: php bin/migrar.php', 'bootstrap');
        }

    } catch (Throwable $e) {
        log_event("db.php", "ERROR", "Verificación fallida: " . $e->getMessage(), "bootstrap");
        error_log("Error verificación DB (tenant): " . $e->getMessage());
        header('Location: /install.php');
        exit();
    }
}
