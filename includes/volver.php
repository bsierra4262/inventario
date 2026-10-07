<?php
/**
 * De dónde vino la persona, para poder devolverla ahí.
 *
 * El problema concreto: desde el listado se entra a editar un activo, se
 * cancela, y la edición devolvía SIEMPRE al detalle del activo — una pantalla
 * por la que nadie había pasado. «Cancelar» tiene que deshacer el paso, no
 * llevar a un lugar nuevo.
 *
 * La forma: quien enlaza a una pantalla de ida y vuelta le pasa `?volver=`
 * con su propia dirección, y esa pantalla usa ese valor para su «Cancelar» y su
 * «Volver». Si no viene, se cae a un destino razonable.
 *
 * ## Por qué no `HTTP_REFERER`
 *
 * Porque no llega siempre —hay navegadores y proxies que lo recortan, y no
 * aparece al venir de un POST— y porque no se puede confiar en él: lo manda el
 * cliente. Un parámetro explícito es la misma información, pero puesta por
 * nosotros y validada.
 *
 * ## Por qué una lista blanca y no «cualquier cosa que empiece con /»
 *
 * Un destino que viene del cliente y se usa en un `Location:` es una
 * redirección abierta: `?volver=//otro-sitio.com` lleva a otro dominio, y el
 * navegador lo trata como una URL absoluta sin protocolo. La lista blanca de
 * prefijos corta eso de raíz; `\` y `%5c` también se descartan porque algunos
 * navegadores los tratan como `/`.
 */

declare(strict_types=1);

if (!defined('VOLVER_PREFIJOS')) {
    /** Los únicos lugares a los que se puede volver. */
    define('VOLVER_PREFIJOS', ['/inventario/', '/backend/', '/dashboard/', '/auth/']);
}

if (!function_exists('volver_destino')) {
/**
 * El destino de «Volver», ya validado.
 *
 * @param string $pordefecto A dónde ir si no vino nada o si lo que vino no sirve.
 */
function volver_destino(string $pordefecto = '/inventario/index.php'): string
{
    $crudo = (string)($_GET['volver'] ?? $_POST['volver'] ?? '');
    if ($crudo === '') { return $pordefecto; }

    // Una sola decodificación: si después de decodificar sigue habiendo un `%`,
    // es un doble encodeado y no un destino honesto.
    $url = rawurldecode($crudo);
    if ($url !== $crudo && str_contains($url, '%')) { return $pordefecto; }

    // Nada de barras invertidas ni saltos de línea (partir una cabecera HTTP).
    if (preg_match('/[\\\\\r\n\t\0]/', $url)) { return $pordefecto; }

    // Tiene que ser una ruta de este sitio: una sola barra al principio.
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) { return $pordefecto; }

    /* Y de una de las secciones conocidas, con el camino ya resuelto: sin esto
       `/inventario/../../otra-cosa` pasaba el filtro por empezar con el prefijo
       correcto, aunque al resolverlo apunte a otro lado. No sale del sitio
       —sigue siendo una ruta relativa— pero tampoco es a donde dice ir. */
    $camino = explode('?', $url, 2)[0];
    $partes = [];
    foreach (explode('/', $camino) as $trozo) {
        if ($trozo === '' || $trozo === '.') { continue; }
        if ($trozo === '..') { array_pop($partes); continue; }
        $partes[] = $trozo;
    }
    $normal = '/' . implode('/', $partes);

    foreach (VOLVER_PREFIJOS as $p) {
        if (str_starts_with($normal, $p)) { return $url; }
    }
    return $pordefecto;
}
}

if (!function_exists('volver_aqui')) {
/**
 * La dirección actual, lista para colgar de un enlace: `volver=/inventario/...`
 *
 * Se usa al armar los enlaces que llevan a una pantalla de ida y vuelta:
 *
 *   <a href="/backend/edit.php?id=7&<?= volver_aqui() ?>">Editar</a>
 *
 * Si la pantalla actual ya trae un `volver`, se conserva ESE: así una cadena
 * de tres pantallas —listado → detalle → edición— vuelve al listado y no al
 * detalle, que es de donde la persona venía de verdad.
 */
function volver_aqui(): string
{
    $actual = (string)($_SERVER['REQUEST_URI'] ?? '');

    // Se saca el propio `volver` de la dirección para no encadenarlos.
    [$camino, $query] = array_pad(explode('?', $actual, 2), 2, '');
    parse_str($query, $partes);
    $heredado = (string)($partes['volver'] ?? '');
    unset($partes['volver']);
    $limpia = $camino . ($partes !== [] ? '?' . http_build_query($partes) : '');

    $destino = $heredado !== '' ? $heredado : $limpia;
    return 'volver=' . rawurlencode($destino);
}
}

if (!function_exists('volver_boton')) {
/**
 * El botón «Volver» del topbar, con su destino ya resuelto.
 *
 * Devuelve HTML porque así lo espera `$topbar_actions`, que las pantallas
 * arman como una cadena.
 */
function volver_boton(string $pordefecto = '/inventario/index.php', string $texto = 'Volver'): string
{
    return '<a href="' . htmlspecialchars(volver_destino($pordefecto), ENT_QUOTES)
         . '" class="ih-btn ih-btn--ghost ih-btn--sm">'
         . '<i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">'
         . htmlspecialchars($texto, ENT_QUOTES) . '</span></a>';
}
}
