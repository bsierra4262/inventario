<?php
/**
 * Lo que hay que borrar cuando un activo se elimina de verdad.
 *
 * Esto vive en un módulo y no dentro del endpoint por una razón concreta: el
 * esquema del tenant no tiene claves foráneas —es una decisión vieja del
 * proyecto— así que nadie borra las dependencias por nosotros. La lista de
 * tablas que apuntan a un activo es, entonces, una lista escrita a mano, y una
 * lista escrita a mano en medio de un endpoint es una lista que se olvida: el
 * día que alguien agregue una tabla nueva, el activo se va a borrar y van a
 * quedar sus puertos de red colgando de un id que ya no existe.
 *
 * Acá está una sola vez, la usa el endpoint, y la batería de integración la
 * ejercita contra una base real para comprobar que no quede nada huérfano.
 *
 * El historial (`producto_movimientos`) NO está en la lista a propósito: es el
 * único rastro de que el equipo existió y se conserva. Ver
 * backend/eliminar_definitivo.php.
 */

declare(strict_types=1);

if (!defined('PAPELERA_MOTIVOS')) {
    /**
     * Por qué se archiva un activo: lo que se guarda => lo que se lee.
     *
     * La clave es el valor que termina en la base y en el historial, y por eso
     * no se toca: hay activos archivados con esos textos. El valor del arreglo
     * es lo que ve la persona, y eso sí se puede escribir bien —«Perdida» sin
     * tilde estaba a la vista en el desplegable, y «Scrapping» es una palabra
     * que no dice nada en una pantalla en castellano.
     *
     * Está acá porque la lista la necesitan dos pantallas que no se hablan:
     * backend/delete.php, que ofrece los motivos al archivar, e
     * inventario/reportes.php, que filtra por ellos. Escritas por separado, una
     * puede ofrecer un motivo que la otra no sabe filtrar.
     */
    define('PAPELERA_MOTIVOS', [
        'Venta'     => 'Se vendió',
        'Donación'  => 'Se donó',
        'Scrapping' => 'Se dio de baja por obsoleto',
        'Desguace'  => 'Se desarmó para repuestos',
        'Perdida'   => 'Se perdió',
        'Robo'      => 'Se lo robaron',
    ]);
}

if (!defined('PAPELERA_TABLAS_DEPENDIENTES')) {
    /**
     * Las tablas con una columna `producto_id`.
     *
     * Si agregás una tabla que apunte a un activo, va acá. El test
     * «Eliminar borra todo lo que colgaba del activo» compara esta lista con
     * las tablas que el esquema realmente tiene, así que olvidarse falla.
     */
    define('PAPELERA_TABLAS_DEPENDIENTES', [
        'activo_so',          // el sistema operativo
        'activo_componente',  // discos, memoria, procesadores
        'activo_infocom',     // compra, garantía, amortización
        'campos_valor',       // los campos que cada empresa se definió
        'contrato_activo',    // qué contratos lo cubren
        'documento_activo',   // qué documentos lo mencionan
        'notas',              // lo que alguien dejó escrito
        'puertos_red',        // las interfaces (y por debajo, sus IP)
    ]);
}

if (!function_exists('papelera_borrar_dependencias')) {
/**
 * Borra todo lo que colgaba de un activo. No borra el activo ni su historial.
 *
 * `$tabla` resuelve el nombre real de una tabla del tenant. En la aplicación es
 * `tn`; en las pruebas es una función que le pega el prefijo de la base
 * descartable. Se pasa como parámetro porque `tn()` depende de la sesión y una
 * prueba no tiene sesión.
 *
 * Devuelve cuántas filas se borraron, para poder decirlo en el log.
 */
function papelera_borrar_dependencias(mysqli $conn, int $productoId, callable $tabla): int
{
    $borradas = 0;

    /* Las direcciones IP cuelgan del puerto, no del activo: van primero, porque
       después de borrar el puerto ya no hay por dónde encontrarlas. */
    $tIp = $tabla('direcciones_ip');
    $tPu = $tabla('puertos_red');
    $st  = $conn->prepare(
        "DELETE di FROM `{$tIp}` di
           INNER JOIN `{$tPu}` pu ON pu.id = di.puerto_id
         WHERE pu.producto_id = ?"
    );
    $st->bind_param('i', $productoId);
    $st->execute();
    $borradas += $st->affected_rows;
    $st->close();

    foreach (PAPELERA_TABLAS_DEPENDIENTES as $nombre) {
        $t  = $tabla($nombre);
        $st = $conn->prepare("DELETE FROM `{$t}` WHERE producto_id = ?");
        $st->bind_param('i', $productoId);
        $st->execute();
        $borradas += $st->affected_rows;
        $st->close();
    }

    // Las relaciones entre activos apuntan en los dos sentidos: el monitor que
    // cuelga de la notebook está guardado una sola vez, y puede estar de
    // cualquiera de los dos lados.
    $tRel = $tabla('activo_relacion');
    $st   = $conn->prepare("DELETE FROM `{$tRel}` WHERE origen_id = ? OR destino_id = ?");
    $st->bind_param('ii', $productoId, $productoId);
    $st->execute();
    $borradas += $st->affected_rows;
    $st->close();

    return $borradas;
}
}
