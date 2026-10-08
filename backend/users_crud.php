<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/security/permissions_db.php';
require_once __DIR__ . '/../core/tenants/tenant_settings.php';

// --- Autenticación y autorización ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    http_response_code(403);
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit();
}

// Solo admins pueden gestionar usuarios
$usersTable = tn('users');
$stmt = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current || !user_can('user.edit', $current)) {
    log_event((string)$user_id, 'ACCESO_DENEGADO', 'Intento de acceso a users_crud sin permiso user.edit', 'seguridad');
    http_response_code(403);
    header('Location: /inventario/index.php');
    exit();
}

// Migracion lazy: asegurar VARCHAR(40) en columna role (empresas viejas tienen ENUM)
// Robusto: chequear schema actual y solo hacer ALTER si es ENUM
try {
    $colInfo = $conn->query("SHOW COLUMNS FROM `{$usersTable}` LIKE 'role'");
    if ($colInfo && $row = $colInfo->fetch_assoc()) {
        $colType = strtolower((string)$row['Type']);
        if (str_starts_with($colType, 'enum')) {
            $alterOk = $conn->query("ALTER TABLE `{$usersTable}` MODIFY COLUMN `role` VARCHAR(40) NOT NULL DEFAULT 'user'");
            if ($alterOk) {
                log_event((string)$user_id, 'MIGRATION', "Columna role migrada de ENUM a VARCHAR(40) en {$usersTable}", 'usuarios');
            } else {
                log_event((string)$user_id, 'MIGRATION_ERROR', "Fallo ALTER role VARCHAR: " . $conn->error, 'usuarios');
            }
        }
    }
} catch (Throwable $e) {
    log_event((string)$user_id, 'MIGRATION_ERROR', "Exception en migracion role: " . $e->getMessage(), 'usuarios');
}

// --- Solo POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /inventario/usuarios.php');
    exit();
}

// --- CSRF ---
csrf_or_fail('users_crud', false);

$action = $_POST['action'] ?? '';

// Roles validos = builtin + custom del tenant (acepta cualquier rol asignable)
$valid_roles = array_keys(all_assignable_roles($conn));

function redirect_users(string $msg, string $type = 'success'): never {
    header('Location: /inventario/usuarios.php?msg=' . urlencode($msg) . '&type=' . $type);
    exit();
}

function validate_username(string $u): bool {
    return strlen($u) >= 3 && strlen($u) <= 120 && preg_match('/^[a-zA-Z0-9._\-]+$/', $u);
}

function validate_password(string $p): bool {
    return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/[0-9]/', $p);
}

/**
 * ¿Queda alguien de alta que pueda administrar usuarios, sin contar a `$excluido`?
 *
 * La misma idea que el anti-lockout del rol, pero para la baja: dar de baja al
 * único admin deja la empresa sin forma de volver a dar de alta a nadie —y la
 * pantalla de usuarios es justamente la que ya no se podría abrir—.
 */
function queda_otro_admin(mysqli $conn, string $tabla, int $excluido): bool {
    $st = $conn->prepare("SELECT role FROM `{$tabla}` WHERE active = 1 AND id <> ?");
    $st->bind_param('i', $excluido);
    $st->execute();
    $res = $st->get_result();
    $hay = false;
    while ($r = $res->fetch_assoc()) {
        if (role_can('user.edit', (string)$r['role'])) { $hay = true; break; }
    }
    $st->close();
    return $hay;
}

