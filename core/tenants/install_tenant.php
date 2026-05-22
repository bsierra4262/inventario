<?php
declare(strict_types=1);

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
 * Crea las tablas núcleo del inventario para un tenant.
 * Si $mode==='prefix': trabaja sobre DB master con prefijo "t{companyId}_"
 * Si $mode==='external': usa conexión externa, sin prefijo.
 */
function install_tenant_schema(int $companyId, string $mode, array $externalDb): void {
    $prefix = TENANT_PREFIX_BASE . $companyId . '_';

    if ($mode === 'prefix') {
        $mdb = new MasterDB();
        $cx  = $mdb->conn;
        $pf  = $cx->real_escape_string($prefix);
    } else {
        $cx = tenant_conn_for_external($externalDb);
        $pf = '';
    }

    $sql = [];

    // === USERS ===
    // Roles soportados: admin (full), manager (CRUD activos + reportes),
    // user (ver/crear/editar), viewer (solo lectura).
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}users` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(120) NOT NULL,
        password    VARCHAR(255) NOT NULL,
        email       VARCHAR(255) UNIQUE DEFAULT NULL,
        role        ENUM('admin','manager','user','viewer') NOT NULL DEFAULT 'user',
        active      TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === PRODUCTOS ===
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}productos` (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        modelo              VARCHAR(255) NULL,
        marca               VARCHAR(255) NULL,
        descripcion         TEXT NULL,
        detalle_tecnico     VARCHAR(255) NULL,
        asignado_a          VARCHAR(255) NULL,
        categoria           VARCHAR(100) NULL,
        subcategoria        VARCHAR(100) NULL,
        servis_tag          VARCHAR(255) NULL,
        cantidad            INT DEFAULT 1,
        valor_usd           DECIMAL(10,2) DEFAULT 0.00,
        ubicacion           VARCHAR(255) NULL,
        estado              VARCHAR(20)  NULL,
        carpeta_raiz_drive  VARCHAR(255) NULL,
        fecha_ingreso       DATE NULL,
        fecha_egreso        DATE NULL,
        fecha_garantia      DATE NULL,
        periodo_garantia    INT DEFAULT 0,
        archivado           TINYINT(1) NOT NULL DEFAULT 0,
        patchcord_tipo      VARCHAR(50)  NULL,
        patchcord_longitud  VARCHAR(10)  NULL,
        switch_gestion      VARCHAR(30)  NULL,
        antenas_tipo        VARCHAR(50)  NULL,
        bobina_categoria    VARCHAR(20)  NULL,
        bobina_ubicacion    VARCHAR(20)  NULL,
        bobina_longitud     VARCHAR(20)  NULL,
        conectores_tipo     VARCHAR(50)  NULL,
        detalle_conectores  VARCHAR(255) NULL,
        cintas_generacion   VARCHAR(20)  NULL,
        detalle_repuesto    VARCHAR(255) NULL,
        detalle_otros       VARCHAR(255) NULL,
        subsoftware         VARCHAR(50)  NULL,
        subsoftware_otros   VARCHAR(255) NULL,
        fecha_compra        DATE NULL,
        fecha_baja          DATE NULL,
        fecha_carga         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_modificacion  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === MOVIMIENTOS ===
    // realizado_por es VARCHAR para aceptar usernames (string) o IDs numéricos como string
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}producto_movimientos` (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        producto_id         INT NOT NULL,
        accion              VARCHAR(100) NOT NULL DEFAULT 'modificado',
        realizado_por       VARCHAR(255) NOT NULL DEFAULT '',
        fecha               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        modelo              VARCHAR(255) NULL,
        marca               VARCHAR(255) NULL,
        descripcion         TEXT NULL,
        detalle_tecnico     VARCHAR(255) NULL,
        asignado_a          VARCHAR(255) NULL,
        categoria           VARCHAR(100) NULL,
        subcategoria        VARCHAR(100) NULL,
        servis_tag          VARCHAR(255) NULL,
        cantidad            INT NULL DEFAULT 1,
        valor_usd           DECIMAL(10,2) NULL DEFAULT 0.00,
        ubicacion           VARCHAR(255) NULL,
        estado              VARCHAR(20)  NULL,
        carpeta_raiz_drive  VARCHAR(255) NULL,
        fecha_ingreso       DATE NULL,
        fecha_egreso        DATE NULL,
        fecha_garantia      DATE NULL,
        periodo_garantia    INT NULL DEFAULT 0,
        archivado           TINYINT(1) NOT NULL DEFAULT 0,
        patchcord_tipo      VARCHAR(50)  NULL,
        patchcord_longitud  VARCHAR(10)  NULL,
        switch_gestion      VARCHAR(30)  NULL,
        antenas_tipo        VARCHAR(50)  NULL,
        bobina_categoria    VARCHAR(20)  NULL,
        bobina_ubicacion    VARCHAR(20)  NULL,
        bobina_longitud     VARCHAR(20)  NULL,
        conectores_tipo     VARCHAR(50)  NULL,
        detalle_conectores  VARCHAR(255) NULL,
        cintas_generacion   VARCHAR(20)  NULL,
        detalle_repuesto    VARCHAR(255) NULL,
        detalle_otros       VARCHAR(255) NULL,
        subsoftware         VARCHAR(50)  NULL,
        subsoftware_otros   VARCHAR(255) NULL,
        fecha_compra        DATE NULL,
        fecha_baja          DATE NULL,
        fecha_modificacion  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_producto (producto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === USER DEVICES ===
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}user_devices` (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        google_email  VARCHAR(255) NOT NULL,
        device_hash   VARCHAR(255) NOT NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_device (user_id, google_email, device_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // === CLOUD CONFIG ===
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$pf}cloud_config` (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        provider     ENUM('google','microsoft') NOT NULL,
        config_key   VARCHAR(100) NOT NULL,
        config_value TEXT NOT NULL,
        updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY provider_key (provider, config_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    foreach ($sql as $q) {
        if (!$cx->query($q)) {
            throw new Exception('Error instalando schema tenant: ' . $cx->error);
        }
    }

    if ($mode === 'external') {
        $cx->close();
    }
}

/**
 * Conecta a la DB de un tenant dado su company ID (lee datos del master).
 * Devuelve la conexión mysqli y el prefijo de tabla (vacío en modo external).
 */
function tenant_conn_by_company(int $companyId): array {
    $mdb  = new MasterDB();
    $stmt = $mdb->pquery('SELECT tenant_mode, db_host, db_name, db_user, db_pass FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception("Empresa ID $companyId no encontrada en master DB.");
    }

    if ($row['tenant_mode'] === 'external') {
        $cx = @new mysqli($row['db_host'] ?: 'localhost', $row['db_user'], $row['db_pass'], $row['db_name']);
        if ($cx->connect_errno) {
            throw new Exception('No conecta a DB del tenant: ' . $cx->connect_error);
        }
        $cx->set_charset('utf8mb4');
        return ['conn' => $cx, 'prefix' => ''];
    }

    // prefix mode
    $prefix = TENANT_PREFIX_BASE . $companyId . '_';
    $cx     = (new MasterDB())->conn;
    return ['conn' => $cx, 'prefix' => $prefix];
}
