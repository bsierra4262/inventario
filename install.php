<?php
// ========================
// INSTALLER - ASSET VAULT
// ========================

declare(strict_types=1);

// Mostrar errores en instalación (útil para depurar en hosting)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// -----------------------------------------
// 1) Conexión legacy (db.php en modo install)
// -----------------------------------------
require __DIR__ . '/backend/db.php'; // este db.php detecta install.php y NO usa bootstrap multiempresa

// -----------------------------------------
// 2) Logger opcional (no romper si no existe)
// -----------------------------------------
$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath; // define log_event()
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'instalacion') { /* silencioso */ }
    }
}

// -----------------------------------------
// 3) Bootstrap de la DB master (companies)
//    Se ejecuta SIEMPRE — es idempotente (CREATE IF NOT EXISTS / INSERT IGNORE)
//    Debe ir antes del chequeo "ya instalado" para no quedar en loop si el tenant
//    existía pero la master nunca tuvo la tabla companies.
// -----------------------------------------
$dbname = 'u566451014_desarrollo'; // <- tu DB objetivo

try {
    require_once __DIR__ . '/core/config/platform.php';
    require_once __DIR__ . '/core/db/master_connection.php';
    $master = new MasterDB();

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

    // Add missing columns to existing installations (idempotent)
    foreach ([
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `razon_social`     VARCHAR(200)  DEFAULT ''",
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `cuit`             VARCHAR(30)   DEFAULT ''",
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `provider`         ENUM('google','microsoft') NOT NULL DEFAULT 'google'",
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `credentials_path` VARCHAR(500)  DEFAULT NULL",
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `active`           TINYINT(1)   NOT NULL DEFAULT 1",
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `subdomain`        VARCHAR(100)  DEFAULT NULL",
        "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `login_url`        VARCHAR(500)  DEFAULT NULL",
    ] as $_alter) {
        try { $master->conn->query($_alter); } catch (Throwable $_ae) {}
    }

    // Tabla de usuarios de plataforma (superadmin / soporte)
    $master->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(100) NOT NULL,
        password    VARCHAR(255) NOT NULL,
        email       VARCHAR(255) DEFAULT NULL,
        role        ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
        active      TINYINT(1)  NOT NULL DEFAULT 1,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed superadmin bsierra si la tabla está vacía
    $cntSa = (int)($master->conn->query("SELECT COUNT(*) as c FROM users_platform")->fetch_assoc()['c'] ?? 0);
    if ($cntSa === 0) {
        $saUser  = 'bsierra';
        $saHash  = password_hash('***SECRETO-PURGADO***', PASSWORD_DEFAULT);
        $saEmail = 'bsierra4262@gmail.com';
        $saStmt  = $master->conn->prepare("INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, 'superadmin')");
        $saStmt->bind_param('sss', $saUser, $saHash, $saEmail);
        $saStmt->execute();
        $saStmt->close();
    }

    $chk = $master->conn->prepare("SELECT id FROM companies WHERE nombre = 'ITH' LIMIT 1");
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $ins = $master->conn->prepare(
            "INSERT INTO companies (nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, db_pass, active)
             VALUES ('ITH', 'Informática Tecnológica Horizontal', '', 'google', 'external', 'localhost', 'u566451014_desarrollo', 'u566451014_desarrollo', '***SECRETO-PURGADO***', 1)"
        );
        $ins->execute();
        $ins->close();
    }
    $chk->close();
} catch (Throwable $e) {
    log_event("install.php", "ADVERTENCIA", "No se pudo configurar DB master: ".$e->getMessage(), "instalacion");
}

// -----------------------------------------
// 3b) Verificar si el tenant ya está instalado
//     Si la tabla `users` existe → redirigir a login (la master ya fue bootstrappeada arriba)
// -----------------------------------------
try {
    $testConn = @new mysqli('localhost', 'u566451014_desarrollo', '***SECRETO-PURGADO***', $dbname);
    if (!$testConn->connect_errno) {
        $chkInstalled = $testConn->query("SHOW TABLES LIKE 'users'");
        if ($chkInstalled && $chkInstalled->num_rows > 0) {
            $testConn->close();
            log_event("install.php", "INSTALACION BLOQUEADA", "Sistema ya instalado (tabla users existe).", "instalacion");
            echo "<script>alert('El sistema ya está instalado.'); window.location.href = 'login.php';</script>";
            exit;
        }
        $testConn->close();
    }
} catch (Throwable $e) {
    // No se puede conectar → continuar con la instalación
}

