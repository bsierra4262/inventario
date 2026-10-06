<?php
/**
 * Batería de regresión — Asset Vault
 *
 *   php test/run_tests.php            todo
 *   php test/run_tests.php seguridad  sólo ese grupo
 *
 * Regla del proyecto: cada defecto detectado incorpora un test que impide su
 * reaparición. Los grupos SEGURIDAD y CODIGO son, casi uno a uno, los agujeros
 * y los bugs que aparecieron en la auditoría del 2026-08-04.
 *
 * La batería NO necesita base de datos ni servidor: es análisis estático sobre
 * el árbol del repositorio, así que corre en cualquier máquina y en CI.
 */

declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

$GRUPO_PEDIDO = $argv[1] ?? '';

$PASS = 0; $FAIL = 0; $FALLOS = [];
$grupoActual = '';

function grupo(string $n): void {
    global $grupoActual, $GRUPO_PEDIDO;
    $grupoActual = $n;
    if ($GRUPO_PEDIDO !== '' && stripos($n, $GRUPO_PEDIDO) === false) { return; }
    echo "\n\033[1m── {$n} " . str_repeat('─', max(0, 60 - strlen($n))) . "\033[0m\n";
}

function chequear(string $desc, bool $ok, string $detalle = ''): void {
    global $PASS, $FAIL, $FALLOS, $grupoActual, $GRUPO_PEDIDO;
    if ($GRUPO_PEDIDO !== '' && stripos($grupoActual, $GRUPO_PEDIDO) === false) { return; }
    if ($ok) {
        $PASS++;
        echo "  \033[32m✓\033[0m {$desc}\n";
    } else {
        $FAIL++;
        $FALLOS[] = "[{$grupoActual}] {$desc}" . ($detalle ? " — {$detalle}" : '');
        echo "  \033[31m✗\033[0m {$desc}" . ($detalle ? "\n      \033[31m{$detalle}\033[0m" : '') . "\n";
    }
}

/** Todos los .php del proyecto, sin vendor. */
function archivos_php(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(RAIZ, FilesystemIterator::SKIP_DOTS),
            function ($f) {
                $n = $f->getFilename();
                if ($f->isDir()) { return !in_array($n, ['vendor', '.git', 'node_modules', 'logs', 'secure'], true); }
                return str_ends_with($n, '.php');
            }
        )
    );
    foreach ($it as $f) { $out[] = $f->getPathname(); }
    sort($out);
    $cache = $out;
    return $out;
}

function rel(string $p): string { return str_replace(RAIZ . DIRECTORY_SEPARATOR, '', $p); }
function leer(string $rel): string {
    $p = RAIZ . '/' . $rel;
    return is_file($p) ? (string)file_get_contents($p) : '';
}

/** Quita comentarios PHP preservando offsets, para no analizar código comentado. */
function sin_comentarios(string $src): string {
    $out = $src; $n = strlen($src); $i = 0; $enStr = false; $q = '';
    while ($i < $n) {
        $c = $src[$i];
        if ($enStr) {
            if ($c === '\\') { $i += 2; continue; }
            if ($c === $q) { $enStr = false; }
            $i++; continue;
        }
        if ($c === "'" || $c === '"') { $enStr = true; $q = $c; $i++; continue; }
        if ($c === '/' && $i + 1 < $n && $src[$i+1] === '/') {
            while ($i < $n && $src[$i] !== "\n") { $out[$i] = ' '; $i++; }
            continue;
        }
        if ($c === '#' && !($i + 1 < $n && $src[$i+1] === '[')) {
            while ($i < $n && $src[$i] !== "\n") { $out[$i] = ' '; $i++; }
            continue;
        }
        if ($c === '/' && $i + 1 < $n && $src[$i+1] === '*') {
            $j = strpos($src, '*/', $i + 2);
            $j = $j === false ? $n : $j + 2;
            for ($k = $i; $k < $j; $k++) { if ($out[$k] !== "\n") { $out[$k] = ' '; } }
            $i = $j; continue;
        }
        $i++;
    }
    return $out;
}

/** Argumentos de nivel superior de una lista ya sin los paréntesis externos. */
function partir_args(string $t): array {
    $args = []; $prof = 0; $act = ''; $enStr = false; $q = ''; $i = 0; $n = strlen($t);
    while ($i < $n) {
        $c = $t[$i];
        if ($enStr) {
            if ($c === '\\') { $act .= substr($t, $i, 2); $i += 2; continue; }
            if ($c === $q) { $enStr = false; }
            $act .= $c;
        } elseif ($c === "'" || $c === '"') { $enStr = true; $q = $c; $act .= $c; }
        elseif (in_array($c, ['(', '[', '{'], true)) { $prof++; $act .= $c; }
        elseif (in_array($c, [')', ']', '}'], true)) { $prof--; $act .= $c; }
        elseif ($c === ',' && $prof === 0) { $args[] = trim($act); $act = ''; }
        else { $act .= $c; }
        $i++;
    }
    if (trim($act) !== '') { $args[] = trim($act); }
    return $args;
}

function cuerpo_parentesis(string $src, int $abre): ?string {
    $prof = 0; $i = $abre; $n = strlen($src); $enStr = false; $q = '';
    while ($i < $n) {
        $c = $src[$i];
        if ($enStr) {
            if ($c === '\\') { $i += 2; continue; }
            if ($c === $q) { $enStr = false; }
        } elseif ($c === "'" || $c === '"') { $enStr = true; $q = $c; }
        elseif ($c === '(') { $prof++; }
        elseif ($c === ')') { $prof--; if ($prof === 0) { return substr($src, $abre + 1, $i - $abre - 1); } }
        $i++;
    }
    return null;
}

echo "\n\033[1;35m  Asset Vault · batería de regresión\033[0m\n";
echo "  " . RAIZ . "\n";

// ═══════════════════════════════════════════════════════════════════════════
grupo('SINTAXIS');
// ═══════════════════════════════════════════════════════════════════════════
// Se usa token_get_all(TOKEN_PARSE) en vez de `php -l`: en hosting compartido
// exec()/shell_exec() suelen estar deshabilitados, y la batería tiene que correr
// igual en el servidor que en la máquina de desarrollo.
$malos = [];
foreach (archivos_php() as $f) {
    try {
        token_get_all((string)file_get_contents($f), TOKEN_PARSE);
    } catch (ParseError $e) {
        $malos[] = rel($f) . ': ' . $e->getMessage() . ' (línea ' . $e->getLine() . ')';
    }
}
chequear(count(archivos_php()) . ' archivos PHP parsean sin error', $malos === [], implode('; ', $malos));

// ═══════════════════════════════════════════════════════════════════════════
grupo('CODIGO · bind_param');
// ═══════════════════════════════════════════════════════════════════════════
// El HTTP 500 al guardar una edición: edit.php declaraba 33 caracteres de tipos
// para 32 parámetros. En PHP 8 eso lanza ArgumentCountError (fatal). El mismo
// defecto estaba en import_csv.php y rompía la importación de CSV.
$desajustes = [];
$revisados = 0;
foreach (archivos_php() as $f) {
    $src = sin_comentarios((string)file_get_contents($f));
    if (!preg_match_all('/bind_param\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) { continue; }
    foreach ($m[0] as $hit) {
        $abre = $hit[1] + strlen($hit[0]) - 1;
        $cuerpo = cuerpo_parentesis($src, $abre);
        if ($cuerpo === null) { continue; }
        $args = partir_args($cuerpo);
        if (!$args) { continue; }
        if (!preg_match('/^([\'"])([sidb]+)\1$/', $args[0], $tm)) { continue; } // tipos dinámicos
        $revisados++;
        $resto = array_slice($args, 1);
        foreach ($resto as $a) { if (str_starts_with($a, '...')) { continue 2; } } // spread
        if (strlen($tm[2]) !== count($resto)) {
            $linea = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $desajustes[] = rel($f) . ":{$linea} (tipos=" . strlen($tm[2]) . ", args=" . count($resto) . ')';
        }
    }
}
chequear("{$revisados} bind_param con tipos literales: ninguno desajustado", $desajustes === [], implode('; ', $desajustes));

// ═══════════════════════════════════════════════════════════════════════════
grupo('CODIGO · Google Drive');
// ═══════════════════════════════════════════════════════════════════════════
// Google\Collection implementa Iterator y Countable, NO ArrayAccess: hacer
// $lista[0] lanza "Cannot use object of type ... as array" (fatal → HTTP 500).
$indexados = [];
foreach (archivos_php() as $f) {
    if (str_contains(rel($f), 'test' . DIRECTORY_SEPARATOR)) { continue; } // la propia regla vive acá
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match_all('/\$(\w*[Ff]ile\w*|\w*[Ff]older\w*|\w*[Ll]ist\w*)\s*\[\s*0\s*\]\s*->\s*getId/', $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $hit) {
            $indexados[] = rel($f) . ':' . (substr_count(substr($src, 0, $hit[1]), "\n") + 1);
        }
    }
}
chequear('Ningún $lista[0]->getId() sobre un resultado de Drive', $indexados === [],
    implode('; ', $indexados) . ' — usar ->getFiles()[0] o drive_primer()');

// Los nombres de archivo/carpeta no pueden interpolarse crudos en la query `q`.
$qCrudas = [];
foreach (archivos_php() as $f) {
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match_all("/'q'\s*=>\s*\"[^\"]*'\\\$(\w+)'/", $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[1] as $i => $var) {
            // Se aceptan las variables ya escapadas (prefijo q…) y los IDs de carpeta.
            if (preg_match('/^(q[A-Z]|.*[Ff]olderId$|.*_drive_id$|parentFolderId$)/', $var[0])) { continue; }
            $qCrudas[] = rel($f) . ':' . (substr_count(substr($src, 0, $mm[0][$i][1]), "\n") + 1) . ' ($' . $var[0] . ')';
        }
    }
}
chequear('Las queries `q` de Drive no interpolan valores sin escapar', $qCrudas === [], implode('; ', $qCrudas));

// ═══════════════════════════════════════════════════════════════════════════
grupo('SEGURIDAD · herramientas destructivas');
// ═══════════════════════════════════════════════════════════════════════════
// bay.php y reset_inventario_ith.php vivían en el web root y se autorizaban con
// str_starts_with($host,'desarrollo.'). Como el host productivo ES
// desarrollo.intellihelp.tech, cualquiera podía borrar la plataforma entera.
chequear('bay.php no está en el web root', !is_file(RAIZ . '/bay.php'));
chequear('reset_inventario_ith.php no está en el web root', !is_file(RAIZ . '/reset_inventario_ith.php'));

$gatesPorHost = [];
foreach (archivos_php() as $f) {
    if (str_contains(rel($f), 'test' . DIRECTORY_SEPARATOR)) { continue; }
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match('/str_starts_with\s*\(\s*\$host\s*,\s*[\'"]desarrollo\./', $src)
     || preg_match('/str_starts_with\s*\(\s*\$host\s*,\s*[\'"]dev\./', $src)) {
        $gatesPorHost[] = rel($f);
    }
}
chequear('Ninguna autorización depende del nombre del host', $gatesPorHost === [], implode(', ', $gatesPorHost));

foreach (['bin/reset_plataforma.php', 'bin/reset_inventario.php', 'bin/crear_superadmin.php', 'bin/migrar.php'] as $t) {
    $src = leer($t);
    chequear("{$t} sólo corre por CLI", $src !== '' && str_contains($src, "_cli.php"));
}
chequear('El guard de bin/ aborta si el SAPI no es cli',
    str_contains(leer('bin/_cli.php'), "PHP_SAPI !== 'cli'"));
chequear('Los resets exigen ASSET_VAULT_DEV',
    str_contains(leer('bin/reset_plataforma.php'), 'cli_requiere_dev')
 && str_contains(leer('bin/reset_inventario.php'), 'cli_requiere_dev'));

