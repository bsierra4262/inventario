<?php
require_once __DIR__ . '/../config/platform.php';

if (!class_exists('MasterDB')) {
class MasterDB {
    public mysqli $conn;

    public function __construct(){
        $this->conn = @new mysqli(MASTER_DB_HOST, MASTER_DB_USER, MASTER_DB_PASS, MASTER_DB_NAME);
        if($this->conn->connect_errno){
            platform_log('ERROR DB MASTER: '.$this->conn->connect_error);
            throw new RuntimeException('No se puede conectar a la DB master: '.$this->conn->connect_error);
        }
        $this->conn->set_charset('utf8mb4');
    }

    public function pquery(string $sql, string $types = '', array $params = []): mysqli_stmt {
        $stmt = $this->conn->prepare($sql);
        if(!$stmt){ throw new Exception('Prepare failed: '.$this->conn->error); }
        if($types && $params){
            $stmt->bind_param($types, ...$params);
        }
        if(!$stmt->execute()){
            throw new Exception('Exec failed: '.$stmt->error);
        }
        return $stmt;
    }
}
}
