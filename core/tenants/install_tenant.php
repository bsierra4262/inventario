<?php
require_once __DIR__ . '/../db/master_connection.php';
require_once __DIR__ . '/../config/platform.php';

function tenant_conn_for_external(array $ext): mysqli {
    $cx = @new mysqli($ext['host'], $ext['user'], $ext['pass'], $ext['name']);
    if ($cx->connect_errno) {
        throw new Exception('No conecta DB externa: ' . $cx->connect_error);
    }
    $cx->set_charset('utf8mb4');
    return $cx;
}

/**
 * Crea las tablas núcleo del inventario.
 * Si $mode==='prefix': trabaja sobre DB master con prefijo "t{companyId}_"
 * Si $mode==='external': usa conexión externa y NO usa prefijo.
 */
function install_tenant_schema(int $companyId, string $mode, array $externalDb): void {
    $prefix = TENANT_PREFIX_BASE . $companyId . '_';

    if ($mode === 'prefix') {
        $mdb = new MasterDB();
        $cx  = $mdb->conn;
        $pf  = $cx->real_escape_string($prefix);
    } else { // external
        $cx = tenant_conn_for_external($externalDb);
        $pf = ''; // sin prefijo
    }

    $sql = [];

    // === USERS (del tenant) ===
    // Estructura alineada con u566451014_desarrollo.users
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}users` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(120) NOT NULL,
      password VARCHAR(255) NOT NULL,
      email VARCHAR(150) UNIQUE,
      role ENUM('admin','user') NOT NULL DEFAULT 'admin',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === PRODUCTOS === (dejamos tu estructura actual)
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}productos` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      modelo VARCHAR(150),
      marca VARCHAR(120),
      sn_tag VARCHAR(150),
      estado VARCHAR(50),
      garantia VARCHAR(50),
      ingreso DATE,
      egreso DATE,
      ubicacion VARCHAR(150),
      asignado_a VARCHAR(150),
      valor_usd DECIMAL(12,2) DEFAULT 0,
      cantidad INT DEFAULT 1,
      categoria VARCHAR(120),
      subcategoria VARCHAR(120),
      detalle_tecnico TEXT,
      descripcion TEXT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === MOVIMIENTOS ===
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}producto_movimientos` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      producto_id INT NOT NULL,
      accion ENUM('Agregado','Editado','Archivado','Restaurado','Eliminado') NOT NULL,
      descripcion TEXT NULL,
      motivo VARCHAR(120) NULL,
      detalle TEXT NULL,
      usuario VARCHAR(120),
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (producto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === USER DEVICES ===
    // Alineado con tu captura: id, user_id, google_email, device_hash, created_at
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}user_devices` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      google_email VARCHAR(255) NOT NULL,
      device_hash VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user_device (user_id, google_email, device_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Ejecutar creación de tablas
    foreach ($sql as $q) {
        if (!$cx->query($q)) {
            throw new Exception('Error instalando schema: ' . $cx->error);
        }
    }

    // --- Usuario admin por defecto (admin / admin) ---
    $hash = password_hash('admin', PASSWORD_DEFAULT);
    $now  = date('Y-m-d H:i:s');

    $hashEsc = $cx->real_escape_string($hash);
    $nowEsc  = $cx->real_escape_string($now);

    $adminSql = "
        INSERT INTO `{$pf}users` (username, password, email, role, created_at)
        SELECT 'admin', '{$hashEsc}', 'admin@inventario.local', 'admin', '{$nowEsc}'
        WHERE NOT EXISTS (SELECT 1 FROM `{$pf}users` WHERE username = 'admin')
    ";

    if (!$cx->query($adminSql)) {
        throw new Exception('Error creando usuario admin por defecto: ' . $cx->error);
    }

    if ($mode === 'external') {
        $cx->close();
    }
}