// ═══════════════════════════════════════════════════════════════════════════
grupo('SEGURIDAD · secretos');
// ═══════════════════════════════════════════════════════════════════════════
// La contraseña del master DB estaba escrita en core/config/platform.php, que
// está versionado; y el superadmin se re-sembraba con una contraseña fija desde
// una página pública. Las dos se purgaron del código y de la historia de git.
//
// El test NO guarda los secretos: los lee del archivo real, que no se versiona,
// y verifica que ninguno de esos valores aparezca en un archivo que sí se
// versiona. Escribir el secreto (o su hash) en el test sería volver a meterlo en
// el repositorio, que es justamente lo que este chequeo trata de impedir.
$secretosReales = [];
$fSec = RAIZ . '/secure/config/secrets.php';
if (is_file($fSec)) {
    $cargado = @include $fSec;
    if (is_array($cargado)) {
        foreach ($cargado as $k => $v) {
            // Sólo lo que de verdad es un secreto. `MASTER_DB_HOST = localhost` y
            // `MS_TENANT_ID = common` son configuración, y aparecen en el código
            // con toda razón.
            if (!preg_match('/(PASS|SECRET|TOKEN|_KEY)$/i', (string)$k)) { continue; }
            if (is_string($v) && strlen($v) >= 6 && !str_starts_with($v, 'CAMBIAR')) {
                $secretosReales[$v] = $k;
            }
        }
    }
}

if (!$secretosReales) {
    chequear('Credenciales reales fuera del repositorio (no hay secure/config/secrets.php para comparar)', true);
} else {
    $conSecretos = [];
    foreach (array_merge(archivos_php(), [RAIZ . '/.htaccess', RAIZ . '/deploy.sh', RAIZ . '/README.md',
                                          RAIZ . '/INSTALL.md', RAIZ . '/SECURITY.md']) as $f) {
        if (!is_file($f)) { continue; }
        $r = rel($f);
        if (str_starts_with($r, 'secure' . DIRECTORY_SEPARATOR)) { continue; } // ese es el archivo real
        $src = (string)file_get_contents($f);
        foreach ($secretosReales as $valor => $clave) {
            if (str_contains($src, $valor)) { $conSecretos[] = "{$r} filtra {$clave}"; }
        }
    }
    chequear(count($secretosReales) . ' credenciales reales: ninguna aparece en un archivo versionado',
        $conSecretos === [], implode('; ', array_unique($conSecretos)));
}

// Y ninguna credencial nueva escrita a mano en el código.
$hardcode = [];
foreach (archivos_php() as $f) {
    $r = rel($f);
    if (str_starts_with($r, 'test' . DIRECTORY_SEPARATOR)) { continue; }
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match('/define\s*\(\s*[\'"](?:MASTER_DB_PASS|DB_PASS|.*SECRET)[\'"]\s*,\s*[\'"][^\'"]{4,}[\'"]/i', $src)) {
        $hardcode[] = $r;
    }
    if (preg_match('/password_hash\s*\(\s*[\'"][^\'"]{6,}[\'"]/', $src)) {
        $hardcode[] = $r . ' (password_hash sobre un literal)';
    }
}
chequear('Ninguna credencial escrita a mano en el código', $hardcode === [], implode('; ', array_unique($hardcode)));

chequear('platform.php lee los secretos de secure/config, no los define',
    str_contains(leer('core/config/platform.php'), 'platform_secret')
 && !preg_match("/define\s*\(\s*'MASTER_DB_PASS'\s*,\s*'[^']+'/", leer('core/config/platform.php')));
chequear('secure/config/ está en .gitignore', str_contains(leer('.gitignore'), 'secure/config/'));
chequear('Existe la plantilla core/config/secrets.example.php', is_file(RAIZ . '/core/config/secrets.example.php'));

$autoSeed = [];
foreach (['auth/superadmin_login.php', 'install.php'] as $t) {
    if (preg_match('/INSERT\s+INTO\s+users_platform/i', sin_comentarios(leer($t)))) { $autoSeed[] = $t; }
}
chequear('Ninguna página pública crea el superadmin', $autoSeed === [], implode(', ', $autoSeed));
chequear('El alta de superadmin vive en bin/ y genera la contraseña al azar',
    str_contains(leer('bin/crear_superadmin.php'), 'random_int'));

// ───────────────────────────────────────────────────────────────────────────
// El historial de git, no sólo el árbol de trabajo.
//
// Acá estaba el punto ciego que dejó pasar una fuga real: el chequeo de arriba
// recorre los archivos que existen HOY. `auth/credentials.json` no existe hoy
// —se borró y está en .gitignore— pero sigue vivo en el primer commit, que es
// alcanzable desde main y desde los dos remotos. Borrar un archivo no lo saca
// de la historia, y el test daba verde igual.
//
// Se buscan patrones de secreto en los blobs de los archivos sensibles que
// alguna vez existieron. Los valores NO se escriben en este archivo ni se pasan
// por línea de comandos (serían visibles en la lista de procesos): se busca por
// forma, no por valor.
//
// Necesita git. En hosting compartido exec() está deshabilitado, así que cuando
// no se puede correr el chequeo se dice, en vez de dar un verde que no verificó
// nada.
$execDisponible = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);

if (!$execDisponible || !is_dir(RAIZ . '/.git')) {
    chequear('Historial de git sin secretos — NO VERIFICADO (sin git o sin exec en este entorno)', true);
} else {
    $git = static function (string $args) : array {
        $out = []; $code = 0;
        @exec('git -C ' . escapeshellarg(RAIZ) . ' ' . $args . ' 2>&1', $out, $code);
        return $code === 0 ? $out : [];
    };

    // Patrones por forma. Son los que identifican un secreto sin conocerlo.
    $patrones = [
        'client_secret de Google OAuth' => '/GOCSPX-[A-Za-z0-9_\-]{20,}/',
        'API key de Google'             => '/\bAIza[0-9A-Za-z_\-]{30,}\b/',
        'clave privada PEM'             => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        // El valor no puede contener `*` ni ser uno de los marcadores: la
        // contraseña de la master YA se purgó del historial y quedó como
        // '***SECRETO-PURGADO***'. Sin esta exclusión el chequeo reporta como
        // fuga justamente la prueba de que la fuga se arregló.
        'contraseña en define()'        => '/define\s*\(\s*[\'"](?:MASTER_DB_PASS|DB_PASS)[\'"]\s*,\s*[\'"](?!CAMBIAR|REMOVED|REDACTED|PURGADO)[^\'"*]{6,}[\'"]/i',
    ];

    // Fugas ya conocidas y asumidas. Baseline explícito: mantiene la batería en
    // verde sobre lo que ya se decidió no tocar, y falla con cualquier fuga
    // NUEVA. Para darla de baja: rotar la credencial y purgar el historial.
    //
    //   auth/credentials.json — client_secret de Google en el primer commit
    //   (e811ff7b). Repositorios privados; se decidió no rotar todavía porque
    //   la credencial está en uso. Registrado el 2026-10-06.
    $fugasAceptadas = ['auth/credentials.json'];

    $sensible = '/(secret|credential|password|token|\.env$|\.pem$|\.key$|\.p12$)/i';
    $altas = $git('log --all --pretty=format: --diff-filter=A --name-only');

    $candidatos = [];
    foreach ($altas as $p) {
        $p = trim($p);
        if ($p === '' || str_starts_with($p, 'vendor/')) { continue; }
        if (preg_match($sensible, $p)) { $candidatos[$p] = true; }
    }
    // platform.php guardaba la contraseña de la master y no matchea el patrón.
    $candidatos['core/config/platform.php'] = true;

    $fugas = [];
    foreach (array_keys($candidatos) as $path) {
        foreach ($git('log --all --format=%H -- ' . escapeshellarg($path)) as $commit) {
            $blob = implode("\n", $git('show ' . escapeshellarg($commit . ':' . $path)));
            if ($blob === '') { continue; }
            foreach ($patrones as $queEs => $re) {
                if (preg_match($re, $blob)) {
                    $fugas[$path] = "{$path} filtra {$queEs} (commit " . substr($commit, 0, 8) . ')';
                    break 2; // una por archivo alcanza para reportarlo
                }
            }
        }
    }

    $nuevas = array_diff_key($fugas, array_flip($fugasAceptadas));
    chequear(count($candidatos) . ' archivos sensibles del historial: ninguna fuga nueva',
        $nuevas === [], implode('; ', $nuevas));

    // Que el baseline no quede como letra muerta: si la fuga aceptada ya se
    // purgó, hay que sacarla de la lista para que el chequeo vuelva a ser real.
    $yaLimpias = array_diff($fugasAceptadas, array_keys($fugas));
    chequear('El baseline de fugas aceptadas no tiene entradas obsoletas',
        $yaLimpias === [], 'ya purgadas, sacar de $fugasAceptadas: ' . implode(', ', $yaLimpias));
}

// ═══════════════════════════════════════════════════════════════════════════
grupo('SEGURIDAD · sesión');
// ═══════════════════════════════════════════════════════════════════════════
// Ningún login rotaba el ID de sesión (fijación de sesión) y la cookie salía
// sin HttpOnly ni SameSite.
$sg = leer('core/security/session_guard.php');
chequear('Existe session_guard.php', $sg !== '');
chequear('app_session_rotar() regenera el ID', str_contains($sg, 'session_regenerate_id'));
chequear('La cookie se declara HttpOnly y SameSite', str_contains($sg, "'httponly' => true") && str_contains($sg, "'samesite' => 'Lax'"));

$ui = leer('.user.ini');
foreach ([
    'session.cookie_httponly = 1',
    'session.cookie_secure   = 1',
    'session.use_strict_mode = 1',
] as $dir) {
    chequear(".user.ini fija {$dir}", str_contains($ui, $dir));
}
chequear('.user.ini apaga display_errors', preg_match('/^display_errors\s*=\s*0/m', $ui) === 1);

foreach (['auth/login.php' => 'app_session_rotar', 'auth/superadmin_callback.php' => 'app_session_rotar',
          'core/security/session_role.php' => 'app_session_rotar'] as $t => $fn) {
    chequear("{$t} rota la sesión al autenticar", str_contains(leer($t), $fn));
}

// El login entraba (quedaba TENANT_LOGIN en el log) y un segundo después la
// sesión aparecía vacía: convivían DOS cookies PHPSESSID —una host-only del
// login y otra con Domain= que dejaba logout.php—, el navegador mandaba las dos
// y PHP se quedaba con la que no era.
chequear('La cookie de sesión tiene nombre propio, no PHPSESSID',
    preg_match("/define\s*\(\s*'APP_SESSION_NAME'\s*,\s*'(?!PHPSESSID)[A-Z0-9_]+'/", $sg) === 1);
chequear('app_session_start() aplica ese nombre', str_contains($sg, 'session_name(APP_SESSION_NAME)'));
chequear('Se expiran las cookies PHPSESSID que quedaron en el navegador',
    str_contains($sg, 'app_session_limpiar_legado'));
chequear('El .htaccess fija session.name (este hosting no lee .user.ini)',
    preg_match('/php_value\s+session\.name\s+\S+/', $ht = leer('.htaccess')) === 1);
foreach (['session.cookie_httponly', 'session.cookie_secure', 'session.cookie_samesite',
          'session.use_strict_mode'] as $flag) {
    chequear(".htaccess fija {$flag}", preg_match('/php_value\s+' . preg_quote($flag, '/') . '\s+\S+/', $ht) === 1);
}

// Nadie puede volver a fijar un domain propio: eso es lo que creaba la segunda cookie.
$conDominio = [];
foreach (archivos_php() as $f) {
    $r = rel($f);
    if (str_contains($r, 'session_guard.php')) { continue; }
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match("/'domain'\s*=>\s*\\\$(host|cookieDomain)/", $src)) { $conDominio[] = $r; }
}
chequear('Ningún archivo fija un domain propio para la cookie de sesión', $conDominio === [], implode(', ', $conDominio));
chequear('logout.php borra la cookie con los mismos parámetros con que se creó',
    str_contains(leer('auth/logout.php'), 'app_session_cookie_params()'));
chequear('login.php valida el company_id del POST contra las empresas activas',
    preg_match('/REQUEST_METHOD.*POST.*company_id/s', leer('auth/login.php')) === 1
 && str_contains(leer('auth/login.php'), 'foreach ($companies as $c)'));

// ═══════════════════════════════════════════════════════════════════════════
grupo('SEGURIDAD · exposición y permisos');
// ═══════════════════════════════════════════════════════════════════════════
$ht = leer('.htaccess');
foreach (['logs', 'backend/temp', 'bin', '\.git'] as $d) {
    chequear(".htaccess bloquea /{$d}", str_contains($ht, $d));
}
foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Strict-Transport-Security'] as $h) {
    chequear(".htaccess envía {$h}", str_contains($ht, $h));
}
chequear('.htaccess bloquea composer.* y deploy.sh', str_contains($ht, 'composer\.(json|lock)') && str_contains($ht, 'sh|md'));

