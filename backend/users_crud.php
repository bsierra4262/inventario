<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';

// --- Autenticación y autorización ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    http_response_code(403);
    $cid_r = (int)($_SESSION['company_id'] ?? 0);
    header('Location: /auth/login.php' . ($cid_r ? '?company=' . $cid_r : ''));
    exit();
}

// Solo admins pueden gestionar usuarios
$usersTable = tn('users');
$stmt = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current || $current['role'] !== 'admin') {
    log_event((string)$user_id, 'ACCESO_DENEGADO', 'Intento de acceso a users_crud sin rol admin', 'seguridad');
    http_response_code(403);
    header('Location: /inventario/index.php');
    exit();
}

// --- Solo POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: ../usuarios.php');
    exit();
}

// --- CSRF ---
csrf_or_fail('users_crud', false);

$action = $_POST['action'] ?? '';

function redirect_users(string $msg, string $type = 'success'): never {
    header('Location: ../usuarios.php?msg=' . urlencode($msg) . '&type=' . $type);
    exit();
}

function validate_username(string $u): bool {
    return strlen($u) >= 3 && strlen($u) <= 120 && preg_match('/^[a-zA-Z0-9._\-]+$/', $u);
}

function validate_password(string $p): bool {
    return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/[0-9]/', $p);
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
        if (!validate_password($password)) {
            redirect_users('La contraseña debe tener al menos 8 caracteres con letras y números.', 'error');
        }
        if (!in_array($role, ['admin', 'manager', 'user', 'viewer'], true)) {
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

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $emailVal = $email !== '' ? $email : null;

        $ins = $conn->prepare("INSERT INTO `{$usersTable}` (username, password, email, role) VALUES (?, ?, ?, ?)");
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
        if (!in_array($role, ['admin', 'manager', 'user', 'viewer'], true)) {
            redirect_users('Rol inválido.', 'error');
        }

        // No puede quitarse el rol admin a sí mismo (evita lockout)
        if ($target_id === $user_id && $role !== 'admin') {
            redirect_users('No podés quitarte el rol de admin a vos mismo.', 'error');
        }

        // Verificar que el target existe
        $chk = $conn->prepare("SELECT id, username FROM `{$usersTable}` WHERE id = ?");
        $chk->bind_param('i', $target_id);
        $chk->execute();
        $target = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$target) {
            redirect_users('Usuario no encontrado.', 'error');
        }

        $emailVal = $email !== '' ? $email : null;

        if ($password !== '') {
            // Con cambio de contraseña
            if (!validate_password($password)) {
                redirect_users('La contraseña debe tener al menos 8 caracteres con letras y números.', 'error');
            }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE `{$usersTable}` SET email = ?, role = ?, password = ? WHERE id = ?");
            $upd->bind_param('sssi', $emailVal, $role, $hashed, $target_id);
        } else {
            // Sin cambio de contraseña
            $upd = $conn->prepare("UPDATE `{$usersTable}` SET email = ?, role = ? WHERE id = ?");
            $upd->bind_param('ssi', $emailVal, $role, $target_id);
        }

        if ($upd->execute()) {
            log_event((string)$user_id, 'EDITAR_USUARIO', "Usuario ID {$target_id} ('{$target['username']}') actualizado", 'usuarios');
            redirect_users("Usuario '{$target['username']}' actualizado correctamente.");
        } else {
            redirect_users('Error al actualizar el usuario.', 'error');
        }

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
