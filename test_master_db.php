<?php
// test_master_db.php (temporal)
require_once __DIR__ . '/core/config/platform.php';
require_once __DIR__ . '/core/db/master_connection.php';

try {
    $m = new MasterDB();
    echo "Conexión MASTER OK - Host: " . MASTER_DB_HOST . " DB: " . MASTER_DB_NAME . "<br>";
    $res = $m->conn->query("SELECT COUNT(*) as c FROM information_schema.tables WHERE table_schema = '".MASTER_DB_NAME."' ");
    $r = $res->fetch_assoc();
    echo "Tablas en la DB master: " . $r['c'];
} catch(Exception $e){
    echo "Error: " . htmlspecialchars($e->getMessage());
}