// Los temporales de subida no pueden quedar en el web root con la extensión del usuario.
$tempEnWebroot = [];
foreach (archivos_php() as $f) {
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match("/tempDir\s*=\s*__DIR__\s*\.\s*'\/temp'/", $src)) { $tempEnWebroot[] = rel($f); }
}
chequear('Los temporales de subida van fuera del web root', $tempEnWebroot === [], implode(', ', $tempEnWebroot));
$conBasename = [];
foreach (archivos_php() as $f) {
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match("/uniqid\s*\(\s*'dup_'\s*\)\s*\.\s*'_'\s*\.\s*basename/", $src)) { $conBasename[] = rel($f); }
}
chequear('Los temporales no conservan el nombre original del archivo', $conBasename === [], implode(', ', $conBasename));

chequear('empresa_eliminar.php exige permiso y CSRF',
    str_contains(leer('dashboard/empresa_eliminar.php'), "platform_can('company.delete')")
 && str_contains(leer('dashboard/empresa_eliminar.php'), 'csrf_validate'));
chequear('empresa_backup.php exige company.backup (no company.view)',
    str_contains(leer('dashboard/empresa_backup.php'), "platform_can('company.backup')"));
chequear('empresa_detalle.php exige permiso para las acciones de gestión',
    str_contains(leer('dashboard/empresa_detalle.php'), "platform_can('tenant_user.manage')"));

// core/ e includes/ son librería, no entry points. /core/config/platform.php,
// /core/db/TenantDB.php y /backend/db.php devolvían HTTP 200 pedidos por URL, e
// /includes/layout/head.php devolvía 500: correr un include fuera de contexto.
foreach (['core', 'includes'] as $d) {
    chequear(".htaccess bloquea /{$d} (es librería, no entry point)",
        preg_match('/RewriteRule\s+\^' . $d . '\(\/\|\$\)/', $ht) === 1);
}
// backend/ no se puede bloquear entero —ahí viven los endpoints— pero db.php y
// csrf.php son librería y respondían 200 pedidos por URL.
chequear('.htaccess bloquea backend/db.php y backend/csrf.php',
    preg_match('/RewriteRule\s+\^backend\/\(db\|csrf\)/', $ht) === 1);

// auth/credentials.json se servía en texto plano si llegaba a estar en el
// servidor: el patrón de archivos sensibles sólo cubría `composer.json`.
chequear('.htaccess bloquea cualquier .json (no sólo composer.json)',
    preg_match('/FilesMatch[^>]*\|json\|/', $ht) === 1);

// Todo endpoint que escribe tiene que pedir permiso, no sólo sesión. Estos cinco
// sólo verificaban que hubiera un user_id: un rol `viewer` llegaba a todos.
foreach ([
    'backend/upload_to_drive.php'  => 'asset.create',
    'backend/upload_renamed.php'   => 'asset.edit',
    'backend/renombrar.php'        => 'asset.edit',
    'backend/sobrescribir.php'     => 'asset.edit',
    'backend/confirmar_accion.php' => 'asset.edit',
] as $f => $accion) {
    chequear("{$f} exige {$accion}",
        str_contains(leer($f), "require_tenant_permission('{$accion}')"));
}

// upload_renamed.php no incluía csrf.php siquiera, y usaba mime_content_type()
// sólo para etiquetar el upload, nunca para validarlo: subía cualquier tipo y
// cualquier tamaño a una carpeta arbitraria del Drive de la empresa.
$ur = sin_comentarios(leer('backend/upload_renamed.php'));
chequear('upload_renamed.php valida el token CSRF', str_contains($ur, 'csrf_or_fail'));
chequear('upload_renamed.php valida tipo real y tamaño del adjunto', str_contains($ur, 'drive_adjunto_valido'));
chequear('La validación de adjuntos vive una sola vez, en drive.php',
    str_contains(leer('core/integraciones/drive.php'), 'function drive_adjunto_valido'));
chequear('El nombre de archivo no se "escapa" con htmlspecialchars (no es contexto HTML)',
    !str_contains($ur, 'htmlspecialchars'));

// ═══════════════════════════════════════════════════════════════════════════
grupo('SEGURIDAD · aislamiento entre empresas');
// ═══════════════════════════════════════════════════════════════════════════
// Escalada de privilegios entre empresas.
//
// `user_id` es el id de una fila en la tabla `users` DE UNA EMPRESA; fuera de
// ella no identifica a nadie. dashboard/select_empresa.php no pedía sesión ni
// token, aceptaba company_id por POST validando sólo que la empresa estuviera
// ACTIVA —nunca que el usuario perteneciera a ella— y escribía company_id
// dejando user_id intacto. A partir de ahí tn() resuelve contra el tenant nuevo
// y el `SELECT ... WHERE id = $user_id` de cada página devuelve a otra persona:
// el usuario 1 de una empresa entraba como el usuario 1 de otra, con su rol —y
// en un tenant recién creado ese id 1 es el admin.
//
// auth/login.php abría el mismo desfasaje por otro lado: sobrescribe company_id
// con el del POST aunque ya haya una sesión viva de otra empresa, así que un
// login fallido dejaba la sesión cruzada.
$se = leer('dashboard/select_empresa.php');
chequear('select_empresa.php valida el token CSRF', str_contains($se, "verifyCsrfToken"));
chequear('select_empresa.php descarta la autenticación al cambiar de empresa',
    str_contains($se, 'app_session_rotar([])'));
chequear('select_empresa.php manda al login de la empresa elegida',
    str_contains($se, 'tenant_login_url'));
chequear('select_empresa.php no conserva user_id', !preg_match("/\\\$_SESSION\['user_id'\]\s*=/", $se));

// La defensa de fondo: cerrar esos dos caminos no alcanza, porque cualquier
// código futuro que escriba company_id reabre el agujero. La empresa contra la
// que se autenticó queda grabada y se compara en cada request.
$dbp = leer('backend/db.php');
chequear('backend/db.php compara la empresa de la sesión con la de autenticación',
    str_contains($dbp, "auth_company_id"));
chequear('Una sesión cruzada entre empresas se destruye, no se reinterpreta',
    str_contains($dbp, 'session_destroy'));
foreach (['auth/login.php', 'auth/superadmin_callback.php'] as $f) {
    chequear("{$f} graba la empresa de autenticación",
        preg_match("/_SESSION\['auth_company_id'\]\s*=/", leer($f)) === 1);
}

// ═══════════════════════════════════════════════════════════════════════════
grupo('ESQUEMA · master');
// ═══════════════════════════════════════════════════════════════════════════
// `users_platform` se declaraba en TRES archivos, cada uno con un `role`
// distinto: ENUM('superadmin','support') en install.php y en
// superadmin_login.php, ENUM de los cuatro valores en superadmin_usuarios.php.
// Qué roles eran asignables dependía de qué archivo hubiera corrido primero: en
// una instalación limpia `admin` y `viewer` no se podían guardar. Lo "arreglaba"
// un MODIFY COLUMN que superadmin_usuarios.php corría en CADA carga.
$conEnumRole = [];
foreach (archivos_php() as $f) {
    $r = rel($f);
    // test/integracion.php crea el ENUM viejo a propósito, para partir del estado
    // roto y comprobar que la migración lo repara. Es la prueba, no el defecto.
    if (str_starts_with($r, 'test' . DIRECTORY_SEPARATOR)) { continue; }
    $src = sin_comentarios((string)file_get_contents($f));
    if (!str_contains($src, 'users_platform')) { continue; }
    if (preg_match('/role\s+ENUM\s*\(/i', $src)) { $conEnumRole[] = $r; }
}
chequear('`users_platform.role` no se declara como ENUM en ningún archivo',
    $conEnumRole === [], implode(', ', $conEnumRole));

$mig = leer('core/db/migraciones.php');
chequear('Existe la vía de migraciones de la master', str_contains($mig, 'function migraciones_master'));
chequear('La versión de la master se guarda en platform_config',
    str_contains($mig, 'MASTER_SCHEMA_VERSION_KEY') && str_contains($mig, 'platform_config'));
chequear('bin/migrar.php aplica también las migraciones de la master',
    str_contains(leer('bin/migrar.php'), 'migrar_master'));

// Nada de DDL suelto en las páginas (regla 5 de CONTRIBUTING.md): tres páginas
// —una de ellas pública— corrían ALTER TABLE sobre users_platform en cada request.
$conDdlPlataforma = [];
foreach (archivos_php() as $f) {
    $r = rel($f);
    if (str_contains($r, 'migraciones.php') || str_starts_with($r, 'test' . DIRECTORY_SEPARATOR)) { continue; }
    $src = sin_comentarios((string)file_get_contents($f));
    if (preg_match('/ALTER\s+TABLE\s+`?users_platform`?/i', $src)) { $conDdlPlataforma[] = $r; }
}
chequear('Ninguna página hace ALTER TABLE sobre users_platform',
    $conDdlPlataforma === [], implode(', ', $conDdlPlataforma));

// ═══════════════════════════════════════════════════════════════════════════
grupo('CATALOGOS');
// ═══════════════════════════════════════════════════════════════════════════
$cat = leer('core/tenants/catalogos.php');
chequear('Existe el módulo de catálogos', $cat !== '');
chequear('Define los tipos asignado_a y ubicacion',
    str_contains($cat, "'asignado_a'") && str_contains($cat, "'ubicacion'"));
chequear('catalogo_normalizar() valida contra la lista en el servidor',
    str_contains($cat, 'function catalogo_normalizar'));
foreach (['backend/upload_to_drive.php' => 'alta', 'backend/edit.php' => 'edición'] as $t => $q) {
    chequear("La {$q} de activos normaliza asignado_a y ubicacion",
        str_contains(leer($t), "catalogo_normalizar(\$conn, 'asignado_a'")
     && str_contains(leer($t), "catalogo_normalizar(\$conn, 'ubicacion'"));
}
chequear('El ABM de catálogos exige catalog.manage',
    str_contains(leer('backend/catalogos_crud.php'), "user_can('catalog.manage'"));
chequear('catalog.manage es sólo de admin por defecto',
    preg_match("/'catalog\.manage'\s*=>\s*\['admin'\]/", leer('core/security/permissions.php')) === 1);
chequear('Renombrar arrastra el cambio a los activos', str_contains($cat, 'function catalogo_renombrar')
    && str_contains($cat, "UPDATE `{\$prod}`"));

// ═══════════════════════════════════════════════════════════════════════════
grupo('INVENTARIO · modelo (fases 1 y 2)');
// ═══════════════════════════════════════════════════════════════════════════
// Se toma de GLPI la forma de modelar, no sólo los campos: catálogos para todo
// lo que era texto libre, ubicaciones como árbol con dirección, y lo financiero
// y administrativo en su propia tabla (lo que GLPI llama Infocom).
$cat = leer('core/tenants/catalogos.php');
$ubi = leer('core/tenants/ubicaciones.php');
$mig = leer('core/db/migraciones.php');

// Fase 1a — marca, modelo, categoría, subcategoría y estado seguían siendo
// texto libre: el mismo problema de "Depósito / deposito / Dep." que la
// migración 3 resolvió para ubicación y responsable, intacto en cinco campos.
foreach (['marca', 'modelo', 'categoria', 'subcategoria', 'estado'] as $t) {
    chequear("CATALOGO_TIPOS incluye «{$t}»", preg_match("/'{$t}'\s*=>\s*\[/", $cat) === 1);
}
chequear('La migración siembra los catálogos nuevos con lo que ya estaba cargado',
    preg_match("/foreach\s*\(\s*\['marca',\s*'modelo',\s*'categoria',\s*'subcategoria',\s*'estado'\]/", $mig) === 1);

// Cada tipo declara dónde se usa su valor. Antes se asumía que el tipo era el
// nombre de la columna en `productos`; los catálogos de Infocom no lo son, y
// con esa suposición catalogo_uso() habría contado siempre cero.
chequear('Cada catálogo declara tabla y columna de destino',
    str_contains($cat, "'tabla' => 'activo_infocom'") && str_contains($cat, 'function catalogo_destino'));
chequear('catalogo_uso() usa el destino declarado y no asume `productos`',
    preg_match('/function catalogo_uso.*?catalogo_destino/s', $cat) === 1);

