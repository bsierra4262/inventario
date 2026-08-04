<?php
/**
 * Campo de formulario alimentado por un catálogo del tenant.
 *
 * Reemplaza los <input type="text"> libres de "Asignado a" y "Ubicación".
 * Con texto libre convivían "Depósito", "deposito" y "Dep.", y los reportes por
 * ubicación o por responsable no agrupaban nada.
 *
 * Al admin (permiso `catalog.manage`) le muestra un botón «+» que da de alta un
 * valor nuevo sin salir del formulario. Al resto, sólo la lista.
 *
 * Uso:
 *   require_once __DIR__ . '/../includes/campo_catalogo.php';
 *   campo_catalogo($conn, 'asignado_a', $valorActual, $puedeGestionarCatalogos);
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/tenants/catalogos.php';

if (!function_exists('campo_catalogo_assets')) {
/** Imprime una sola vez el JS del alta rápida. */
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

      window.catalogoAgregar = async function (boton) {
        const tipo   = boton.dataset.tipo;
        const select = document.getElementById(boton.dataset.target);
        const label  = boton.dataset.label || 'valor';

        const valor = window.prompt('Nuevo ' + label + ':');
        if (valor === null) return;
        if (!valor.trim()) return;

        boton.disabled = true;
        const textoPrevio = boton.textContent;
        boton.textContent = '…';

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
            body: body.toString(),
            credentials: 'same-origin'
          });
          const data = await r.json();

          if (!data.ok) { alert(data.msg || 'No se pudo agregar.'); return; }

          // Si ya existía (reactivado), no duplicar la opción.
          let opt = Array.from(select.options).find(o => o.value === data.valor);
          if (!opt) {
            opt = document.createElement('option');
            opt.value = data.valor;
            opt.textContent = data.valor;
            select.appendChild(opt);
            // Reordenar alfabéticamente dejando el placeholder arriba.
            const fijas = Array.from(select.options).filter(o => o.value === '');
            const resto = Array.from(select.options).filter(o => o.value !== '')
                               .sort((a, b) => a.textContent.localeCompare(b.textContent, 'es'));
            select.innerHTML = '';
            fijas.concat(resto).forEach(o => select.appendChild(o));
          }
          select.value = data.valor;
          select.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
          alert('No se pudo contactar al servidor.');
        } finally {
          boton.disabled = false;
          boton.textContent = textoPrevio;
        }
      };
    })();
    </script>
    <style>
      .cat-campo { display: flex; gap: 6px; align-items: stretch; }
      .cat-campo > select { flex: 1 1 auto; min-width: 0; }
      .cat-add {
        flex: 0 0 auto; width: 38px; cursor: pointer; font-weight: 700; line-height: 1;
        border: 1px solid var(--ih-gray-300, #d4d4d8); background: var(--ih-white, #fff);
        color: var(--ih-purple, #663399); border-radius: var(--ih-radius-sm, 8px);
      }
      .cat-add:hover:not(:disabled) { background: var(--ih-purple-50, #f4f1f9); }
      .cat-add:disabled { opacity: .5; cursor: default; }
      .cat-huerfano { font-size: 11px; color: var(--ih-warning, #EA580C); margin-top: 4px; display: block; }
    </style>
    <?php
}
}

if (!function_exists('campo_catalogo')) {
/**
 * @param mysqli      $conn        Conexión del tenant
 * @param string      $tipo        'asignado_a' | 'ubicacion'
 * @param string|null $valorActual Valor guardado del activo (puede no estar en la lista)
 * @param bool        $puedeGestionar  true si el usuario tiene catalog.manage
 * @param string|null $id          id del <select> (por defecto f-<tipo>)
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
        <button type="button" class="cat-add" title="Agregar <?= htmlspecialchars($label) ?>"
                data-tipo="<?= htmlspecialchars($tipo) ?>"
                data-target="<?= htmlspecialchars($id) ?>"
                data-label="<?= htmlspecialchars(mb_strtolower($label)) ?>"
                onclick="catalogoAgregar(this)">+</button>
      <?php endif; ?>
    </div>
    <?php if ($huerfano): ?>
      <small class="cat-huerfano">
        Este valor no está en la lista de <?= htmlspecialchars($label) ?>.
        <?= $puedeGestionar ? 'Agregalo con «+» para normalizarlo.' : 'Pedile al administrador que lo agregue.' ?>
      </small>
    <?php endif; ?>
    <?php if (!$lista && $puedeGestionar): ?>
      <small class="cat-huerfano">
        La lista está vacía. Cargá los valores en
        <a href="/inventario/catalogos.php?tipo=<?= htmlspecialchars($tipo) ?>">Listas</a>.
      </small>
    <?php endif;
}
}
