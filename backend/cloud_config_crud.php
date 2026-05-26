<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../logs/logger.php';

// --- Solo admins ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    header('Location: /auth/login.php');
    exit();
}
$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
if (!$me || $me['role'] !== 'admin') {
    header('Location: /inventario/index.php');
    exit();
}

// --- Solo POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inventario/configurar_integracion.php');
    exit();
}

csrf_or_fail('cloud_config', false);

$action   = $_POST['action']   ?? '';
$provider = $_POST['provider'] ?? '';

if (!in_array($provider, ['google', 'microsoft'], true)) {
    header('Location: ../configurar_integracion.php?msg=' . urlencode('Proveedor inválido.') . '&type=error');
    exit();
}

$configTable = tn('cloud_config');

function save_config(mysqli $conn, string $table, string $provider, string $key, string $value): void {
    $stmt = $conn->prepare(
        "INSERT INTO `{$table}` (provider, config_key, config_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
    );
    $stmt->bind_param('sss', $provider, $key, $value);
    $stmt->execute();
    $stmt->close();
}

function load_config(mysqli $conn, string $table, string $provider): array {
    $stmt = $conn->prepare("SELECT config_key, config_value FROM `{$table}` WHERE provider = ?");
    $stmt->bind_param('s', $provider);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[$row['config_key']] = $row['config_value'];
    }
    $stmt->close();
    return $out;
}

function redirect_cfg(string $msg, string $type = 'success'): never {
    header('Location: /inventario/configurar_integracion.php?msg=' . urlencode($msg) . '&type=' . $type);
    exit();
}

// ============================================================
switch ($action) {

    // ---- Guardar Google Drive ----
    case 'save_google':
        $mode = $_POST['google_mode'] ?? 'form'; // 'form' o 'json'

        if ($mode === 'json') {
            // Usuario pega/sube el JSON completo de credenciales OAuth
            $jsonRaw = '';
            if (!empty($_FILES['credentials_file']['tmp_name'])) {
                $jsonRaw = file_get_contents($_FILES['credentials_file']['tmp_name']);
            } elseif (!empty($_POST['credentials_json'])) {
                $jsonRaw = trim($_POST['credentials_json']);
            }
            if ($jsonRaw === '') {
                redirect_cfg('Tenés que pegar el JSON o subir el archivo.', 'error');
            }
            $decoded = json_decode($jsonRaw, true);
            if (!$decoded) {
                redirect_cfg('JSON inválido. Verificá el formato.', 'error');
            }
            // Soporta formato "web" y "installed" de Google
            $creds = $decoded['web'] ?? $decoded['installed'] ?? $decoded;
            $clientId     = $creds['client_id']     ?? '';
            $clientSecret = $creds['client_secret'] ?? '';
            $redirectUri  = $creds['redirect_uris'][0] ?? '';
            if (!$clientId || !$clientSecret) {
                redirect_cfg('El JSON no contiene client_id o client_secret válidos.', 'error');
            }
            save_config($conn, $configTable, 'google', 'client_id',     $clientId);
            save_config($conn, $configTable, 'google', 'client_secret', $clientSecret);
            if ($redirectUri) {
                save_config($conn, $configTable, 'google', 'redirect_uri', $redirectUri);
            }
        } else {
            // Formulario manual
            $clientId     = trim($_POST['google_client_id']     ?? '');
            $clientSecret = trim($_POST['google_client_secret'] ?? '');
            $redirectUri  = trim($_POST['google_redirect_uri']  ?? '');
            $rootFolder   = trim($_POST['google_root_folder']   ?? '');
            if (!$clientId || !$clientSecret) {
                redirect_cfg('Client ID y Client Secret son obligatorios.', 'error');
            }
            save_config($conn, $configTable, 'google', 'client_id',     $clientId);
            save_config($conn, $configTable, 'google', 'client_secret', $clientSecret);
            if ($redirectUri) {
                save_config($conn, $configTable, 'google', 'redirect_uri', $redirectUri);
            }
            if ($rootFolder) {
                save_config($conn, $configTable, 'google', 'root_folder_id', $rootFolder);
            }
        }

        log_event((string)$user_id, 'CLOUD_CONFIG', 'Credenciales Google guardadas', 'integracion');
        redirect_cfg('Credenciales de Google Drive guardadas correctamente.');

    // ---- Guardar Microsoft OneDrive ----
    case 'save_microsoft':
        $tenantId     = trim($_POST['ms_tenant_id']     ?? '');
        $clientId     = trim($_POST['ms_client_id']     ?? '');
        $clientSecret = trim($_POST['ms_client_secret'] ?? '');
        $redirectUri  = trim($_POST['ms_redirect_uri']  ?? '');
        $driveId      = trim($_POST['ms_drive_id']      ?? '');

        if (!$tenantId || !$clientId || !$clientSecret) {
            redirect_cfg('Tenant ID, Client ID y Client Secret son obligatorios.', 'error');
        }
        save_config($conn, $configTable, 'microsoft', 'tenant_id',     $tenantId);
        save_config($conn, $configTable, 'microsoft', 'client_id',     $clientId);
        save_config($conn, $configTable, 'microsoft', 'client_secret', $clientSecret);
        if ($redirectUri) {
            save_config($conn, $configTable, 'microsoft', 'redirect_uri', $redirectUri);
        }
        if ($driveId) {
            save_config($conn, $configTable, 'microsoft', 'drive_id', $driveId);
        }

        log_event((string)$user_id, 'CLOUD_CONFIG', 'Credenciales Microsoft guardadas', 'integracion');
        redirect_cfg('Credenciales de Microsoft OneDrive guardadas correctamente.');

    // ---- Limpiar config de un proveedor ----
    case 'clear':
        $stmt = $conn->prepare("DELETE FROM `{$configTable}` WHERE provider = ?");
        $stmt->bind_param('s', $provider);
        $stmt->execute();
        $stmt->close();
        log_event((string)$user_id, 'CLOUD_CONFIG', "Config {$provider} eliminada", 'integracion');
        redirect_cfg("Configuración de " . ucfirst($provider) . " eliminada.");

    default:
        redirect_cfg('Acción no reconocida.', 'error');
}
