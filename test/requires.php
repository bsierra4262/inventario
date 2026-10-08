<?php
/**
 * ¿Cada pantalla trae los archivos de las funciones que llama?
 *
 *   php test/requires.php
 *
 * Existe por un fatal en producción. Se agregó `volver_aqui()` a la papelera
 * —el botón «Ver el detalle» de cada fila— y no se agregó el
 * `require_once includes/volver.php`. PHP no avisa nada hasta que ejecuta esa
 * línea, y esa línea sólo se ejecuta si la papelera TIENE filas: con la
 * papelera vacía la pantalla salía perfecta. Con un activo archivado, el HTML
 * se cortaba a la mitad del primer `<a href=` y el navegador mostraba media
 * tabla sin ningún error.
 *
 * Lo que lo vuelve difícil de ver a ojo: el fatal no rompe el encabezado, ni el
 * menú, ni el contador de arriba —todo eso ya se había impreso—. Y las pruebas
 * de punta a punta lo reportaron como «la papelera no muestra el activo» y
 * «419 al postear», que suena a un problema de permisos o de CSRF y no a un
 * `require` que falta.
 *
 * Cómo se chequea: para cada pantalla (un .php que nadie incluye) se junta todo
 * lo que llama —ella y sus parciales— y se exige que la función esté definida
 * en algún archivo de la cadena de `require`. Es estático: no hace falta ni
 * base ni servidor, y encuentra el caso de la papelera sin tener que acertar
 * con los datos que hacen entrar al `if`.
 */

declare(strict_types=1);

if (!defined('RAIZ')) { define('RAIZ', dirname(__DIR__)); }

if (!function_exists('req_archivos')) {
/** Los .php del proyecto, sin vendor ni pruebas. */
function req_archivos(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(RAIZ, FilesystemIterator::SKIP_DOTS),
            function ($f) {
                $n = $f->getFilename();
                if ($f->isDir()) {
                    return !in_array($n, ['vendor', '.git', 'node_modules', 'secure', 'test'], true);
                }
                return str_ends_with($n, '.php');
            }
        )
    );
    foreach ($it as $f) { $out[] = req_rel($f->getPathname()); }
    sort($out);
    return $cache = $out;
}
}

if (!function_exists('req_rel')) {
/** Ruta relativa a la raíz, con barras normales en cualquier sistema. */
function req_rel(string $abs): string
{
    $abs = str_replace('\\', '/', $abs);
    $raiz = str_replace('\\', '/', RAIZ) . '/';
    return str_starts_with($abs, $raiz) ? substr($abs, strlen($raiz)) : $abs;
}
}

if (!function_exists('req_normalizar')) {
/** Resuelve los `..` de una ruta sin tocar el disco. */
function req_normalizar(string $ruta): string
{
    $partes = [];
    foreach (explode('/', str_replace('\\', '/', $ruta)) as $p) {
        if ($p === '' || $p === '.') { continue; }
        if ($p === '..') { array_pop($partes); continue; }
        $partes[] = $p;
    }
    return implode('/', $partes);
}
}

if (!function_exists('req_tokens')) {
/** Los tokens de un archivo, cacheados: se recorre más de una vez. */
function req_tokens(string $rel): array
{
    static $cache = [];
    if (isset($cache[$rel])) { return $cache[$rel]; }
    $txt = (string)@file_get_contents(RAIZ . '/' . $rel);
    return $cache[$rel] = @token_get_all($txt) ?: [];
}
}

if (!function_exists('req_definidas')) {
/**
 * Mapa `función => [archivos que la definen]`.
 *
 * Sólo funciones de verdad: los métodos de una clase se saltean contando
 * llaves, porque `function t(...)` dentro de `class TenantDB` no define una
 * función global y confundir las dos daría falsos negativos justo en `tn()`.
 */
function req_definidas(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $mapa = [];
    foreach (req_archivos() as $rel) {
        foreach (req_funciones_de($rel) as $n) { $mapa[$n][] = $rel; }
    }
    return $cache = $mapa;
}
}

