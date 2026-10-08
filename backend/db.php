<?php
/**
 * backend/db.php — Conexión al tenant activo (multiempresa)
 * Expone $conn (mysqli) y tn() para todas las páginas del inventario.
 * Logger opcional: si no existe logs/logger.php no rompe.
 */

declare(strict_types=1);

// ---------- Rutas robustas ----------
defined('BACKEND_PATH') || define('BACKEND_PATH', __DIR__);
defined('ROOT_PATH')    || define('ROOT_PATH', dirname(BACKEND_PATH));

// ---------- Logger opcional ----------
$loggerPath = ROOT_PATH . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') {}
    }
}

// ---------- Detección de script actual ----------
$current_file = basename($_SERVER['PHP_SELF'] ?? '');

// ============================================================
// MODO NORMAL: bootstrap multiempresa
// ============================================================
/* Un fallo acá puede ser de dos cosas muy distintas: el entorno no está
   preparado —y entonces corresponde el instalador— o la base no contestó este
   pedido en particular.

   Lo segundo pasa de verdad y es medible: el servidor corta a las **18
   conexiones simultáneas** por usuario, y al pasarse mysqli no dice «demasiadas
   conexiones» sino `Operation not permitted` con errno 2002 —el error de socket
   genérico—, que no se parece a nada. Un pedido de treinta falla y el siguiente
   anda.

   Así que se reintenta, con una espera corta y creciente. La espera es la parte
   que importa: reintentar en el mismo instante encuentra el mismo socket
   ocupado y falla igual. Treinta y cien milisegundos no se notan y alcanzan,
   porque las conexiones que sobran las está soltando el pedido de al lado.
   Si igual no entra, se muestra «el servicio no está disponible» y no se manda
   al instalador a alguien que ya tiene la sesión abierta. */
$_bootError = null;
for ($_intento = 1; $_intento <= 3; $_intento++) {
    try {
        require ROOT_PATH . '/core/tenant_bootstrap.php';
        // tenant_bootstrap expone: $tenantDb (TenantDB) y $conn (mysqli)
        $_bootError = null;
        break;
    } catch (Throwable $e) {
        $_bootError = $e;
        // Puede haber fallado antes incluso de que la clase se cargara.
        if (class_exists('MasterDB')) { MasterDB::olvidar(); }
        if ($_intento < 3) { usleep($_intento * 30000); }
    }
}
if ($_bootError !== null) {
    log_event("db.php", "ERROR", "Fallo al inicializar tenant: " . $_bootError->getMessage(), "bootstrap");
    error_log("Error en bootstrap multiempresa: " . $_bootError->getMessage());
    require_once ROOT_PATH . '/includes/sin_base.php';
    pantalla_sin_base();
}

// ============================================================
// Guard: la identidad tiene que corresponder a la empresa activa
//
// `user_id` es el id de una fila en la tabla `users` DE UNA EMPRESA. No
// identifica a nadie fuera de ella. Si `company_id` cambia y `user_id`
// sobrevive, tn() resuelve contra las tablas de la empresa nueva y el
// `SELECT ... WHERE id = $user_id` de cada página devuelve a OTRA persona:
// quedás logueado como el usuario que tenga ese mismo id numérico allá, con su
// rol. El usuario 1 de una empresa entraba como el usuario 1 de otra.
//
// Había al menos dos formas de provocar ese desfasaje:
//   - dashboard/select_empresa.php fijaba company_id sin tocar user_id;
//   - auth/login.php sobrescribe company_id con el del POST aunque ya haya una
//     sesión viva de otra empresa, así que un login fallido dejaba la sesión
//     cruzada.
//
// Cerrar los dos caminos no alcanza: cualquier código futuro que escriba
// company_id reabre el agujero. Así que la empresa contra la que se autenticó
// queda grabada en la sesión y acá se compara en cada request. Si no coinciden,
// la sesión no es de fiar y se destruye.
// ============================================================
if (!empty($_SESSION['user_id'])) {
    $_auth_cid = (int)($_SESSION['auth_company_id'] ?? 0);
    $_curr_cid = (int)($_SESSION['company_id'] ?? 0);

    if ($_auth_cid === 0) {
        // Sesión abierta antes de que existiera el guard: se adopta la empresa
        // actual como la de autenticación. Es el estado en el que ya estaba, no
        // afloja nada; a partir del próximo cambio de empresa queda protegida.
        $_SESSION['auth_company_id'] = $_curr_cid;
    } elseif ($_auth_cid !== $_curr_cid) {
        log_event('db.php', 'AUTH_FAIL',
            "Sesión cruzada entre empresas: user_id={$_SESSION['user_id']} autenticado en cid={$_auth_cid}, pedido cid={$_curr_cid}",
            'seguridad');
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        require_once ROOT_PATH . '/core/tenants/tenant_url_helper.php';
        header('Location: ' . tenant_login_url($_curr_cid, ['err' => 'Tu sesión no corresponde a esta empresa. Volvé a iniciar sesión.']));
        exit();
    }
}

// ============================================================
// Guard: cambio de contraseña obligatorio (solo check de sesión)
// ============================================================
$_excluded_from_force = ['login.php', 'select_empresa.php', 'change_password.php', 'logout.php'];
if (!in_array($current_file, $_excluded_from_force, true) && !empty($_SESSION['force_password_change'])) {
    header('Location: /auth/change_password.php');
    exit();
}

