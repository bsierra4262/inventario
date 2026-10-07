<?php
/**
 * Ficha técnica y administrativa de un activo.
 *
 * Todo lo que GLPI reparte en siete pestañas —sistema operativo, componentes,
 * puertos de red, relaciones, contratos, documentos y notas— en una sola
 * pantalla, una sección debajo de la otra.
 *
 * Es a propósito: con pestañas hay que acordarse de ir a mirar cada una, y lo
 * que se busca acá casi siempre es «contame todo lo de este equipo». Las
 * secciones tienen ancla propia (#so, #componentes, #red…) para poder volver al
 * mismo lugar después de guardar.
 *
 * El detalle del activo (backend/view_details.php) sigue siendo la vista de
 * lectura; ésta es donde se carga.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../includes/volver.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
require_once __DIR__ . '/../includes/campo_catalogo.php';
require_once __DIR__ . '/../includes/campo_so.php';
require_once __DIR__ . '/../core/tenants/sistema_operativo.php';
require_once __DIR__ . '/../core/tenants/componentes.php';
require_once __DIR__ . '/../core/tenants/red.php';
require_once __DIR__ . '/../core/tenants/relaciones.php';
require_once __DIR__ . '/../core/tenants/contratos.php';
require_once __DIR__ . '/../core/tenants/documentos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$user    = require_tenant_permission('asset.view');
$puedeEditar = user_can('asset.edit', $user);
// Quien administra catálogos ve el botón para sumar un valor sin salir de acá.
$puedeCatalogos = $puedeEditar && user_can('catalog.manage', $user);

$productoId = (int)($_GET['id'] ?? 0);
if ($productoId <= 0) { header('Location: /inventario/index.php'); exit; }

$st = $conn->prepare('SELECT id, modelo, marca, categoria, subcategoria, servis_tag, asignado_a,
                             ubicacion, estado, archivado
                        FROM `' . tn('productos') . '` WHERE id = ? LIMIT 1');
$st->bind_param('i', $productoId);
$st->execute();
$activo = $st->get_result()->fetch_assoc();
$st->close();
if (!$activo) { header('Location: /inventario/index.php?err=' . urlencode('Ese activo no existe.')); exit; }

$nombre = trim((string)$activo['modelo']) !== ''
    ? trim((string)$activo['marca'] . ' ' . (string)$activo['modelo'])
    : ('Activo #' . $productoId);

// Si el tenant todavía no corrió las migraciones 8 y 9, la pantalla lo dice en
// vez de caerse: cada bloque pregunta por su propia tabla.
$haySo   = so_disponible($conn);
$hayComp = componentes_disponible($conn);
$hayRed  = red_disponible($conn);
$hayRel  = relaciones_disponible($conn);
$hayCon  = contratos_disponible($conn);
$hayDoc  = documentos_disponible($conn);
$faltaEsquema = !$haySo || !$hayComp || !$hayRed || !$hayRel || !$hayCon || !$hayDoc;

$so        = $haySo   ? so_de($conn, $productoId)             : [];
$comps     = $hayComp ? componentes_de_activo($conn, $productoId) : [];
$puertos   = $hayRed  ? red_puertos_de($conn, $productoId)     : [];
$rels      = $hayRel  ? relaciones_de($conn, $productoId)      : [];
$contratos = $hayCon  ? contratos_de_activo($conn, $productoId): [];
$docs      = $hayDoc  ? documentos_de_activo($conn, $productoId) : [];
$notas     = $hayDoc  ? notas_de_activo($conn, $productoId)    : [];

// Para los desplegables de «vincular con».
$otros = [];
if ($hayRel) {
    $st = $conn->prepare('SELECT id, modelo, marca, categoria FROM `' . tn('productos') . '`
                           WHERE id <> ? AND archivado = 0 ORDER BY modelo LIMIT 500');
    $st->bind_param('i', $productoId);
    $st->execute();
    $otros = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$contratosDisp = $hayCon ? contratos_listar($conn) : [];

$csrf   = generateCsrfToken('ficha_abm');
$okMsg  = (string)($_GET['ok']  ?? '');
$errMsg = (string)($_GET['err'] ?? '');
$esNuevo = !empty($_GET['nuevo']);

require_once __DIR__ . '/../core/db/empresa_cache.php';
$tenant_name = empresa_nombre();

$page_title      = 'Ficha · ' . $nombre;
$page_section    = 'inventario';
$page_portal     = 'tenant';
$topbar_title    = 'Ficha técnica';
$topbar_subtitle = $nombre;
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
/* El boton dice «Volver» y no «Detalle»: ahora devuelve a donde estaba la
   persona, que puede ser el listado, la papelera o el detalle. Un rotulo que
   nombra un destino fijo miente en cuanto el destino deja de ser fijo. */