if (!function_exists('req_funciones_de')) {
/** Los nombres de función que define un archivo, fuera de toda clase. */
function req_funciones_de(string $rel): array
{
    $tk = req_tokens($rel);
    $out = [];
    $prof = 0;          // nivel de llaves
    $enClase = -1;      // nivel donde empezó la clase actual, -1 si ninguna
    $esperaClase = false;

    foreach ($tk as $i => $t) {
        if (req_abre($t)) { $prof++; if ($esperaClase) { $enClase = $prof; $esperaClase = false; } }
        if (is_string($t)) {
            if ($t === '}') { if ($enClase === $prof) { $enClase = -1; } $prof--; }
            continue;
        }
        if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // `Foo::class` no abre una clase.
            $ant = req_anterior($tk, $i);
            if (!($ant && !is_string($ant) && $ant[0] === T_DOUBLE_COLON)) { $esperaClase = true; }
            continue;
        }
        if ($t[0] !== T_FUNCTION || $enClase !== -1) { continue; }
        $sig = req_siguiente($tk, $i);
        if ($sig && !is_string($sig) && $sig[0] === T_STRING) { $out[] = strtolower($sig[1]); }
    }
    return $out;
}
}

if (!function_exists('req_abre')) {
/**
 * ¿Este token abre una llave?
 *
 * No alcanza con comparar contra `'{'`: la interpolación de
 * `"SELECT ... FROM `{$tabla}`"` abre con un token propio —`T_CURLY_OPEN`— y
 * cierra con una `}` suelta. Contando sólo las literales, el contador se iba a
 * negativo en la primera consulta con una tabla interpolada y el bloque `try`
 * parecía terminar ahí: nueve de cada diez `try` del proyecto quedaban sin
 * detectar, que es exactamente donde vive el modismo que se quiere reconocer.
 */
function req_abre($t): bool
{
    if (is_string($t)) { return $t === '{'; }
    return in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
}
}