// Crear base si no existe
$sql_create_db = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Instalación · Asset Vault · INTELLIHELP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#663399">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #f4f1f9 0%, #ffffff 100%); min-height: 100vh; padding: 40px 16px; }
    .install-wrap { max-width: 760px; margin: 0 auto; }
    .install-card { background: #fff; border-radius: 20px; box-shadow: 0 20px 60px rgba(102,51,153,.15); overflow: hidden; }
    .install-card__header { background: linear-gradient(135deg, #663399, #4E247A); color: #fff; padding: 28px 32px; }
    .install-card__brand { display: flex; align-items: center; gap: 14px; }
    .install-card__logo { width: 52px; height: 52px; background: #fff; color: #663399; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 24px; position: relative; }
    .install-card__logo::after { content: ''; position: absolute; right: 6px; bottom: 6px; width: 10px; height: 10px; background: #9CC930; border-radius: 50%; }
    .install-card__title { margin: 0; font-size: 22px; font-weight: 700; }
    .install-card__subtitle { font-size: 13px; opacity: .75; margin-top: 2px; }
    .install-card__body { padding: 28px 32px 32px; }
    .install-card__body .alert { margin-bottom: 10px; opacity: 1 !important; animation: ih-fade-in 300ms ease; }
    code { background: #f4f1f9; border: 1px solid #d0bfea; border-radius: 6px; padding: 2px 8px; font-size: 13px; color: #4E247A; }
    .install-foot { text-align: center; margin-top: 24px; font-size: 12px; color: #666; }
    /* Compatibilidad con las alertas que se imprimen desde el bloque PHP de instalación */
    .alert { padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .alert::before { font-family: 'bootstrap-icons'; font-size: 16px; }
    .alert-success { background: #DCFCE7; color: #14532D; border: 1px solid #BBF7D0; }
    .alert-success::before { content: '\F26B'; color: #16A34A; }
    .alert-warning { background: #FFEDD5; color: #7C2D12; border: 1px solid #FED7AA; }
    .alert-warning::before { content: '\F33A'; color: #EA580C; }
    .alert-danger  { background: #FEE2E2; color: #7F1D1D; border: 1px solid #FECACA; }
    .alert-danger::before { content: '\F623'; color: #DC2626; }
  </style>
</head>
<body>
<div class="install-wrap">
  <div class="install-card ih-anim-fade">
    <div class="install-card__header">
      <div class="install-card__brand">
        <div class="install-card__logo">i</div>
        <div>
          <h1 class="install-card__title">Asset Vault — Instalación</h1>
          <div class="install-card__subtitle">INTELLIHELP · Aprovisionamiento del tenant</div>
        </div>
      </div>
    </div>
    <div class="install-card__body">
<?php
// -----------------------------------------------------
// 4) Crear base de datos y seleccionar la base objetivo
// -----------------------------------------------------
try {
    if ($conn->query($sql_create_db) === true) {
        log_event("install.php", "INSTALACION", "Base de datos creada o ya existente.", "instalacion");
        echo '<div class="alert alert-success">✅ Base de datos creada o existente.</div>';
    } else {
        throw new Exception('Error al crear la base: ' . $conn->error);
    }

    if (!$conn->select_db($dbname)) {
        throw new Exception('No se pudo seleccionar la base: ' . $conn->error);
    }
    echo '<div class="alert alert-success">✅ Base de datos seleccionada: <code>' . htmlspecialchars($dbname) . '</code></div>';
    log_event("install.php", "INSTALACION", "Base seleccionada: $dbname", "instalacion");

    // Asegurar charset
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

} catch (Throwable $e) {
    log_event("install.php", "ERROR", $e->getMessage(), "instalacion");
    echo '<div class="alert alert-danger">❌ ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div></div></div>';
    exit;
}

// -----------------------------------------------------
// 5) Borrar trigger + tablas (conservando archivos_productos)
// -----------------------------------------------------
try {
    // Borrar trigger si existe
    @$conn->query("DROP TRIGGER IF EXISTS trg_productos_after_update");

    // Borrar tabla de movimientos primero (evita FKs futuras)
    @$conn->query("DROP TABLE IF EXISTS producto_movimientos");

    // Borrar resto de tablas salvo archivos_productos
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $t = $row[0];
        if ($t !== 'archivos_productos') {
            @$conn->query("DROP TABLE IF EXISTS `$t`");
            log_event("install.php", "INSTALACION", "Tabla $t eliminada.", "instalacion");
            echo "<div class='alert alert-warning'>⚠️ Tabla <code>$t</code> eliminada.</div>";
        }
    }

} catch (Throwable $e) {
    log_event("install.php", "ERROR", "Error limpiando tablas: ".$e->getMessage(), "instalacion");
    echo "<div class='alert alert-danger'>❌ Error limpiando tablas: ".htmlspecialchars($e->getMessage())."</div>";
}

// -----------------------------------------------------
// 6) Crear tablas (estructura actual)
// -----------------------------------------------------
$tables = [

"productos" => "
CREATE TABLE IF NOT EXISTS productos (
    id                  INT(11) AUTO_INCREMENT PRIMARY KEY,
    modelo              VARCHAR(255) NULL,
    marca               VARCHAR(255) NULL,
    descripcion         TEXT NULL,
    detalle_tecnico     VARCHAR(255) NULL,
    asignado_a          VARCHAR(255) NULL,
    categoria           VARCHAR(100) NULL,
    subcategoria        VARCHAR(100) NULL,
    servis_tag          VARCHAR(255) NULL,
    cantidad            INT(11) NULL DEFAULT 1,
    valor_usd           DECIMAL(10,2) NULL DEFAULT 0.00,
    ubicacion           VARCHAR(255) NULL,
    estado              VARCHAR(20) NULL,
    carpeta_raiz_drive  VARCHAR(255) NULL,
    fecha_ingreso       DATE NULL,
    fecha_egreso        DATE NULL,
    fecha_garantia      DATE NULL,
    periodo_garantia    INT(11) NULL DEFAULT 0,
    archivado           TINYINT(1) NOT NULL DEFAULT 0,
    patchcord_tipo      VARCHAR(50) NULL,
    patchcord_longitud  VARCHAR(10) NULL,
    switch_gestion      VARCHAR(30) NULL,
    antenas_tipo        VARCHAR(50) NULL,
    bobina_categoria    VARCHAR(20) NULL,
    bobina_ubicacion    VARCHAR(20) NULL,
    bobina_longitud     VARCHAR(20) NULL,
    conectores_tipo     VARCHAR(50) NULL,
    detalle_conectores  VARCHAR(255) NULL,
    cintas_generacion   VARCHAR(20) NULL,
    detalle_repuesto    VARCHAR(255) NULL,
    detalle_otros       VARCHAR(255) NULL,
    subsoftware         VARCHAR(50) NULL,
    subsoftware_otros   VARCHAR(255) NULL,
    fecha_compra        DATE NULL,
    fecha_baja          DATE NULL,
    fecha_carga         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
",

"users" => "
CREATE TABLE IF NOT EXISTS users (
    id          INT(11) AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(120) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NULL,
    role        ENUM('admin','user') NOT NULL DEFAULT 'user',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
",

"producto_movimientos" => "
CREATE TABLE IF NOT EXISTS producto_movimientos (
    id                  INT(11) AUTO_INCREMENT PRIMARY KEY,
    producto_id         INT(11) NOT NULL,
    accion              VARCHAR(50) NOT NULL DEFAULT 'modificado',
    realizado_por       INT(11) NOT NULL,
    fecha               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modelo              VARCHAR(255) NULL,
    marca               VARCHAR(255) NULL,
    descripcion         TEXT NULL,
    detalle_tecnico     VARCHAR(255) NULL,
    asignado_a          VARCHAR(255) NULL,
    categoria           VARCHAR(100) NULL,
    subcategoria        VARCHAR(100) NULL,
    servis_tag          VARCHAR(255) NULL,
    cantidad            INT(11) NULL DEFAULT 1,
    valor_usd           DECIMAL(10,2) NULL DEFAULT 0.00,
    ubicacion           VARCHAR(255) NULL,
    estado              VARCHAR(20) NULL,
    carpeta_raiz_drive  VARCHAR(255) NULL,
    fecha_ingreso       DATE NULL,
    fecha_egreso        DATE NULL,
    fecha_garantia      DATE NULL,
    periodo_garantia    INT(11) NULL DEFAULT 0,
    archivado           TINYINT(1) NOT NULL DEFAULT 0,
    patchcord_tipo      VARCHAR(50) NULL,
    patchcord_longitud  VARCHAR(10) NULL,
    switch_gestion      VARCHAR(30) NULL,
    antenas_tipo        VARCHAR(50) NULL,
    bobina_categoria    VARCHAR(20) NULL,
    bobina_ubicacion    VARCHAR(20) NULL,
    bobina_longitud     VARCHAR(20) NULL,
    conectores_tipo     VARCHAR(50) NULL,
    detalle_conectores  VARCHAR(255) NULL,
    cintas_generacion   VARCHAR(20) NULL,
    detalle_repuesto    VARCHAR(255) NULL,
    detalle_otros       VARCHAR(255) NULL,
    subsoftware         VARCHAR(50) NULL,
    subsoftware_otros   VARCHAR(255) NULL,
    fecha_compra        DATE NULL,
    fecha_baja          DATE NULL,
    fecha_modificacion  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
    INDEX(producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
",

"user_devices" => "
CREATE TABLE IF NOT EXISTS user_devices (
    id            INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id       INT(11) NOT NULL,
    google_email  VARCHAR(255) NOT NULL,
    device_hash   VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, google_email, device_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
",

"cloud_config" => "
CREATE TABLE IF NOT EXISTS cloud_config (
    id           INT(11) AUTO_INCREMENT PRIMARY KEY,
    provider     ENUM('google','microsoft') NOT NULL,
    config_key   VARCHAR(100) NOT NULL,
    config_value TEXT NOT NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY provider_key (provider, config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
",

];

foreach ($tables as $name => $sql) {
    try {
        $conn->query($sql);
        log_event("install.php", "INSTALACION", "Tabla $name creada correctamente.", "instalacion");
        echo "<div class='alert alert-success'>✅ Tabla <code>$name</code> creada correctamente.</div>";
    } catch (Throwable $e) {
        log_event("install.php", "ERROR", "Error al crear $name: ".$e->getMessage(), "instalacion");
        echo "<div class='alert alert-danger'>❌ Error en tabla <code>$name</code>: ".htmlspecialchars($e->getMessage())."</div>";
    }
}

// -----------------------------------------------------
// 7) Trigger de trazabilidad (sin DELIMITER en PHP)
// -----------------------------------------------------
try {
    @$conn->query("DROP TRIGGER IF EXISTS trg_productos_after_update");

    $trigger_sql = "
    CREATE TRIGGER trg_productos_after_update
    AFTER UPDATE ON productos
    FOR EACH ROW
    BEGIN
      IF NOT (
         OLD.modelo              <=> NEW.modelo AND
         OLD.marca               <=> NEW.marca AND
         OLD.descripcion         <=> NEW.descripcion AND
         OLD.detalle_tecnico     <=> NEW.detalle_tecnico AND
         OLD.asignado_a          <=> NEW.asignado_a AND
         OLD.categoria           <=> NEW.categoria AND
         OLD.subcategoria        <=> NEW.subcategoria AND
         OLD.servis_tag          <=> NEW.servis_tag AND
         OLD.cantidad            <=> NEW.cantidad AND
         OLD.valor_usd           <=> NEW.valor_usd AND
         OLD.ubicacion           <=> NEW.ubicacion AND
         OLD.estado              <=> NEW.estado AND
         OLD.carpeta_raiz_drive  <=> NEW.carpeta_raiz_drive AND
         OLD.fecha_ingreso       <=> NEW.fecha_ingreso AND
         OLD.fecha_egreso        <=> NEW.fecha_egreso AND
         OLD.fecha_garantia      <=> NEW.fecha_garantia AND
         OLD.periodo_garantia    <=> NEW.periodo_garantia AND
         OLD.archivado           <=> NEW.archivado AND
         OLD.patchcord_tipo      <=> NEW.patchcord_tipo AND
         OLD.patchcord_longitud  <=> NEW.patchcord_longitud AND
         OLD.switch_gestion      <=> NEW.switch_gestion AND
         OLD.antenas_tipo        <=> NEW.antenas_tipo AND
         OLD.bobina_categoria    <=> NEW.bobina_categoria AND
         OLD.bobina_ubicacion    <=> NEW.bobina_ubicacion AND
         OLD.bobina_longitud     <=> NEW.bobina_longitud AND
         OLD.conectores_tipo     <=> NEW.conectores_tipo AND
         OLD.detalle_conectores  <=> NEW.detalle_conectores AND
         OLD.cintas_generacion   <=> NEW.cintas_generacion AND
         OLD.detalle_repuesto    <=> NEW.detalle_repuesto AND
         OLD.detalle_otros       <=> NEW.detalle_otros AND
         OLD.subsoftware         <=> NEW.subsoftware AND
         OLD.subsoftware_otros   <=> NEW.subsoftware_otros AND
         OLD.fecha_compra        <=> NEW.fecha_compra AND
         OLD.fecha_baja          <=> NEW.fecha_baja
      ) THEN
        INSERT INTO producto_movimientos (
          producto_id, accion, realizado_por,
          modelo, marca, descripcion, detalle_tecnico, asignado_a,
          categoria, subcategoria, servis_tag,
          cantidad, valor_usd, ubicacion, estado,
          carpeta_raiz_drive,
          fecha_ingreso, fecha_egreso, fecha_garantia, periodo_garantia,
          archivado,
          patchcord_tipo, patchcord_longitud,
          switch_gestion, antenas_tipo,
          bobina_categoria, bobina_ubicacion, bobina_longitud,
          conectores_tipo, detalle_conectores, cintas_generacion,
          detalle_repuesto, detalle_otros,
          subsoftware, subsoftware_otros,
          fecha_compra, fecha_baja
        )
        VALUES (
          NEW.id,
          'modificado',
          COALESCE(@current_user_id,0),
          NEW.modelo, NEW.marca, NEW.descripcion, NEW.detalle_tecnico, NEW.asignado_a,
          NEW.categoria, NEW.subcategoria, NEW.servis_tag,
          NEW.cantidad, NEW.valor_usd, NEW.ubicacion, NEW.estado,
          NEW.carpeta_raiz_drive,
          NEW.fecha_ingreso, NEW.fecha_egreso, NEW.fecha_garantia, NEW.periodo_garantia,
          NEW.archivado,
          NEW.patchcord_tipo, NEW.patchcord_longitud,
          NEW.switch_gestion, NEW.antenas_tipo,
          NEW.bobina_categoria, NEW.bobina_ubicacion, NEW.bobina_longitud,
          NEW.conectores_tipo, NEW.detalle_conectores, NEW.cintas_generacion,
          NEW.detalle_repuesto, NEW.detalle_otros,
          NEW.subsoftware, NEW.subsoftware_otros,
          NEW.fecha_compra, NEW.fecha_baja
        );
      END IF;
    END;
    ";

    $conn->query($trigger_sql);
    log_event("install.php", "INSTALACION", "Trigger creado correctamente.", "instalacion");
    echo "<div class='alert alert-success'>✅ Trigger de trazabilidad instalado.</div>";

} catch (Throwable $e) {
    log_event("install.php", "ERROR", "Error creando trigger: ".$e->getMessage(), "instalacion");
    echo "<div class='alert alert-danger'>❌ Error creando trigger: ".htmlspecialchars($e->getMessage())."</div>";
}

// -----------------------------------------------------
// 8) NO se crea ningún usuario por defecto.
//    En el modelo SaaS, los usuarios del tenant se crean desde el panel SuperAdmin
//    al dar de alta una empresa (dashboard_empresas.php). Esto evita el clásico
//    "admin/admin" inseguro y obliga a un onboarding controlado.
// -----------------------------------------------------
echo "<div class='alert alert-info'>ℹ️ No se crean usuarios por defecto. Creá la empresa cliente desde el panel SuperAdmin — el admin del tenant se crea ahí con email y contraseña que elijas.</div>";
log_event("install.php", "INSTALACION", "Schema instalado sin usuarios tenant (modelo SaaS).", "instalacion");

// -----------------------------------------------------
// 9) Instalación finalizada — redirigir a login
// -----------------------------------------------------
log_event("install.php", "INSTALACION", "Instalación completada correctamente.", "instalacion");
?>
      <div class="ih-alert ih-alert--success ih-mt-4">
        <i class="bi bi-check-circle-fill"></i>
        <div>
          <div class="ih-alert__title">Instalación finalizada</div>
          Próximo paso: ingresá al panel SuperAdmin para configurar Google Workspace SSO y registrar empresas cliente.
        </div>
      </div>
      <div class="ih-flex ih-gap-2 ih-mt-4">
        <a href="/auth/superadmin_login.php" class="ih-btn ih-btn--primary">
          <i class="bi bi-shield-lock"></i> Ir al panel SuperAdmin
        </a>
        <a href="/login.php" class="ih-btn ih-btn--outline">
          <i class="bi bi-box-arrow-in-right"></i> Login del inventario
        </a>
      </div>
<?php
@$conn->close();
?>
    </div>
  </div>
  <div class="install-foot">
    © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
  </div>
</div>
</body>
</html>
