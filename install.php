<?php
/**
 * install.php — Instalador del master DB (plataforma SaaS)
 *
 * Solo crea la estructura del master DB y el superadmin inicial.
 * Las tablas del tenant (productos, users, etc.) se crean cuando el SuperAdmin
 * registra una empresa desde el dashboard → dashboard_empresas.php.
 *
 * Si ya está instalado (users_platform tiene filas) → redirige al login de SuperAdmin.
 */

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/core/config/platform.php';
require_once __DIR__ . '/core/db/master_connection.php';

// Logger opcional
$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($o, $t, $m, $g = 'instalacion') {}
    }
}

// ─── Verificar si ya está instalado ──────────────────────────────────────────
try {
    $masterCheck = new MasterDB();
    $chkTable = $masterCheck->conn->query("SHOW TABLES LIKE 'users_platform'");
    if ($chkTable && $chkTable->num_rows > 0) {
        $cnt = (int)($masterCheck->conn->query("SELECT COUNT(*) c FROM users_platform")->fetch_assoc()['c'] ?? 0);
        if ($cnt > 0) {
            log_event("install.php", "BLOQUEADO", "Sistema ya instalado ($cnt superadmin/s). Redirigiendo.", "instalacion");
            header('Location: /auth/superadmin_login.php');
            exit;
        }
    }
} catch (Throwable $eCheck) {
    // Master DB no accesible aún → continuar con la instalación
    $masterCheck = null;
}