$topbar_actions  = volver_boton('/backend/view_details.php?id=' . $productoId);

require __DIR__ . '/../includes/layout/head.php';

/** Una fila de la tabla de una sección, con su botón de quitar. */
function ficha_quitar(string $accion, array $campos, int $productoId, string $csrf, string $texto): void { ?>
    <form method="post" action="/backend/ficha_crud.php"
          onsubmit="return confirm('<?= htmlspecialchars($texto, ENT_QUOTES) ?>')">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="accion" value="<?= htmlspecialchars($accion, ENT_QUOTES) ?>">
      <input type="hidden" name="producto_id" value="<?= $productoId ?>">
      <?php foreach ($campos as $k => $v): ?>
        <input type="hidden" name="<?= htmlspecialchars((string)$k, ENT_QUOTES) ?>"
               value="<?= htmlspecialchars((string)$v, ENT_QUOTES) ?>">
      <?php endforeach; ?>
      <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" title="Quitar">
        <i class="bi bi-trash"></i>
      </button>
    </form>
<?php }
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

  <?php /* Cuando se llega recién creado el activo, el aviso dice qué sigue: el
           alta terminó, pero componentes, red y contratos recién se pueden
           cargar ahora, porque necesitan el id que hasta hace un segundo no
           existía. Antes había que volver al listado, buscarlo y entrar. */ ?>
  <?php if ($esNuevo): ?>
    <div class="ih-alert ih-alert--success ih-mb-4"><i class="bi bi-check-circle-fill"></i>
      <div>
        <strong><?= $okMsg !== '' ? htmlspecialchars($okMsg) : 'Activo creado.' ?></strong><br>
        Ya podés seguir cargando acá: componentes, puertos de red, vínculos con otros activos,
        contratos y documentos. Todo esto necesitaba que el activo existiera.
      </div>
    </div>
  <?php elseif ($okMsg !== ''): ?>
    <div class="ih-alert ih-alert--success ih-mb-4"><i class="bi bi-check-circle-fill"></i>
      <div><?= htmlspecialchars($okMsg) ?></div></div>
  <?php endif; ?>
  <?php if ($errMsg !== ''): ?>
    <div class="ih-alert ih-alert--danger ih-mb-4"><i class="bi bi-exclamation-triangle-fill"></i>
      <div><?= htmlspecialchars($errMsg) ?></div></div>
  <?php endif; ?>

  <?php if ($faltaEsquema): ?>
    <div class="ih-alert ih-alert--warning ih-mb-4"><i class="bi bi-database-exclamation"></i>
      <div>Esta base todavía no tiene las tablas de la ficha técnica. Falta correr
      <code>php bin/migrar.php</code>. Lo que sí está migrado se muestra igual.</div></div>
  <?php endif; ?>

  <?php /* Un índice al principio: la pantalla es larga a propósito y conviene
           poder saltar. No son pestañas — todo está cargado, esto sólo mueve. */ ?>
  <nav class="ficha-indice ih-mb-4" aria-label="Secciones de la ficha">
    <a href="#so"><i class="bi bi-windows"></i> Sistema operativo</a>
    <a href="#componentes"><i class="bi bi-cpu"></i> Componentes <span class="ih-badge ih-badge--neutral"><?= count($comps) ?></span></a>
    <a href="#red"><i class="bi bi-ethernet"></i> Red <span class="ih-badge ih-badge--neutral"><?= count($puertos) ?></span></a>
    <a href="#relaciones"><i class="bi bi-diagram-3"></i> Relaciones <span class="ih-badge ih-badge--neutral"><?= count($rels) ?></span></a>
    <a href="#contratos"><i class="bi bi-file-earmark-text"></i> Contratos <span class="ih-badge ih-badge--neutral"><?= count($contratos) ?></span></a>
    <a href="#documentos"><i class="bi bi-paperclip"></i> Documentos <span class="ih-badge ih-badge--neutral"><?= count($docs) ?></span></a>
    <a href="#notas"><i class="bi bi-sticky"></i> Notas <span class="ih-badge ih-badge--neutral"><?= count($notas) ?></span></a>
  </nav>

  <?php // ════════════════════════════════════════════════ SISTEMA OPERATIVO ?>
  <section class="ih-card ih-mb-4" id="so">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-windows"></i> Sistema operativo</h2>
        <p class="ih-card__subtitle">Qué corre el equipo. Con esto se puede contestar «cuántas
        máquinas siguen en Windows 10» sin revisarlas una por una.</p>
      </div>
    </div>
    <div class="ih-card__body">
      <?php if (!$haySo): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
      <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="accion" value="so_guardar">
        <input type="hidden" name="producto_id" value="<?= $productoId ?>">

        <?php /* Los cuatro primeros son catálogos administrables, como «Asignado
                 a» o «Ubicación»: la migración 10 los siembra con la lista
                 completa —Windows, Linux, macOS, hipervisores, equipos de red— y
                 el admin puede sumar los que falten con el botón de al lado.
                 Antes era un <input list> con diez sugerencias en el HTML. */ ?>
        <div class="ih-field">
          <label class="ih-label" for="f-so">Sistema</label>
          <?php campo_catalogo($conn, 'so', (string)($so['so'] ?? ''), $puedeCatalogos, 'f-so'); ?>
          <div class="ih-field__hint">Ej: Windows 11, Ubuntu Server, VMware ESXi, MikroTik RouterOS</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="f-so_version">Versión</label>
          <?php campo_so_dependiente($conn, 'versiones', 'so_version', (string)($so['version'] ?? ''), $puedeCatalogos, 'f-so_version'); ?>
          <div class="ih-field__hint">Ej: 22.04 LTS, 23H2, 8.0, Sonoma 14.4</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="f-so_edicion">Edición</label>
          <?php campo_so_dependiente($conn, 'ediciones', 'so_edicion', (string)($so['edicion'] ?? ''), $puedeCatalogos, 'f-so_edicion'); ?>
          <div class="ih-field__hint">Ej: Pro, Home, Enterprise, Datacenter, Standard</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="f-so_arquitectura">Arquitectura</label>
          <?php campo_catalogo($conn, 'so_arquitectura', (string)($so['arquitectura'] ?? ''), $puedeCatalogos, 'f-so_arquitectura'); ?>
          <div class="ih-field__hint">Ej: x86_64 en un PC o servidor, ARM64 en una Mac con chip M o en un celular</div>
        </div>

        <div class="ih-field">
          <label class="ih-label" for="so-kernel">Kernel o build</label>
          <input class="ih-input" id="so-kernel" name="kernel" <?= $puedeEditar ? '' : 'disabled' ?>
                 value="<?= htmlspecialchars((string)($so['kernel'] ?? ''), ENT_QUOTES) ?>"
                 placeholder="Ej: 5.15.0-91-generic">
          <div class="ih-field__hint">En Linux, lo que devuelve <code>uname -r</code>. En Windows, el número de compilación: 22631.3155</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="so-sp">Service pack</label>
          <input class="ih-input" id="so-sp" name="service_pack" <?= $puedeEditar ? '' : 'disabled' ?>
                 value="<?= htmlspecialchars((string)($so['service_pack'] ?? ''), ENT_QUOTES) ?>"
                 placeholder="Ej: SP1, Update 3">
          <div class="ih-field__hint">Sólo en sistemas que todavía los usan, como Windows 7 o Server 2012</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="so-clave">Clave de producto</label>
          <input class="ih-input" id="so-clave" name="clave_producto" <?= $puedeEditar ? '' : 'disabled' ?>
                 value="<?= htmlspecialchars((string)($so['clave_producto'] ?? ''), ENT_QUOTES) ?>"
                 placeholder="Ej: XXXXX-XXXXX-XXXXX-XXXXX-XXXXX">
          <div class="ih-field__hint">La clave con la que se activó el sistema</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="so-lic">Número de licencia</label>
          <input class="ih-input" id="so-lic" name="numero_licencia" <?= $puedeEditar ? '' : 'disabled' ?>
                 value="<?= htmlspecialchars((string)($so['numero_licencia'] ?? ''), ENT_QUOTES) ?>"
                 placeholder="Ej: OEM-2024-00187">
          <div class="ih-field__hint">El número con el que figura la licencia en la factura o en el portal del fabricante</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="so-fecha">Instalado el</label>
          <input class="ih-input" type="date" id="so-fecha" name="instalado_en" <?= $puedeEditar ? '' : 'disabled' ?>
                 value="<?= htmlspecialchars((string)($so['instalado_en'] ?? ''), ENT_QUOTES) ?>">
          <div class="ih-field__hint">Cuándo se instaló o se reinstaló por última vez</div>
        </div>

        <?php if ($puedeEditar): ?>
        <div class="ih-field ih-field--ancho">
          <button type="submit" class="ih-btn ih-btn--accent"><i class="bi bi-check-lg"></i> Guardar sistema operativo</button>
        </div>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <?php // ═══════════════════════════════════════════════════ COMPONENTES ?>
  <section class="ih-card ih-mb-4" id="componentes">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-cpu"></i> Componentes</h2>
        <p class="ih-card__subtitle">Procesador, memoria, discos, placas. El catálogo es compartido:
        cargás «Intel Core i5-1135G7» una vez y lo usás en todos los equipos que lo tienen.</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($comps) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if (!$hayComp): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
        <?php if ($comps === []): ?>
          <p class="ih-text-muted ih-mb-4">Todavía no se cargó ningún componente.</p>
        <?php else: ?>
        <div class="ih-table-wrap ih-mb-4">
          <table class="ih-table">
            <thead><tr>
              <th>Tipo</th><th>Componente</th><th>Cantidad</th><th>Detalle</th><th>Serie</th>
              <?php if ($puedeEditar): ?><th></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($comps as $c):
                $def = COMPONENTE_TIPOS[(string)$c['tipo']] ?? ['label' => (string)$c['tipo'], 'icono' => 'bi-puzzle'];
                $detalle = [];
                if (($c['capacidad_mb'] ?? null) !== null) { $detalle[] = componente_capacidad_legible((int)$c['capacidad_mb']); }
                if (($c['frecuencia_mhz'] ?? null) !== null) { $detalle[] = (int)$c['frecuencia_mhz'] . ' MHz'; }
                if (($c['nucleos'] ?? null) !== null) { $detalle[] = (int)$c['nucleos'] . ' núcleos'; }
                if (($c['hilos'] ?? null) !== null) { $detalle[] = (int)$c['hilos'] . ' hilos'; }
                if (trim((string)($c['mac'] ?? '')) !== '') { $detalle[] = (string)$c['mac']; }
            ?>
              <tr>
                <td><i class="bi <?= htmlspecialchars($def['icono']) ?>"></i> <?= htmlspecialchars($def['label']) ?></td>
                <td>
                  <strong><?= htmlspecialchars((string)$c['designacion']) ?></strong>
                  <?php if (trim((string)($c['fabricante'] ?? '')) !== ''): ?>
                    <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars((string)$c['fabricante']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= (int)$c['cantidad'] ?></td>
                <td class="ih-text-sm"><?= htmlspecialchars(implode(' · ', $detalle)) ?></td>
                <td class="ih-text-sm"><?= htmlspecialchars((string)($c['numero_serie'] ?? '')) ?></td>
                <?php if ($puedeEditar): ?>
                <td class="ih-text-right"><?php
                  ficha_quitar('componente_quitar', ['asignacion_id' => (int)$c['id']], $productoId, $csrf,
                               '¿Quitar «' . addslashes((string)$c['designacion']) . '» de este activo?');
                ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="accion" value="componente_agregar">
          <input type="hidden" name="producto_id" value="<?= $productoId ?>">
          <div class="ih-field">
            <label class="ih-label" for="co-tipo">Tipo <span class="req">*</span></label>
            <select class="ih-select" id="co-tipo" name="tipo" required>
              <?php foreach (COMPONENTE_TIPOS as $k => $d): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($d['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field ih-col-span-2">
            <label class="ih-label" for="co-desig">Componente <span class="req">*</span></label>
            <input class="ih-input" id="co-desig" name="designacion" required
                   list="lista-componentes" placeholder="Ej: Intel Core i5-1135G7">
            <datalist id="lista-componentes">
              <?php foreach (componentes_catalogo($conn) as $cc): ?>
                <option value="<?= htmlspecialchars((string)$cc['designacion'], ENT_QUOTES) ?>">
              <?php endforeach; ?>
            </datalist>
            <div class="ih-field__hint">Si ya existe en el catálogo se reusa; si no, se da de alta.</div>
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-fab">Fabricante</label>
            <input class="ih-input" id="co-fab" name="fabricante" placeholder="Ej: Intel">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-cant">Cantidad</label>
            <input class="ih-input" type="number" id="co-cant" name="cantidad" min="1" value="1"
                   placeholder="Ej: 2 módulos de memoria">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-cap">Capacidad (MB)</label>
            <input class="ih-input" type="number" id="co-cap" name="capacidad_mb" min="0"
                   placeholder="8192 = 8 GB">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-frec">Frecuencia (MHz)</label>
            <input class="ih-input" type="number" id="co-frec" name="frecuencia_mhz" min="0"
                   placeholder="Ej: 3200">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-nuc">Núcleos</label>
            <input class="ih-input" type="number" id="co-nuc" name="nucleos" min="0" placeholder="Ej: 8">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-hil">Hilos</label>
            <input class="ih-input" type="number" id="co-hil" name="hilos" min="0" placeholder="Ej: 16">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-mac">Dirección MAC</label>
            <input class="ih-input" id="co-mac" name="mac" placeholder="AA:BB:CC:DD:EE:FF">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="co-serie">Número de serie</label>
            <input class="ih-input" id="co-serie" name="numero_serie" placeholder="Ej: S4A2NX0T901234">
          </div>
          <div class="ih-field ih-field--ancho">
            <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-plus-lg"></i> Agregar componente</button>
          </div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php // ══════════════════════════════════════════════════════════ RED ?>
  <section class="ih-card ih-mb-4" id="red">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-ethernet"></i> Red</h2>
        <p class="ih-card__subtitle">Puertos y direcciones. Es lo que permite contestar «qué equipo
        tiene la IP 10.0.0.47» sin una planilla aparte.</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($puertos) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if (!$hayRed): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
        <?php if ($puertos === []): ?>
          <p class="ih-text-muted ih-mb-4">Todavía no se cargó ningún puerto.</p>
        <?php endif; ?>

        <?php foreach ($puertos as $p): ?>
        <div class="ih-card ih-card--anidada ih-mb-3">
          <div class="ih-card__header">
            <div>
              <h3 class="ih-card__title">
                <i class="bi bi-ethernet"></i>
                <?= htmlspecialchars(trim((string)($p['nombre'] ?? '')) ?: ('Puerto ' . (int)$p['numero'])) ?>
                <span class="ih-badge ih-badge--neutral"><?= htmlspecialchars(RED_TIPOS_PUERTO[(string)$p['tipo']] ?? (string)$p['tipo']) ?></span>
              </h3>
              <p class="ih-card__subtitle">
                <?= htmlspecialchars((string)($p['mac'] ?? '—')) ?>
                <?php if (($p['velocidad_mbps'] ?? null) !== null): ?> · <?= (int)$p['velocidad_mbps'] ?> Mbps<?php endif; ?>
                <?php if (trim((string)($p['vlan'] ?? '')) !== ''): ?> · VLAN <?= htmlspecialchars((string)$p['vlan']) ?><?php endif; ?>
              </p>
            </div>
            <?php if ($puedeEditar) {
              ficha_quitar('puerto_borrar', ['puerto_id' => (int)$p['id']], $productoId, $csrf,
                           '¿Eliminar este puerto y todas sus direcciones?');
            } ?>
          </div>
          <div class="ih-card__body">
            <?php if (!empty($p['ips'])): ?>
              <div class="ih-table-wrap ih-mb-3">
                <table class="ih-table">
                  <thead><tr><th>Dirección</th><th>Máscara</th><th>Gateway</th><th>Asignación</th><th>DNS</th>
                  <?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
                  <tbody>
                  <?php foreach ($p['ips'] as $ip): ?>
                    <tr>
                      <td><strong><?= htmlspecialchars((string)$ip['ip']) ?></strong>
                          <span class="ih-badge ih-badge--neutral">IPv<?= (int)$ip['version_ip'] ?></span></td>
                      <td><?= htmlspecialchars((string)($ip['mascara'] ?? '')) ?></td>
                      <td><?= htmlspecialchars((string)($ip['gateway'] ?? '')) ?></td>
                      <td><?= htmlspecialchars(RED_ASIGNACIONES_IP[(string)$ip['asignacion']] ?? '') ?></td>
                      <td><?= htmlspecialchars((string)($ip['nombre_dns'] ?? '')) ?></td>
                      <?php if ($puedeEditar): ?>
                      <td class="ih-text-right"><?php
                        ficha_quitar('ip_borrar', ['ip_id' => (int)$ip['id']], $productoId, $csrf,
                                     '¿Eliminar la dirección ' . addslashes((string)$ip['ip']) . '?');
                      ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php if ($puedeEditar): ?>
            <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="accion" value="ip_agregar">
              <input type="hidden" name="producto_id" value="<?= $productoId ?>">
              <input type="hidden" name="puerto_id" value="<?= (int)$p['id'] ?>">
              <div class="ih-field">
                <label class="ih-label" for="ip-<?= (int)$p['id'] ?>">Dirección IP</label>
                <input class="ih-input" id="ip-<?= (int)$p['id'] ?>" name="ip" required placeholder="10.0.0.47">
              </div>
              <div class="ih-field">
                <label class="ih-label">Máscara</label>
                <input class="ih-input" name="mascara" placeholder="255.255.255.0">
              </div>
              <div class="ih-field">
                <label class="ih-label">Gateway</label>
                <input class="ih-input" name="gateway" placeholder="10.0.0.1">
              </div>
              <div class="ih-field">
                <label class="ih-label">Asignación</label>
                <select class="ih-select" name="asignacion">
                  <?php foreach (RED_ASIGNACIONES_IP as $k => $v): ?>
                    <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ih-field">
                <label class="ih-label">Nombre DNS</label>
                <input class="ih-input" name="nombre_dns" placeholder="srv-01.empresa.local">
              </div>
              <div class="ih-field ih-field--al-pie">
                <button type="submit" class="ih-btn ih-btn--ghost"><i class="bi bi-plus-lg"></i> Agregar dirección</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if ($puedeEditar): ?>
        <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="accion" value="puerto_guardar">
          <input type="hidden" name="producto_id" value="<?= $productoId ?>">
          <input type="hidden" name="puerto_id" value="0">
          <div class="ih-field">
            <label class="ih-label" for="pu-num">Número</label>
            <input class="ih-input" type="number" id="pu-num" name="numero" min="0" placeholder="1">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="pu-nom">Nombre</label>
            <input class="ih-input" id="pu-nom" name="nombre" placeholder="eth0, eno1, Wi-Fi">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="pu-tipo">Tipo</label>
            <select class="ih-select" id="pu-tipo" name="tipo">
              <?php foreach (RED_TIPOS_PUERTO as $k => $v): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field">
            <label class="ih-label" for="pu-mac">Dirección MAC</label>
            <input class="ih-input" id="pu-mac" name="mac" placeholder="AA:BB:CC:DD:EE:FF">
            <div class="ih-field__hint">Se guarda en un solo formato, la escribas como la escribas.</div>
          </div>
          <div class="ih-field">
            <label class="ih-label" for="pu-vel">Velocidad (Mbps)</label>
            <input class="ih-input" type="number" id="pu-vel" name="velocidad_mbps" min="0" placeholder="1000">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="pu-vlan">VLAN</label>
            <input class="ih-input" id="pu-vlan" name="vlan" placeholder="Ej: 120">
          </div>
          <div class="ih-field ih-field--ancho">
            <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-plus-lg"></i> Agregar puerto</button>
          </div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php // ═══════════════════════════════════════════════════ RELACIONES ?>
  <section class="ih-card ih-mb-4" id="relaciones">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-diagram-3"></i> Relaciones con otros activos</h2>
        <p class="ih-card__subtitle">El monitor que cuelga de esta notebook, la UPS que alimenta este
        servidor. Sirve para saber qué se cae con qué antes de dar algo de baja.</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($rels) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if (!$hayRel): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
        <?php if ($rels === []): ?>
          <p class="ih-text-muted ih-mb-4">Este activo no está vinculado con ningún otro.</p>
        <?php else: ?>
        <div class="ih-table-wrap ih-mb-4">
          <table class="ih-table">
            <thead><tr><th>Vínculo</th><th>Activo</th><th>Nota</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($rels as $r): ?>
              <tr>
                <td><i class="bi <?= htmlspecialchars((string)$r['icono']) ?>"></i> <?= htmlspecialchars((string)$r['etiqueta']) ?></td>
                <td>
                  <a href="/inventario/ficha.php?id=<?= (int)$r['otro_id'] ?>">
                    <?= htmlspecialchars(trim((string)$r['marca'] . ' ' . (string)$r['modelo'])) ?>
                  </a>
                  <?php if (!empty($r['archivado'])): ?>
                    <span class="ih-badge ih-badge--neutral">Archivado</span>
                  <?php endif; ?>
                  <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars((string)($r['categoria'] ?? '')) ?></div>
                </td>
                <td class="ih-text-sm"><?= htmlspecialchars((string)($r['nota'] ?? '')) ?></td>
                <?php if ($puedeEditar): ?>
                <td class="ih-text-right"><?php
                  ficha_quitar('relacion_borrar', ['relacion_id' => (int)$r['id']], $productoId, $csrf,
                               '¿Quitar este vínculo?');
                ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if ($puedeEditar && $otros !== []): ?>
        <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="accion" value="relacion_crear">
          <input type="hidden" name="producto_id" value="<?= $productoId ?>">
          <div class="ih-field">
            <label class="ih-label" for="re-tipo">Este activo…</label>
            <select class="ih-select" id="re-tipo" name="tipo">
              <?php foreach (RELACION_TIPOS as $k => $d): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($d['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field ih-col-span-2">
            <label class="ih-label" for="re-dest">…este otro</label>
            <select class="ih-select" id="re-dest" name="destino_id" required>
              <option value="">Elegí un activo</option>
              <?php foreach ($otros as $o): ?>
                <option value="<?= (int)$o['id'] ?>">
                  <?= htmlspecialchars(trim((string)$o['marca'] . ' ' . (string)$o['modelo'])) ?>
                  <?= trim((string)($o['categoria'] ?? '')) !== '' ? ' — ' . htmlspecialchars((string)$o['categoria']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field">
            <label class="ih-label" for="re-nota">Nota</label>
            <input class="ih-input" id="re-nota" name="nota" placeholder="Ej: alimentación principal">
          </div>
          <div class="ih-field ih-field--al-pie">
            <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-link-45deg"></i> Vincular</button>
          </div>
        </form>
        <?php elseif ($puedeEditar): ?>
          <p class="ih-text-muted">Hace falta otro activo cargado para poder vincular.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php // ════════════════════════════════════════════════════ CONTRATOS ?>
  <section class="ih-card ih-mb-4" id="contratos">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-file-earmark-text"></i> Contratos</h2>
        <p class="ih-card__subtitle">Soporte, garantía extendida, alquiler. Para contestar «¿hasta
        cuándo tenemos soporte de este equipo?».</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($contratos) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if (!$hayCon): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
        <?php if ($contratos === []): ?>
          <p class="ih-text-muted ih-mb-4">Este activo no está cubierto por ningún contrato.</p>
        <?php else: ?>
        <div class="ih-table-wrap ih-mb-4">
          <table class="ih-table">
            <thead><tr><th>Contrato</th><th>Tipo</th><th>Proveedor</th><th>Vence</th>
            <?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($contratos as $ct):
                $dias = $ct['dias_restantes'] !== null ? (int)$ct['dias_restantes'] : null;
            ?>
              <tr>
                <td><strong><?= htmlspecialchars((string)$ct['nombre']) ?></strong>
                  <?php if (trim((string)($ct['numero'] ?? '')) !== ''): ?>
                    <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars((string)$ct['numero']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars(CONTRATO_TIPOS[(string)$ct['tipo']] ?? (string)$ct['tipo']) ?></td>
                <td><?= htmlspecialchars((string)($ct['proveedor'] ?? '')) ?></td>
                <td>
                  <?php if ($ct['fin'] === null): ?>
                    <span class="ih-text-muted">Sin fecha</span>
                  <?php else: ?>
                    <?= htmlspecialchars(date('d/m/Y', strtotime((string)$ct['fin']))) ?>
                    <?php if ($dias !== null && $dias < 0): ?>
                      <span class="ih-badge ih-badge--danger">Vencido</span>
                    <?php elseif ($dias !== null && $dias <= 90): ?>
                      <span class="ih-badge ih-badge--warning">Faltan <?= $dias ?> días</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <?php if ($puedeEditar): ?>
                <td class="ih-text-right"><?php
                  ficha_quitar('contrato_desvincular', ['contrato_id' => (int)$ct['id']], $productoId, $csrf,
                               '¿Desvincular este contrato del activo?');
                ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if ($puedeEditar && $contratosDisp !== []): ?>
        <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="accion" value="contrato_vincular">
          <input type="hidden" name="producto_id" value="<?= $productoId ?>">
          <div class="ih-field ih-col-span-2">
            <label class="ih-label" for="ct-sel">Cubrir con un contrato</label>
            <select class="ih-select" id="ct-sel" name="contrato_id" required>
              <option value="">Elegí un contrato</option>
              <?php foreach ($contratosDisp as $cd): ?>
                <option value="<?= (int)$cd['id'] ?>">
                  <?= htmlspecialchars((string)$cd['nombre']) ?>
                  <?= $cd['fin'] !== null ? ' — vence ' . htmlspecialchars(date('d/m/Y', strtotime((string)$cd['fin']))) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field ih-field--al-pie">
            <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-link-45deg"></i> Asociar</button>
          </div>
        </form>
        <?php elseif ($puedeEditar): ?>
          <p class="ih-text-muted">Todavía no hay contratos cargados.
            <a href="/inventario/contratos.php">Cargá el primero</a>.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php // ═══════════════════════════════════════════════════ DOCUMENTOS ?>
  <section class="ih-card ih-mb-4" id="documentos">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-paperclip"></i> Documentos</h2>
        <p class="ih-card__subtitle">La factura, el manual, el certificado de garantía. El archivo
        sigue viviendo en Drive: acá se guarda qué es cada uno, que es lo que hoy no se puede saber.</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($docs) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if (!$hayDoc): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
        <?php if ($docs === []): ?>
          <p class="ih-text-muted ih-mb-4">Todavía no se registró ningún documento.</p>
        <?php else: ?>
        <div class="ih-table-wrap ih-mb-4">
          <table class="ih-table">
            <thead><tr><th>Documento</th><th>Tipo</th><th>Enlace</th>
            <?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td><strong><?= htmlspecialchars((string)$d['nombre']) ?></strong>
                  <?php if (trim((string)($d['nota'] ?? '')) !== ''): ?>
                    <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars((string)$d['nota']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="ih-badge ih-badge--purple"><?= htmlspecialchars(DOCUMENTO_TIPOS[(string)$d['tipo']] ?? (string)$d['tipo']) ?></span></td>
                <td>
                  <?php if (trim((string)($d['enlace'] ?? '')) !== ''): ?>
                    <a href="<?= htmlspecialchars((string)$d['enlace'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                      Abrir <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                  <?php else: ?>
                    <span class="ih-text-muted"><?= htmlspecialchars((string)($d['nombre_archivo'] ?? 'En Drive')) ?></span>
                  <?php endif; ?>
                </td>
                <?php if ($puedeEditar): ?>
                <td class="ih-text-right"><?php
                  ficha_quitar('documento_borrar', ['documento_id' => (int)$d['id']], $productoId, $csrf,
                               '¿Eliminar el registro de «' . addslashes((string)$d['nombre']) . '»? El archivo en la nube no se toca.');
                ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <form method="post" action="/backend/ficha_crud.php" class="ih-form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="accion" value="documento_agregar">
          <input type="hidden" name="producto_id" value="<?= $productoId ?>">
          <div class="ih-field">
            <label class="ih-label" for="do-nombre">Nombre <span class="req">*</span></label>
            <input class="ih-input" id="do-nombre" name="nombre" required placeholder="Factura A 0001-00004521">
          </div>
          <div class="ih-field">
            <label class="ih-label" for="do-tipo">Tipo</label>
            <select class="ih-select" id="do-tipo" name="tipo">
              <?php foreach (DOCUMENTO_TIPOS as $k => $v): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ih-field ih-col-span-2">
            <label class="ih-label" for="do-enlace">Enlace <span class="req">*</span></label>
            <input class="ih-input" type="url" id="do-enlace" name="enlace" required
                   placeholder="https://drive.google.com/file/d/…">
            <div class="ih-field__hint">Pegá el enlace del archivo en Drive o en OneDrive.</div>
          </div>
          <div class="ih-field ih-col-span-2">
            <label class="ih-label" for="do-nota">Nota</label>
            <input class="ih-input" id="do-nota" name="nota" placeholder="Ej: incluye la extensión de garantía a 3 años">
          </div>
          <div class="ih-field ih-field--ancho">
            <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-plus-lg"></i> Agregar documento</button>
          </div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php // ═══════════════════════════════════════════════════════ NOTAS ?>
  <section class="ih-card ih-mb-4" id="notas">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-sticky"></i> Notas</h2>
        <p class="ih-card__subtitle">Lo que no es el cambio de ningún campo y conviene que quede
        escrito: «la fuente hace ruido desde marzo», «lo usa Contaduría aunque figure en Sistemas».</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($notas) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if (!$hayDoc): ?>
        <p class="ih-text-muted">Falta migrar la base.</p>
      <?php else: ?>
        <?php foreach ($notas as $n): ?>
          <div class="ih-nota">
            <i class="bi bi-sticky"></i>
            <div class="ih-nota__cuerpo">
              <p class="ih-nota__texto"><?= htmlspecialchars((string)$n['texto']) ?></p>
              <p class="ih-nota__firma">
                <?= htmlspecialchars((string)($n['autor'] ?? 'Alguien')) ?> ·
                <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$n['creado_en']))) ?>
              </p>
            </div>
            <?php if ($puedeEditar) {
              ficha_quitar('nota_borrar', ['nota_id' => (int)$n['id']], $productoId, $csrf, '¿Eliminar esta nota?');
            } ?>
          </div>
        <?php endforeach; ?>
        <?php if ($notas === []): ?>
          <p class="ih-text-muted ih-mb-4">Todavía no hay notas.</p>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <form method="post" action="/backend/ficha_crud.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="accion" value="nota_agregar">
          <input type="hidden" name="producto_id" value="<?= $productoId ?>">
          <div class="ih-field">
            <label class="ih-label" for="no-texto">Nota nueva</label>
            <textarea class="ih-textarea" id="no-texto" name="texto" rows="3" required
                      placeholder="Qué conviene que sepa quien mire este activo después"></textarea>
          </div>
          <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-plus-lg"></i> Agregar nota</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

</main>
</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