// Fase 1b — ubicaciones como árbol.
chequear('Existe el módulo de ubicaciones', $ubi !== '');
chequear('La migración crea la tabla de ubicaciones', str_contains($mig, 'ubicaciones'));
chequear('Las ubicaciones tienen dirección y coordenadas',
    str_contains($mig, 'codigo_postal') && str_contains($mig, 'latitud'));
chequear('Las ubicaciones son un árbol (padre_id)', str_contains($mig, 'padre_id'));
chequear('catalogo_valores() delega en el árbol para ubicación',
    str_contains($cat, 'ubicaciones_rutas'));
chequear('Renombrar una ubicación arrastra la ruta de todo el subárbol',
    str_contains($ubi, 'function ubicacion_recalcular_rutas'));
chequear('Renombrar una ubicación propaga el cambio a los activos',
    preg_match('/function ubicacion_recalcular_rutas.*?UPDATE `\{\$prod\}` SET ubicacion = /s', $ubi) === 1);
chequear('Un nodo no puede colgar de un descendiente suyo',
    str_contains($ubi, 'ubicacion_descendientes($conn, $id)'));
chequear('No se borra una ubicación con hijos ni con activos',
    preg_match('/function ubicacion_eliminar.*?ubicacion_uso.*?hijos/s', $ubi) === 1);

// La ruta completa se sigue guardando en `productos.ubicacion`, así que listado,
// reportes, CSV y backup no cambian; `ubicacion_id` es el enlace preciso.
chequear('La migración agrega productos.ubicacion_id', str_contains($mig, 'ubicacion_id'));
foreach (['backend/upload_to_drive.php' => 'alta', 'backend/edit.php' => 'edición'] as $t => $q) {
    chequear("La {$q} sincroniza ubicacion_id", str_contains(leer($t), 'ubicacion_sincronizar_id'));
}
// Una sola fuente de verdad: el dato no puede quedar en `catalogos` y en
// `ubicaciones` a la vez, que es de donde salen las inconsistencias.
chequear('Las ubicaciones salen de `catalogos` al migrar',
    preg_match("/DELETE FROM `\{\\\$p\}catalogos` WHERE tipo = 'ubicacion'/", $mig) === 1);

// Entre que el código se despliega y que alguien corre `php bin/migrar.php` hay
// una ventana con código nuevo y esquema viejo. Sin esto, el desplegable de
// Ubicación aparecía VACÍO en el alta y en la edición: una regresión visible
// causada por un despliegue a medio aplicar.
chequear('Hay un chequeo de si el árbol ya existe', str_contains($ubi, 'function ubicaciones_disponible'));
chequear('Sin migrar, Ubicación sigue leyendo la lista plana de antes',
    substr_count($cat, 'ubicaciones_disponible($conn)') >= 6,
    'puntos protegidos: ' . substr_count($cat, 'ubicaciones_disponible($conn)'));

// Fase 2 — Infocom.
chequear('La migración crea la tabla de datos financieros y administrativos',
    str_contains($mig, 'activo_infocom'));
foreach (['numero_pedido', 'numero_remito', 'numero_factura', 'proveedor', 'presupuesto',
          'fecha_puesta_servicio', 'fecha_decomiso', 'criticidad'] as $campo) {
    chequear("Infocom tiene «{$campo}»", str_contains($mig, $campo));
}
// Lo que de verdad destraba: el reporte de amortización calculaba con un `anios`
// del querystring, igual para todos los activos, porque no había dónde guardarla.
foreach (['amortizacion_anios', 'amortizacion_tipo', 'amortizacion_coeficiente'] as $campo) {
    chequear("La amortización se guarda por activo: «{$campo}»", str_contains($mig, $campo));
}
chequear('Cada activo arranca con su fila de Infocom',
    preg_match('/INSERT IGNORE INTO `\{\$p\}activo_infocom` \(producto_id\) SELECT id/', $mig) === 1);
chequear('Infocom no duplica los campos que ya viven en productos',
    !preg_match('/activo_infocom.*?\n.*?valor_usd/s', $mig));
chequear('La criticidad arranca con la escala de GLPI',
    str_contains($mig, 'Muy baja') && str_contains($mig, 'Muy alta'));

// Un esquema que nadie puede llenar no sirve: la edición tiene que cargarlo y
// el reporte tiene que usarlo.
$ed = leer('backend/edit.php');
chequear('La edición del activo carga y guarda los datos de Infocom',
    str_contains($ed, 'infocom_de(') && str_contains($ed, 'infocom_guardar('));
foreach (['numero_factura', 'fecha_puesta_servicio', 'amortizacion_anios'] as $campo) {
    chequear("El formulario de edición tiene «{$campo}»", str_contains($ed, 'name="' . $campo . '"'));
}
// Proveedor, presupuesto y criticidad son catálogos: los pinta campo_catalogo(),
// que es lo que les da el desplegable y el botón «+» del admin.
foreach (['proveedor', 'presupuesto', 'criticidad'] as $campo) {
    chequear("El formulario de edición pide «{$campo}» del catálogo",
        str_contains($ed, "campo_catalogo(\$conn, '{$campo}'"));
}
// «Estado» tenía DOS listas fijas y distintas: el alta ofrecía cuatro valores
// escritos en el formulario y la edición sólo Nuevo/Usado. El mismo campo, dos
// conjuntos de opciones según la pantalla.
foreach (['backend/edit.php' => 'La edición', 'inventario/agregar.php' => 'El alta'] as $t => $q) {
    chequear("{$q} toma «Estado» del catálogo, no de una lista fija",
        str_contains(leer($t), "campo_catalogo(\$conn, 'estado'")
     && !preg_match('/<option value="Usado"/', leer($t)));
}
chequear('El alta valida «Estado» en el servidor',
    str_contains(leer('backend/upload_to_drive.php'), "catalogo_normalizar(\$conn, 'estado'"));
chequear('La migración siembra los estados que estaban escritos en el formulario',
    str_contains($mig, "'estado'     => ['Nuevo', 'Usado', 'A reparar', 'Dado de baja']"));
chequear('Los catálogos de Infocom se validan en el servidor, no sólo en el <select>',
    preg_match("/function infocom_guardar.*?catalogo_normalizar/s", leer('core/tenants/infocom.php')) === 1);
chequear('Los tipos de bind_param de Infocom se derivan de la lista de columnas',
    preg_match('/strlen\(\$tipos\) !== count\(\$vals\)/', leer('core/tenants/infocom.php')) === 1);

$rep = leer('inventario/reportes.php');
chequear('El reporte de amortización usa el plazo guardado por activo',
    str_contains($rep, 'infocom_amortizacion_anios'));
chequear('El plazo del reporte queda como respaldo, no como único valor',
    preg_match('/infocom_amortizacion_anios\(\$row, \$anios\)/', $rep) === 1);

// ═══════════════════════════════════════════════════════════════════════════
grupo('INVENTARIO · campos por categoría (fase 3)');
// ═══════════════════════════════════════════════════════════════════════════
// `productos` tenía CATORCE columnas para campos que sólo aplican a una
// subcategoría. Agregar una categoría era un ALTER TABLE más tocar el alta, la
// edición, el detalle, los dos export y los dos import.
$cmp = leer('core/tenants/campos.php');
chequear('Existe el módulo de campos por categoría', $cmp !== '');
chequear('La migración crea las dos tablas',
    str_contains($mig, 'campos_definicion') && str_contains($mig, 'campos_valor'));
chequear('Se siembran los 14 campos que ya existían',
    substr_count($mig, "'lista',") + substr_count($mig, "'texto',") >= 14);

// La pieza que hace que esto sea seguro: los campos viejos NO se mueven de
// lugar. Siguen en su columna, así que export, import y respaldo no cambian.
chequear('Una definición puede apuntar a una columna heredada',
    str_contains($mig, 'columna_legacy') && str_contains($cmp, 'columna_legacy'));
chequear('Los valores heredados se leen de su columna de productos',
    preg_match('/function campos_valores_de.*?columna_legacy/s', $cmp) === 1);
chequear('Los campos heredados no se escriben en campos_valor',
    preg_match("/function campos_guardar.*?columna_legacy.*?continue/s", $cmp) === 1);
chequear('Una definición heredada no se puede borrar',
    preg_match("/function campo_definicion_eliminar.*?columna_legacy/s", $cmp) === 1);
chequear('Una definición en uso no se puede borrar',
    preg_match('/function campo_definicion_eliminar.*?campo_definicion_uso/s', $cmp) === 1);
chequear('Una clave no puede pisar una columna real de productos',
    str_contains($cmp, 'function campos_clave_reservada'));

// La validación es del servidor, como con los catálogos.
chequear('El valor se valida contra la definición en el servidor',
    str_contains($cmp, 'function campos_normalizar_valor'));
chequear('Un valor fuera de la lista de opciones se descarta',
    preg_match("/case 'lista':.*?in_array\(\\\$valor, \\\$ops, true\)/s", $cmp) === 1);

// Render y guardado en las dos pantallas.
chequear('Existe el render de los campos configurables', leer('includes/campos_dinamicos.php') !== '');
foreach (['inventario/agregar.php' => 'El alta', 'backend/edit.php' => 'La edición'] as $t => $q) {
    chequear("{$q} dibuja los campos configurables", str_contains(leer($t), 'campos_dinamicos_render'));
}
foreach (['backend/upload_to_drive.php' => 'El alta', 'backend/edit.php' => 'La edición'] as $t => $q) {
    chequear("{$q} guarda los campos configurables", str_contains(leer($t), 'campos_guardar('));
}
chequear('El detalle del activo los muestra',
    str_contains(leer('backend/view_details.php'), 'campos_valores_de'));

// La pantalla de administración: sin ella habría que definir campos por SQL.
chequear('Existe la pantalla para definir campos', is_file(RAIZ . '/inventario/campos.php'));
chequear('Existe el ABM de definiciones', is_file(RAIZ . '/backend/campos_crud.php'));
chequear('El ABM de campos exige permiso, no sólo sesión',
    str_contains(leer('backend/campos_crud.php'), "require_tenant_permission('catalog.manage')"));
chequear('El ABM de campos valida CSRF',
    str_contains(leer('backend/campos_crud.php'), 'verifyCsrfToken'));
// Antes esto pedía que `campos.php` estuviera enlazado en el menú. Desde que la
// fila de pestañas pasó de once entradas a cinco, esa pantalla cuelga de
// Configuración y el chequeo literal dejó de valer — pero lo que importaba
// nunca fue el menú: era que se pueda llegar.
//
// Así que ahora se sigue la navegación de verdad, saltando de pantalla en
// pantalla desde la fila de pestañas. Una pantalla a la que no se llega por
// ningún camino sólo existe para quien se sepa la URL.
$navPendientes = ['includes/layout/sidebar_tenant.php'];
$navVistas     = [];
$navAlcanzadas = [];
while ($navPendientes !== []) {
    $f = array_shift($navPendientes);
    if (isset($navVistas[$f])) { continue; }
    $navVistas[$f] = true;
    $src = leer($f);
    if ($src === '') { continue; }
    if (preg_match_all('#[\'"](/inventario/([a-z_]+)\.php)#', $src, $mm)) {
        foreach ($mm[2] as $i => $nombre) {
            $rel = 'inventario/' . $nombre . '.php';
            $navAlcanzadas[$rel] = true;
            $navPendientes[] = $rel;
        }
    }
}

$navTodas = [];
foreach (glob(RAIZ . '/inventario/*.php') ?: [] as $f) {
    $navTodas[] = 'inventario/' . basename($f);
}
$navHuerfanas = array_values(array_diff($navTodas, array_keys($navAlcanzadas)));
chequear(count($navTodas) . ' pantallas del portal: a todas se llega navegando',
    $navHuerfanas === [], 'sin camino desde el menú: ' . implode(', ', $navHuerfanas));

// La fila de pestañas tiene que seguir siendo corta. Once entradas eran un
// muro; el número no es sagrado, pero si vuelve a crecer conviene que alguien
// lo decida a propósito y no que pase sin que nadie lo note.
$sbTenant = leer('includes/layout/sidebar_tenant.php');
preg_match_all('#class="ih-sidebar__link<\?=\s*ih_tn_active#', $sbTenant, $mPest);
$cuantasPestanas = count($mPest[0]);
chequear("La fila de pestañas tiene {$cuantasPestanas} entradas (seis o menos)",
    $cuantasPestanas > 0 && $cuantasPestanas <= 6, "son {$cuantasPestanas}");