// ─── HTML ─────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#663399">
  <title>Instalación · Asset Vault · INTELLIHELP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #f4f1f9 0%, #fff 100%); min-height: 100vh; padding: 40px 16px; }
    .install-wrap { max-width: 680px; margin: 0 auto; }
    .install-card { background: #fff; border-radius: 20px; box-shadow: 0 20px 60px rgba(102,51,153,.15); overflow: hidden; }
    .install-card__header { background: linear-gradient(135deg, #663399, #4E247A); color: #fff; padding: 28px 32px; }
    .install-card__brand { display: flex; align-items: center; gap: 14px; }
    .install-card__logo { width: 52px; height: 52px; background: #fff; color: #663399; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 24px; position: relative; flex-shrink:0 }
    .install-card__logo::after { display: none; }
    .install-card__title { margin: 0; font-size: 22px; font-weight: 700; }
    .install-card__subtitle { font-size: 13px; opacity: .75; margin-top: 2px; }
    .install-card__body { padding: 28px 32px 32px; }
    .install-foot { text-align: center; margin-top: 20px; font-size: 12px; color: #888; }
    .step { display:flex; align-items:flex-start; gap:10px; padding:9px 0; border-bottom:1px solid #f0ebf8; font-size:14px; }
    .step:last-child { border-bottom: none; }
    .step-icon { font-size:18px; flex-shrink:0; margin-top:1px }
    .step-ok   { color: #16A34A; }
    .step-err  { color: #DC2626; }
    .step-info { color: #663399; }
  </style>
</head>
<body>
<div class="install-wrap">
  <div class="install-card ih-anim-fade">
    <div class="install-card__header">
      <div class="install-card__brand">
        <div class="install-card__logo"><?= platform_logo_html() ?></div>
        <div>
          <h1 class="install-card__title">Asset Vault — Instalación</h1>
          <div class="install-card__subtitle">INTELLIHELP · Configuración inicial del master DB</div>
        </div>
      </div>
    </div>
    <div class="install-card__body">
<?php

// ─── Ejecutar instalación ─────────────────────────────────────────────────────
$hasError = false;

function installStep(string $icon, string $cssClass, string $msg): void {
    echo '<div class="step"><span class="step-icon ' . $cssClass . '"><i class="bi bi-' . $icon . '"></i></span><span>' . $msg . '</span></div>';
    flush();
}

try {
    $master = $masterCheck ?? new MasterDB();

    installStep('check-circle-fill', 'step-ok', 'Conexión al master DB establecida (<code>' . htmlspecialchars(MASTER_DB_NAME) . '</code>).');
    log_event("install.php", "INSTALACION", "Conexión master DB OK", "instalacion");

    // ── Tabla companies ──────────────────────────────────────────────────────
    $master->conn->query("CREATE TABLE IF NOT EXISTS `companies` (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        nombre           VARCHAR(200) NOT NULL,
        razon_social     VARCHAR(200) DEFAULT '',
        cuit             VARCHAR(30)  DEFAULT '',
        provider         ENUM('google','microsoft') NOT NULL DEFAULT 'google',
        credentials_path VARCHAR(500) DEFAULT NULL,
        tenant_mode      ENUM('prefix','external') NOT NULL DEFAULT 'external',
        db_host          VARCHAR(200) DEFAULT 'localhost',
        db_name          VARCHAR(200) DEFAULT NULL,
        db_user          VARCHAR(200) DEFAULT NULL,
        db_pass          VARCHAR(255) DEFAULT NULL,
        subdomain        VARCHAR(100) DEFAULT NULL,
        login_url        VARCHAR(500) DEFAULT NULL,
        active           TINYINT(1)  NOT NULL DEFAULT 1,
        created_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    installStep('check-circle-fill', 'step-ok', 'Tabla <code>companies</code> lista.');
    log_event("install.php", "INSTALACION", "Tabla companies OK", "instalacion");

    // ── Tabla users_platform ─────────────────────────────────────────────────
    $master->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(100) NOT NULL,
        password    VARCHAR(255) DEFAULT NULL,
        email       VARCHAR(255) DEFAULT NULL,
        google_sub  VARCHAR(64)  DEFAULT NULL,
        google_pic  VARCHAR(500) DEFAULT NULL,
        ms_sub      VARCHAR(64)  DEFAULT NULL,
        ms_pic      VARCHAR(500) DEFAULT NULL,
        role        ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
        active      TINYINT(1)  NOT NULL DEFAULT 1,
        last_login  DATETIME    DEFAULT NULL,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    installStep('check-circle-fill', 'step-ok', 'Tabla <code>users_platform</code> lista.');
    log_event("install.php", "INSTALACION", "Tabla users_platform OK", "instalacion");

    // ── Tabla platform_config ────────────────────────────────────────────────
    $master->conn->query("CREATE TABLE IF NOT EXISTS `platform_config` (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        config_key   VARCHAR(100) NOT NULL UNIQUE,
        config_value TEXT NOT NULL,
        updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    installStep('check-circle-fill', 'step-ok', 'Tabla <code>platform_config</code> lista.');
    log_event("install.php", "INSTALACION", "Tabla platform_config OK", "instalacion");

    // ── Seed superadmin ──────────────────────────────────────────────────────
    $cntSA = (int)($master->conn->query("SELECT COUNT(*) c FROM users_platform")->fetch_assoc()['c'] ?? 0);
    if ($cntSA === 0) {
        $saUser  = 'bsierra';
        $saEmail = 'bsierra@intellihelp.tech';
        $saPass  = '***SECRETO-PURGADO***';
        $saHash  = password_hash($saPass, PASSWORD_DEFAULT);
        $saStmt  = $master->conn->prepare(
            "INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, 'superadmin')"
        );
        $saStmt->bind_param('sss', $saUser, $saHash, $saEmail);
        $saStmt->execute();
        $saStmt->close();
        installStep('person-check-fill', 'step-ok', 'SuperAdmin <strong>bsierra</strong> creado (contraseña inicial: <code>***SECRETO-PURGADO***</code>).');
        log_event("install.php", "INSTALACION", "SuperAdmin bsierra creado.", "instalacion");
    } else {
        installStep('person-fill', 'step-info', "SuperAdmin ya existente ($cntSA usuario/s) — no se sobreescribió.");
    }

    log_event("install.php", "INSTALACION", "Instalación completada correctamente.", "instalacion");

} catch (Throwable $e) {
    $hasError = true;
    installStep('x-circle-fill', 'step-err', 'Error: ' . htmlspecialchars($e->getMessage()));
    log_event("install.php", "ERROR", $e->getMessage(), "instalacion");
}
?>

      <?php if (!$hasError): ?>
        <div style="margin-top:20px" class="ih-alert ih-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div>
            <strong>Instalación completada.</strong><br>
            Ingresá al panel SuperAdmin, creá tu primera empresa y vinculá la base de datos del tenant.
          </div>
        </div>
        <div class="ih-flex ih-gap-2" style="margin-top:16px">
          <a href="/auth/superadmin_login.php" class="ih-btn ih-btn--primary ih-btn--lg">
            <i class="bi bi-shield-lock"></i> Ir al panel SuperAdmin
          </a>
        </div>
      <?php else: ?>
        <div style="margin-top:20px" class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>Hubo un error durante la instalación. Revisá las credenciales del master DB en <code>core/config/platform.php</code>.</div>
        </div>
        <a href="/install.php" class="ih-btn ih-btn--outline" style="margin-top:12px">
          <i class="bi bi-arrow-clockwise"></i> Reintentar
        </a>
      <?php endif; ?>

    </div>
  </div>
  <div class="install-foot">© <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault</div>
</div>
</body>
</html>
