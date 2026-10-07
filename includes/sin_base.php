<?php
/**
 * «El servicio no está disponible en este momento», con el diseño del sistema.
 *
 * Antes, cuando la base no respondía, `backend/db.php` redirigía a
 * `/install.php` desde cuatro lugares distintos. Para el caso que lo motivó
 * —un entorno sin preparar— está bien; para una base que tardó un instante en
 * contestar, no: una persona con la sesión abierta, en medio de la papelera,
 * terminaba en el instalador. Y «instalar» es la única acción que el instalador
 * ofrece, así que la pantalla no sólo desorienta: invita a hacer lo peor que se
 * puede hacer ahí.
 *
 * Pasaba de verdad. En hosting compartido, al tocar el límite de conexiones
 * simultáneas, `mysqli` no dice «demasiadas conexiones» sino
 * `Operation not permitted`, que no se parece a nada. Lo que se arregló
 * primero fue la causa —una sola conexión a la master por pedido, ver
 * `core/db/master_connection.php`—; esto es lo que tiene que pasar si igual
 * falla.
 *
 * Responde **503 con `Retry-After`**: para quien mira, es la misma tarjeta que
 * la del acceso; para un buscador o un monitor, es «volvé en un rato» y no
 * «esta página no existe» ni «todo bien».
 *
 * La tarjeta estaba escrita dos veces dentro de `auth/login.php`, con dos
 * textos distintos para la misma situación. Vive acá una sola vez.
 */

declare(strict_types=1);

if (!function_exists('pantalla_sin_base')) {
/**
 * Dibuja la pantalla y termina el request.
 *
 * @param string $subtitulo Qué no se pudo hacer, en el idioma de la persona.
 * @param string $detalle   El párrafo del aviso.
 */
function pantalla_sin_base(
    string $subtitulo = 'No se puede acceder al inventario en este momento',
    string $detalle   = 'La base de datos no respondió. Es algo momentáneo: volvé a intentar en unos segundos. Si sigue igual, avisale al administrador del sistema.'
): never {
    if (!headers_sent()) { header('Retry-After: 15'); }
    require_once __DIR__ . '/pantalla_aviso.php';
    pantalla_aviso([
        'titulo'    => 'Servicio no disponible',
        'subtitulo' => $subtitulo,
        'detalle'   => htmlspecialchars($detalle),
        'tono'      => 'warning',
        'icono'     => 'exclamation-triangle-fill',
        'codigo'    => 503,
        'acciones'  => [
            /* Reintentar un GET es volver a pedir la misma dirección. Reintentar
               un POST no: el navegador tendría que volver a enviar el formulario,
               y la dirección del endpoint pedida por GET contesta «acción no
               permitida». Así que en ese caso el botón lleva al listado. */
            ['texto' => 'Volver a intentar', 'icono' => 'arrow-clockwise',
             'href'  => (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')
                        ? (string)($_SERVER['REQUEST_URI'] ?? '/inventario/index.php')
                        : '/inventario/index.php'],
        ],
    ]);
}
}