// Las pantallas que cuelgan de un índice tienen que poder volver a él. Antes
// todas tenían un «Volver» que iba a la lista de activos, que no es de donde se
// venía, y tres no tenían ninguno.
foreach ([
    'inventario/catalogos.php'              => 'configuracion',
    'inventario/campos.php'                 => 'configuracion',
    'inventario/usuarios.php'               => 'configuracion',
    'inventario/roles.php'                  => 'configuracion',
    'inventario/configurar_integracion.php' => 'configuracion',
    'inventario/exportar.php'               => 'datos',
    'inventario/importar.php'               => 'datos',
] as $pantalla => $indice) {
    chequear(basename($pantalla) . " vuelve a {$indice}",
        str_contains(leer($pantalla), "hub_volver('{$indice}')"));
}

// Un índice sin permiso no puede ser una puerta abierta, pero tampoco puede
// pedir un permiso que no tenga nada que ver con lo que muestra.
foreach (['inventario/configuracion.php', 'inventario/datos.php'] as $indice) {
    chequear(basename($indice) . ' exige al menos uno de los permisos de adentro',
        str_contains(leer($indice), 'require_tenant_permission_any(['));
}
chequear('require_tenant_permission_any existe y verifica sesión',
    str_contains(leer('core/security/permissions.php'), 'function require_tenant_permission_any(')
    && preg_match('/function require_tenant_permission_any.*?current_tenant_user\(\)/s',
                  leer('core/security/permissions.php')) === 1);

// ═══════════════════════════════════════════════════════════════════════════
grupo('TESTS · batería de integración');
// ═══════════════════════════════════════════════════════════════════════════
// Hay cosas que no se comprueban leyendo el código: que una migración aplique
// sin error, que sea idempotente, que renombrar una ubicación recalcule el
// subárbol. Eso necesita una base de verdad, y vive aparte para que esta
// batería siga corriendo en cualquier lado.
$itg = leer('test/integracion.php');
chequear('Existe la batería de integración', $itg !== '');
chequear('Sale limpio si no hay base configurada', str_contains($itg, 'AV_TEST_DB_HOST'));
chequear('Verifica que las migraciones sean idempotentes',
    str_contains($itg, 'no aplica nada ni falla'));
chequear('Verifica el recálculo de rutas del árbol',
    str_contains($itg, 'La ruta del nieto se recalculó'));
chequear('Verifica que los cuatro roles de plataforma se puedan guardar',
    str_contains($itg, 'Los cuatro roles de plataforma se pueden guardar'));
// Una instalación limpia no es el caso riesgoso. El riesgoso es la que ya existe
// y tiene datos: ésa es la que puede perderlos.
chequear('Verifica la actualización de una instalación CON datos',
    str_contains($itg, 'actualizar una instalación CON DATOS'));
chequear('Verifica que la actualización no pierda activos',
    str_contains($itg, 'No se perdió ningún activo'));
chequear('Verifica que los campos específicos no se muevan de su columna',
    str_contains($itg, 'Los campos específicos siguen en su columna'));
chequear('El esquema base es armable sin conexión (para poder testearlo)',
    str_contains(leer('core/tenants/install_tenant.php'), 'function tenant_schema_sql'));

// ═══════════════════════════════════════════════════════════════════════════
grupo('PAPELERA');
// ═══════════════════════════════════════════════════════════════════════════
chequear('Existe la pantalla de papelera', is_file(RAIZ . '/inventario/archivados.php'));
chequear('Existe el endpoint de restaurar', is_file(RAIZ . '/backend/restaurar.php'));
chequear('Restaurar exige asset.restore', str_contains(leer('backend/restaurar.php'), "require_permission('asset.restore'"));
chequear('Restaurar exige CSRF', str_contains(leer('backend/restaurar.php'), "csrf_or_fail('restaurar_activo'"));
chequear('Ver la papelera exige asset.trash.view', str_contains(leer('inventario/archivados.php'), "require_permission('asset.trash.view'"));
chequear('Restaurar deja rastro en el historial', str_contains(leer('backend/restaurar.php'), 'producto_movimientos'));
$drv = leer('core/integraciones/drive.php');
chequear('Archivar mueve la carpeta a «Archivados» en Drive', str_contains($drv, 'drive_archivar_carpeta'));
chequear('Restaurar devuelve la carpeta a su lugar', str_contains($drv, 'drive_restaurar_carpeta'));
chequear('Se recuerda el padre previo de la carpeta', str_contains(leer('backend/delete.php'), 'drive_padre_previo'));
chequear('asset.restore no lo tiene un rol de sólo lectura',
    preg_match("/'asset\.restore'\s*=>\s*\[([^\]]*)\]/", leer('core/security/permissions.php'), $m) === 1
 && !str_contains($m[1], 'viewer'));

// ═══════════════════════════════════════════════════════════════════════════
grupo('ESQUEMA · migraciones');
// ═══════════════════════════════════════════════════════════════════════════
$mig = leer('core/db/migraciones.php');
chequear('Existe el módulo de migraciones', $mig !== '');
require_once RAIZ . '/core/db/migraciones.php';
$migs = migraciones_tenant();
chequear('Las versiones son consecutivas desde 1',
    array_keys($migs) === range(1, count($migs)), 'claves: ' . implode(',', array_keys($migs)));
chequear('Hay al menos una migración de índices para productos',
    str_contains($mig, 'idx_categoria') && str_contains($mig, 'idx_archivado'));
chequear('La migración crea la tabla catalogos', str_contains($mig, 'catalogos'));
chequear('La migración agrega las columnas de papelera',
    str_contains($mig, 'archivado_en') && str_contains($mig, 'drive_padre_previo'));

// El DDL no puede volver a correr en cada request.
chequear('backend/db.php ya no ejecuta ALTER TABLE en cada request',
    !preg_match('/ALTER\s+TABLE/i', sin_comentarios(leer('backend/db.php'))));
chequear('usuarios.php ya no migra la columna role en cada carga',
    !preg_match('/ALTER\s+TABLE.*MODIFY\s+COLUMN\s+`role`/i', sin_comentarios(leer('inventario/usuarios.php'))));
foreach (['core/security/permissions_db.php', 'core/tenants/tenant_settings.php'] as $t) {
    chequear("{$t} saltea el DDL si el esquema está migrado",
        str_contains(leer($t), "defined('APP_SCHEMA_OK')"));
}

// ═══════════════════════════════════════════════════════════════════════════
grupo('DISENO · identidad visual');
// ═══════════════════════════════════════════════════════════════════════════
// El portal tenant tiene que hablar el mismo idioma visual que el CRM y APP
// Tickets: mismos tokens, misma fuente, mismo acento.
$css = leer('assets/css/app.css');
chequear('Existe la hoja del portal tenant', $css !== '');
$tokens = [
    '--ink:#161922'      => 'texto/neutro',
    '--primary:#663399'  => 'primario',
    '--accent:#9CC930'   => 'acento',
    '--surface:#ffffff'  => 'superficie',
    '--bg:#f6f5fa'       => 'fondo',
    '--radius:15px'      => 'radio',
];
foreach ($tokens as $tk => $desc) {
    chequear("Token de {$desc} igual al del CRM/Tickets ({$tk})", str_contains(str_replace(' ', '', $css), $tk));
}
chequear("La fuente es Saira", str_contains($css, "--font:'Saira'"));
chequear('El acento verde no compite con un "success" propio',
    !preg_match('/--ih-success:\s*#(?!.*var\()/i', $css));
chequear('Saira se carga por <link>, no por @import',
    str_contains(leer('includes/layout/head.php'), 'fonts.googleapis.com/css2?family=Saira')
 && !str_contains($css, '@import'));
chequear('head.php versiona los assets (cache-busting)', str_contains(leer('includes/layout/head.php'), 'ASSETS_V'));
chequear('El panel superadmin conserva su hoja', str_contains(leer('includes/layout/head.php'), 'intellihelp.css'));

// Ninguna pantalla del portal tenant puede quedar con la hoja vieja.
$hojaVieja = [];
foreach (glob(RAIZ . '/inventario/*.php') as $f) {
    if (str_contains((string)file_get_contents($f), 'assets/css/intellihelp.css')) { $hojaVieja[] = rel($f); }
}
chequear('Ninguna pantalla del inventario usa la hoja del superadmin', $hojaVieja === [], implode(', ', $hojaVieja));

// Todas las clases ih- del markup tienen que existir en alguna hoja.
//
// El regex de antes era /\bih-[a-zA-Z0-9_]+.../ — **sin guión** en la clase de
// caracteres. `ih-form-grid` se truncaba a `ih-form` y `ih-portal-superadmin` a
// `ih-portal`, que sí existían, así que el chequeo daba verde validando
// prefijos en lugar de clases. Además comparaba contra `styles.css`, una hoja
// que ninguna página carga. Lo descubrió una comparación contra el HTML que
// sirve el servidor, no esta batería.
//
// Ahora las clases se sacan de los atributos `class="…"` y se exigen enteras.
$enMarkup = [];
foreach (archivos_php() as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('/class\s*=\s*"([^"]*)"/', $src, $mm)) { continue; }
    foreach ($mm[1] as $attr) {
        foreach (preg_split('/\s+/', $attr) ?: [] as $c) {
            if (preg_match('/^ih-[a-zA-Z0-9_-]+$/', $c)) { $enMarkup[$c] = true; }
        }
    }
}

// Las reglas dentro de un <style> de una página también se aplican, así que no
// son un error — pero una clase del sistema de diseño definida en una sola
// pantalla no la ve ninguna otra. Se cuentan aparte.
$inline = '';
foreach (archivos_php() as $f) {
    if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', (string)file_get_contents($f), $ss)) {
        $inline .= implode("\n", $ss[1]);
    }
}

// componentes.css entra en la cuenta: la cargan los dos portales, asi que una
// clase definida ahi SI tiene estilo en pantalla (ver includes/layout/head.php).
$comp  = leer('assets/css/componentes.css');
$enCss = $css . leer('assets/css/intellihelp.css') . $comp;
$sinEstilo = []; $soloInline = [];
foreach (array_keys($enMarkup) as $c) {
    if (str_contains($enCss, '.' . $c)) { continue; }
    if (str_contains($inline, '.' . $c)) { $soloInline[] = $c; continue; }
    $sinEstilo[] = $c;
}
chequear(count($enMarkup) . ' clases ih- del markup tienen estilo en una hoja', $sinEstilo === [],
    'sin estilo: ' . implode(', ', array_slice($sinEstilo, 0, 12)));
chequear('Ninguna clase del sistema de diseño se define sólo dentro de una página',
    $soloInline === [], 'sólo inline: ' . implode(', ', array_slice($soloInline, 0, 12)));

// ───────────────────────────────────────────────────────────────────────────
// Cobertura POR PORTAL — lo anterior no alcanza.
//
// El portal tenant carga app.css y el panel superadmin carga intellihelp.css.
// El chequeo de arriba compara contra la UNIÓN de las dos, así que una clase
// definida en app.css y usada en el panel aparece cubierta y en pantalla no
// tiene ninguna regla.
//
// Así se coló el logo del topbar del panel: `.ih-topbar__brand-logo` sólo
// existía en app.css, el panel lo usaba igual, y el SVG se dibujaba a tamaño
// natural tapando la primera tarjeta de KPI. A simple vista, y la batería en
// verde. Nueve clases estaban en esa situación.
//
// includes/ y auth/ son compartidos: sus clases tienen que existir en las dos.
$clasesDe = static function (string $dir) use (&$clasesDe): array {
    $out = [];
    foreach (glob(RAIZ . '/' . $dir . '/*') ?: [] as $f) {
        if (is_dir($f)) { $out += $clasesDe(substr($f, strlen(RAIZ) + 1)); continue; }
        if (!str_ends_with($f, '.php')) { continue; }
        if (!preg_match_all('/class\s*=\s*"([^"]*)"/', (string)file_get_contents($f), $mm)) { continue; }
        foreach ($mm[1] as $attr) {
            foreach (preg_split('/\s+/', $attr) ?: [] as $c) {
                if (preg_match('/^ih-[a-zA-Z0-9_-]+$/', $c)) { $out[$c] = true; }
            }
        }
    }
    return $out;
};

