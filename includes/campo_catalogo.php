<?php
/**
 * Campo de formulario alimentado por un catálogo del tenant.
 *
 * Reemplaza los <input type="text"> libres de «Asignado a» y «Ubicación». Con
 * texto libre convivían «Depósito», «deposito» y «Dep.», y los reportes por
 * ubicación o por responsable no agrupaban nada.
 *
 * Al admin (permiso `catalog.manage`) le muestra un botón que da de alta un
 * valor nuevo sin salir del formulario. Al resto, sólo la lista.
 *
 * Uso:
 *   require_once __DIR__ . '/../includes/campo_catalogo.php';
 *   campo_catalogo($conn, 'asignado_a', $valorActual, $puedeGestionarCatalogos);
 *
 * ## Lo que ya no está acá
 *
 * Este archivo tenía su propio diálogo para pedir el valor nuevo —unas sesenta
 * líneas— y su propia hoja de estilos en un <style> que se reimprimía en cada
 * página y que el navegador no podía cachear. Las dos cosas se fueron a la capa
 * compartida: el diálogo es `ihPedirTexto()` de assets/js/componentes.js, y los
 * estilos están en assets/css/componentes.css. Eran un segundo diálogo haciendo
 * casi lo mismo que el de confirmación, y el botón «+» tenía un gris y un radio
 * escritos a mano, distintos de los del desplegable de al lado.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/tenants/catalogos.php';

if (!function_exists('campo_catalogo_assets')) {
/** Imprime una sola vez el JavaScript del alta rápida. */
function campo_catalogo_assets(): void {
    static $hecho = false;
    if ($hecho) { return; }
    $hecho = true;

    require_once __DIR__ . '/../backend/csrf.php';
    $token = generateCsrfToken('catalogo_ajax');
    ?>
    <script>
    (function () {
      const TOKEN = <?= json_encode($token) ?>;

      /** Avisa debajo del campo, sin sacar a la persona del formulario. */
      function avisar(texto, boton, esError) {
        const cont = boton.closest('.cat-campo') || boton.parentElement;
        const previo = cont.parentElement.querySelector('.cat-aviso');
        if (previo) previo.remove();
        const p = document.createElement('p');
        p.className = 'cat-aviso ' + (esError ? 'ih-text-danger' : 'ih-text-muted');
        p.textContent = texto;
        cont.parentElement.appendChild(p);
        setTimeout(() => p.remove(), 6000);
      }

      window.catalogoAgregar = async function (boton) {
        const tipo   = boton.dataset.tipo;
        const select = document.getElementById(boton.dataset.target);
        const label  = boton.dataset.label || 'valor';

        // El diálogo es el compartido: ver assets/js/componentes.js.
        const valor = await window.ihPedirTexto({
          titulo:   'Agregar ' + label,
          etiqueta: 'Nombre',
          aceptar:  'Agregar'
        });
        if (valor === null) return;

        boton.disabled = true;
        const iconoPrevio = boton.innerHTML;
        boton.innerHTML = '<span aria-hidden="true">…</span>';

        try {
          const body = new URLSearchParams({ accion: 'agregar', tipo: tipo, valor: valor });
          const r = await fetch('/backend/catalogos_crud.php?ajax=1', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'X-CSRF-Token': TOKEN,
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: body.toString()
          });
          const data = await r.json();
          if (!r.ok || !data || data.ok !== true) {
            avisar((data && data.error) || 'No se pudo agregar el valor.', boton, true);
            return;
          }

          // Se agrega al <select> real y se reordena alfabéticamente, dejando
          // arriba la opción vacía.
          let opt = Array.from(select.options).find(o => o.value === data.valor);
          if (!opt) {
            opt = document.createElement('option');
            opt.value = data.valor;
            opt.textContent = data.valor;
            select.appendChild(opt);
            const fijas = Array.from(select.options).filter(o => o.value === '');
            const resto = Array.from(select.options).filter(o => o.value !== '')
                               .sort((a, b) => a.textContent.localeCompare(b.textContent, 'es'));
            select.innerHTML = '';
            fijas.concat(resto).forEach(o => select.appendChild(o));
          }
          select.value = data.valor;
          // `change` para que la cara visible del desplegable propio se entere.
          select.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
          avisar('No se pudo contactar al servidor.', boton, true);
        } finally {
          boton.disabled = false;
          boton.innerHTML = iconoPrevio;
        }
      };
    })();
    </script>
    <?php
}
}

if (!function_exists('campo_catalogo')) {
/**
 * @param mysqli      $conn           Conexión del tenant
 * @param string      $tipo           'asignado_a' | 'ubicacion'
 * @param string|null $valorActual    Valor guardado del activo (puede no estar en la lista)
 * @param bool        $puedeGestionar true si el usuario tiene catalog.manage
 * @param string|null $id             id del <select> (por defecto f-<tipo>)
 */
function campo_catalogo(mysqli $conn, string $tipo, ?string $valorActual, bool $puedeGestionar, ?string $id = null): void {
    if (!catalogo_tipo_valido($tipo)) { return; }

    $id     = $id ?: ('f-' . $tipo);
    $label  = CATALOGO_TIPOS[$tipo]['label'];
    $actual = trim((string)$valorActual);
    $lista  = catalogo_valores($conn, $tipo);

    // Un activo cargado antes del catálogo (o importado por CSV) puede tener un
    // valor que no está en la lista. No se pierde: se muestra igual, marcado.
    $huerfano = ($actual !== '' && !in_array($actual, $lista, true));

    campo_catalogo_assets();
    ?>
    <div class="cat-campo">
      <select class="ih-select" id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($tipo) ?>">
        <option value="">— Sin asignar —</option>
        <?php if ($huerfano): ?>
          <option value="<?= htmlspecialchars($actual, ENT_QUOTES, 'UTF-8') ?>" selected>
            <?= htmlspecialchars($actual) ?> (fuera de la lista)
          </option>
        <?php endif; ?>
        <?php foreach ($lista as $v): ?>
          <option value="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>"
                  <?= $v === $actual ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($puedeGestionar): ?>
        <?php /* `aria-label` y no sólo `title`: un «+» a secas no le dice nada a
                 un lector de pantalla, y el title tarda en aparecer al pasar el
                 mouse. El icono es el mismo set que usa el resto de la app. */ ?>
        <button type="button" class="cat-add"
                aria-label="Agregar un valor a <?= htmlspecialchars(mb_strtolower($label)) ?>"
                title="Agregar un valor a <?= htmlspecialchars(mb_strtolower($label)) ?>"
                data-tipo="<?= htmlspecialchars($tipo) ?>"
                data-target="<?= htmlspecialchars($id) ?>"
                data-label="<?= htmlspecialchars(mb_strtolower($label)) ?>"
                onclick="catalogoAgregar(this)"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
      <?php endif; ?>
    </div>
    <?php if ($huerfano): ?>
      <small class="cat-huerfano">
        Este valor no está en la lista de <?= htmlspecialchars(mb_strtolower($label)) ?>.
        <?= $puedeGestionar
            ? 'Agregalo con el botón de al lado para normalizarlo.'
            : 'Pedile al administrador que lo agregue.' ?>
      </small>
    <?php endif; ?>
    <?php if (!$lista && $puedeGestionar): ?>
      <small class="cat-huerfano">
        La lista está vacía. Cargá los valores en
        <a href="/inventario/catalogos.php?tipo=<?= htmlspecialchars($tipo) ?>">Listas desplegables</a>.
      </small>
    <?php endif;
}
}
