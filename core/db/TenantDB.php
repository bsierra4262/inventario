<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/platform.php';
require_once __DIR__ . '/master_connection.php';

/**
 * TenantDB
 * - En modo 'external': conecta a la DB del tenant (misma estructura/tabla sin prefijos).
 * - En modo 'prefix': conecta a la DB master y expone un prefijo para anteponer en cada tabla.
 *   (Tus scripts actuales NO usan prefijos; por eso, por compatibilidad inmediata recomiendo 'external'.)
 */
class TenantDB {
    public mysqli $conn;
    public string $mode;         // 'external' | 'prefix'
    public int $companyId;       // ID de la empresa en la DB master
    public string $prefix = '';  // ej: "t3_"

    public function __construct(int $companyId){
        $this->companyId = $companyId;

        // Traer datos de la empresa desde MASTER
        $mdb = new MasterDB();
        $stmt = $mdb->pquery('SELECT tenant_mode, db_host, db_name, db_user, db_pass FROM companies WHERE id=?', 'i', [$companyId]);
        $row  = $stmt->get_result()->fetch_assoc();
        if(!$row){ 
            platform_log("TenantDB: empresa $companyId no encontrada");
            http_response_code(500);
            die('Empresa no encontrada');
        }

        $this->mode = ($row['tenant_mode'] === 'prefix') ? 'prefix' : 'external';

        if($this->mode === 'external'){
            // Conexión directa a la DB del tenant (tablas sin prefijo: users, productos, etc.)
            $host = $row['db_host'] ?: 'localhost';
            $name = $row['db_name'] ?: '';
            $user = $row['db_user'] ?: '';
            $pass = $row['db_pass'] ?: '';
            $cx = @new mysqli($host, $user, $pass, $name);
            if($cx->connect_errno){
                platform_log('TenantDB external connect error: '.$cx->connect_error);
                http_response_code(500);
                die('No se puede conectar a la base de datos del cliente');
            }
            $cx->set_charset('utf8mb4');
            $this->conn = $cx;
        } else {
            // Modo prefijo: mismas tablas pero con prefijo dentro de la MASTER
            $this->prefix = TENANT_PREFIX_BASE . $companyId . '_';
            $this->conn = (new MasterDB())->conn;
            $this->conn->set_charset('utf8mb4');
        }
    }

    /**
     * Devuelve el nombre calzado de la tabla para modo 'prefix'.
     * En 'external' retorna el nombre tal cual.
     */
    public function t(string $table): string {
        return ($this->mode === 'prefix') ? ($this->prefix . $table) : $table;
    }

    /**
     * Helper para consultas preparadas sobre el tenant.
     */
    public function pquery(string $sql, string $types = '', array $params = []): mysqli_stmt {
        // Reemplazo automático de {{table}} si se usa el atajo: ej. "SELECT * FROM {{users}}"
        $sql = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\}\}/', function($m){ return $this->t($m[1]); }, $sql);
        $stmt = $this->conn->prepare($sql);
        if(!$stmt){ throw new Exception('Prepare failed: '.$this->conn->error); }
        if($types && $params){ $stmt->bind_param($types, ...$params); }
        if(!$stmt->execute()){ throw new Exception('Exec failed: '.$stmt->error); }
        return $stmt;
    }
}
