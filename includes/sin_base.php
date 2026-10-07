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
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 15');
        header('Cache-Control: no-store');
    }

    $page_title = 'Servicio no disponible';
    require __DIR__ . '/layout/head.php';
    ?>
    <div class="ih-auth">
      <div class="ih-auth__card ih-anim-fade">
        <div class="ih-auth__header">
          <div class="ih-auth__brand">
            <div class="ih-auth__logo"><?= function_exists('platform_logo_html') ? platform_logo_html() : 'i' ?></div>
            <div class="ih-auth__brand-text"><strong>INTELLIHELP</strong><span>Asset Vault</span></div>
          </div>
          <h2 class="ih-auth__title">Servicio no disponible</h2>
          <p class="ih-auth__subtitle"><?= htmlspecialchars($subtitulo) ?></p>
        </div>
        <div class="ih-auth__body">
          <div class="ih-alert ih-alert--warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= htmlspecialchars($detalle) ?></div>
          </div>
          <?php /* Reintentar un GET es volver a pedir la misma dirección. Reintentar un
                   POST no: el navegador tendría que volver a enviar el formulario, y la
                   dirección del endpoint pedida por GET contesta «acción no permitida».
                   Así que en ese caso el botón lleva al listado. */
          $sb_volver = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')
              ? (string)($_SERVER['REQUEST_URI'] ?? '/inventario/index.php')
              : '/inventario/index.php'; ?>
          <a class="ih-btn ih-btn--primary ih-btn--block" href="<?= htmlspecialchars($sb_volver) ?>">
            <i class="bi bi-arrow-clockwise"></i> Volver a intentar
          </a>
        </div>
        <div class="ih-auth__footer">© <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault</div>
      </div>
    </div>
    <?php
    require __DIR__ . '/layout/foot.php';
    exit;
}
}