switch ($action) {

    // =========================================================
    case 'create':
    // =========================================================

        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'user';

        if (!validate_username($username)) {
            redirect_users('Nombre de usuario inválido (mín. 3 caracteres, sin espacios).', 'error');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect_users('Email inválido.', 'error');
        }
        /* En una empresa que entra SOLO con Google o Microsoft, la llave es el
           correo: `auth/superadmin_callback.php` busca `WHERE email = ?` en esta
           tabla y, si no encuentra nada, rechaza. La contraseña no se usa nunca.

           Antes era al revés: la contraseña obligatoria y el correo opcional. Se
           podía crear un usuario sin correo —que no iba a poder entrar jamás, sin
           que nada lo dijera— y había que inventarle una contraseña que no servía
           para nada. */
        $soloSSO = tenant_local_login_disabled($conn);

        if ($soloSSO) {
            if ($email === '') {
                redirect_users('Hace falta el correo: es lo único que le da acceso a esta empresa, '
                    . 'porque acá se entra con Google o Microsoft.', 'error');
            }
            /* Una contraseña que nadie va a usar, pero la columna no admite nulos
               y dejarla vacía abriría la puerta si mañana se habilita el acceso
               con contraseña. Aleatoria y descartada. */
            $password = bin2hex(random_bytes(24));
        } elseif (!validate_password($password)) {
            redirect_users('La contraseña debe tener al menos 8 caracteres con letras y números.', 'error');
        }

        if (!in_array($role, $valid_roles, true)) {
            $role = 'user';
        }

        // Verificar username único
        $chk = $conn->prepare("SELECT id FROM `{$usersTable}` WHERE username = ?");
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            redirect_users("El usuario '{$username}' ya existe.", 'error');
        }
        $chk->close();

        /* Y el correo también único: el login hace `WHERE email = ? LIMIT 1`, así
           que dos filas con el mismo correo significan que entra una de las dos y
           nadie sabe cuál —ni con qué rol—. */
        if ($email !== '') {
            $chkE = $conn->prepare("SELECT username FROM `{$usersTable}` WHERE email = ? LIMIT 1");
            $chkE->bind_param('s', $email);
            $chkE->execute();
            $dup = $chkE->get_result()->fetch_assoc();
            $chkE->close();
            if ($dup) {
                redirect_users("Ese correo ya lo usa '{$dup['username']}'. "
                    . 'Dos usuarios con el mismo correo no pueden entrar: el sistema no sabría cuál es.', 'error');
            }
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $emailVal = $email !== '' ? $email : null;

        /* `active = 1` explícito y no por el DEFAULT de la columna: así la fila
           queda bien aunque la base venga de una instalación vieja donde la
           columna se agregó después (migración 11). */
        $ins = $conn->prepare("INSERT INTO `{$usersTable}` (username, password, email, role, active)
                               VALUES (?, ?, ?, ?, 1)");
        $ins->bind_param('ssss', $username, $hashed, $emailVal, $role);

        if ($ins->execute()) {
            log_event((string)$user_id, 'CREAR_USUARIO', "Usuario '{$username}' creado con rol '{$role}'", 'usuarios');
            redirect_users("Usuario '{$username}' creado correctamente.");
        } else {
            log_event((string)$user_id, 'ERROR', 'Error al crear usuario: ' . $ins->error, 'usuarios');
            redirect_users('Error al crear el usuario.', 'error');
        }

    // =========================================================
    case 'edit':
    // =========================================================

        $target_id = (int)($_POST['target_id'] ?? 0);
        $email     = trim($_POST['email'] ?? '');
        $role      = $_POST['role'] ?? '';
        $password  = $_POST['password'] ?? '';

        if ($target_id <= 0) {
            redirect_users('ID de usuario inválido.', 'error');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect_users('Email inválido.', 'error');
        }
        if (!in_array($role, $valid_roles, true)) {
            redirect_users('Rol inválido.', 'error');
        }

        // Anti-lockout: no puede asignarse a si mismo un rol que no tenga user.edit
        if ($target_id === $user_id && !role_can('user.edit', $role)) {
            redirect_users('No podés asignarte un rol que pierda el permiso de editar usuarios.', 'error');
        }

        // Verificar que el target existe
        $chk = $conn->prepare("SELECT id, username, active FROM `{$usersTable}` WHERE id = ?");
        $chk->bind_param('i', $target_id);
        $chk->execute();
        $target = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$target) {
            redirect_users('Usuario no encontrado.', 'error');
        }

        /* Lo mismo que al crear: en una empresa que entra sólo con Google o
           Microsoft, sacarle el correo a alguien es sacarle el acceso, y dejar
           dos usuarios con el mismo correo deja el login sin saber cuál es. */
        if (tenant_local_login_disabled($conn) && $email === '') {
            redirect_users("Si le sacás el correo, «{$target['username']}» no va a poder entrar: "
                . 'en esta empresa se entra con Google o Microsoft.', 'error');
        }
        if ($email !== '') {
            $chkE = $conn->prepare("SELECT username FROM `{$usersTable}` WHERE email = ? AND id <> ? LIMIT 1");
            $chkE->bind_param('si', $email, $target_id);
            $chkE->execute();
            $dup = $chkE->get_result()->fetch_assoc();
            $chkE->close();
            if ($dup) {
                redirect_users("Ese correo ya lo usa '{$dup['username']}'. "
                    . 'Dos usuarios con el mismo correo no pueden entrar: el sistema no sabría cuál es.', 'error');
            }
        }

        $emailVal = $email !== '' ? $email : null;

        /* El estado viene del switch del modal. Un `checkbox` sin marcar no
           manda nada, así que la ausencia es «de baja» —y por eso el modal tiene
           que incluir siempre el switch, aunque esté marcado—. */
        $activo = isset($_POST['active']) ? 1 : 0;

        if ($target_id === $user_id && $activo === 0) {
            redirect_users('No podés darte de baja a vos mismo: te quedarías afuera en el próximo minuto.', 'error');
        }
        if ($activo === 0 && (int)($target['active'] ?? 1) === 1
            && !queda_otro_admin($conn, $usersTable, $target_id)
        ) {
            redirect_users("Si das de baja a «{$target['username']}» no queda nadie de alta que pueda "
                . 'administrar usuarios, y nadie podría volver a dar de alta a nadie.', 'error');
        }

        if ($password !== '') {
            // Con cambio de contraseña
            if (!validate_password($password)) {
                redirect_users('La contraseña debe tener al menos 8 caracteres con letras y números.', 'error');
            }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE `{$usersTable}` SET email = ?, role = ?, active = ?, password = ? WHERE id = ?");
            $upd->bind_param('ssisi', $emailVal, $role, $activo, $hashed, $target_id);
        } else {
            // Sin cambio de contraseña
            $upd = $conn->prepare("UPDATE `{$usersTable}` SET email = ?, role = ?, active = ? WHERE id = ?");
            $upd->bind_param('ssii', $emailVal, $role, $activo, $target_id);
        }

        if ($upd->execute()) {
            $affected = $upd->affected_rows;
            // Read-back: comparar lo intentado vs lo realmente guardado
            $check = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
            $check->bind_param('i', $target_id);
            $check->execute();
            $rowChk = $check->get_result()->fetch_assoc();
            $check->close();
            $savedRole = (string)($rowChk['role'] ?? '');

            // Detectar tipo de columna para diagnostico
            $colType = 'desconocido';
            $colInfo = $conn->query("SHOW COLUMNS FROM `{$usersTable}` LIKE 'role'");
            if ($colInfo && $cr = $colInfo->fetch_assoc()) { $colType = (string)$cr['Type']; }

            if ($savedRole !== $role) {
                $detail = "Rol intentado: '{$role}' | Rol guardado: '{$savedRole}' | Tipo de columna: {$colType} | Filas afectadas: {$affected}";
                log_event((string)$user_id, 'EDITAR_USUARIO_WARN', "Discrepancia rol usuario {$target['username']}: {$detail}", 'usuarios');
                redirect_users("El rol no se guardo bien. {$detail}", 'error');
            }
            log_event((string)$user_id, 'EDITAR_USUARIO', "Usuario ID {$target_id} ('{$target['username']}') actualizado a rol '{$role}' (tipo col: {$colType})", 'usuarios');
            redirect_users("Usuario '{$target['username']}' actualizado correctamente. Rol asignado: {$savedRole}.");
        } else {
            log_event((string)$user_id, 'EDITAR_USUARIO_ERROR', "Fallo UPDATE usuario {$target_id}: " . $upd->error, 'usuarios');
            redirect_users('Error al actualizar el usuario: ' . $upd->error, 'error');
        }

    // =========================================================
    case 'toggle_active':
    // =========================================================

        /* Dar de baja y reactivar con un botón, igual que en Catálogos. Eliminar
           no sirve para esto: se pierde el rol que tenía y, si la persona vuelve,
           hay que acordarse de todo de nuevo. */
        $target_id = (int)($_POST['target_id'] ?? 0);

        if ($target_id <= 0) {
            redirect_users('ID de usuario inválido.', 'error');
        }
        if ($target_id === $user_id) {
            redirect_users('No podés darte de baja a vos mismo: te quedarías afuera en el próximo minuto.', 'error');
        }

        $chk = $conn->prepare("SELECT username, active FROM `{$usersTable}` WHERE id = ?");
        $chk->bind_param('i', $target_id);
        $chk->execute();
        $target = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$target) {
            redirect_users('Usuario no encontrado.', 'error');
        }

        $nuevo = (int)($target['active'] ?? 1) === 1 ? 0 : 1;

        if ($nuevo === 0 && !queda_otro_admin($conn, $usersTable, $target_id)) {
            redirect_users("Si das de baja a «{$target['username']}» no queda nadie de alta que pueda "
                . 'administrar usuarios, y nadie podría volver a dar de alta a nadie.', 'error');
        }

        $upd = $conn->prepare("UPDATE `{$usersTable}` SET active = ? WHERE id = ?");
        $upd->bind_param('ii', $nuevo, $target_id);

        if ($upd->execute()) {
            log_event((string)$user_id, $nuevo === 1 ? 'REACTIVAR_USUARIO' : 'BAJA_USUARIO',
                "Usuario '{$target['username']}' " . ($nuevo === 1 ? 'reactivado' : 'dado de baja'), 'usuarios');
            redirect_users($nuevo === 1
                ? "«{$target['username']}» vuelve a poder entrar."
                : "«{$target['username']}» quedó de baja y no va a poder entrar.");
        }
        redirect_users('No se pudo cambiar el estado del usuario.', 'error');

    // =========================================================
    case 'delete':
    // =========================================================

        $target_id = (int)($_POST['target_id'] ?? 0);

        if ($target_id <= 0) {
            redirect_users('ID de usuario inválido.', 'error');
        }
        if ($target_id === $user_id) {
            redirect_users('No podés eliminar tu propia cuenta.', 'error');
        }

        $chk = $conn->prepare("SELECT username FROM `{$usersTable}` WHERE id = ?");
        $chk->bind_param('i', $target_id);
        $chk->execute();
        $target = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$target) {
            redirect_users('Usuario no encontrado.', 'error');
        }

        $del = $conn->prepare("DELETE FROM `{$usersTable}` WHERE id = ?");
        $del->bind_param('i', $target_id);

        if ($del->execute()) {
            log_event((string)$user_id, 'ELIMINAR_USUARIO', "Usuario '{$target['username']}' eliminado", 'usuarios');
            redirect_users("Usuario '{$target['username']}' eliminado.");
        } else {
            redirect_users('Error al eliminar el usuario.', 'error');
        }

    default:
        redirect_users('Acción no reconocida.', 'error');
}
