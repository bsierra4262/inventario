<?php
/**
 * auth/superadmin_ithub_callback.php — Vuelta de ITHub al panel SuperAdmin
 * (OpenID Connect, código + PKCE, cliente `inventario-superadmin`). Ver
 * core/security/ithub.php.
 *
 * Reemplaza al Google propio del panel cuando ITHUB_SA_CLIENT_ID y
 * ITHUB_SA_CLIENT_SECRET están configurados. Valida el token, busca (o crea)
 * la fila de `users_platform` por email, le aplica el rol de ITHub
 * (superadmin | admin | support | viewer) e inicia la sesión del panel.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/session_guard.php';
require_once __DIR__ . '/../core/security/ithub.php';

app_session_start();

/** Roles del panel. Los mismos que define platform_permissions_matrix(). */
const SA_ITHUB_ROLES = ['superadmin', 'admin', 'support', 'viewer'];

function sa_ithub_error(string $msg): never {
    $_SESSION['sa_login_error'] = $msg;
    app_session_cerrar_y_redirigir('/auth/superadmin_login.php?manual=1');
}

if (!ithub_habilitado('superadmin')) {
    app_session_cerrar_y_redirigir('/auth/superadmin_login.php');
}

[$stateOk, $nonce, $verificador] = ithub_tomar_estado('superadmin', (string)($_GET['state'] ?? ''));
if (!$stateOk) {
    sa_ithub_error('La validación de seguridad con ITHub falló. Volvé a intentar.');
}
if (isset($_GET['error'])) {
    sa_ithub_error(($_GET['error'] ?? '') === 'access_denied'
        ? 'Tu usuario de ITHub no tiene acceso al panel SuperAdmin. Si lo necesitás, pedíselo a un administrador de ITHub.'
        : 'No se pudo completar el ingreso con ITHub.');
}

$tok = ithub_canjear_codigo('superadmin', (string)($_GET['code'] ?? ''), $verificador);
try {
    $claims = ithub_validar_jwt((string)($tok['id_token'] ?? ''), 'superadmin', $nonce);
} catch (Throwable $e) {
    platform_log('SUPERADMIN ITHub: id_token inválido: ' . $e->getMessage()
        . (isset($tok['error']) ? ' (' . (string)$tok['error'] . ')' : ''));
    sa_ithub_error('No se pudo validar el ingreso con ITHub. Volvé a intentar.');
}

$email  = ithub_email_de($claims);
$rol    = (string)($claims['rol'] ?? '');
$nombre = trim((string)($claims['name'] ?? '')) ?: $email;
$foto   = (string)($claims['picture'] ?? '');
if ($email === '') {
    sa_ithub_error('ITHub no mandó un email válido.');
}
if (!in_array($rol, SA_ITHUB_ROLES, true)) {
    platform_log("SUPERADMIN ITHub: rol desconocido «{$rol}» para {$email}");
    sa_ithub_error('Tu rol en ITHub no corresponde a ninguno del panel. Avisale al administrador.');
}

try {
    $db = new MasterDB();
    $st = $db->pquery('SELECT id, role, active FROM users_platform WHERE email = ? LIMIT 1', 's', [$email]);
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    if ($u === null) {
        // Primera vez: se crea sin contraseña (el panel entra por ITHub).
        $username = ithub_usuario_nuevo($email, static function (string $n) use ($db): bool {
            $q = $db->pquery('SELECT 1 FROM users_platform WHERE username = ? LIMIT 1', 's', [$n]);
            $hay = $q->get_result()->fetch_row() !== null;
            $q->close();
            return $hay;
        });
        $db->pquery('INSERT INTO users_platform (username, password, email, role, active, last_login)
                     VALUES (?, NULL, ?, ?, 1, NOW())', 'sss', [$username, $email, $rol])->close();
        $uid = (int)$db->conn->insert_id;
        platform_log("SUPERADMIN ITHub: alta de {$email} con rol {$rol}");
    } else {
        $uid = (int)$u['id'];
        if ((string)$u['role'] !== $rol || (int)$u['active'] !== 1) {
            platform_log("SUPERADMIN ITHub: rol y estado de {$email} tomados de ITHub: {$u['role']}/"
                . (int)$u['active'] . " → {$rol}/1");
        }
        $db->pquery('UPDATE users_platform SET role = ?, active = 1, google_pic = ?, last_login = NOW() WHERE id = ?',
                    'ssi', [$rol, $foto !== '' ? $foto : null, $uid])->close();
    }
} catch (Throwable $e) {
    platform_log('SUPERADMIN ITHub: ' . $e->getMessage());
    sa_ithub_error('No se pudo completar el ingreso en este momento. Volvé a intentar.');
}

superadmin_login($uid, $rol);   // rota la sesión (anti fijación)
$_SESSION['superadmin_email'] = $email;
$_SESSION['superadmin_name']  = $nombre;
$_SESSION['superadmin_pic']   = $foto;
$_SESSION['ithub_cliente']    = 'superadmin';
$_SESSION['ithub_sid']        = (string)($claims['sid'] ?? '');
$_SESSION['ithub_id_token']   = (string)$tok['id_token'];

app_session_cerrar_y_redirigir('/dashboard/dashboard_resumen.php');
