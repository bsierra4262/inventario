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
// 3) Parámetros de instalación
// -----------------------------------------
$dbname = 'u566451014_desarrollo'; // <- tu DB objetivo

// Si ya fue ejecutado, bloquear reinstalación accidental
if (file_exists(__DIR__ . '/install.lock')) {
    log_event("install.php", "INSTALACION BLOQUEADA", "Intento de reinstalación detectado.", "instalacion");
    echo "<script>alert('El script de instalación ya fue ejecutado.'); window.location.href = 'login.php';</script>";
    exit;
}

// -----------------------------------------
// 3b) Bootstrap de la DB master (companies)
// -----------------------------------------
try {
    require_once __DIR__ . '/core/config/platform.php';
    require_once __DIR__ . '/core/db/master_connection.php';
    $master = new MasterDB();

    // Crear tabla companies si no existe
    $master->conn->query("CREATE TABLE IF NOT EXISTS `companies` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nombre      VARCHAR(200) NOT NULL,
        tenant_mode ENUM('prefix','external') NOT NULL DEFAULT 'external',
        db_host     VARCHAR(200) DEFAULT 'localhost',
        db_name     VARCHAR(200) DEFAULT NULL,
        db_user     VARCHAR(200) DEFAULT NULL,
        db_pass     VARCHAR(255) DEFAULT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Registrar empresa ITH si no existe
    $chk = $master->conn->prepare("SELECT id FROM companies WHERE nombre = 'ITH' LIMIT 1");
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $ins = $master->conn->prepare(
            "INSERT INTO companies (nombre, tenant_mode, db_host, db_name, db_user, db_pass)
             VALUES ('ITH', 'external', 'localhost', 'u566451014_desarrollo', 'u566451014_desarrollo', '***SECRETO-PURGADO***')"
        );
        $ins->execute();
    }
    $chk->close();
} catch (Throwable $e) {
    // No es fatal — el tenant puede instalarse igual. Mostrar advertencia.
    log_event("install.php", "ADVERTENCIA", "No se pudo configurar DB master: ".$e->getMessage(), "instalacion");
}

// Crear base si no existe
$sql_create_db = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Instalación del Sistema de Inventario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{font-family:'Saira',sans-serif;background:#fff;padding-top:30px}
    .card{border-color:#663399}
    .card-header{background:#663399;color:#fff}
    .btn-success{background:#9cc930;border-color:#9cc930}
    .btn-success:hover{background:#86b329;border-color:#86b329}
    .alert{margin-top:10px;opacity:0;transition:opacity 1s ease-in-out}
    footer{background:#663399;color:#fff;text-align:center;padding:10px 0;position:fixed;width:100%;bottom:0}
    code{background:#f5f5f5;border:1px solid #eee;border-radius:6px;padding:2px 6px}
  </style>
</head>
<body>
<div class="container">
  <h2 class="text-center mb-4">Instalación del Sistema de Inventario</h2>
  <div class="card shadow-lg">
    <div class="card-header text-center">Proceso de Instalación</div>
    <div class="card-body">
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
// 8) Usuario admin por defecto
// -----------------------------------------------------
try {
    $default_password = password_hash('admin', PASSWORD_DEFAULT);
    $sql_insert_user = "
        INSERT INTO users (username, password, email, role, created_at)
        SELECT 'admin', '$default_password', 'admin@inventario.local', 'admin', NOW()
        WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='admin')
    ";
    $conn->query($sql_insert_user);
    log_event("install.php", "INSTALACION", "Usuario admin creado/ya existente.", "instalacion");
    echo "<div class='alert alert-success'>✅ Usuario administrador creado (o ya existía).</div>";
} catch (Throwable $e) {
    log_event("install.php", "ERROR", "Error creando usuario admin: ".$e->getMessage(), "instalacion");
    echo "<div class='alert alert-danger'>❌ Error creando usuario admin: ".htmlspecialchars($e->getMessage())."</div>";
}

// -----------------------------------------------------
// 9) Crear install.lock y redirigir a login
// -----------------------------------------------------
if (file_put_contents(__DIR__ . '/install.lock', 'Instalación completada.') !== false) {
    log_event("install.php", "INSTALACION", "Archivo install.lock creado.", "instalacion");
    echo "<div class='alert alert-info'>ℹ️ Instalación finalizada. Redirigiendo a login...</div>";
    echo "<script>setTimeout(()=>window.location.href='login.php', 2500);</script>";
} else {
    log_event("install.php", "ERROR", "No se pudo crear install.lock", "instalacion");
    echo "<div class='alert alert-danger'>❌ No se pudo crear <code>install.lock</code>. Ver permisos del directorio.</div>";
}

@$conn->close();
?>
    </div>
  </div>
</div>
<footer class="sticky-footer">© 2025 ITH - Todos los derechos reservados</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=> {
  document.querySelectorAll('.alert').forEach((el,i)=>setTimeout(()=>el.style.opacity=1, 500*(i+1)));
});
</script>
</body>
</html>
