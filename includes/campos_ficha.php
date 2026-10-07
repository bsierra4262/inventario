<?php
/**
 * Las secciones de la ficha técnica, dentro del formulario de alta.
 *
 * Antes el alta creaba el activo y mandaba a la ficha para cargar componentes,
 * red, relaciones, contratos, documentos y notas. Eran dos pantallas y ocho
 * formularios separados para dar de alta una computadora, y cada uno con su
 * propio envío: cargabas un disco, se recargaba la página, cargabas la
 * memoria, se recargaba otra vez.
 *
 * Acá todo eso entra en el mismo formulario, con filas que se agregan sin
 * recargar nada. Lo guarda `ficha_alta_guardar()` en el mismo paso.
 *
 * ## Por qué filas y no un campo por cosa
 *
 * Porque no se sabe cuántas hay: una notebook tiene un disco, un servidor tiene
 * seis, un switch tiene veinticuatro puertos. Un formulario con «Disco 1»,
 * «Disco 2» y «Disco 3» fijos alcanza para la notebook y no para el servidor.
 *
 * ## Si el JavaScript no corre
 *
 * Queda una fila de cada sección, que se puede llenar igual y se guarda igual.
 * Lo único que se pierde es poder agregar más de una sin pasar por la ficha.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/tenants/componentes.php';
require_once __DIR__ . '/../core/tenants/red.php';
require_once __DIR__ . '/../core/tenants/relaciones.php';
require_once __DIR__ . '/../core/tenants/contratos.php';
require_once __DIR__ . '/../core/tenants/documentos.php';

if (!function_exists('campos_ficha_alta')) {
/**
 * Dibuja las seis secciones. Cada una se omite si su tabla todavía no existe
 * —una base sin migrar— en lugar de romper la pantalla entera.
 */