// backend/ es COMPARTIDO, no del portal tenant: la cabecera elige la hoja
// segun la sesion, asi que backend/edit.php y backend/view_details.php salen
// con app.css para un usuario de empresa y con intellihelp.css para un
// superadmin. Contarlo como tenant dejaba un punto ciego: `.ih-edit-grid` y
// `.ih-badge--neutral` estaban solo en app.css y en el panel no tenian regla.
$compartidas = $clasesDe('includes') + $clasesDe('auth') + $clasesDe('backend');

foreach ([
    'portal tenant (app.css)'            => [['inventario'], $css],
    'panel superadmin (intellihelp.css)' => [['dashboard'],   leer('assets/css/intellihelp.css')],
] as $portal => [$dirs, $hoja]) {
    $usa = $compartidas;
    foreach ($dirs as $d) { $usa += $clasesDe($d); }
    $faltan = [];
    foreach (array_keys($usa) as $c) {
        if (str_contains($hoja, '.' . $c)) { continue; }
        if (str_contains($comp, '.' . $c)) { continue; }
        if (str_contains($inline, '.' . $c)) { continue; }
        $faltan[] = $c;
    }
    chequear(count($usa) . " clases del {$portal} están en una hoja QUE ESE PORTAL CARGA",
        $faltan === [], 'faltan: ' . implode(', ', array_slice($faltan, 0, 12)));
}

// ───────────────────────────────────────────────────────────────────────────
// Clases sin prefijo `ih-`
//
// El chequeo de arriba sólo mira las `ih-*`. Debajo de ese filtro se escondían
// restos de Bootstrap (`modal`, `modal-dialog`, `btn-close`, `ms-1`) que no
// tenían ninguna regla porque Bootstrap ya no se carga.
//
// Una clase sin regla CSS no siempre es un error: algunas son GANCHOS de
// JavaScript (`col-toggle`, `export-csv-btn`, `role-panel`) y el estilo lo
// traen las `ih-btn` que llevan al lado. Así que se acepta la que aparezca en
// un selector de JS; lo que no se acepta es la que no esté en ninguna parte.
// Un gancho se reconoce porque el nombre aparece entre comillas en el propio
// archivo: querySelectorAll('.col-toggle'), classList.add('is-open'). Se mira
// con str_contains y no con una expresión: una clase de comillas dentro de una
// cadena de PHP se cierra sola y ya me rompió este archivo una vez.
$esGanchoJs = static fn(string $c, string $src): bool =>
    str_contains($src, "'." . $c . "'") || str_contains($src, "'" . $c . "'")
    || str_contains($src, '".' . $c . '"') || str_contains($src, '"' . $c . '"');
$todasHojas = $css . leer('assets/css/intellihelp.css') . $comp;
$huerfanas = [];
foreach (archivos_php() as $f) {
    $rel = substr($f, strlen(RAIZ) + 1);
    if (str_starts_with($rel, 'test')) { continue; }
    $src = (string)file_get_contents($f);
    if (!preg_match_all('/class\s*=\s*"([^"<>{}$]*)"/', $src, $mm)) { continue; }
    foreach ($mm[1] as $attr) {
        foreach (preg_split('/\s+/', trim($attr)) ?: [] as $c) {
            if ($c === '' || str_starts_with($c, 'bi')) { continue; }
            if (str_contains($todasHojas, '.' . $c) || str_contains($inline, '.' . $c)) { continue; }
            if ($esGanchoJs($c, $src)) { continue; }
            $huerfanas[$c] = $rel;
        }
    }
}
chequear('Ninguna clase del markup se quedó sin regla y sin uso en JavaScript',
    $huerfanas === [],
    implode(', ', array_map(fn($c, $f) => "{$c} ({$f})", array_keys($huerfanas), $huerfanas)));

chequear('Bootstrap ya no se carga en NINGUNA pantalla',
    !array_filter(archivos_php(), fn($f) =>
        !str_contains($f, 'test') && str_contains((string)file_get_contents($f), 'bootstrap@')),
    'sigue cargándose en alguna página: su reset llega después del sistema de diseño y le gana');

// ───────────────────────────────────────────────────────────────────────────
grupo('COMPONENTES');
//
// El navegador dibuja por su cuenta la lista desplegada de un <select>, el
// calendario de un input[type=date] y el cuadro de confirm(): ninguna regla CSS
// los alcanza y salen con los colores del sistema operativo. assets/js/
// componentes.js los reemplaza y assets/css/componentes.css les da el estilo.

$compJs = leer('assets/js/componentes.js');
$head   = leer('includes/layout/head.php');
$foot   = leer('includes/layout/foot.php');

chequear('La hoja y el script de componentes existen', $comp !== '' && $compJs !== '');
chequear('La cabecera enlaza componentes.css', str_contains($head, 'ASSETS_COMPONENTES_CSS ?>'));
chequear('El pie carga componentes.js', str_contains($foot, 'ASSETS_COMPONENTES_JS ?>'));

// Si la version de cache mirara una sola hoja, tocar componentes.css o .js no
// la movería y el cambio quedaría tapado por la cache del navegador y del CDN.
chequear('La versión de assets mira los TRES archivos, no sólo la hoja del portal',
    str_contains($head, 'ASSETS_COMPONENTES_CSS, ASSETS_COMPONENTES_JS'));

// Mejora progresiva: el control nativo tiene que SEGUIR en el formulario. Si se
// reemplazara de verdad, un fallo del script dejaría el campo sin valor.
chequear('El script conserva el control nativo y no lo elimina',
    str_contains($compJs, 'envoltorio.appendChild(nativo)')
    && !preg_match('/nativo\.(remove|replaceWith)\s*\(/', $compJs)
    && !preg_match('/removeChild\s*\(\s*nativo/', $compJs));

// El <select> real es el que guarda el valor; elegir en la cara visible tiene
// que dispararle `change`, porque hay pantallas escuchando ese evento.
chequear('Elegir una opción dispara `change` en el <select> real',
    str_contains($compJs, "nativo.dispatchEvent(new Event('change'"));

// Cada clase que el script escribe necesita una regla, y cada regla necesita a
// alguien que la escriba: lo contrario es una clase invisible o CSS muerto. Se
// buscan los identificadores `ihc-` sin mirar comillas, porque el script los
// escribe de tres formas (el(), className e innerHTML) y una expresión que
// intente cubrir las tres se vuelve ilegible y se rompe sola.
//
// Estos tres no son clases sino `id`, y por eso no llevan regla:
$IDS = ['ihc-file', 'ihc-op', 'ihc-modal-tit', 'ihc-modal-det',
        'ihc-texto-tit', 'ihc-texto-input'];

// `(?<!data-)` deja afuera los atributos data-ihc-si / data-ihc-no, que no
// son clases: el \b los dejaba pasar porque el guion de `data-` corta la palabra.
preg_match_all('/(?<!data-)\bihc-[a-zA-Z0-9_-]+/', $compJs, $mj);
$enJs = array_values(array_diff(array_unique($mj[1] ?? $mj[0]), $IDS));
preg_match_all('/\.(ihc-[a-zA-Z0-9_-]+)/', preg_replace('#/\*.*?\*/#s', '', $comp) ?? '', $mc);
$enCssComp = array_unique($mc[1]);

$sinRegla = array_values(array_diff($enJs, $enCssComp));
chequear(count($enJs) . ' clases `ihc-` que escribe el script tienen regla en la hoja',
    $sinRegla === [], 'sin regla (no se verían): ' . implode(', ', $sinRegla));

$muertas = array_values(array_diff($enCssComp, $enJs));
chequear(count($enCssComp) . ' reglas `ihc-` de la hoja las escribe alguien',
    $muertas === [], 'CSS muerto: ' . implode(', ', $muertas));

// Los estados (is-open, es-hoy, …) viven en selectores compuestos y se ponen
// con classList: se chequean aparte.
preg_match_all('/classList\.(?:add|toggle|contains)\(\s*\W((?:is|es)-[a-z-]+)/', $compJs, $me);
$estadosSin = [];
foreach (array_unique($me[1]) as $e) {
    if (!str_contains($comp, '.' . $e)) { $estadosSin[] = $e; }
}
chequear(count(array_unique($me[1])) . ' estados del script (is-*/es-*) tienen regla',
    $estadosSin === [], 'sin regla: ' . implode(', ', $estadosSin));

// Los desplegables se cuelgan del <body>, salvo dentro de un <dialog> abierto:
// ese vive en la capa superior del navegador y todo lo demás se dibuja debajo,
// así que la lista de un <select> dentro de un modal saldría tapada.
chequear('Los desplegables respetan la capa superior de los <dialog>',
    str_contains($compJs, "closest('dialog')") && str_contains($compJs, 'anfitrion(ancla).appendChild'));

// El diálogo propio también es <dialog>: si fuera un div con z-index quedaría
// por debajo de los modales de las pantallas, que sí usan showModal().
chequear('El diálogo de confirmación es un <dialog> y no un div con z-index',
    str_contains($compJs, "el('dialog', 'ihc-modal')") && str_contains($compJs, 'm.showModal()'));

// El foco arranca en Cancelar: si alguien venía tecleando Enter, no tiene que
// confirmar un borrado sin haberlo leído.
chequear('La confirmación abre con el foco en Cancelar, no en el botón que borra',
    str_contains($compJs, 'setTimeout(function () { no.focus(); }, 0)'));

// ── Defectos que SÓLO aparecieron corriendo el script en un DOM de verdad
//    (node test/componentes.js). El análisis estático no podía verlos.

// 1) CSS.escape no existe en todos los entornos. Se usaba para encontrar la
//    etiqueta del campo y, al faltar, tiraba una excepción que cortaba el
//    recorrido: media pantalla quedaba con los controles del navegador y la
//    otra media con los propios.
chequear('La etiqueta se reapunta sin CSS.escape, que no existe en todos lados',
    !str_contains($compJs, 'CSS.escape(') && str_contains($compJs, 'nativo.labels'));

// 2) El selector [onsubmit*="confirm("] devuelve CERO coincidencias en nwsapi
//    por el paréntesis dentro de la cadena. Y no falla: simplemente no
//    encuentra nada, así que los confirm() seguían siendo los del navegador
//    sin ningún aviso.
chequear('Los confirm() se buscan filtrando en JS, no con un selector por subcadena',
    !preg_match('/\[on(submit|click)\*=/', $compJs)
    && str_contains($compJs, 'function conAtributo('));

// Un control que falle no puede llevarse puestos los demás.
chequear('Cada control se mejora protegido: una excepción no corta el resto',
    str_contains($compJs, 'function aCadaUno(')
    && str_contains($compJs, 'try { fn(n); } catch'));

// Los catálogos están cargados con tildes y nadie las escribe al buscar.
chequear('El buscador de los desplegables ignora tildes y mayúsculas',
    str_contains($compJs, 'function plano(')
    && str_contains($compJs, "normalize('NFD')"));

// El lector de pantalla necesita el id de la opción resaltada; estaba puesto en
// cadena vacía, que no nombra nada.
chequear('La lista nombra la opción resaltada (aria-activedescendant con un id)',
    str_contains($compJs, "'aria-activedescendant', opciones[i].id"));

// La prueba funcional tiene que existir: es la única que ejecuta el script.
chequear('Existe la prueba funcional de la capa de componentes',
    is_file(RAIZ . '/test/componentes.js') && is_file(RAIZ . '/package.json'));
chequear('node_modules no se versiona',
    str_contains(leer('.gitignore'), 'node_modules/'));

// componentes.css la cargan las dos hojas, que definen los tokens por separado:
// un token que exista en una sola haría que el componente se vea distinto.
preg_match_all('/var\(\s*(--ih-[a-zA-Z0-9-]+)/', $comp, $mt);
$ihcss2 = leer('assets/css/intellihelp.css');
$faltaTok = [];
foreach (array_unique($mt[1]) as $t) {
    if (!str_contains($css, $t . ':') || !str_contains($ihcss2, $t . ':')) { $faltaTok[] = $t; }
}
chequear(count(array_unique($mt[1])) . ' tokens que usa componentes.css están en LAS DOS hojas',
    $faltaTok === [], 'faltan en alguna: ' . implode(', ', $faltaTok));

