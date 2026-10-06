<?php
/**
 * Render de los campos específicos por categoría definidos en datos.
 *
 * ## Por qué esto convive con los bloques fijos del formulario
 *
 * Los catorce campos viejos (`patchcord_tipo`, `bobina_longitud`, …) siguen
 * teniendo su columna en `productos` y su bloque escrito a mano en el alta y en
 * la edición. Eso **no se toca**: funciona, y reescribir un formulario de mil
 * líneas con su JavaScript para no cambiar nada de lo que el usuario ve es
 * riesgo sin beneficio.
 *
 * Lo que este archivo agrega es el camino nuevo: los campos que **no** tienen
 * columna heredada se dibujan solos a partir de `campos_definicion`. Con eso ya
 * se cumple lo que importa — **agregar una categoría nueva dejó de requerir un
 * `ALTER TABLE`** y de obligar a tocar trece archivos.
 *
 * Retirar los bloques fijos es el paso siguiente, cuando el camino nuevo esté
 * probado en producción. Agregar la forma nueva y sacar la vieja en un mismo
 * movimiento es como se rompen las cosas.
 *
 * ## Cómo se muestran
 *
 * La categoría se elige en el mismo formulario, así que no se puede decidir en
 * el servidor cuáles mostrar: se emiten todos los ámbitos ocultos y un poco de
 * JavaScript muestra el que corresponda, igual que ya hacían los bloques fijos.
 * La validación real es del lado del servidor (`campos_guardar`), como siempre.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/tenants/campos.php';

if (!function_exists('campos_dinamicos_render')) {
/**
 * Dibuja los campos configurables (los que no tienen columna heredada).
 *
 * @param array<string,string> $valores Valores actuales, por clave.
 */
function campos_dinamicos_render(mysqli $conn, array $valores = []): void {
    $defs = [];
    foreach (campos_definiciones($conn) as $d) {
        if ($d['activo'] !== 1) { continue; }
        if ((string)($d['columna_legacy'] ?? '') !== '') { continue; } // ese ya lo dibuja el bloque fijo
        $defs[] = $d;
    }
    if (!$defs) { return; }

    // Agrupar por ámbito: categoría + subcategoría.
    $porAmbito = [];
    foreach ($defs as $d) {
        $clave = (string)($d['categoria'] ?? '') . '||' . (string)($d['subcategoria'] ?? '');
        $porAmbito[$clave][] = $d;
    }

    echo '<div id="campos-dinamicos">';
    foreach ($porAmbito as $ambito => $lista) {
        [$cat, $sub] = explode('||', $ambito, 2);
        ?>
        <div class="ih-form-grid campos-dinamicos__grupo"
             data-cat="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
             data-sub="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>" hidden>
        <?php foreach ($lista as $d):
            $clave = (string)$d['clave'];
            $val   = (string)($valores[$clave] ?? '');
            $id    = 'f-campo-' . $clave; ?>
            <div class="ih-field">
              <label class="ih-label" for="<?= htmlspecialchars($id) ?>">
                <?= htmlspecialchars((string)$d['etiqueta'], ENT_QUOTES, 'UTF-8') ?>
              </label>
              <?php if ((string)$d['tipo'] === 'lista'): ?>
                <select id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($clave) ?>" class="ih-select">
                  <option value="">— Seleccioná —</option>
                  <?php foreach ($d['opciones'] as $op): ?>
                    <option value="<?= htmlspecialchars($op, ENT_QUOTES, 'UTF-8') ?>" <?= $val === $op ? 'selected' : '' ?>>
                      <?= htmlspecialchars($op, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ((string)$d['tipo'] === 'booleano'): ?>
                <select id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($clave) ?>" class="ih-select">
                  <option value="0" <?= $val !== '1' ? 'selected' : '' ?>>No</option>
                  <option value="1" <?= $val === '1' ? 'selected' : '' ?>>Sí</option>
                </select>
              <?php else:
                $tipoInput = match ((string)$d['tipo']) {
                    'numero' => 'number',
                    'fecha'  => 'date',
                    default  => 'text',
                }; ?>
                <input type="<?= $tipoInput ?>" id="<?= htmlspecialchars($id) ?>"
                       name="<?= htmlspecialchars($clave) ?>" class="ih-input"
                       value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php
    }
    echo '</div>';

    campos_dinamicos_js();
}
}

if (!function_exists('campos_dinamicos_js')) {
/**
 * Muestra el grupo que corresponde a la categoría/subcategoría elegida.
 * Se imprime una sola vez por página.
 */
function campos_dinamicos_js(): void {
    static $hecho = false;
    if ($hecho) { return; }
    $hecho = true;
    ?>
    <script>
    (function () {
      const cont = document.getElementById('campos-dinamicos');
      if (!cont) return;

      // Los nombres de los <select> de categoría cambian entre el alta y la
      // edición, así que se buscan por name y se toma el primero que exista.
      const selCat = document.querySelector('[name="categoria"]');
      const selSub = document.querySelector('[name="subcategoria"]');

      function refrescar() {
        const cat = selCat ? (selCat.value || '') : '';
        const sub = selSub ? (selSub.value || '') : '';
        cont.querySelectorAll('.campos-dinamicos__grupo').forEach(g => {
          const gc = g.dataset.cat || '';
          const gs = g.dataset.sub || '';
          // Ámbito vacío = aplica siempre. Misma regla que campos_para() en PHP.
          const aplica = (gc === '' || gc === cat) && (gs === '' || gs === sub);
          g.hidden = !aplica;
        });
      }

      if (selCat) selCat.addEventListener('change', refrescar);
      if (selSub) selSub.addEventListener('change', refrescar);
      refrescar();
    })();
    </script>
    <?php
}
}