function campos_ficha_alta(mysqli $conn, int $excluirActivo = 0): void
{
    $hayComp = componentes_disponible($conn);
    $hayRed  = red_disponible($conn);
    $hayRel  = relaciones_disponible($conn);
    $hayCon  = contratos_disponible($conn);
    $hayDoc  = documentos_disponible($conn);

    if (!$hayComp && !$hayRed && !$hayRel && !$hayCon && !$hayDoc) { return; }
    ?>

    <?php if ($hayComp): ?>
    <!-- ── Componentes ──────────────────────────────────────────────── -->
    <div class="ih-card ih-mb-4">
      <div class="ih-card__header">
        <div>
          <h3 class="ih-card__title"><i class="bi bi-cpu"></i> Componentes</h3>
          <p class="ih-card__subtitle">
            Procesador, memoria, discos, placas. El catálogo es compartido: «Intel Core i5-1135G7»
            se carga una vez y se usa en todos los equipos que lo tienen.
          </p>
        </div>
      </div>
      <div class="ih-card__body">
        <div class="ih-filas" data-filas="componente">
          <div class="ih-fila" data-fila>
            <div class="ih-form-grid">
              <div class="ih-field">
                <label class="ih-label">Tipo</label>
                <select class="ih-select" name="componente_tipo[]">
                  <?php foreach (COMPONENTE_TIPOS as $k => $d): ?>
                    <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($d['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ih-field ih-col-span-2">
                <label class="ih-label">Cuál</label>
                <input class="ih-input" name="componente_designacion[]" list="lista-componentes"
                       placeholder="Ej: Intel Core i5-1135G7, Kingston DDR4 8GB, Samsung 980 500GB">
                <div class="ih-field__hint">Si ya está en el catálogo se reusa; si no, se da de alta.</div>
              </div>
              <div class="ih-field">
                <label class="ih-label">Marca</label>
                <input class="ih-input" name="componente_fabricante[]" placeholder="Ej: Intel, Kingston, Samsung">
              </div>
              <div class="ih-field">
                <label class="ih-label">Cantidad</label>
                <input class="ih-input" type="number" name="componente_cantidad[]" min="1" value="1"
                       placeholder="Ej: 2">
              </div>
              <div class="ih-field">
                <label class="ih-label">Capacidad en MB</label>
                <input class="ih-input" type="number" name="componente_capacidad[]" min="0"
                       placeholder="Ej: 8192 para 8 GB">
                <div class="ih-field__hint">Para discos y memoria. 1 GB = 1024 MB.</div>
              </div>
              <div class="ih-field">
                <label class="ih-label">Número de serie</label>
                <input class="ih-input" name="componente_serie[]" placeholder="Ej: S4EVNF0N512345">
              </div>
            </div>
            <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm ih-fila__quitar" data-quitar>
              <i class="bi bi-x-lg"></i> Quitar
            </button>
          </div>
        </div>
        <datalist id="lista-componentes">
          <?php foreach (componentes_catalogo($conn) as $cc): ?>
            <option value="<?= htmlspecialchars((string)$cc['designacion'], ENT_QUOTES) ?>">
          <?php endforeach; ?>
        </datalist>
        <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" data-agregar="componente">
          <i class="bi bi-plus-lg"></i> Agregar otro componente
        </button>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($hayRed): ?>
    <!-- ── Red ──────────────────────────────────────────────────────── -->
    <div class="ih-card ih-mb-4">
      <div class="ih-card__header">
        <div>
          <h3 class="ih-card__title"><i class="bi bi-ethernet"></i> Red</h3>
          <p class="ih-card__subtitle">
            Una fila por interfaz, con su dirección principal. Es lo que después
            contesta «qué equipo tiene la 10.0.0.47».
          </p>
        </div>
      </div>
      <div class="ih-card__body">
        <div class="ih-filas" data-filas="puerto">
          <div class="ih-fila" data-fila>
            <div class="ih-form-grid">
              <div class="ih-field">
                <label class="ih-label">Nombre</label>
                <input class="ih-input" name="puerto_nombre[]" placeholder="Ej: eth0, Wi-Fi, Puerto 1">
              </div>
              <div class="ih-field">
                <label class="ih-label">Tipo</label>
                <select class="ih-select" name="puerto_tipo[]">
                  <?php foreach (RED_TIPOS_PUERTO as $k => $lbl): ?>
                    <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ih-field">
                <label class="ih-label">MAC</label>
                <input class="ih-input" name="puerto_mac[]" placeholder="Ej: A4:BB:6D:1F:02:9C">
                <div class="ih-field__hint">Da igual con guiones, con dos puntos o pegada.</div>
              </div>
              <div class="ih-field">
                <label class="ih-label">Velocidad en Mbps</label>
                <input class="ih-input" type="number" name="puerto_velocidad[]" min="0"
                       placeholder="Ej: 1000">
              </div>
              <div class="ih-field">
                <label class="ih-label">Dirección IP</label>
                <input class="ih-input" name="puerto_ip[]" placeholder="Ej: 10.0.0.47">
              </div>
              <div class="ih-field">
                <label class="ih-label">Máscara</label>
                <input class="ih-input" name="puerto_mascara[]" placeholder="Ej: 255.255.255.0">
              </div>
              <div class="ih-field">
                <label class="ih-label">Puerta de enlace</label>
                <input class="ih-input" name="puerto_gateway[]" placeholder="Ej: 10.0.0.1">
              </div>
            </div>
            <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm ih-fila__quitar" data-quitar>
              <i class="bi bi-x-lg"></i> Quitar
            </button>
          </div>
        </div>
        <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" data-agregar="puerto">
          <i class="bi bi-plus-lg"></i> Agregar otra interfaz
        </button>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // Las relaciones necesitan con qué relacionarse: si no hay otros activos
    // cargados, la sección no se dibuja en lugar de mostrar un desplegable vacío.
    $otros = [];
    if ($hayRel) {
        try {
            $tp = $conn->real_escape_string(tn('productos'));
            $sql = "SELECT id, modelo, marca FROM `{$tp}` WHERE archivado = 0"
                 . ($excluirActivo > 0 ? ' AND id <> ' . (int)$excluirActivo : '')
                 . ' ORDER BY modelo LIMIT 500';
            $rs = $conn->query($sql);
            while ($rs && ($f = $rs->fetch_assoc())) { $otros[] = $f; }
        } catch (Throwable) {}
    }
    ?>
    <?php if ($hayRel && $otros !== []): ?>
    <!-- ── Relaciones ───────────────────────────────────────────────── -->
    <div class="ih-card ih-mb-4">
      <div class="ih-card__header">
        <div>
          <h3 class="ih-card__title"><i class="bi bi-diagram-3"></i> Relaciones con otros activos</h3>
          <p class="ih-card__subtitle">
            El monitor que cuelga de esta notebook, el UPS que la alimenta. Sirve para saber qué se
            cae con qué antes de dar algo de baja.
          </p>
        </div>
      </div>
      <div class="ih-card__body">
        <div class="ih-filas" data-filas="relacion">
          <div class="ih-fila" data-fila>
            <div class="ih-form-grid">
              <div class="ih-field">
                <label class="ih-label">Este activo…</label>
                <select class="ih-select" name="relacion_tipo[]">
                  <?php foreach (RELACION_TIPOS as $k => $d): ?>
                    <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($d['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ih-field ih-col-span-2">
                <label class="ih-label">…este otro</label>
                <select class="ih-select" name="relacion_destino[]">
                  <option value="">— Ninguno —</option>
                  <?php foreach ($otros as $o): ?>
                    <option value="<?= (int)$o['id'] ?>">
                      <?= htmlspecialchars(trim(($o['modelo'] ?? '') . ' — ' . ($o['marca'] ?? ''), ' —')) ?>
                      (#<?= (int)$o['id'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ih-field ih-col-span-2">
                <label class="ih-label">Nota</label>
                <input class="ih-input" name="relacion_nota[]"
                       placeholder="Ej: por el puerto 3 del switch del rack A3">
              </div>
            </div>
            <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm ih-fila__quitar" data-quitar>
              <i class="bi bi-x-lg"></i> Quitar
            </button>
          </div>
        </div>
        <button type="button" class="ih-btn ih-btn--ghost ih-btn--sm" data-agregar="relacion">
          <i class="bi bi-plus-lg"></i> Agregar otra relación
        </button>
      </div>
    </div>
    <?php endif; ?>

    <?php
    $contratos  = $hayCon ? contratos_listar($conn) : [];
    $documentos = $hayDoc ? documentos_listar($conn) : [];
    ?>
    <?php if (($hayCon && $contratos !== []) || ($hayDoc && $documentos !== []) || $hayDoc): ?>
    <!-- ── Contratos, documentos y la primera nota ──────────────────── -->
    <div class="ih-card ih-mb-5">
      <div class="ih-card__header">
        <div>
          <h3 class="ih-card__title"><i class="bi bi-file-earmark-text"></i> Contratos, documentos y notas</h3>
          <p class="ih-card__subtitle">
            Lo que ya está cargado en el sistema se elige de la lista; lo que no, se carga después
            desde la ficha.
          </p>
        </div>
      </div>
      <div class="ih-card__body">
        <div class="ih-form-grid">
          <?php if ($contratos !== []): ?>
          <div class="ih-field">
            <label class="ih-label" for="f-contratos">Contratos que lo cubren</label>
            <select class="ih-select" id="f-contratos" name="contrato_id[]" multiple size="4">
              <?php foreach ($contratos as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars((string)$c['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ih-field__hint">Se eligen varios con Ctrl (o Cmd en Mac).</div>
          </div>
          <?php endif; ?>

          <?php if ($documentos !== []): ?>
          <div class="ih-field">
            <label class="ih-label" for="f-documentos">Documentos relacionados</label>
            <select class="ih-select" id="f-documentos" name="documento_id[]" multiple size="4">
              <?php foreach ($documentos as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars((string)$d['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ih-field__hint">La factura, el manual, el remito. Se eligen varios con Ctrl.</div>
          </div>
          <?php endif; ?>

          <?php if ($hayDoc): ?>
          <div class="ih-field ih-field--ancho">
            <label class="ih-label" for="f-nota">Una primera nota</label>
            <textarea class="ih-input" id="f-nota" name="nota_inicial" rows="2"
                      placeholder="Ej: lo trajo el proveedor sin el cargador, se pidió aparte"></textarea>
            <div class="ih-field__hint">
              Lo que convenga dejar escrito y no sea el valor de ningún campo.
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php campos_ficha_script(); ?>
    <?php
}
}

if (!function_exists('campos_ficha_script')) {
/** Agregar y quitar filas. Se imprime una sola vez. */
function campos_ficha_script(): void
{
    static $yaSalio = false;
    if ($yaSalio) { return; }
    $yaSalio = true;
    ?>
<script>
/* Filas que se agregan y se quitan sin recargar.
   La fila nueva es una copia de la PRIMERA, con los valores en blanco: así no
   hay una plantilla en el JavaScript que haya que mantener al día cada vez que
   se agrega un campo al formulario. */
(function () {
  function limpiar(fila) {
    fila.querySelectorAll('input, select, textarea').forEach(function (campo) {
      if (campo.type === 'checkbox' || campo.type === 'radio') { campo.checked = false; }
      else if (campo.tagName === 'SELECT') { campo.selectedIndex = 0; }
      else if (campo.type === 'number' && campo.min) { campo.value = campo.min; }
      else { campo.value = ''; }
      // Los id de la copia chocarian con los del original.
      campo.removeAttribute('id');
      campo.removeAttribute('data-ihc');
    });
    fila.querySelectorAll('label[for]').forEach(function (l) { l.removeAttribute('for'); });
    /* La copia trae los envoltorios que dibujo componentes.js sobre el original.
       Se sacan para que los vuelva a construir sobre los controles de verdad: si
       no, quedan dos caras visibles y una sola que funciona. */
    fila.querySelectorAll('.ihc-select, .ihc-fecha, .ihc-archivo').forEach(function (env) {
      var nativo = env.querySelector('select, input');
      if (nativo && env.parentNode) {
        nativo.classList.remove('ihc-select__nativo', 'ihc-fecha__nativo', 'ihc-archivo__nativo');
        env.parentNode.replaceChild(nativo, env);
      }
    });
    return fila;
  }

  function actualizarQuitar(cont) {
    // Con una sola fila no se ofrece quitarla: dejaria la seccion sin ninguna.
    var filas = cont.querySelectorAll('[data-fila]');
    filas.forEach(function (f) {
      var b = f.querySelector('[data-quitar]');
      if (b) { b.hidden = filas.length <= 1; }
    });
  }

  document.querySelectorAll('[data-agregar]').forEach(function (boton) {
    var cont = document.querySelector('[data-filas="' + boton.dataset.agregar + '"]');
    if (!cont) { return; }
    actualizarQuitar(cont);

    boton.addEventListener('click', function () {
      var copia = limpiar(cont.querySelector('[data-fila]').cloneNode(true));
      cont.appendChild(copia);
      if (window.ihMejorar) { window.ihMejorar(copia); }
      actualizarQuitar(cont);
      var primero = copia.querySelector('input, select, textarea');
      if (primero) { primero.focus(); }
    });
  });

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-quitar]') : null;
    if (!b) { return; }
    var fila = b.closest('[data-fila]');
    var cont = fila ? fila.closest('[data-filas]') : null;
    if (!fila || !cont || cont.querySelectorAll('[data-fila]').length <= 1) { return; }
    fila.remove();
    actualizarQuitar(cont);
  });
})();
</script>
    <?php
}
}