// Los controles propios tienen que medir EXACTAMENTE lo mismo que un <input>
// nativo del mismo portal. Escritos a mano, el botón del desplegable quedaba
// 1px menos redondeado y un poco más bajo que el campo de al lado, y en una
// fila de filtros el escalón se veía. La geometría sale de los tokens --ih-ctl-*
// que cada hoja define con los valores que ya usa en sus propios campos.
foreach (['--ih-ctl-pad', '--ih-ctl-min-h', '--ih-ctl-radius', '--ih-ctl-border',
          '--ih-ctl-hover', '--ih-ctl-ring', '--ih-ctl-font', '--ih-ctl-bg',
          '--ih-ctl-placeholder'] as $tok) {
    chequear("Las dos hojas definen {$tok}",
        str_contains($css, $tok . ':') && str_contains($ihcss2, $tok . ':'));
}
chequear('El botón del desplegable toma su geometría de los tokens, no a mano',
    str_contains($comp, 'padding: var(--ih-ctl-pad')
    && str_contains($comp, 'border-radius: var(--ih-ctl-radius')
    && str_contains($comp, 'min-height: var(--ih-ctl-min-h'));
chequear('El campo de fecha también',
    preg_match('/\.ihc-fecha__texto\s*\{[^}]*var\(--ih-ctl-pad/s', $comp) === 1);

// El anillo de foco de la fecha va en el grupo: puesto en cada mitad se dibujaba
// también entre las dos, partiendo el control al medio.
chequear('El anillo de foco de la fecha envuelve el control entero',
    preg_match('/\.ihc-fecha:focus-within\s+\.ihc-fecha__campo[^{]*\{[^}]*box-shadow/s', $comp) === 1);

// El reposo de los desplegables se declara. Si la visibilidad dependiera sólo
// del final de un keyframe, una animación que no corra deja el desplegable
// abierto pero invisible — que es peor que no animar.
chequear('Los desplegables declaran su opacidad en reposo',
    preg_match('/\.ihc-pop\s*\{[^}]*opacity:\s*1/s', $comp) === 1);

// Cambiar de tamaño RECOLOCA el desplegable; cerrarlo ahí significaba cerrarlo
// en cuanto se abre el teclado de un teléfono.
chequear('Cambiar el tamaño de la ventana recoloca el desplegable, no lo cierra',
    str_contains($compJs, 'alCambiarTamano')
    && !preg_match("/addEventListener\('resize', cerrar\)/", $compJs));

// Desplazar la lista de opciones no puede cerrarla: `scroll` no burbujea, se
// escucha en captura, y en captura también llegan los de adentro.
chequear('Desplazar la lista de opciones no la cierra',
    str_contains($compJs, 'function alDesplazar(')
    && !preg_match("/addEventListener\('scroll', cerrar, true\)/", $compJs));



// ───────────────────────────────────────────────────────────────────────────
// Escala responsive
//
// Había dos cortes (860 y 640) y entre ellos nada: una grilla de tres columnas
// seguía en tres hasta los 640px, que en una notebook de 13" o una tablet en
// vertical deja los campos de ~200px. Los cortes nuevos salen de anchos reales
// de uso, no de nombres de dispositivo.
$ihcss = leer('assets/css/intellihelp.css');
foreach ([
    'assets/css/app.css'         => [$css,   [1200, 1024, 860, 640, 400]],
    'assets/css/intellihelp.css' => [$ihcss, [1200, 1024, 767, 480]],
] as $hoja => [$txt, $cortes]) {
    $faltan = [];
    foreach ($cortes as $px) {
        if (preg_match('/@media\s*\(\s*max-width:\s*' . $px . 'px\s*\)/', $txt) !== 1) { $faltan[] = $px; }
    }
    chequear(basename($hoja) . ' cubre los cortes ' . implode('/', $cortes),
        $faltan === [], 'faltan: ' . implode(', ', $faltan));
}
// La regla y su corte viven en componentes.css desde que backend/edit.php se
// reconocio como pantalla compartida por los dos portales.
// Se aplana la hoja (saltos, espacios y punto y coma) antes de comparar: el
// chequeo tiene que hablar de la regla, no del formato con que esta escrita.
$plano = str_replace(["\n", ' ', ';'], '', $comp);
chequear('Las grillas de 3 columnas pasan por 2 antes de llegar a 1',
    str_contains($plano, '@media(max-width:1024px){.ih-edit-grid-3{grid-template-columns:1fr1fr}}'));
chequear('Y a 860px las grillas del formulario pasan a una sola columna',
    str_contains($plano, '@media(max-width:860px){.ih-edit-grid,.ih-edit-grid-3{grid-template-columns:1fr}}'));

// Nada puede empujar la página en horizontal: lo ancho se desplaza en su caja.
foreach (['app.css' => $css, 'intellihelp.css' => $ihcss] as $n => $txt) {
    chequear("{$n} impide el desplazamiento horizontal de la página",
        preg_match('/html\s*,\s*body\s*\{\s*overflow-x:\s*hidden/', $txt) === 1);
    chequear("{$n} limita las imágenes al ancho disponible",
        preg_match('/img\s*,\s*svg\s*,\s*video\s*\{\s*max-width:\s*100%/', $txt) === 1);
}
// 44px es el área táctil mínima recomendada; los iconos de acción eran de 28.
foreach (['app.css' => $css, 'intellihelp.css' => $ihcss] as $n => $txt) {
    chequear("{$n} agranda el área táctil en pantallas sin mouse",
        str_contains(str_replace(' ', '', $txt), '@media(pointer:coarse)'));
}

// Las grillas del formulario de edición vivían en un <style> dentro de edit.php.
chequear('Las grillas de edición viven en la hoja, no en la página',
    str_contains($css, '.ih-edit-grid') && !str_contains(leer('backend/edit.php'), '.edit-grid {'));

// ───────────────────────────────────────────────────────────────────────────
// Diálogos propios, no los del navegador
//
// El alta rápida de un valor de catálogo abría un window.prompt(): el cuadro
// gris del navegador, con el nombre del dominio arriba, sin la tipografía ni
// los colores del sistema. En una pantalla de gestión queda fuera de lugar.
$cc = sin_comentarios(leer('includes/campo_catalogo.php'));
chequear('El alta rápida no usa el cuadro nativo del navegador',
    !preg_match('/window\.prompt\s*\(/', $cc) && !preg_match('/(?<![.\w])alert\s*\(/', $cc));

// El diálogo vivía dentro de este include: sesenta líneas de JavaScript y una
// hoja de estilos en un <style> que se reimprimía en cada página que usaba el
// campo y que el navegador no podía cachear. Era un segundo diálogo haciendo
// casi lo mismo que el de confirmación. Ahora los dos salen de la capa
// compartida, así que lo que hay que chequear es que lo use y que no se haya
// quedado con una copia propia.
chequear('El alta rápida usa el diálogo compartido',
    str_contains($cc, 'window.ihPedirTexto('));
chequear('El include ya no trae su propio diálogo ni su propia hoja de estilos',
    !str_contains($cc, 'cat-modal') && !str_contains($cc, '<style>'));
chequear('Los estilos del campo de catálogo están en la hoja compartida',
    str_contains($comp, '.cat-campo') && str_contains($comp, '.cat-add'));

// El botón del «+» tiene que medir lo mismo que el desplegable de al lado.
// Estaba con un gris y un radio escritos a mano, distintos de los del campo.
chequear('El botón de agregar toma la geometría de los tokens del portal',
    preg_match('/\.cat-add\s*\{[^}]*var\(--ih-ctl-radius/s', $comp) === 1
    && preg_match('/\.cat-add\s*\{[^}]*var\(--ih-ctl-border/s', $comp) === 1);

// Un «+» a secas no le dice nada a un lector de pantalla, y el `title` tarda en
// aparecer al pasar el mouse.
chequear('El botón de agregar dice qué agrega', str_contains($cc, 'aria-label="Agregar un valor a'));

// `ihPedirTexto` es un <dialog>: Escape, el foco de vuelta y el fondo oscuro los
// pone el navegador, no hay que programarlos.
// Se mira DENTRO de la función, no en todo el archivo: el de confirmación
// también llama a showModal() y encontrarlo ahí no probaría nada de éste.
preg_match('/window.ihPedirTexto = function.*?
  };/s', $compJs, $mPT);
chequear('El diálogo de texto es un <dialog>, con lo que Escape y el foco salen gratis',
    str_contains($compJs, "el('dialog', 'ihc-modal ihc-modal--texto')")
    && str_contains($mPT[0] ?? '', 'showModal()'));
chequear('El diálogo de texto cancela con Escape sin confirmar',
    preg_match('/function onCancel\(e\) \{ e\.preventDefault\(\); cerrar\(null\); \}/', $compJs) === 1);
chequear('Enviar el campo vacío no cierra el diálogo',
    str_contains($compJs, "err.textContent = 'Escribí un valor.'"));
chequear('La etiqueta del campo apunta al campo',
    str_contains($compJs, 'for="ihc-texto-input"') && str_contains($compJs, 'id="ihc-texto-input"'));

// ───────────────────────────────────────────────────────────────────────────
// Estructura de las tarjetas del alta
//
// Las dos secciones nuevas quedaron metidas DENTRO del `ih-card__header` de otra
// tarjeta: en pantalla salían como una columna angosta con media tarjeta vacía.
$ag = leer('inventario/agregar.php');
preg_match_all('/<div class="ih-card"[^>]*>\s*<div class="ih-card__header">/', $ag, $mCards);
preg_match_all('/<div class="ih-card__body">/', $ag, $mBodies);
chequear('Cada tarjeta del alta tiene cabecera y cuerpo propios',
    count($mCards[0]) === count($mBodies[0]) && count($mCards[0]) >= 6,
    'cabeceras: ' . count($mCards[0]) . ', cuerpos: ' . count($mBodies[0]));
chequear('Ninguna tarjeta quedó anidada dentro de la cabecera de otra',
    preg_match('/<div class="ih-card__header">\s*<div class="ih-card/', $ag) !== 1);

// ───────────────────────────────────────────────────────────────────────────
// Controles de formulario con la identidad de marca
//
// El panel no estilaba NINGÚN control nativo: sólo había una regla para los
// checkbox dentro de `.ih-check`, y pantallas como Roles y permisos usan
// `<input type="checkbox">` pelado. Salían con el azul del sistema operativo,
// que no es ningún color de la marca. Es lo mismo que ya resuelven el CRM y
// APP Tickets, de donde salen estos tokens.
foreach (['app.css' => $css, 'intellihelp.css' => $ihcss] as $n => $txt) {
    $plano = str_replace(["\n", ' '], '', $txt);
    chequear("{$n} pinta casillas y radios con el color de marca",
        preg_match('/input\[type="?checkbox"?\],input\[type="?radio"?\]\{accent-color:var\(--/', $plano) === 1);
    // El mismo chevron se puede escribir `m6 9 6 6 6-6` o `M6 9l6 6 6-6`: es la
    // misma forma en notación relativa o absoluta, así que se aceptan las dos.
    chequear("{$n} dibuja su propia flecha en los desplegables",
        str_contains($plano, 'appearance:none')
     && preg_match("/d='[Mm]6 9l?\s?6 6 6-6'/", $txt) === 1);
    chequear("{$n} atenúa el icono del calendario nativo",
        str_contains($txt, '::-webkit-calendar-picker-indicator'));
}

// Las tablas de reportes dibujaban el encabezado en violeta sólido con texto
// blanco, mientras todas las demás lo hacen en gris claro con texto atenuado:
// puestas una al lado de la otra parecían dos productos distintos.
chequear('Las tablas de reportes usan el mismo encabezado que el resto',
    str_contains($css, '.rp-table thead th') && !str_contains(leer('inventario/reportes.php'), '.rp-table thead th {'));
chequear('Ninguna tabla fuerza un encabezado violeta sólido',
    !preg_match('/\.rp-table thead th\{[^}]*background:var\(--ih-primary/', str_replace(["\n", ' '], '', $css)));

// ───────────────────────────────────────────────────────────────────────────
// Navegación en pantallas chicas
//
// El panel superadmin esconde su sidebar por debajo de 991px y lo muestra con
// `.ih-app.is-open`, pero el topbar —que es compartido— nunca dibujaba el botón
// para abrirlo, y el único JS que había servía para CERRARLO. El panel quedaba
// sin ninguna forma de navegar en tablet y en teléfono.
$tb = leer('includes/layout/topbar.php');
$ft = leer('includes/layout/foot.php');
chequear('El topbar dibuja el botón de menú', str_contains($tb, 'ih-topbar__burger'));
chequear('El botón declara qué controla y si está abierto',
    str_contains($tb, 'aria-expanded') && str_contains($tb, 'aria-controls'));
