<?php
/**
 * Leer el log de auditoría sin leerlo entero.
 *
 * `logs/audit.log` es un CSV al que sólo se le agregan renglones: nunca se
 * borra nada, porque es el rastro de qué pasó y quién lo hizo. Eso está bien
 * para guardar y mal para abrirlo: la pantalla de auditoría cargaba el archivo
 * completo en memoria, lo parseaba entero y recién después se quedaba con los
 * últimos quinientos. Con mil quinientos renglones ya devolvía 522 KB de HTML
 * por pantalla; con un año de uso, el costo crece con el archivo y no con lo
 * que se mira.
 *
 * Acá se lee **desde el final**, de a pedazos, y se corta apenas hay suficiente.
 * Abrir la última página cuesta lo mismo con mil renglones que con un millón.
 *
 * Se puede leer por renglones sueltos porque `logger.php` saca los saltos de
 * línea de cada campo antes de escribir: un renglón del archivo es un evento, y
 * no hay campos de varias líneas que haya que volver a pegar.
 */

declare(strict_types=1);

if (!defined('AUDITORIA_PEDAZO')) {
    /** Cuánto se lee de una. 64 KB son unos 400 eventos: casi siempre, una sola pasada. */
    define('AUDITORIA_PEDAZO', 65536);
}

if (!function_exists('auditoria_fila')) {
/** Un renglón del CSV, con nombres. Devuelve null si no es un evento. */
function auditoria_fila(string $linea): ?array
{
    $linea = trim($linea);
    if ($linea === '') { return null; }
    $c = str_getcsv($linea);
    if (count($c) < 7) { return null; }
    if ($c[0] === 'Fecha') { return null; }   // el encabezado del archivo
    return [
        'fecha' => $c[0], 'hora' => $c[1], 'origen' => $c[2],
        'tipo'  => $c[3], 'mensaje' => $c[4], 'tag' => $c[5], 'ip' => $c[6],
    ];
}
}

if (!function_exists('auditoria_coincide')) {
/** ¿Este evento pasa los filtros? */
function auditoria_coincide(array $f, array $filtros): bool
{
    if (($filtros['tag']   ?? '') !== '' && $f['tag']   !== $filtros['tag'])   { return false; }
    if (($filtros['tipo']  ?? '') !== '' && $f['tipo']  !== $filtros['tipo'])  { return false; }
    if (($filtros['fecha'] ?? '') !== '' && $f['fecha'] !== $filtros['fecha']) { return false; }
    $q = trim((string)($filtros['q'] ?? ''));
    if ($q !== '' && stripos($f['mensaje'] . ' ' . $f['origen'], $q) === false) { return false; }
    return true;
}
}

if (!function_exists('auditoria_leer')) {
/**
 * Los eventos más nuevos que pasan los filtros, del más nuevo al más viejo.
 *
 * @param int $saltar  Cuántos descartar antes de empezar a juntar (la página).
 * @param int $cuantos Cuántos devolver.
 * @param int $techo   Renglones como máximo a mirar. Es el freno: con un filtro
 *                     que no encuentra nada, sin esto se recorrería el archivo
 *                     entero buscando algo que no está.
 * @return array{filas: array, hay_mas: bool, mirados: int, agotado: bool}
 *         `agotado` = se llegó al principio del archivo, así que no hay más
 *         para atrás; distinto de `hay_mas`, que mira hacia adelante.
 */
function auditoria_leer(string $archivo, array $filtros = [], int $saltar = 0, int $cuantos = 50, int $techo = 50000): array
{
    $vacio = ['filas' => [], 'hay_mas' => false, 'mirados' => 0, 'agotado' => true];
    if (!is_file($archivo)) { return $vacio; }
    $h = @fopen($archivo, 'rb');
    if (!$h) { return $vacio; }

    $tam = filesize($archivo) ?: 0;
    $pos = $tam;
    $resto = '';          // el pedazo de renglón cortado por el borde del bloque
    $encontrados = 0;     // cuántos pasaron el filtro (incluye los que se saltan)
    $mirados = 0;
    $filas = [];
    $hayMas = false;

    while ($pos > 0 && $mirados < $techo) {
        $leer = (int)min(AUDITORIA_PEDAZO, $pos);
        $pos -= $leer;
        fseek($h, $pos);
        $bloque = (string)fread($h, $leer) . $resto;

        /* El primer renglón del bloque casi nunca empieza en el borde: se guarda
           para pegarlo al final del bloque siguiente, que es el que lo empieza.
           Si ya estamos en el byte 0, no hay bloque siguiente y el pedazo ES el
           renglón entero. */
        $lineas = explode("\n", $bloque);
        $resto  = $pos > 0 ? array_shift($lineas) : '';

        for ($i = count($lineas) - 1; $i >= 0; $i--) {
            $mirados++;
            $f = auditoria_fila($lineas[$i]);
            if ($f === null || !auditoria_coincide($f, $filtros)) { continue; }
            $encontrados++;
            if ($encontrados <= $saltar) { continue; }
            if (count($filas) < $cuantos) { $filas[] = $f; continue; }
            $hayMas = true;     // hay al menos uno más: alcanza para el «siguiente»
            break 2;
        }
    }
    fclose($h);

    return [
        'filas'   => $filas,
        'hay_mas' => $hayMas,
        'mirados' => $mirados,
        'agotado' => $pos <= 0 && !$hayMas,
    ];
}
}

if (!function_exists('auditoria_valores')) {
/**
 * Los tipos y los tags que hay, para llenar los desplegables del filtro.
 *
 * Mira sólo lo reciente y no el archivo entero: un tipo de evento que no
 * aparece hace cinco mil renglones tampoco se va a querer filtrar, y recorrer
 * todo para armar un desplegable es justo el costo que se quería sacar.
 *
 * @return array{tipos: string[], tags: string[]}
 */
function auditoria_valores(string $archivo, int $techo = 5000): array
{
    $r = auditoria_leer($archivo, [], 0, $techo, $techo);
    $tipos = []; $tags = [];
    foreach ($r['filas'] as $f) {
        if ($f['tipo'] !== '') { $tipos[$f['tipo']] = true; }
        if ($f['tag']  !== '') { $tags[$f['tag']]   = true; }
    }
    $tipos = array_keys($tipos); $tags = array_keys($tags);
    sort($tipos); sort($tags);
    return ['tipos' => $tipos, 'tags' => $tags];
}
}

if (!function_exists('auditoria_insignia')) {
/**
 * De qué color va un evento.
 *
 * Un log donde todo se ve igual obliga a leerlo entero para encontrar lo que
 * importa. Lo que importa es lo que falló.
 */
function auditoria_insignia(string $tipo): string
{
    $t = strtoupper($tipo);
    if (str_contains($t, 'ERROR') || str_contains($t, 'FAIL'))  { return 'ih-badge--danger'; }
    if (str_contains($t, 'FALLIDO'))                            { return 'ih-badge--warning'; }
    if (str_contains($t, 'ELIMINAR'))                           { return 'ih-badge--danger'; }
    if (str_contains($t, 'LOGIN'))                              { return 'ih-badge--success'; }
    if (str_contains($t, 'ARCHIVO') || str_contains($t, 'RESTAURAR')) { return 'ih-badge--info'; }
    return 'ih-badge--neutral';
}
}
