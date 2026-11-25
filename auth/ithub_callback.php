<?php
/**
 * auth/ithub_callback.php — Vuelta de ITHub al inventario de la empresa ITH
 * (OpenID Connect, código + PKCE). Ver core/security/ithub.php.
 *
 * Valida el state, canjea el código por los tokens (servidor a servidor, con
 * el secreto del cliente `inventario`), valida la firma y los claims del
 * id_token, busca o crea el usuario en la tabla `users` del tenant ITH con el
 * rol que manda ITHub e inicia la sesión. Guarda el `sid` (para el aviso de
 * cierre) y el id_token (para salir sin que ITHub pregunte).
 *
 * El acceso y el rol los decide ITHub: si la persona estaba dada de baja en
 * el inventario pero ITHub le da el inventario, queda de alta con ese rol.
 *
 * Si ITHub contesta access_denied es que la persona no tiene el inventario
 * habilitado: ITHub ya le mostró su «No tenés acceso», acá sólo se explica.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_guard.php';
require_once __DIR__ . '/../core/security/ithub.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';
require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';

app_session_start();

if (is_file(__DIR__ . '/../logs/logger.php')) { require_once __DIR__ . '/../logs/logger.php'; }
if (!function_exists('log_event')) {
    function log_event($origin, $type, $msg, $tag = 'auth') {}
}

/** Vuelve al login de ITH mostrando el motivo (con ?manual=1 no rebota a ITHub). */
function ithub_cb_error(int $cid, string $msg): never {
    app_session_cerrar_y_redirigir(tenant_login_url($cid, ['manual' => 1, 'err' => $msg]));
}

if (!ithub_habilitado('inventario')) {
    app_session_cerrar_y_redirigir('/auth/login.php');
}

[$stateOk, $nonce, $verificador] = ithub_tomar_estado('inventario', (string)($_GET['state'] ?? ''));

try {
    $cid = ithub_empresa_id();
} catch (Throwable $e) {
    log_event('ithub', 'ERROR', 'Callback ITHub: la master no contestó: ' . $e->getMessage(), 'auth');
    ithub_cb_error(0, 'No se pudo completar el ingreso en este momento. Volvé a intentar.');
}
if ($cid <= 0) {
    ithub_cb_error(0, 'La empresa que entra con ITHub no está activa. Avisale al administrador del sistema.');
}

if (!$stateOk) {
    ithub_cb_error($cid, 'La validación de seguridad con ITHub falló. Volvé a intentar.');
}
if (isset($_GET['error'])) {
    ithub_cb_error($cid, ($_GET['error'] ?? '') === 'access_denied'
        ? 'Tu usuario de ITHub no tiene acceso al inventario. Si lo necesitás, pedíselo a un administrador de ITHub.'
        : 'No se pudo completar el ingreso con ITHub.');
}

$tok = ithub_canjear_codigo('inventario', (string)($_GET['code'] ?? ''), $verificador);
try {
    $claims = ithub_validar_jwt((string)($tok['id_token'] ?? ''), 'inventario', $nonce);
} catch (Throwable $e) {
    log_event('ithub', 'ERROR', 'id_token de ITHub inválido: ' . $e->getMessage()
        . (isset($tok['error']) ? ' (' . (string)$tok['error'] . ')' : ''), 'auth');
    ithub_cb_error($cid, 'No se pudo validar el ingreso con ITHub. Volvé a intentar.');
}

$email  = ithub_email_de($claims);
$rol    = (string)($claims['rol'] ?? '');
$nombre = trim((string)($claims['name'] ?? '')) ?: $email;
$foto   = (string)($claims['picture'] ?? '');
if ($email === '') {
    ithub_cb_error($cid, 'ITHub no mandó un email válido. Avisale al administrador.');
}

// ── Usuario local en el tenant ITH, con el rol de ITHub ─────────────────────
try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($cid);
    $usersTable = $pf . 'users';

    // Roles que existen en ITH: los cuatro de siempre más los personalizados.
    $rolesValidos = TENANT_ROLES;
    $rc = @$tconn->query("SELECT role_key FROM `{$pf}custom_roles`");
    if ($rc) { while ($r = $rc->fetch_row()) { $rolesValidos[] = (string)$r[0]; } }
    if (!in_array($rol, $rolesValidos, true)) {
        log_event($email, 'LOGIN RECHAZADO', "ITHub mandó un rol que el inventario no conoce: {$rol}", 'auth');
        ithub_cb_error($cid, 'Tu rol en ITHub no corresponde a ninguno del inventario. Avisale al administrador.');
    }

    $st = $tconn->prepare("SELECT id, role, active FROM `{$usersTable}` WHERE email = ? LIMIT 1");
    $st->bind_param('s', $email);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    if ($u === null) {
        // Primera vez: se crea. La contraseña es aleatoria y nadie la conoce:
        // para ITH el formulario de usuario y contraseña ya no se usa.
        $username = ithub_usuario_nuevo($email, static function (string $n) use ($tconn, $usersTable): bool {
            $q = $tconn->prepare("SELECT 1 FROM `{$usersTable}` WHERE username = ? LIMIT 1");
            $q->bind_param('s', $n);
            $q->execute();
            $hay = $q->get_result()->fetch_row() !== null;
            $q->close();
            return $hay;
        });
        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        $st = $tconn->prepare("INSERT INTO `{$usersTable}` (username, password, email, role, active, must_change_password)
                               VALUES (?, ?, ?, ?, 1, 0)");
        $st->bind_param('ssss', $username, $hash, $email, $rol);
        $st->execute();
        $uid = (int)$tconn->insert_id;
        $st->close();
        log_event($email, 'USUARIO_ITHUB', "Alta desde ITHub: uid={$uid} rol={$rol}", 'auth');
    } else {
        $uid = (int)$u['id'];
        if ((string)$u['role'] !== $rol || (int)$u['active'] !== 1) {
            $st = $tconn->prepare("UPDATE `{$usersTable}` SET role = ?, active = 1 WHERE id = ?");
            $st->bind_param('si', $rol, $uid);
            $st->execute();
            $st->close();
            log_event($email, 'USUARIO_ITHUB',
                "Rol y estado tomados de ITHub: {$u['role']}/" . (int)$u['active'] . " → {$rol}/1", 'auth');
        }
    }
} catch (Throwable $e) {
    log_event($email, 'ERROR', 'Callback ITHub: ' . $e->getMessage(), 'auth');
    ithub_cb_error($cid, 'No se pudo completar el ingreso en este momento. Volvé a intentar.');
}

// ── Sesión ─────────────────────────────────────────────────────────────────
// Rotar el ID (anti fijación) y descartar todo lo anterior, como los otros logins.
app_session_rotar();
$_SESSION['company_id']      = $cid;
$_SESSION['auth_company_id'] = $cid;   // backend/db.php la compara en cada pedido
$_SESSION['user_id']         = $uid;
$_SESSION['role']            = $rol;
$_SESSION['csrf_token']      = bin2hex(random_bytes(32));
$_SESSION['user_email']      = $email;
$_SESSION['user_name']       = $nombre;
$_SESSION['user_picture']    = $foto;
$_SESSION['ithub_cliente']   = 'inventario';
$_SESSION['ithub_sid']       = (string)($claims['sid'] ?? '');
$_SESSION['ithub_id_token']  = (string)$tok['id_token'];

log_event($email, 'TENANT_LOGIN', "uid={$uid} cid={$cid} provider=ithub tabla={$usersTable}", 'auth');

app_session_cerrar_y_redirigir('/inventario/index.php');