chequear('Hay código para ABRIR el menú, no sólo para cerrarlo',
    str_contains($ft, 'classList.toggle(\'is-open\')'));
chequear('El menú se cierra con Escape y tocando el fondo',
    str_contains($ft, "'Escape'") && str_contains($ft, 'ih-backdrop'));
chequear('El panel declara el estado abierto del menú',
    str_contains($ihcss, '.ih-app.is-open'));
chequear('El portal tenant no muestra ese botón (navega por pestañas)',
    str_contains(str_replace(' ', '', $css), '.ih-topbar__burger{display:none}'));

// El detalle del activo mostraba una tarjeta de garantía vacía y no mostraba
// nada de los datos de compra, que sí se podían cargar.
$vd = leer('backend/view_details.php');
chequear('El detalle muestra los datos de compra y administración',
    str_contains($vd, 'infocom_de(') && str_contains($vd, 'Compra y administración'));
chequear('El detalle no dibuja tarjetas vacías',
    str_contains($vd, '$htmlGarantia') && str_contains($vd, '$htmlInfocom'));

// ═══════════════════════════════════════════════════════════════════════════
// ───────────────────────────────────────────────────────────────────────────
grupo('CSV · exportar e importar');
//
// Exportar el inventario, abrirlo en Excel y volver a importarlo es un camino
// que la gente usa. Estaba roto y en silencio: de 32 columnas exportadas, 15 se
// perdían al reimportar.
//
// La causa era norm_col(), que borra los guiones bajos: un alias escrito
// 'patchcord_tipo' se normaliza a "patchcordtipo" y nunca coincide con la
// cabecera "Patchcord tipo" -> "patchcord tipo". Y `fecha_compra` directamente
// no estaba en el INSERT.

$expCsv = leer('backend/export_csv.php');
$impCsv = leer('backend/import_csv.php');

/** La misma normalización que hace el import. */
$normCol = static function (string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = (string)preg_replace('/[^a-z0-9\/ ]/', '', $s);
    return (string)preg_replace('/\s+/', ' ', $s);
};

// Cabeceras que escribe el export.
preg_match('/fputcsv\(\$output, array_filter\(\[(.*?)\]\)\);/s', $expCsv, $mExp);
preg_match_all("/'([^']+)'/", $mExp[1] ?? '', $mmExp);
$cabeceras = $mmExp[1];

// Alias que acepta el import.
preg_match('/\$columnMap\s*=\s*\[(.*?)\n\];/s', $impCsv, $mImp);
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*\[([^\]]*)\]/s", $mImp[1] ?? '', $mmImp, PREG_SET_ORDER);
$alias = [];
foreach ($mmImp as $g) {
    preg_match_all("/'([^']*)'/", $g[2], $v);
    foreach ($v[1] as $a) { $alias[$normCol($a)] = $g[1]; }
}

chequear('Se pudieron leer las cabeceras del export y el mapa del import',
    count($cabeceras) > 20 && count($alias) > 50,
    count($cabeceras) . ' cabeceras, ' . count($alias) . ' alias');

// `ID` lo asigna la base. `Fecha Carga` es cuándo se cargó la fila y lo pone el
// sistema. `Archivado` es el estado de la papelera: dejar que un CSV lo escriba
// sería permitir que una importación mande cosas a la papelera sin avisar.
$SOLO_EXPORT = ['ID', 'Fecha Carga', 'Archivado'];
$perdidas = [];
foreach ($cabeceras as $h) {
    if (in_array($h, $SOLO_EXPORT, true)) { continue; }
    if (!isset($alias[$normCol($h)])) { $perdidas[] = $h; }
}
chequear(count($cabeceras) . ' columnas exportadas: todas se vuelven a importar',
    $perdidas === [], 'se pierden: ' . implode(', ', $perdidas));

// Las tres columnas de sólo exportación tienen que seguir sin alias: si alguien
// agrega uno, una importación podría archivar activos en silencio.
$coladas = [];
foreach (['Fecha Carga', 'Archivado'] as $h) {
    if (isset($alias[$normCol($h)])) { $coladas[] = $h; }
}
chequear('Ni la fecha de carga ni el estado de archivado se pueden importar',
    $coladas === [], 'ahora se importan: ' . implode(', ', $coladas));

// mysqli no avisa cuando la cadena de tipos, los marcadores y los argumentos no
// coinciden: falla en ejecución, con una fila a medio escribir.
preg_match('/\$stmtProd->bind_param\((.*?)\n\s*\);/s', $impCsv, $mB);
$bloqueBind = $mB[1] ?? '';
preg_match("/'([sidb]+)'/", $bloqueBind, $mT);
$tipos = strlen($mT[1] ?? '');
preg_match('/\) VALUES \(([?,]+)\)/', $impCsv, $mV);
$marcadores = substr_count($mV[1] ?? '', '?');
$argumentos = substr_count($bloqueBind, '$registro[');
chequear("El INSERT de importación tiene {$tipos} tipos, {$marcadores} marcadores y {$argumentos} argumentos: coinciden",
    $tipos > 0 && $tipos === $marcadores && $tipos === $argumentos,
    "tipos={$tipos} marcadores={$marcadores} argumentos={$argumentos}");

// El comentario que explica la cuenta tiene que decir la verdad.
chequear('El comentario de los tipos dice el número correcto',
    str_contains($impCsv, "// {$tipos} tipos, uno por columna"));

// ───────────────────────────────────────────────────────────────────────────
grupo('DISENO · medido en el navegador');
//
// test/diseno/ mide las pantallas en Chrome: cuánto mide cada control, qué
// tamaños de letra se usan, si algo desborda. Eso no se puede hacer acá (esto
// es análisis estático) ni en test/componentes.js (jsdom no calcula layout).
// Lo que sí se puede es dejar clavados los defectos que esa medición encontró,
// para que no vuelvan en silencio.

chequear('La auditoría de diseño está en el repositorio',
    is_file(RAIZ . '/test/diseno/auditor.js') && is_file(RAIZ . '/test/diseno/README.md'));

// Un <button> no hereda la tipografía: el icono del «+» de los catálogos y el
// del calendario salían en la fuente por omisión del navegador, Arial 13,33px,
// en vez de Saira.
chequear('Los botones con icono declaran su tipografía',
    preg_match('/\.cat-add\s*\{[^}]*font-family:\s*inherit/s', $comp) === 1
    && preg_match('/\.ihc-fecha__abrir\s*\{[^}]*font-family:\s*inherit/s', $comp) === 1);

// El mismo `.ih-btn--sm` medía 27px en el panel y 34px en el portal del cliente,
// porque la hoja del panel no declaraba alto mínimo. 34 es el de APP Tickets.
foreach ([
    'assets/css/app.css'         => $css,
    'assets/css/intellihelp.css' => $ihcss2,
] as $hoja => $txt) {
    $plano2 = str_replace([" ", "\n"], '', $txt);
    chequear(basename($hoja) . ' declara el alto mínimo de los botones',
        str_contains($plano2, 'min-height:36px') && str_contains($plano2, 'min-height:34px'));
}

// Una insignia de 9px no se lee. El tamaño lo da la hoja, no un style suelto.
$conStyleChico = [];
foreach (archivos_php() as $f) {
    $rel = substr($f, strlen(RAIZ) + 1);
    if (str_starts_with($rel, 'test')) { continue; }
    // Todas las del archivo, no sólo la primera: la primera vez que corrió esto
    // quedaron tres sin arreglar porque sólo informaba una por archivo.
    if (preg_match_all('/style="[^"]*font-size:\s*([0-9.]+)px/', (string)file_get_contents($f), $mm)) {
        foreach ($mm[1] as $px) {
            if ((float)$px < 11) { $conStyleChico[] = $rel . ' (' . $px . 'px)'; }
        }
    }
}
chequear('Ninguna pantalla fuerza una letra de menos de 11px',
    $conStyleChico === [], implode(', ', $conStyleChico));

// ───────────────────────────────────────────────────────────────────────────
grupo('ORTOGRAFIA');
//
// La revisión vive en test/ortografia.php, que también se puede correr sola
// (`php test/ortografia.php`) para ver el detalle de cada hallazgo. Acá se
// incluye y se pregunta lo mismo, para que no haya que acordarse de correrla
// aparte y para no repetir las listas de palabras en dos lugares.
require_once RAIZ . '/test/ortografia.php';
$orto = ortografia_hallazgos();
$cuantas = array_sum(array_map('count', $orto));
chequear('El texto visible no tiene faltas de ortografía ni términos fuera de lugar',
    $orto === [],
    $cuantas . ' en ' . count($orto) . ' archivos: ' . implode(', ', array_keys($orto))
    . ' — correr `php test/ortografia.php` para ver el detalle');
grupo('DOCUMENTACION');
// ═══════════════════════════════════════════════════════════════════════════
// Regla del proyecto: la documentación se actualiza en tiempo real y el README
// refleja el documento maestro 1:1, diagramas incluidos. Sin esto, "subir por
// subir" no sirve.
foreach (['README.md', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md', 'INSTALL.md',
          'LICENSE', 'md/PROYECTO.md', 'md/REQUERIMIENTOS.md'] as $doc) {
    chequear("Existe {$doc}", is_file(RAIZ . '/' . $doc));
}

$readme  = leer('README.md');
$maestro = leer('md/PROYECTO.md');

preg_match_all('/```mermaid/', $readme, $mR);
preg_match_all('/```mermaid/', $maestro, $mM);
$nR = count($mR[0]); $nM = count($mM[0]);
chequear("El README tiene diagramas Mermaid ({$nR})", $nR >= 4);
chequear("El documento maestro tiene diagramas Mermaid ({$nM})", $nM >= 4);
chequear("README y maestro tienen la misma cantidad de diagramas (1:1)", $nR === $nM,
    "README={$nR}, maestro={$nM}");

// Cada capacidad viva tiene que estar documentada.
$capacidades = [
    'catálogo'            => ['catalogos.php'],
    'papelera'            => ['archivados.php', 'Papelera'],
    'restaurar'           => ['restaurar'],
    'migraciones'         => ['bin/migrar.php'],
    'roles personalizados'=> ['custom_roles', 'roles personalizados', 'Roles'],
    'SSO'                 => ['Google', 'Microsoft'],
    'Drive'               => ['Drive'],
    'multiempresa'        => ['tenant', 'multiempresa', 'multi-empresa'],
];
$faltan = [];
foreach ($capacidades as $cap => $agujas) {
    $ok = false;
    foreach ($agujas as $a) { if (stripos($readme, $a) !== false) { $ok = true; break; } }
    if (!$ok) { $faltan[] = $cap; }
}
chequear('El README documenta todas las capacidades vivas', $faltan === [], 'faltan: ' . implode(', ', $faltan));

// El CHANGELOG tiene que mencionar los arreglos de esta tanda.
$chg = leer('CHANGELOG.md');
$hitos = ['bay.php', '500', 'catálogo', 'papelera', 'sesión'];
$sinRegistrar = [];
foreach ($hitos as $h) { if (stripos($chg, $h) === false) { $sinRegistrar[] = $h; } }
chequear('El CHANGELOG registra los cambios de esta versión', $sinRegistrar === [],
    'sin registrar: ' . implode(', ', $sinRegistrar));

chequear('SECURITY.md describe el modelo de secretos',
    stripos(leer('SECURITY.md'), 'secrets.php') !== false);
chequear('INSTALL.md explica cómo correr las migraciones',
    stripos(leer('INSTALL.md'), 'bin/migrar.php') !== false);

// ═══════════════════════════════════════════════════════════════════════════
grupo('REPOSITORIO');
// ═══════════════════════════════════════════════════════════════════════════
chequear('.gitattributes normaliza los finales de línea', str_contains(leer('.gitattributes'), 'text=auto'));
chequear('composer.lock está versionado', is_file(RAIZ . '/composer.lock'));
chequear('vendor/ está ignorado', str_contains(leer('.gitignore'), 'vendor/'));
chequear('Los uploads temporales están ignorados', str_contains(leer('.gitignore'), 'secure/tmp/'));

// ═══════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 68) . "\n";
if ($FAIL === 0) {
    echo "\033[1;32m  {$PASS} checks OK\033[0m\n\n";
    exit(0);
}
echo "\033[1;31m  {$FAIL} fallaron\033[0m  ·  {$PASS} OK\n\n";
foreach ($FALLOS as $f) { echo "  \033[31m·\033[0m {$f}\n"; }
echo "\n";
exit(1);
