<?php
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();
csrf_start();

$err = $ok = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_validate();

    $nombre       = trim($_POST['nombre'] ?? '');
    $razon        = trim($_POST['razon_social'] ?? '');
    $cuit         = trim($_POST['cuit'] ?? '');
    $provider     = in_array($_POST['provider'] ?? '', PROVIDERS, true) ? $_POST['provider'] : 'google';
    $tenant_mode  = ($_POST['tenant_mode']??DEFAULT_TENANT_MODE) === 'external' ? 'external' : 'prefix';

    if($nombre===''){ $err='El nombre es obligatorio'; }
    if(empty($_FILES['cred']['tmp_name'])){ $err='Subí el JSON de credenciales del proveedor'; }

    // Subir credencial a /secure/tenants/{id}/... luego de insertar
    if(!$err){
        $db = new MasterDB();
        $stmt = $db->pquery(
            'INSERT INTO companies (nombre,razon_social,cuit,provider,credentials_path,tenant_mode) VALUES (?,?,?,?,?,?)',
            'ssssss',
            [$nombre,$razon,$cuit,$provider,'', $tenant_mode]
        );
        $newId = $db->conn->insert_id;

        // Ruta final de credenciales
        $destDir  = TENANTS_SECURE_PATH . '/'.$newId.'/'.$provider;
        $destFile = $destDir.'/credentials.json';
        ensure_dir($destDir);

        // Guardar archivo
        $raw = file_get_contents($_FILES['cred']['tmp_name']);
        // Validación mínima del JSON
        json_decode($raw);
        if(json_last_error() !== JSON_ERROR_NONE){
            $err = 'El archivo no es un JSON válido';
        } else {
            file_put_contents($destFile, $raw);
            chmod($destFile, 0600);

            // Actualizar path
            $db->pquery('UPDATE companies SET credentials_path=? WHERE id=?','si',[$destFile,$newId]);

            // Si modo external, tomar credenciales DB externas del formulario
            $db_host = trim($_POST['db_host'] ?? 'localhost');
            $db_name = trim($_POST['db_name'] ?? '');
            $db_user = trim($_POST['db_user'] ?? '');
            $db_pass = trim($_POST['db_pass'] ?? '');

            if($tenant_mode==='external'){
                if($db_name==='' || $db_user===''){ $err='Completá DB externa (nombre/usuario)'; }
                if(!$err){
                    $db->pquery(
                        'UPDATE companies SET db_host=?, db_name=?, db_user=?, db_pass=? WHERE id=?',
                        'ssssi',
                        [$db_host,$db_name,$db_user,$db_pass,$newId]
                    );
                }
            }

            if(!$err){
                // Instalar esquema del tenant
                try{
                    install_tenant_schema($newId, $tenant_mode, [
                        'host'=>$db_host,'name'=>$db_name,'user'=>$db_user,'pass'=>$db_pass
                    ]);
                    $ok = 'Empresa creada con ID '.$newId.' y esquema instalado correctamente.';
                }catch(Exception $e){
                    $err = 'Se creó la empresa pero falló la instalación del esquema: '.$e->getMessage();
                }
            }
        }

        if($err && isset($newId)){
            platform_log("Alta empresa ID:$newId error: $err");
        } else {
            platform_log("Alta empresa ID:$newId OK");
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Alta de empresa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Saira,system-ui;background:#fafafa;margin:0}
.top{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#663399;color:#fff}
.wrap{max-width:920px;margin:20px auto;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:20px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.input,select{width:100%;padding:10px;border:1px solid #e3e3e3;border-radius:10px}
label{font-size:14px;color:#444}
.btn{background:#86B329;color:#fff;border:0;padding:12px 16px;border-radius:12px;cursor:pointer;font-weight:700}
.note{font-size:13px;color:#666}
.err{background:#ffebee;color:#c62828;padding:10px;border-radius:10px;margin-bottom:10px}
.ok{background:#e8f5e9;color:#2e7d32;padding:10px;border-radius:10px;margin-bottom:10px}
.box{background:#fafafa;border:1px dashed #ddd;border-radius:12px;padding:12px}
</style>
<script>
function toggleDB(){
  const val = document.querySelector('select[name="tenant_mode"]').value;
  document.getElementById('dbext').style.display = (val==='external') ? 'block' : 'none';
}
</script>
</head>
<body>
<div class="top">
  <div><strong>Alta de empresa</strong></div>
  <div>
    <a class="btn" href="/dashboard/dashboard_superadmin.php" style="background:#444;text-decoration:none">Volver</a>
  </div>
</div>

<div class="wrap">
  <?php if($err): ?><div class="err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if($ok):  ?><div class="ok"><?php  echo htmlspecialchars($ok);  ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?php csrf_input(); ?>

    <div class="grid">
      <div>
        <label>Nombre de la empresa</label>
        <input class="input" name="nombre" required>
      </div>
      <div>
        <label>Razón social</label>
        <input class="input" name="razon_social">
      </div>
      <div>
        <label>CUIT</label>
        <input class="input" name="cuit" placeholder="Opcional">
      </div>
      <div>
        <label>Proveedor de nube</label>
        <select class="input" name="provider">
          <option value="google">Google Drive</option>
          <option value="microsoft">Microsoft OneDrive (stub)</option>
        </select>
      </div>

      <div>
        <label>Modo de instalación</label>
        <select class="input" name="tenant_mode" onchange="toggleDB()">
          <option value="prefix" selected>Automático (tablas con prefijo en DB master)</option>
          <option value="external">DB separada (creada en el panel)</option>
        </select>
      </div>

      <div class="box">
        <label>Credenciales del proveedor (JSON)</label>
        <input class="input" type="file" name="cred" accept=".json" required>
        <div class="note">Subí el <b>credentials.json</b> del cliente (Google/Azure).</div>
      </div>
    </div>

    <div id="dbext" style="display:none;margin-top:12px" class="box">
      <h4 style="margin:0 0 8px">Datos de DB externa (si elegís “DB separada”)</h4>
      <div class="grid">
        <div>
          <label>Host</label>
          <input class="input" name="db_host" value="localhost">
        </div>
        <div>
          <label>Nombre DB</label>
          <input class="input" name="db_name" placeholder="u566451014_empresaX">
        </div>
        <div>
          <label>Usuario DB</label>
          <input class="input" name="db_user" placeholder="u566451014_userX">
        </div>
        <div>
          <label>Contraseña DB</label>
          <input class="input" name="db_pass" type="password">
        </div>
      </div>
      <div class="note">Recordá crear la DB y el usuario en Hostinger antes de continuar.</div>
    </div>

    <div style="margin-top:16px">
      <button class="btn" type="submit">Crear empresa</button>
    </div>
  </form>
</div>

<script>toggleDB();</script>
</body>
</html>
