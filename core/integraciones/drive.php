<?php
/**
 * Helpers de Google Drive para el tenant activo.
 *
 * El bootstrap del cliente de Drive (leer cloud_config, armar Google\Client,
 * refrescar el token) estaba copiado en edit.php, delete.php, upload_to_drive.php,
 * renombrar.php y sobrescribir.php, con variantes distintas en cada uno. Acá vive
 * una sola vez.
 *
 * Todo devuelve null en vez de tirar excepción: que Drive no esté configurado o
 * que el token haya vencido nunca debe impedir trabajar con el inventario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/platform.php';

if (!defined('DRIVE_CARPETA_ARCHIVADOS')) {
    define('DRIVE_CARPETA_ARCHIVADOS', 'Archivados');
}

if (!function_exists('drive_q')) {
/** Escapa un valor para interpolarlo en una query `q` de la API de Drive. */
function drive_q(string $v): string {
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $v);
}
}

if (!function_exists('drive_config_tenant')) {
/** Lee la config de Google del tenant, con la de plataforma como respaldo. */
function drive_config_tenant(mysqli $conn): array {
    $cfg = [
        'client_id'      => GOOGLE_CLIENT_ID,
        'client_secret'  => GOOGLE_CLIENT_SECRET,
        'redirect_uri'   => GOOGLE_REDIRECT_URI ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http')
                             . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php'),
        'root_folder_id' => '',
    ];
    try {
        $t = tn('cloud_config');
        $r = $conn->query("SELECT config_key, config_value FROM `{$t}` WHERE provider = 'google'");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if (array_key_exists($row['config_key'], $cfg)) {
                    $cfg[$row['config_key']] = (string)$row['config_value'];
                }
            }
        }
    } catch (Throwable) {}
    return $cfg;
}
}

if (!function_exists('drive_servicio')) {
/**
 * Devuelve un Google\Service\Drive listo para usar, o null si no se puede.
 * Cachea por request: varias operaciones seguidas no rehacen el handshake.
 */
function drive_servicio(mysqli $conn): ?Google\Service\Drive {
    static $cache = null;
    static $intentado = false;
    if ($intentado) { return $cache; }
    $intentado = true;

    if (empty($_SESSION['access_token'])) { return null; }

    $cfg = drive_config_tenant($conn);
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') { return null; }

    try {
        $c = new Google\Client();
        $c->setClientId($cfg['client_id']);
        $c->setClientSecret($cfg['client_secret']);
        $c->setRedirectUri($cfg['redirect_uri']);
        $c->setAccessToken($_SESSION['access_token']);

        if ($c->isAccessTokenExpired()) {
            $rt = $c->getRefreshToken();
            if (!$rt) { return null; }
            $_SESSION['access_token'] = $c->fetchAccessTokenWithRefreshToken($rt);
        }

        $cache = new Google\Service\Drive($c);
        return $cache;
    } catch (Throwable) {
        return null;
    }
}
}

if (!function_exists('drive_primer')) {
/**
 * Primer resultado de un listado, o null.
 *
 * `Google\Service\Drive\FileList` extiende `Google\Collection`, que implementa
 * Iterator y Countable pero NO ArrayAccess: hacer `$lista[0]` lanza un error
 * fatal (HTTP 500). Este helper existe para que nadie vuelva a caer en eso.
 */
function drive_primer($lista): ?Google\Service\Drive\DriveFile {
    if (!$lista) { return null; }
    $f = $lista->getFiles();
    return $f ? $f[0] : null;
}
}

if (!function_exists('drive_buscar_o_crear_carpeta')) {
/** Busca una carpeta por nombre dentro de un padre; la crea si no existe. */
function drive_buscar_o_crear_carpeta(Google\Service\Drive $svc, string $nombre, string $padreId): ?string {
    try {
        $qn = drive_q($nombre);
        $qp = drive_q($padreId);
        $lista = $svc->files->listFiles([
            'q'      => "name = '{$qn}' and mimeType = 'application/vnd.google-apps.folder'"
                      . " and '{$qp}' in parents and trashed = false",
            'fields' => 'files(id, name)',
            'spaces' => 'drive',
        ]);
        $encontrada = drive_primer($lista);
        if ($encontrada) { return $encontrada->getId(); }

        $meta = new Google\Service\Drive\DriveFile([
            'name'     => $nombre,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$padreId],
        ]);
        return $svc->files->create($meta, ['fields' => 'id'])->id;
    } catch (Throwable) {
        return null;
    }
}
}