if (!function_exists('req_siguiente')) {
/** El token útil que viene después de $i (saltea espacios y comentarios). */
function req_siguiente(array $tk, int $i)
{
    $n = count($tk);
    for ($j = $i + 1; $j < $n; $j++) {
        if (is_string($tk[$j])) { return $tk[$j]; }
        if (!in_array($tk[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { return $tk[$j]; }
    }
    return null;
}
}

if (!function_exists('req_anterior')) {
/** El token útil que viene antes de $i. */
function req_anterior(array $tk, int $i)
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (is_string($tk[$j])) { return $tk[$j]; }
        if (!in_array($tk[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { return $tk[$j]; }
    }
    return null;
}
}

if (!function_exists('req_llamadas_de')) {
/**
 * Las funciones que llama un archivo, con el renglón de la primera llamada.
 *
 * Se descarta lo que no es una llamada a función global: los métodos
 * (`$x->f()`, `A::f()`), las declaraciones, `new F()`, los nombres con
 * namespace y `function_exists('f')` —que es justamente el chequeo de que
 * puede no estar—.
 */
function req_llamadas_de(string $rel): array
{
    $tk     = req_tokens($rel);
    $atrapa = req_tries_de($rel);
    $out    = [];
    foreach ($tk as $i => $t) {
        if (is_string($t) || $t[0] !== T_STRING) { continue; }
        if (req_siguiente($tk, $i) !== '(') { continue; }
        foreach ($atrapa as [$desde, $hasta]) {
            if ($i > $desde && $i < $hasta) { continue 2; }
        }
        $ant = req_anterior($tk, $i);
        if ($ant !== null && !is_string($ant) && in_array($ant[0],
                [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_NS_SEPARATOR,
                 T_NULLSAFE_OBJECT_OPERATOR, T_ATTRIBUTE], true)) { continue; }
        $n = strtolower($t[1]);
        if (!isset($out[$n])) { $out[$n] = $t[2]; }
    }
    return $out;
}
}

if (!function_exists('req_tries_de')) {
/**
 * Los tramos de un archivo que están dentro de un `try` con `catch (Throwable)`.
 *
 * Una función que no existe lanza `Error`, y `Error` es un `Throwable`: ahí
 * adentro, llamar a algo que no está cargado no tumba la página. Es un modismo
 * deliberado del proyecto —`cloud_provider_actual()` llama a `tn()` así y
 * devuelve «google» si no hay tenant— y pedirle un `require` sería pedirle
 * justamente lo que decidió no depender de.
 *
 * @return array<array{0:int,1:int}> Pares de índices de token (abre, cierra).
 */
function req_tries_de(string $rel): array
{
    $tk  = req_tokens($rel);
    $n   = count($tk);
    $out = [];

    for ($i = 0; $i < $n; $i++) {
        if (is_string($tk[$i]) || $tk[$i][0] !== T_TRY) { continue; }

        // El bloque: de su `{` hasta la `}` que lo cierra.
        $abre = $i;
        while ($abre < $n && !req_abre($tk[$abre])) { $abre++; }
        if ($abre >= $n) { continue; }
        $prof = 0;
        for ($j = $abre; $j < $n; $j++) {
            if (req_abre($tk[$j])) { $prof++; }
            elseif ($tk[$j] === '}') { $prof--; if ($prof === 0) { break; } }
        }
        if ($j >= $n) { continue; }
        $cierra = $j;

        // ¿Alguno de sus `catch` agarra Throwable o Error?
        $agarra = false;
        $k = $cierra;
        while (true) {
            $sig = null;
            for ($m = $k + 1; $m < $n; $m++) {
                if (is_string($tk[$m])) { $sig = $m; break; }
                if (!in_array($tk[$m][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $sig = $m; break; }
            }
            if ($sig === null || is_string($tk[$sig]) || $tk[$sig][0] !== T_CATCH) { break; }
            // Los tipos listados entre paréntesis.
            for ($m = $sig; $m < $n && $tk[$m] !== ')'; $m++) {
                if (is_string($tk[$m])) { continue; }
                if (in_array($tk[$m][0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                    && in_array(strtolower(ltrim($tk[$m][1], '\\')), ['throwable', 'error'], true)) {
                    $agarra = true;
                }
            }
            // Saltar el cuerpo del catch para ver si hay otro.
            while ($m < $n && !req_abre($tk[$m])) { $m++; }
            $prof = 0;
            for (; $m < $n; $m++) {
                if (req_abre($tk[$m])) { $prof++; }
                elseif ($tk[$m] === '}') { $prof--; if ($prof === 0) { break; } }
            }
            $k = $m;
        }

        if ($agarra) { $out[] = [$abre, $cierra]; }
    }
    return $out;
}
}

if (!function_exists('req_incluidos_de')) {
/**
 * Los archivos que un .php trae con require/include.
 *
 * Dos formas, las dos usadas en el proyecto:
 *
 *   require_once __DIR__ . '/x.php';
 *   $p = __DIR__ . '/x.php';  if (is_file($p)) require_once $p;   // carga diferida
 *
 * La segunda importa: así se traen `permissions_db.php` y
 * `platform_permissions_db.php`, y leerla sólo literal daría tres falsos
 * positivos por pantalla.
 */
function req_incluidos_de(string $rel): array
{
    $txt = (string)@file_get_contents(RAIZ . '/' . $rel);
    $dir = dirname($rel);
    $out = [];

    $resolver = function (string $base, string $camino) use ($dir, &$out): void {
        $raiz = str_starts_with(strtolower($base), 'dirname') ? dirname($dir)
              : (strtoupper($base) === '__DIR__' ? $dir : '');
        $out[] = req_normalizar(($raiz === '.' ? '' : $raiz) . '/' . $camino);
    };

    $base = '(__DIR__|ROOT_PATH|RAIZ|APP_ROOT|dirname\s*\(\s*__DIR__\s*\))';

    $rx = '/(?:require|include)(?:_once)?\s*\(?\s*' . $base . '\s*\.\s*[\'"]([^\'"]+)[\'"]/i';
    if (preg_match_all($rx, $txt, $m, PREG_SET_ORDER)) {
        foreach ($m as $g) { $resolver($g[1], $g[2]); }
    }

    // Carga diferida: la variable que se requiere, y de dónde salió su valor.
    if (preg_match_all('/(?:require|include)(?:_once)?\s+\$(\w+)\s*;/i', $txt, $m)) {
        foreach (array_unique($m[1]) as $var) {
            $rxa = '/\$' . preg_quote($var, '/') . '\s*=\s*' . $base . '\s*\.\s*[\'"]([^\'"]+)[\'"]/i';
            if (preg_match($rxa, $txt, $a)) { $resolver($a[1], $a[2]); }
        }
    }

    return array_values(array_unique($out));
}
}

if (!function_exists('req_cadena')) {
/** El archivo y todo lo que arrastra, transitivamente. */
function req_cadena(string $rel, array $vistos = []): array
{
    if (isset($vistos[$rel])) { return $vistos; }
    $vistos[$rel] = true;
    foreach (req_incluidos_de($rel) as $hijo) {
        if (!is_file(RAIZ . '/' . $hijo)) { continue; }
        $vistos = req_cadena($hijo, $vistos);
    }
    return $vistos;
}
}

if (!function_exists('req_parciales')) {
/** Los archivos que alguien incluye: no son pantallas, se chequean con su padre. */
function req_parciales(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $set = [];
    foreach (req_archivos() as $rel) {
        foreach (req_incluidos_de($rel) as $hijo) {
            if (is_file(RAIZ . '/' . $hijo)) { $set[$hijo] = true; }
        }
    }
    return $cache = $set;
}
}

if (!function_exists('req_protegida')) {
/**
 * ¿El archivo pregunta si la función existe antes de llamarla?
 *
 * `if (function_exists('log_event')) { log_event(...); }` no puede dar un
 * fatal: la ausencia está contemplada. Reportarlo sería pedir un `require` que
 * el autor decidió no hacer a propósito —el logger es opcional—.
 */
function req_protegida(string $rel, string $fn): bool
{
    $txt = (string)@file_get_contents(RAIZ . '/' . $rel);
    return (bool)preg_match('/function_exists\s*\(\s*[\'"]' . preg_quote($fn, '/') . '[\'"]/i', $txt);
}
}

if (!function_exists('req_incluidos_con_posicion')) {
/**
 * Lo mismo que `req_incluidos_de()`, pero diciendo en qué token aparece cada uno.
 *
 * Hace falta para saber si un `require` está dentro de un `try` o fuera.
 *
 * @return array<array{0:string,1:int}> Pares (archivo incluido, índice de token).
 */
function req_incluidos_con_posicion(string $rel): array
{
    $tk  = req_tokens($rel);
    $dir = dirname($rel);
    $out = [];
    foreach ($tk as $i => $t) {
        if (is_string($t) || !in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        /* La ruta literal que viene después: `__DIR__ . '/../x.php'`. Se junta
           lo que haya hasta el `;`. */
        $ruta = '';
        for ($j = $i + 1, $n = count($tk); $j < $n && $tk[$j] !== ';'; $j++) {
            if (!is_string($tk[$j]) && $tk[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $ruta .= trim($tk[$j][1], "'\"");
            }
        }
        if ($ruta === '') { continue; }
        $out[] = [req_normalizar($dir . '/' . ltrim($ruta, '/')), $i];
    }
    return $out;
}
}

if (!function_exists('req_llamadas_con_posicion')) {
/**
 * Las llamadas a función de un archivo, con su índice de token y su línea.
 *
 * A diferencia de `req_llamadas_de()`, acá NO se saltean las que están dentro
 * de un `try`: justamente lo que se quiere saber es de qué lado caen.
 *
 * @return array<array{0:string,1:int,2:int}> (nombre, índice de token, línea).
 */
function req_llamadas_con_posicion(string $rel): array
{
    $tk  = req_tokens($rel);
    $out = [];
    foreach ($tk as $i => $t) {
        if (is_string($t) || $t[0] !== T_STRING) { continue; }
        if (req_siguiente($tk, $i) !== '(') { continue; }
        $ant = req_anterior($tk, $i);
        if ($ant !== null && !is_string($ant) && in_array($ant[0],
                [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_NS_SEPARATOR,
                 T_NULLSAFE_OBJECT_OPERATOR, T_ATTRIBUTE], true)) { continue; }
        $out[] = [strtolower($t[1]), $i, $t[2]];
    }
    return $out;
}
}

if (!function_exists('requires_condicionales')) {
/**
 * Requires que pueden no llegar a correr, con llamadas que no lo contemplan.
 *
 * Un `require_once` dentro de un `try { } catch (Throwable) { }` sólo corre si
 * nada falló antes dentro de ese mismo `try`. Lo que se llame DENTRO del try
 * está cubierto —el mismo catch lo agarra—; lo de AFUERA es un fatal el día que
 * el catch salte.
 *
 * @return string[] Un aviso por cada llamada desprotegida.
 */
function requires_condicionales(): array
{
    $parciales = req_parciales();
    $avisos    = [];

    foreach (req_archivos() as $rel) {
        $tries = req_tries_de($rel);
        if ($tries === []) { continue; }

        foreach (req_incluidos_con_posicion($rel) as [$incluido, $pos]) {
            // ¿En qué try cae este require?
            $dentro = null;
            foreach ($tries as [$desde, $hasta]) {
                if ($pos > $desde && $pos < $hasta) { $dentro = [$desde, $hasta]; break; }
            }
            if ($dentro === null) { continue; }
            if (!is_file(RAIZ . '/' . $incluido)) { continue; }

            $traeFn = req_funciones_de($incluido);
            if ($traeFn === []) { continue; }

            foreach (req_llamadas_con_posicion($rel) as [$fn, $iPos, $linea]) {
                if (!in_array($fn, $traeFn, true)) { continue; }
                if ($iPos > $dentro[0] && $iPos < $dentro[1]) { continue; }   // dentro del mismo try
                if (req_protegida($rel, $fn)) { continue; }
                /* Puede venir por otro lado sin condición: si algún otro
                   require del archivo lo trae y ese está fuera de todo try, no
                   hay problema. */
                $seguro = false;
                foreach (req_incluidos_con_posicion($rel) as [$otro, $otroPos]) {
                    if ($otro !== $incluido || $otroPos === $pos) { continue; }
                    $enTry = false;
                    foreach ($tries as [$d, $h]) {
                        if ($otroPos > $d && $otroPos < $h) { $enTry = true; break; }
                    }
                    if (!$enTry) { $seguro = true; break; }
                }
                if ($seguro) { continue; }

                $avisos[] = "{$rel}:{$linea}  {$fn}() se llama fuera del try, pero "
                          . basename($incluido) . ' sólo se carga adentro';
            }
        }
    }
    return array_values(array_unique($avisos));
}
}

if (!function_exists('requires_faltantes')) {
/**
 * Las llamadas que no tienen de dónde salir.
 *
 * @return array{0: string[], 1: int} Los avisos y cuántas pantallas se miraron.
 */
function requires_faltantes(): array
{
    $definidas = req_definidas();
    $parciales = req_parciales();
    $avisos    = [];
    $pantallas = 0;

    foreach (req_archivos() as $rel) {
        if (isset($parciales[$rel])) { continue; }   // lo chequea quien lo incluye
        $pantallas++;
        $cadena = req_cadena($rel);

        foreach (array_keys($cadena) as $archivo) {
            foreach (req_llamadas_de($archivo) as $fn => $linea) {
                if (!isset($definidas[$fn])) { continue; }   // nativa, de vendor o de una clase
                if (req_protegida($archivo, $fn)) { continue; }
                foreach ($definidas[$fn] as $donde) {
                    if (isset($cadena[$donde])) { continue 2; }
                }
                $avisos[] = "{$archivo}:{$linea}  {$fn}() se define en "
                          . implode(' o ', $definidas[$fn])
                          . ', que no entra por ' . $rel;
            }
        }
    }

    return [array_values(array_unique($avisos)), $pantallas];
}
}

// ── Modo suelto: `php test/requires.php` ─────────────────────────────────────
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    [$avisos, $pantallas] = requires_faltantes();
    $avisos = array_merge($avisos, requires_condicionales());
    echo PHP_EOL . "  {$pantallas} pantallas revisadas" . PHP_EOL . PHP_EOL;
    if ($avisos === []) {
        echo "  Ninguna llamada quedó sin su require." . PHP_EOL . PHP_EOL;
        exit(0);
    }
    foreach ($avisos as $a) { echo "  · {$a}" . PHP_EOL; }
    echo PHP_EOL . '  ' . count($avisos) . ' sin resolver' . PHP_EOL . PHP_EOL;
    exit(1);
}
