<?php
/**
 * Proveedores y contratos del inventario.
 *
 * Dos cosas en una pantalla porque un contrato casi siempre es de un proveedor,
 * y separarlas obligaba a ir y volver para cargar el primero.
 *
 * Arriba, lo que vence: es la única pregunta que se hace sola —«¿qué se nos
 * vence pronto?»— y la razón por la que la fecha de fin se guarda calculada en
 * vez de derivarse en cada consulta.
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
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
require_once __DIR__ . '/../core/tenants/contratos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$user = require_tenant_permission('asset.view');
$puedeEditar = user_can('asset.edit', $user);

$hay = contratos_disponible($conn);
$proveedores = $hay ? proveedores_listar($conn) : [];
$contratos   = $hay ? contratos_listar($conn)   : [];
$porVencer   = $hay ? contratos_por_vencer($conn, 90) : [];

$csrf   = generateCsrfToken('contratos_abm');
$okMsg  = (string)($_GET['ok']  ?? '');
$errMsg = (string)($_GET['err'] ?? '');

$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst  = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = (string)$crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

$page_title      = 'Contratos — Asset Vault';
$page_section    = 'contratos';
$page_portal     = 'tenant';
$topbar_title    = 'Proveedores y contratos';
$topbar_subtitle = 'Soporte, garantías y alquileres de ' . $tenant_name;
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = hub_volver('configuracion');

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

  <?php if ($okMsg !== ''): ?>
    <div class="ih-alert ih-alert--success ih-mb-4"><i class="bi bi-check-circle-fill"></i>
      <div><?= htmlspecialchars($okMsg) ?></div></div>
  <?php endif; ?>
  <?php if ($errMsg !== ''): ?>
    <div class="ih-alert ih-alert--danger ih-mb-4"><i class="bi bi-exclamation-triangle-fill"></i>
      <div><?= htmlspecialchars($errMsg) ?></div></div>
  <?php endif; ?>

  <?php if (!$hay): ?>
    <div class="ih-alert ih-alert--warning"><i class="bi bi-database-exclamation"></i>
      <div>Esta base todavía no tiene las tablas de contratos. Falta correr
      <code>php bin/migrar.php</code>.</div></div>
  <?php else: ?>

  <?php // ─────────────────────────────────────────────── LO QUE VENCE ?>
  <?php if ($porVencer !== []): ?>
  <section class="ih-card ih-mb-4">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-hourglass-split"></i> Vence en los próximos 90 días</h2>
        <p class="ih-card__subtitle">Lo que hay que renovar o dejar caer a propósito.</p>
      </div>
      <span class="ih-badge ih-badge--warning"><?= count($porVencer) ?></span>
    </div>
    <div class="ih-card__body">
      <div class="ih-table-wrap">
        <table class="ih-table">
          <thead><tr><th>Contrato</th><th>Proveedor</th><th>Vence</th><th>Preaviso</th></tr></thead>
          <tbody>
          <?php foreach ($porVencer as $c):
              $dias = (int)$c['dias_restantes'];
              $avisar = $dias <= (int)$c['preaviso_dias'];
          ?>
            <tr>
              <td><strong><?= htmlspecialchars((string)$c['nombre']) ?></strong>
                <div class="ih-text-xs ih-text-muted">
                  <?= htmlspecialchars(CONTRATO_TIPOS[(string)$c['tipo']] ?? (string)$c['tipo']) ?>
                </div>
              </td>
              <td><?= htmlspecialchars((string)($c['proveedor'] ?? '')) ?></td>
              <td>
                <?= htmlspecialchars(date('d/m/Y', strtotime((string)$c['fin']))) ?>
                <?php if ($dias < 0): ?>
                  <span class="ih-badge ih-badge--danger">Vencido hace <?= abs($dias) ?> días</span>
                <?php else: ?>
                  <span class="ih-badge ih-badge--warning">Faltan <?= $dias ?> días</span>
                <?php endif; ?>
              </td>
              <td class="ih-text-sm">
                <?php if ((int)$c['preaviso_dias'] > 0): ?>
                  <?= (int)$c['preaviso_dias'] ?> días
                  <?php if ($avisar && $dias >= 0): ?>
                    <span class="ih-badge ih-badge--danger">Hay que avisar ya</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="ih-text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php // ─────────────────────────────────────────────────── CONTRATOS ?>
  <section class="ih-card ih-mb-4" id="contratos">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-file-earmark-text"></i> Contratos</h2>
        <p class="ih-card__subtitle">La fecha de fin se calcula con el inicio y la duración:
        no se escribe a mano, para que no termine diciendo algo distinto.</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($contratos) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if ($contratos === []): ?>
        <p class="ih-text-muted ih-mb-4">Todavía no hay contratos cargados.</p>
      <?php else: ?>
      <div class="ih-table-wrap ih-mb-4">
        <table class="ih-table">
          <thead><tr>
            <th>Contrato</th><th>Tipo</th><th>Proveedor</th><th>Desde</th><th>Hasta</th>
            <th>Activos</th><th>Costo</th><?php if ($puedeEditar): ?><th></th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($contratos as $c): ?>
            <tr>
              <td><strong><?= htmlspecialchars((string)$c['nombre']) ?></strong>
                <?php if (trim((string)($c['numero'] ?? '')) !== ''): ?>
                  <div class="ih-text-xs ih-text-muted"><?= htmlspecialchars((string)$c['numero']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars(CONTRATO_TIPOS[(string)$c['tipo']] ?? (string)$c['tipo']) ?></td>
              <td><?= htmlspecialchars((string)($c['proveedor'] ?? '')) ?></td>
              <td><?= $c['inicio'] ? htmlspecialchars(date('d/m/Y', strtotime((string)$c['inicio']))) : '—' ?></td>
              <td><?= $c['fin'] ? htmlspecialchars(date('d/m/Y', strtotime((string)$c['fin']))) : '—' ?></td>
              <td><span class="ih-badge ih-badge--neutral"><?= (int)$c['activos'] ?></span></td>
              <td><?= $c['costo'] !== null
                      ? htmlspecialchars((string)$c['moneda'] . ' ' . number_format((float)$c['costo'], 2, ',', '.'))
                      : '—' ?></td>
              <?php if ($puedeEditar): ?>
              <td class="ih-text-right">
                <form method="post" action="/backend/contratos_crud.php" style="display:inline"
                      onsubmit="return confirm('¿Dar de baja el contrato «<?= htmlspecialchars(addslashes((string)$c['nombre']), ENT_QUOTES) ?>»? Queda guardado, sale de la lista.')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                  <input type="hidden" name="accion" value="contrato_baja">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" title="Dar de baja">
                    <i class="bi bi-archive"></i>
                  </button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($puedeEditar): ?>
      <form method="post" action="/backend/contratos_crud.php" class="ih-form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="accion" value="contrato_guardar">
        <div class="ih-field ih-col-span-2">
          <label class="ih-label" for="ct-nombre">Nombre <span class="req">*</span></label>
          <input class="ih-input" id="ct-nombre" name="nombre" required placeholder="Soporte 24x7 servidores">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-numero">Número</label>
          <input class="ih-input" id="ct-numero" name="numero" placeholder="SOP-2026-014">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-tipo">Tipo</label>
          <select class="ih-select" id="ct-tipo" name="tipo">
            <?php foreach (CONTRATO_TIPOS as $k => $v): ?>
              <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-prov">Proveedor</label>
          <select class="ih-select" id="ct-prov" name="proveedor_id">
            <option value="">— Sin proveedor —</option>
            <?php foreach ($proveedores as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars((string)$p['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-inicio">Inicio</label>
          <input class="ih-input" type="date" id="ct-inicio" name="inicio">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-dur">Duración (meses)</label>
          <input class="ih-input" type="number" id="ct-dur" name="duracion_meses" min="0" value="12">
          <div class="ih-field__hint">La fecha de fin se calcula sola.</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-pre">Preaviso (días)</label>
          <input class="ih-input" type="number" id="ct-pre" name="preaviso_dias" min="0" value="30">
          <div class="ih-field__hint">Con cuánta anticipación hay que avisar para no renovar.</div>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-ren">Renovación</label>
          <select class="ih-select" id="ct-ren" name="renovacion">
            <?php foreach (CONTRATO_RENOVACIONES as $k => $v): ?>
              <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-costo">Costo</label>
          <input class="ih-input" id="ct-costo" name="costo" inputmode="decimal" placeholder="4500.00">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-moneda">Moneda</label>
          <select class="ih-select" id="ct-moneda" name="moneda">
            <option value="USD">USD</option><option value="ARS">ARS</option><option value="EUR">EUR</option>
          </select>
        </div>
        <div class="ih-field">
          <label class="ih-label" for="ct-cont">Número contable</label>
          <input class="ih-input" id="ct-cont" name="numero_contable" placeholder="Ej: 4.1.2-SOP">
        </div>
        <div class="ih-field ih-col-span-2">
          <label class="ih-label" for="ct-nota">Nota</label>
          <input class="ih-input" id="ct-nota" name="nota" placeholder="Ej: cubre los dos servidores del rack A3">
        </div>
        <div class="ih-field" style="grid-column:1/-1">
          <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-plus-lg"></i> Agregar contrato</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <?php // ────────────────────────────────────────────────── PROVEEDORES ?>
  <section class="ih-card" id="proveedores">
    <div class="ih-card__header">
      <div>
        <h2 class="ih-card__title"><i class="bi bi-shop"></i> Proveedores</h2>
        <p class="ih-card__subtitle">A quién se le compra y quién da el soporte. El proveedor que ya
        figuraba en los datos de compra de cada activo se trajo solo al migrar.</p>
      </div>
      <span class="ih-badge ih-badge--purple"><?= count($proveedores) ?></span>
    </div>
    <div class="ih-card__body">
      <?php if ($proveedores === []): ?>
        <p class="ih-text-muted ih-mb-4">Todavía no hay proveedores cargados.</p>
      <?php else: ?>
      <div class="ih-table-wrap ih-mb-4">
        <table class="ih-table">
          <thead><tr><th>Proveedor</th><th>CUIT</th><th>Contacto</th><th>Teléfono</th><th>Email</th>
          <?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($proveedores as $p): ?>
            <tr>
              <td><strong><?= htmlspecialchars((string)$p['nombre']) ?></strong></td>
              <td class="ih-text-sm"><?= htmlspecialchars((string)($p['cuit'] ?? '')) ?></td>
              <td class="ih-text-sm"><?= htmlspecialchars((string)($p['contacto'] ?? '')) ?></td>
              <td class="ih-text-sm"><?= htmlspecialchars((string)($p['telefono'] ?? '')) ?></td>
              <td class="ih-text-sm"><?= htmlspecialchars((string)($p['email'] ?? '')) ?></td>
              <?php if ($puedeEditar): ?>
              <td class="ih-text-right">
                <form method="post" action="/backend/contratos_crud.php" style="display:inline"
                      onsubmit="return confirm('¿Dar de baja a «<?= htmlspecialchars(addslashes((string)$p['nombre']), ENT_QUOTES) ?>»?')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                  <input type="hidden" name="accion" value="proveedor_baja">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" title="Dar de baja">
                    <i class="bi bi-archive"></i>
                  </button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($puedeEditar): ?>
      <form method="post" action="/backend/contratos_crud.php" class="ih-form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="accion" value="proveedor_guardar">
        <div class="ih-field ih-col-span-2">
          <label class="ih-label" for="pv-nombre">Nombre <span class="req">*</span></label>
          <input class="ih-input" id="pv-nombre" name="nombre" required placeholder="Ej: Distribuidora Norte S.A.">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="pv-cuit">CUIT</label>
          <input class="ih-input" id="pv-cuit" name="cuit" placeholder="30-12345678-9">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="pv-contacto">Contacto</label>
          <input class="ih-input" id="pv-contacto" name="contacto" placeholder="Ej: Laura Gómez (ventas)">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="pv-tel">Teléfono</label>
          <input class="ih-input" id="pv-tel" name="telefono" placeholder="Ej: +54 11 4555-1234">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="pv-email">Email</label>
          <input class="ih-input" type="email" id="pv-email" name="email" placeholder="ventas@proveedor.com.ar">
        </div>
        <div class="ih-field">
          <label class="ih-label" for="pv-web">Web</label>
          <input class="ih-input" id="pv-web" name="web" placeholder="https://www.proveedor.com.ar">
        </div>
        <div class="ih-field ih-col-span-2">
          <label class="ih-label" for="pv-dir">Dirección</label>
          <input class="ih-input" id="pv-dir" name="direccion" placeholder="Ej: Av. Corrientes 1234, CABA">
        </div>
        <div class="ih-field" style="grid-column:1/-1">
          <button type="submit" class="ih-btn ih-btn--primary"><i class="bi bi-plus-lg"></i> Agregar proveedor</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <?php endif; ?>
</main>
</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
