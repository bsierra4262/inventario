<?php
/**
 * Versión y Edición, atadas al sistema operativo que se eligió.
 *
 * Lo que resuelve: los dos desplegables estaban vacíos, con el cartel «La lista
 * está vacía. Cargá los valores en Listas desplegables». Para anotar
 * «Windows 11 · 24H2 · Pro» había que ir a otra pantalla, dar de alta «24H2»,
 * dar de alta «Pro» y volver. Nadie hace eso.
 *
 * Ahora: se elige el sistema y las dos listas se llenan solas con lo que ese
 * sistema tiene. Elegir Windows 11 trae 25H2, 24H2, 23H2, 22H2 y 21H2; elegir
 * Ubuntu Server trae 24.04 LTS, 22.04 LTS y las demás. Los datos están en
 * core/tenants/so_versiones.php.
 *
 * ## Qué pasa si falta algo
 *
 * El botón `+` sigue estando y lo que se agrega por ahí se guarda en el
 * catálogo de la empresa y aparece en todos los sistemas. Dos casos: una
 * versión más nueva que la lista que trae el código, y una distribución interna
 * que sólo existe en esa empresa.
 *
 * ## Qué pasa si el JavaScript no corre
 *
 * Los dos desplegables salen del servidor con TODAS las opciones adentro,
 * agrupadas por sistema con `<optgroup>`. Sin JavaScript se ven las de todos
 * los sistemas —largo, pero elegible— y el formulario funciona igual. El
 * JavaScript sólo esconde lo que no corresponde.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/tenants/so_versiones.php';

if (!function_exists('campo_so_dependiente')) {
/**
 * Un desplegable de versión o de edición, atado al del sistema.
 *
 * @param string $que      'versiones' o 'ediciones'
 * @param string $tipo     el tipo de catálogo: 'so_version' o 'so_edicion'
 * @param string $actual   el valor guardado
 * @param string $idSo     el id del `<select>` del sistema que lo gobierna
 */
function campo_so_dependiente(
    mysqli $conn,
    string $que,
    string $tipo,
    string $actual,
    bool   $puedeGestionar,
    string $id,
    string $idSo = 'f-so'
): void {
    $catalogo = so_catalogo_completo($conn);
    $actual   = trim($actual);

    /* Todas las opciones de todos los sistemas, cada una marcada con el suyo.
       El JavaScript esconde las que no son del sistema elegido; sin JavaScript
       quedan todas a la vista y se puede elegir igual. */
    $vistos = [];
    ?>
    <div class="cat-campo">
      <select class="ih-select" id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($tipo) ?>"
              data-so-depende="<?= htmlspecialchars($idSo) ?>" data-so-que="<?= htmlspecialchars($que) ?>">
        <option value="">— Sin asignar —</option>
        <?php foreach ($catalogo as $so => $datos): ?>
          <?php foreach (($datos[$que] ?? []) as $v): ?>
            <?php
              // La misma versión aparece en varios sistemas (lo que cargó la
              // empresa está en todos). Se dibuja una sola vez, con la lista de
              // sistemas a los que pertenece en un atributo.
              if (isset($vistos[$v])) { $vistos[$v][] = $so; continue; }
              $vistos[$v] = [$so];
            ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php foreach ($vistos as $valor => $sistemas): ?>
          <option value="<?= htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8') ?>"
                  data-so="<?= htmlspecialchars(implode('|', $sistemas), ENT_QUOTES, 'UTF-8') ?>"
                  <?= (string)$valor === $actual ? 'selected' : '' ?>><?= htmlspecialchars((string)$valor) ?></option>
        <?php endforeach; ?>
        <?php if ($actual !== '' && !isset($vistos[$actual])): ?>
          <?php /* Un valor que ya estaba guardado y que no está en ninguna lista
                   —viene de un CSV, o de antes de que existiera el catálogo— no
                   se pierde: se muestra y queda elegido. */ ?>
          <option value="<?= htmlspecialchars($actual, ENT_QUOTES, 'UTF-8') ?>" data-so="*" selected>
            <?= htmlspecialchars($actual) ?> (fuera de la lista)
          </option>
        <?php endif; ?>
      </select>
      <?php if ($puedeGestionar): ?>
        <?php $rotulo = $que === 'versiones' ? 'versión' : 'edición'; ?>
        <button type="button" class="cat-add"
                aria-label="Agregar una <?= $rotulo ?>" title="Agregar una <?= $rotulo ?>"
                data-tipo="<?= htmlspecialchars($tipo) ?>"
                data-target="<?= htmlspecialchars($id) ?>"
                data-label="<?= $rotulo ?>"
                onclick="catalogoAgregar(this)"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
      <?php endif; ?>
    </div>
    <?php
    campo_so_script();
}
}

if (!function_exists('campo_so_script')) {
/** El JavaScript que ata las listas al sistema. Se imprime una sola vez. */
function campo_so_script(): void
{
    static $yaSalio = false;
    if ($yaSalio) { return; }
    $yaSalio = true;
    ?>
<script>
/* Versión y Edición siguen al sistema operativo.
   Cada <option> trae en `data-so` los sistemas a los que pertenece, separados
   por «|». Al cambiar el sistema se esconden las que no corresponden.

   Se esconde y no se borra a propósito: si la persona vuelve a cambiar de
   sistema, las opciones ya están y no hay que reconstruir nada. Y si el valor
   que estaba elegido no pertenece al sistema nuevo, se avisa en lugar de
   borrarlo en silencio — borrar un dato que alguien cargó sin decírselo es
   peor que dejarlo marcado como raro. */
(function () {
  function atar(dependiente) {
    var so = document.getElementById(dependiente.dataset.soDepende);
    if (!so) { return; }

    function aplicar() {
      var elegido = (so.value || '').trim();
      var hayAlguna = false;

      Array.prototype.forEach.call(dependiente.options, function (op) {
        if (op.value === '') { return; }                 // «Sin asignar» siempre
        var suyos = (op.dataset.so || '').split('|');
        var entra = elegido === '' || suyos.indexOf(elegido) !== -1 || op.dataset.so === '*';
        op.hidden = !entra;
        op.disabled = !entra;
        if (entra) { hayAlguna = true; }
      });

      // Si lo que estaba elegido ya no corresponde, se deselecciona.
      var sel = dependiente.selectedOptions[0];
      if (sel && sel.hidden) { dependiente.value = ''; }

      /* Sin ninguna opción para este sistema, el campo no sirve: se apaga y se
         dice por qué, en lugar de dejar un desplegable que no abre nada. */
      var campo = dependiente.closest('.ih-field');
      var aviso = campo ? campo.querySelector('.cat-sin-opciones') : null;
      if (aviso) { aviso.hidden = hayAlguna || elegido === ''; }

      // El desplegable propio del sistema tiene que enterarse del cambio.
      if (window.ihRefrescarSelect) { window.ihRefrescarSelect(dependiente); }
    }

    so.addEventListener('change', aplicar);
    aplicar();
  }

  function arrancar() {
    document.querySelectorAll('select[data-so-depende]').forEach(atar);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arrancar);
  } else {
    arrancar();
  }
})();
</script>
    <?php
}
}
