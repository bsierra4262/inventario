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

      // Dialogo de alta rapida.
      //
      // Antes esto lo pedia el cuadro nativo del navegador: funcionaba, pero es
      // gris, sin los colores ni la tipografia del sistema, y con el nombre del
      // dominio arriba. En una pantalla de gestion queda fuera de lugar.
      let dlg = null;
      function construirDialogo() {
        if (dlg) return dlg;
        const el = document.createElement('div');
        el.className = 'cat-modal';
        el.hidden = true;
        el.innerHTML = `
          <div class="cat-modal__fondo" data-cerrar></div>
          <div class="cat-modal__caja" role="dialog" aria-modal="true" aria-labelledby="cat-modal-tit">
            <h3 class="cat-modal__titulo" id="cat-modal-tit"></h3>
            <label class="cat-modal__label" for="cat-modal-input"></label>
            <input type="text" id="cat-modal-input" class="ih-input" maxlength="255" autocomplete="off">
            <p class="cat-modal__error" role="alert" hidden></p>
            <div class="cat-modal__acciones">
              <button type="button" class="ih-btn ih-btn--ghost" data-cerrar>Cancelar</button>
              <button type="button" class="ih-btn ih-btn--primary" data-aceptar>Agregar</button>
            </div>
          </div>`;
        document.body.appendChild(el);
        dlg = el;
        return el;
      }

      function pedirValor(label) {
        const el    = construirDialogo();
        const input = el.querySelector('#cat-modal-input');
        const err   = el.querySelector('.cat-modal__error');
        el.querySelector('.cat-modal__titulo').textContent = 'Nuevo valor';
        el.querySelector('.cat-modal__label').textContent  = label;
        input.value = '';
        err.hidden = true;
        el.hidden = false;
        // El foco entra al dialogo y vuelve a donde estaba al cerrarlo.
        const previo = document.activeElement;
        setTimeout(() => input.focus(), 0);

        return new Promise(resolve => {
          function cerrar(valor) {
            el.hidden = true;
            el.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey);
            if (previo && previo.focus) previo.focus();
            resolve(valor);
          }
          function onClick(e) {
            if (e.target.closest('[data-aceptar]')) {
              const v = input.value.trim();
              if (!v) { err.textContent = 'Escribí un valor.'; err.hidden = false; input.focus(); return; }
              cerrar(v);
            } else if (e.target.closest('[data-cerrar]')) {
              cerrar(null);
            }
          }
          function onKey(e) {
            if (e.key === 'Escape') { cerrar(null); }
            if (e.key === 'Enter' && document.activeElement === input) {
              e.preventDefault();
              el.querySelector('[data-aceptar]').click();
            }
          }
          el.addEventListener('click', onClick);
          document.addEventListener('keydown', onKey);
        });
      }

      // Aviso no bloqueante, en lugar del cuadro modal del navegador.
      function avisar(msg, boton) {
        const p = document.createElement('p');
        p.className = 'cat-huerfano';
        p.setAttribute('role', 'alert');
        p.textContent = msg;
        const cont = boton.closest('.cat-campo') || boton.parentElement;
        const previo = cont.parentElement.querySelector('.cat-aviso');
        if (previo) previo.remove();
        p.classList.add('cat-aviso');
        cont.parentElement.appendChild(p);
        setTimeout(() => p.remove(), 6000);
      }

      window.catalogoAgregar = async function (boton) {
        const tipo   = boton.dataset.tipo;
        const select = document.getElementById(boton.dataset.target);
        const label  = boton.dataset.label || 'valor';

        const valor = await pedirValor(label);
        if (valor === null) return;

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

          if (!data.ok) { avisar(data.msg || 'No se pudo agregar.', boton); return; }

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
          avisar('No se pudo contactar al servidor.', boton);
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

      /* Dialogo de alta rapida — reemplaza al cuadro nativo del navegador. */
      .cat-modal { position: fixed; inset: 0; z-index: 1000; display: flex;
        align-items: center; justify-content: center; padding: 1rem; }
      .cat-modal[hidden] { display: none; }
      .cat-modal__fondo { position: absolute; inset: 0; background: rgba(17,20,24,.45); }
      .cat-modal__caja {
        position: relative; width: 100%; max-width: 420px;
        background: var(--ih-white, #fff); border-radius: var(--ih-radius-lg, 15px);
        padding: 1.5rem; box-shadow: 0 20px 50px rgba(17,20,24,.25);
        animation: cat-modal-in .14s ease-out;
      }
      @keyframes cat-modal-in { from { opacity: 0; transform: translateY(8px); } }
      @media (prefers-reduced-motion: reduce) { .cat-modal__caja { animation: none; } }
      .cat-modal__titulo { margin: 0 0 .25rem; font-size: 1.05rem; font-weight: 700;
        color: var(--ih-gray-900, #161922); }
      .cat-modal__label { display: block; margin: .75rem 0 .35rem;
        font-size: .8rem; font-weight: 600; color: var(--ih-gray-600, #52525b); }
      .cat-modal__error { margin: .5rem 0 0; font-size: .78rem; color: var(--ih-danger, #DC2626); }
      .cat-modal__acciones { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1.25rem; }
      @media (max-width: 420px) {
        .cat-modal__acciones { flex-direction: column-reverse; }
        .cat-modal__acciones .ih-btn { width: 100%; }
      }
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