if (!function_exists('drive_padres')) {
/** IDs de las carpetas padre de un archivo. */
function drive_padres(Google\Service\Drive $svc, string $fileId): array {
    try {
        $f = $svc->files->get($fileId, ['fields' => 'parents']);
        return $f->getParents() ?: [];
    } catch (Throwable) {
        return [];
    }
}
}

if (!function_exists('drive_mover')) {
/**
 * Mueve un archivo/carpeta a otro padre.
 *
 * @return string|null El ID del padre anterior (para poder deshacer), o null si falló.
 */
function drive_mover(Google\Service\Drive $svc, string $fileId, string $nuevoPadreId): ?string {
    try {
        $padresPrevios = drive_padres($svc, $fileId);
        $previo = $padresPrevios[0] ?? null;
        if ($previo === $nuevoPadreId) { return $previo; }

        $svc->files->update($fileId, new Google\Service\Drive\DriveFile(), [
            'addParents'    => $nuevoPadreId,
            'removeParents' => implode(',', $padresPrevios),
            'fields'        => 'id, parents',
        ]);
        return $previo;
    } catch (Throwable) {
        return null;
    }
}
}

if (!function_exists('drive_renombrar')) {
/** Renombra un archivo/carpeta. Devuelve el nombre anterior o null. */
function drive_renombrar(Google\Service\Drive $svc, string $fileId, string $nuevoNombre): ?string {
    try {
        $actual = $svc->files->get($fileId, ['fields' => 'name'])->getName();
        if ($actual === $nuevoNombre) { return $actual; }
        $svc->files->update($fileId, new Google\Service\Drive\DriveFile(['name' => $nuevoNombre]));
        return $actual;
    } catch (Throwable) {
        return null;
    }
}
}

if (!function_exists('drive_archivar_carpeta')) {
/**
 * Manda la carpeta de un activo a la carpeta "Archivados" del tenant y le
 * antepone la marca de archivado al nombre.
 *
 * @return array{ok:bool, padre_previo:?string, mensaje:string}
 */
function drive_archivar_carpeta(mysqli $conn, string $carpetaId, string $fecha): array {
    $svc = drive_servicio($conn);
    if (!$svc || $carpetaId === '') {
        return ['ok' => false, 'padre_previo' => null, 'mensaje' => ''];
    }

    $cfg  = drive_config_tenant($conn);
    $root = $cfg['root_folder_id'];
    if ($root === '') {
        return ['ok' => false, 'padre_previo' => null, 'mensaje' => ''];
    }

    $archivados = drive_buscar_o_crear_carpeta($svc, DRIVE_CARPETA_ARCHIVADOS, $root);
    if (!$archivados) {
        return ['ok' => false, 'padre_previo' => null, 'mensaje' => ''];
    }

    $previo = drive_mover($svc, $carpetaId, $archivados);
    if ($previo === null) {
        return ['ok' => false, 'padre_previo' => null, 'mensaje' => ''];
    }

    $nombre = $svc->files->get($carpetaId, ['fields' => 'name'])->getName();
    if (!str_starts_with($nombre, '[ARCHIVADO')) {
        drive_renombrar($svc, $carpetaId, '[ARCHIVADO ' . $fecha . '] ' . $nombre);
    }

    return [
        'ok'           => true,
        'padre_previo' => $previo,
        'mensaje'      => ' La carpeta se movió a «' . DRIVE_CARPETA_ARCHIVADOS . '» en Drive.',
    ];
}
}

if (!function_exists('drive_restaurar_carpeta')) {
/**
 * Devuelve la carpeta del activo a donde estaba y le saca la marca de archivado.
 * Si no se guardó el padre previo, la deja en la raíz del tenant.
 */
function drive_restaurar_carpeta(mysqli $conn, string $carpetaId, ?string $padrePrevio): array {
    $svc = drive_servicio($conn);
    if (!$svc || $carpetaId === '') {
        return ['ok' => false, 'mensaje' => ''];
    }

    $destino = $padrePrevio ?: drive_config_tenant($conn)['root_folder_id'];
    if (!$destino) {
        return ['ok' => false, 'mensaje' => ''];
    }

    if (drive_mover($svc, $carpetaId, $destino) === null) {
        return ['ok' => false, 'mensaje' => ''];
    }

    try {
        $nombre = $svc->files->get($carpetaId, ['fields' => 'name'])->getName();
        // Sacar el prefijo "[ARCHIVADO aaaa-mm-dd] " que puso el archivado.
        $limpio = preg_replace('/^\[ARCHIVADO[^\]]*\]\s*/u', '', $nombre);
        if ($limpio !== $nombre) { drive_renombrar($svc, $carpetaId, $limpio); }
    } catch (Throwable) {}

    return ['ok' => true, 'mensaje' => ' La carpeta volvió a su lugar en Drive.'];
}
}