// ============================================================
// Verificación de esquema
//
// Antes acá corría DDL en CADA request: SHOW TABLES, dos ALTER TABLE ...
// ADD COLUMN IF NOT EXISTS, un SHOW COLUMNS y (hasta el commit baf64d82) un
// ALTER que revertía `role` a ENUM. Eran 5+ consultas de metadata antes de la
// primera consulta útil de cada página, y una de ellas rompía los roles
// personalizados.
//
// Ahora el esquema se versiona (core/db/migraciones.php) y se aplica una sola
// vez con `php bin/migrar.php`. Acá sólo se lee la versión: una consulta por
// clave primaria contra tenant_settings.
// ============================================================
if (!in_array($current_file, ['login.php', 'select_empresa.php'], true)) {
    try {
        if (!$conn || !$conn->ping()) {
            log_event("db.php", "ERROR", "Ping a la DB del tenant fallido", "bootstrap");
            require_once ROOT_PATH . '/includes/sin_base.php';
            pantalla_sin_base();
        }

        $conn->set_charset('utf8mb4');

        require_once ROOT_PATH . '/core/db/migraciones.php';
        $_prefijo   = $tenantDb->mode === 'prefix' ? $tenantDb->prefix : '';
        $_versionOk = schema_version_actual($conn, $_prefijo);

        if ($_versionOk === 0) {
            // Tenant sin estructura (o sin migrar nunca) → mandar a instalar.
            $usersTable = tn('users');
            $safeTable  = $conn->real_escape_string($usersTable);
            $check      = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
            if (!$check || $check->num_rows === 0) {
                log_event("db.php", "ERROR", "Tabla `{$usersTable}` no encontrada en tenant", "bootstrap");
                header('Location: /install.php');
                exit();
            }
        }

        // Marca para que los helpers no repitan CREATE TABLE IF NOT EXISTS.
        if ($_versionOk >= schema_version_objetivo()) {
            defined('APP_SCHEMA_OK') || define('APP_SCHEMA_OK', true);
        } else {
            log_event('db.php', 'WARN',
                "Esquema del tenant en v{$_versionOk}, el código espera v" . schema_version_objetivo()
                . ' — correr: php bin/migrar.php', 'bootstrap');
        }

    } catch (Throwable $e) {
        log_event("db.php", "ERROR", "Verificación fallida: " . $e->getMessage(), "bootstrap");
        error_log("Error verificación DB (tenant): " . $e->getMessage());
        require_once ROOT_PATH . '/includes/sin_base.php';
        pantalla_sin_base();
    }
}

// ============================================================
// Guard: la cuenta sigue estando de alta
//
// Los dos logins ya respetan `users.active`, pero eso sólo cierra la puerta de
// entrada: a alguien que ya está adentro darle de baja no lo saca, y su sesión
// dura ocho horas. O sea que la baja tardaría una jornada entera en hacerse
// efectiva, que es justo lo contrario de para qué se da de baja a alguien.
//
// No se consulta en cada request: la respuesta se guarda en la sesión por un
// minuto, como ya se hace con la fila de la empresa (core/db/empresa_cache.php).
// Un minuto de demora es aceptable para una baja; una consulta extra en cada
// carga de cada pantalla, no.
// ============================================================
if (!in_array($current_file, ['login.php', 'select_empresa.php', 'logout.php'], true)
    && !empty($_SESSION['user_id'])
) {
    $_ahora  = time();
    $_cache  = $_SESSION['_activo'] ?? null;
    $_vigente = is_array($_cache)
        && (int)($_cache['id'] ?? 0) === (int)$_SESSION['user_id']
        && ($_ahora - (int)($_cache['ts'] ?? 0)) < 60;

    if (!$_vigente) {
        $_sigue = true;   // Ante la duda no se echa a nadie: un error de base
                          // no es una baja. Ya hay pantalla para eso más arriba.
        try {
            $_st = $conn->prepare("SELECT active FROM " . tn('users') . " WHERE id = ?");
            $_st->bind_param('i', $_SESSION['user_id']);
            $_st->execute();
            $_row = $_st->get_result()->fetch_assoc();
            $_st->close();
            // Sin fila: el usuario fue eliminado mientras tenía la sesión abierta.
            $_sigue = $_row !== null && (int)($_row['active'] ?? 1) === 1;
        } catch (Throwable $e) {
            log_event('db.php', 'WARN', 'No se pudo verificar si la cuenta sigue activa: '
                . $e->getMessage(), 'seguridad');
        }

        if (!$_sigue) {
            log_event('db.php', 'SESION_CERRADA',
                "user_id={$_SESSION['user_id']} dado de baja o eliminado: sesión cerrada", 'seguridad');
            $_cid = (int)($_SESSION['company_id'] ?? 0);
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
            require_once ROOT_PATH . '/core/tenants/tenant_url_helper.php';
            header('Location: ' . tenant_login_url($_cid, [
                'err' => 'Tu cuenta ya no está habilitada en esta empresa.',
            ]));
            exit();
        }

        $_SESSION['_activo'] = ['id' => (int)$_SESSION['user_id'], 'ts' => $_ahora];
    }
}
