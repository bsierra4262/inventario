<?php
/**
 * Una pantalla entera para decir una sola cosa, con el diseño del sistema.
 *
 * Es para los cortes: la sesión venció, el formulario caducó, la base no
 * contesta. Son pantallas que casi nadie ve y que, justamente por eso, se
 * escriben con un `echo '<h1>…</h1>'` y se olvidan. El resultado es que el
 * único momento en que algo sale mal es también el único momento en que la
 * aplicación se ve rota: Times New Roman sobre fondo blanco, sin menú, sin
 * salida. La persona no sabe si perdió lo que había cargado, ni cómo volver.
 *
 * Tres cosas que tiene que hacer una pantalla así, y que un `<h1>` suelto no
 * hace: decir **qué pasó** sin jerga, decir **si se perdió algo**, y dar un
 * **camino de vuelta**. El código HTTP correcto es para los programas; para la
 * persona lo que importa son esas tres.
 *
 * No toca la base: se usa cuando la base puede ser justamente lo que falló.
 */

declare(strict_types=1);

if (!function_exists('pantalla_aviso')) {
/**
 * Dibuja la pantalla y termina el request.
 *
 * @param array{
 *   titulo:string, subtitulo?:string, detalle?:string,
 *   tono?:string, icono?:string, codigo?:int,
 *   acciones?: array<array{texto:string, href?:string, onclick?:string, tipo?:string, icono?:string}>
 * } $op
 */
function pantalla_aviso(array $op): never {
    $titulo    = (string)($op['titulo'] ?? 'No se pudo completar la acción');
    $subtitulo = (string)($op['subtitulo'] ?? '');
    $detalle   = (string)($op['detalle'] ?? '');
    $tono      = (string)($op['tono'] ?? 'warning');     // info | warning | danger
    $icono     = (string)($op['icono'] ?? 'exclamation-triangle-fill');
    $codigo    = (int)($op['codigo'] ?? 0);
    $acciones  = $op['acciones'] ?? [];

    if ($codigo > 0 && !headers_sent()) {
        http_response_code($codigo);
        header('Cache-Control: no-store');
    }

    /* Si alguien ya empezó a escribir la respuesta, el documento que sale es
       mitad pantalla y mitad esto. Se tira lo escrito: media pantalla rota es
       peor que una pantalla entera de aviso. */
    if (ob_get_level() > 0) { @ob_end_clean(); }

    $page_title = $titulo;
    require __DIR__ . '/layout/head.php';
    ?>
    <div class="ih-auth">
      <div class="ih-auth__card ih-anim-fade">
        <div class="ih-auth__header">
          <div class="ih-auth__brand">
            <div class="ih-auth__logo"><?= function_exists('platform_logo_html') ? platform_logo_html() : 'i' ?></div>
            <div class="ih-auth__brand-text"><strong>INTELLIHELP</strong><span>Asset Vault</span></div>
          </div>
          <h2 class="ih-auth__title"><?= htmlspecialchars($titulo) ?></h2>
          <?php if ($subtitulo !== ''): ?>
            <p class="ih-auth__subtitle"><?= htmlspecialchars($subtitulo) ?></p>
          <?php endif; ?>
        </div>
        <div class="ih-auth__body">
          <?php if ($detalle !== ''): ?>
            <div class="ih-alert ih-alert--<?= htmlspecialchars($tono) ?>">
              <i class="bi bi-<?= htmlspecialchars($icono) ?>"></i>
              <div><?= $detalle /* Lo arma quien llama; puede traer <strong>. */ ?></div>
            </div>
          <?php endif; ?>
          <?php foreach ($acciones as $i => $a):
              $tipo = (string)($a['tipo'] ?? ($i === 0 ? 'primary' : 'ghost')); ?>
            <a class="ih-btn ih-btn--<?= htmlspecialchars($tipo) ?> ih-btn--block ih-mb-2"
               href="<?= htmlspecialchars((string)($a['href'] ?? '#')) ?>"
               <?= !empty($a['onclick']) ? 'onclick="' . htmlspecialchars((string)$a['onclick'], ENT_QUOTES) . '"' : '' ?>>
              <?php if (!empty($a['icono'])): ?><i class="bi bi-<?= htmlspecialchars((string)$a['icono']) ?>"></i><?php endif; ?>
              <?= htmlspecialchars((string)$a['texto']) ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="ih-auth__footer">© <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault</div>
      </div>
    </div>
    <?php
    require __DIR__ . '/layout/foot.php';
    exit;
}
}
