<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/config/platform.php';

$db = new MasterDB();
$companies = [];
$res = $db->pquery('SELECT id,nombre FROM companies ORDER BY nombre ASC');
$rs = $res->get_result();
while($row = $rs->fetch_assoc()){ $companies[] = $row; }

if($_SERVER['REQUEST_METHOD']==='POST'){
    $cid = (int)($_POST['company_id'] ?? 0);
    foreach($companies as $c){ if($c['id']===$cid){ $_SESSION['company_id']=$cid; break; } }
    $target = $_SESSION['pending_target'] ?? '/';
    unset($_SESSION['pending_target']);
    header('Location: '.$target);
    exit;
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Seleccionar empresa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Saira,system-ui;background:#f5f5f7;margin:0}
.wrap{max-width:560px;margin:60px auto;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:24px}
h1{margin:0 0 12px 0;font-size:22px}
label,select,button{font-size:16px}
select,button{width:100%;padding:12px;border-radius:12px;border:1px solid #ddd}
button{background:#663399;color:#fff;border:none;margin-top:12px}
a{color:#663399;text-decoration:none}
</style>
</head>
<body>
  <div class="wrap">
    <h1>Elegí la empresa</h1>
    <form method="post">
      <label for="company_id">Empresa</label>
      <select id="company_id" name="company_id" required>
        <option value="" disabled selected>Seleccioná...</option>
        <?php foreach($companies as $c): ?>
          <option value="<?=htmlspecialchars((string)$c['id'])?>"><?=htmlspecialchars($c['nombre'])?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Continuar</button>
    </form>
    <p style="margin-top:16px"><a href="/login.php">Volver</a></p>
  </div>
</body>
</html>
