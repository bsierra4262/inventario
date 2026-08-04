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
$enMarkup = [];
foreach (archivos_php() as $f) {
    if (preg_match_all('/\bih-[a-zA-Z0-9_]+(?:__[a-zA-Z0-9_-]+)?(?:--[a-zA-Z0-9_-]+)?/', (string)file_get_contents($f), $mm)) {
        foreach ($mm[0] as $c) { $enMarkup[$c] = true; }
    }
}
$enCss = $css . leer('assets/css/intellihelp.css') . leer('assets/css/styles.css');
$sinEstilo = [];
foreach (array_keys($enMarkup) as $c) {
    // Los tokens (--ih-*) y los prefijos de utilidades con sufijo numérico se ignoran.
    if (!str_contains($enCss, $c)) { $sinEstilo[] = $c; }
}
chequear(count($enMarkup) . ' clases ih- del markup tienen estilo definido', $sinEstilo === [],
    'sin estilo: ' . implode(', ', array_slice($sinEstilo, 0, 12)));

chequear('Bootstrap CSS ya no se carga en el portal tenant',
    !str_contains(leer('inventario/usuarios.php'), 'bootstrap@5.3.3/dist/css'));

// ═══════════════════════════════════════════════════════════════════════════
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
