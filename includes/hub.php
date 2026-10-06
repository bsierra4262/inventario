<?php
/**
 * Tarjetas de acceso para las pantallas que agrupan a otras.
 *
 * La fila de pestañas del portal llegó a tener once entradas. Varias no decían
 * nada por sí solas: «Listas», «Campos», y sobre todo «Importar», que abría una
 * pantalla titulada «Backup & Migración». El rótulo y el contenido no coincidían.
 *
 * Dos de esas pestañas —Datos y Configuración— pasaron a ser pantallas que
 * agrupan al resto, y cada opción se presenta con una frase que explica para
 * qué sirve. El nombre solo no alcanza: «Campos» no le dice nada a nadie,
 * «Qué datos se le piden a cada tipo de activo» sí.
 *
 * Una opción que el rol actual no puede usar se dibuja apagada y sin enlace, en
 * vez de desaparecer: así la pantalla no cambia de forma según quién entra, y
 * quien no tiene el permiso ve que la función existe y puede pedirla.
 */

declare(strict_types=1);

if (!function_exists('hub_grupo')) {
/**
 * Título de un grupo de opciones.
 */
function hub_grupo(string $titulo): void {
    ?>
    <div class="ih-hub-grupo">
      <h2 class="ih-hub-grupo__titulo"><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h2>
    </div>
    <?php
}
}

if (!function_exists('hub_tarjetas')) {
/**
 * Una grilla de tarjetas.
 *
 * Cada opción: ['url', 'icono' (clase de Bootstrap Icons), 'titulo', 'desc',
 * 'puede' => bool, 'insignia' => string|null].
 */
function hub_tarjetas(array $opciones): void {
    ?>
    <div class="ih-hub">
    <?php foreach ($opciones as $o):
        $puede   = (bool)($o['puede'] ?? true);
        $etiqueta = (string)$o['titulo'];
        ?>
      <?php if ($puede): ?>
        <a class="ih-hub__item" href="<?= htmlspecialchars((string)$o['url'], ENT_QUOTES, 'UTF-8') ?>">
      <?php else: ?>
        <div class="ih-hub__item es-bloqueado" aria-disabled="true"
             title="Tu rol no tiene permiso para esta opción">
      <?php endif; ?>
          <span class="ih-hub__icono" aria-hidden="true">
            <i class="bi <?= htmlspecialchars((string)$o['icono'], ENT_QUOTES, 'UTF-8') ?>"></i>
          </span>
          <span class="ih-hub__texto">
            <span class="ih-hub__titulo">
              <?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?>
              <?php if (!empty($o['insignia'])): ?>
                <span class="ih-badge ih-badge--purple"><?= htmlspecialchars((string)$o['insignia'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
              <?php if (!$puede): ?>
                <span class="ih-badge ih-badge--neutral">Sin permiso</span>
              <?php endif; ?>
            </span>
            <span class="ih-hub__desc"><?= htmlspecialchars((string)$o['desc'], ENT_QUOTES, 'UTF-8') ?></span>
          </span>
          <?php if ($puede): ?>
            <svg class="ih-hub__flecha" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="m9 18 6-6-6-6"/>
            </svg>
          <?php endif; ?>
      <?= $puede ? '</a>' : '</div>' ?>
    <?php endforeach; ?>
    </div>
    <?php
}
}

if (!function_exists('hub_volver')) {
/**
 * Botón de vuelta al índice del que cuelga la pantalla.
 *
 * Nombra el destino en vez de decir sólo «Volver»: estas pantallas se abren
 * desde Datos o desde Configuración, y un «Volver» a secas no dice a cuál. Antes
 * todas volvían a la lista de activos, que no es de donde se venía.
 */
function hub_volver(string $destino): string {
    $mapa = [
        'configuracion' => ['/inventario/configuracion.php', 'Configuración'],
        'datos'         => ['/inventario/datos.php',         'Datos'],
        'inventario'    => ['/inventario/index.php',         'Activos'],
    ];
    [$url, $texto] = $mapa[$destino] ?? $mapa['inventario'];
    return '<a href="' . $url . '" class="ih-btn ih-btn--ghost ih-btn--sm">'
         . '<i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">' . $texto . '</span></a>';
}
}
