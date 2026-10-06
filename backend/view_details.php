<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/db.php';
// logger.php ya está incluido por db.php — stub de seguridad únicamente
if (!function_exists('log_event')) { function log_event(...$a) {} }
require_once __DIR__ . '/../includes/cloud_icons.php';
require_once __DIR__ . '/../core/security/permissions.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    header('Location: /inventario/index.php');
    exit;
}

$producto_id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT * FROM `" . tn('productos') . "` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $producto_id);
$stmt->execute();
$producto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$producto) {
    log_event($user_id, 'ERROR', "Producto ID:{$producto_id} no encontrado en view_details", 'consulta');
    header('Location: /inventario/index.php');
    exit;
}

log_event($user_id, 'CONSULTA', "Visualización de producto ID:{$producto_id}", 'consulta');

// Nombre de empresa (MasterDB ya disponible via db.php → tenant_bootstrap → master_connection)
$tenant_name = 'Mi empresa';
try {
    $masterDb   = new MasterDB();
    $cidTn      = (int)($_SESSION['company_id'] ?? 0);
    if ($cidTn > 0) {
        $cs = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$cidTn]);
        $cr = $cs->get_result()->fetch_assoc();
        if ($cr) $tenant_name = $cr['nombre'];
        $cs->close();
    }
} catch (Throwable) {}

// ── Layout vars ─────────────────────────────────────────────────────────────
$nombreModelo = $producto['modelo'] ?: ('Activo #' . $producto_id);

$page_title   = 'Detalle · ' . $nombreModelo;
$page_section = 'inventario';

$topbar_title    = 'Detalle del activo';
$topbar_subtitle = $nombreModelo;
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
// Permisos del usuario actual
$_vd_us = tn('users');
$_vd_stmt = $conn->prepare("SELECT role FROM `{$_vd_us}` WHERE id = ? LIMIT 1");
$_vd_stmt->bind_param('i', $user_id);
$_vd_stmt->execute();
$_vd_user = $_vd_stmt->get_result()->fetch_assoc();
$_vd_stmt->close();
$_canEdit    = user_can('asset.edit', $_vd_user);
$_canArchive = user_can('asset.archive', $_vd_user);

$topbar_actions = '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';
if ($_canEdit) {
    $topbar_actions .= ' <a href="/backend/edit.php?id=' . $producto_id . '" class="ih-btn ih-btn--primary ih-btn--sm"><i class="bi bi-pencil"></i> <span class="ih-hidden-mobile">Editar</span></a>';
}

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main">
<?php

// ── Helpers ────────────────────────────────────────────────────────────────
if (!function_exists('vd_val')) {
function vd_val(mixed $v): string {
    if ($v === null || $v === '' || $v === '0000-00-00') return '';
    return (string)$v;
}
}
if (!function_exists('vd_row')) {
function vd_row(string $label, mixed $valor, string $icon = ''): void {
    $v = vd_val($valor);
    if ($v === '') return;
    $ic = $icon ? "<i class=\"bi bi-{$icon} me-1\"></i>" : '';
    echo "<div class=\"vd-row\"><span class=\"vd-label\">{$ic}{$label}</span><span class=\"vd-value\">" . htmlspecialchars($v, ENT_QUOTES) . "</span></div>\n";
}
}

$estado = vd_val($producto['estado'] ?? '');
$estadoBadge = match($estado) {
    'Nuevo'  => 'ih-badge--success',
    'Usado'  => 'ih-badge--warn',
    default  => 'ih-badge--neutral',
};

$archivado = !empty($producto['archivado']) && (int)$producto['archivado'] === 1;
?>

<style>
.vd-section { margin-bottom: 1.5rem; }
.vd-section-title {
    font-size: var(--ih-text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ih-gray-500);
    margin-bottom: .75rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--ih-gray-100);
}
.vd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
@media(max-width:640px){ .vd-grid { grid-template-columns: 1fr; } }
.vd-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: .6rem .75rem;
    border-bottom: 1px solid var(--ih-gray-100);
}
.vd-row:nth-child(odd)  { background: transparent; }
.vd-row:nth-child(even) { background: var(--ih-gray-50); }
.vd-label {
    font-size: var(--ih-text-xs);
    font-weight: 600;
    color: var(--ih-gray-500);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.vd-value {
    font-size: var(--ih-text-sm);
    color: var(--ih-gray-800);
    word-break: break-word;
}
.vd-drive-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    background: #fff;
    border: 2px solid var(--ih-gray-200);
    color: var(--ih-gray-800);
    border-radius: var(--ih-radius-full);
    font-weight: 600;
    font-size: var(--ih-text-sm);
    text-decoration: none;
    transition: border-color .15s, box-shadow .15s, transform .05s;
}
.vd-drive-btn:hover {
    border-color: var(--ih-primary);
    box-shadow: 0 4px 12px rgba(102, 51, 153, .15);
    color: var(--ih-gray-800);
    text-decoration: none;
}
.vd-drive-btn:active { transform: translateY(1px); }
</style>

<!-- Encabezado del activo -->
<div class="ih-card" style="margin-bottom:1.5rem;">
  <div class="ih-card__body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div style="flex:1;min-width:0;">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
        <h2 style="margin:0;font-size:var(--ih-text-xl);font-weight:700;color:var(--ih-gray-800);">
          <?= htmlspecialchars($nombreModelo, ENT_QUOTES) ?>
        </h2>
        <?php if ($estado): ?>
          <span class="ih-badge <?= $estadoBadge ?>"><?= htmlspecialchars($estado) ?></span>
        <?php endif; ?>
        <?php if ($archivado): ?>
          <span class="ih-badge ih-badge--neutral"><i class="bi bi-archive"></i> Archivado</span>
        <?php endif; ?>
      </div>
      <p style="margin:0;color:var(--ih-gray-500);font-size:var(--ih-text-sm);">
        <?php if ($producto['marca']): ?>
          <i class="bi bi-tag"></i> <?= htmlspecialchars($producto['marca'], ENT_QUOTES) ?> &nbsp;·&nbsp;
        <?php endif; ?>
        <?php if ($producto['categoria']): ?>
          <i class="bi bi-folder"></i> <?= htmlspecialchars($producto['categoria'], ENT_QUOTES) ?>
          <?php if ($producto['subcategoria']): ?>
            › <?= htmlspecialchars($producto['subcategoria'], ENT_QUOTES) ?>
          <?php endif; ?>
        <?php endif; ?>
        &nbsp;·&nbsp; <i class="bi bi-hash"></i> ID <?= $producto_id ?>
      </p>
    </div>

    <?php if (!empty($producto['carpeta_raiz_drive'])): ?>
      <?php $cloudProv = cloud_provider_actual($conn); ?>
      <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($producto['carpeta_raiz_drive'], ENT_QUOTES) ?>"
         target="_blank" rel="noopener" class="vd-drive-btn">
        <?= cloud_icon_svg($cloudProv, 18) ?> Ver archivos en Drive
      </a>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

  <!-- Columna izquierda -->
  <div>
    <!-- Datos principales -->
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-info-circle"></i> Datos principales</div>
        <div class="vd-grid">
          <?php
          vd_row('Asignado a',   $producto['asignado_a'],  'person');
          vd_row('Descripción',  $producto['descripcion'], 'text-paragraph');
          vd_row('S/N Service Tag', $producto['servis_tag'], 'upc-scan');
          vd_row('Ubicación',    $producto['ubicacion'],   'geo-alt');
          vd_row('Detalle técnico', $producto['detalle_tecnico'] ?? null, 'tools');
          ?>
        </div>
      </div>
    </div>

    <!-- Estado y valores -->
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-currency-dollar"></i> Estado y valores</div>
        <div class="vd-grid">
          <?php
          vd_row('Cantidad',   $producto['cantidad'],  'boxes');
          if (!empty($producto['valor_usd']) && (float)$producto['valor_usd'] > 0) {
              echo "<div class=\"vd-row\"><span class=\"vd-label\"><i class=\"bi bi-currency-dollar me-1\"></i>Valor USD</span><span class=\"vd-value\">\$" . number_format((float)$producto['valor_usd'], 2) . "</span></div>\n";
          }
          ?>
        </div>
      </div>
    </div>

    <!-- Garantía y fechas -->
    <?php
    // vd_row() ya se saltea los valores vacios, pero la TARJETA se dibujaba
    // igual: un activo sin fechas mostraba un recuadro con el titulo y nada
    // debajo. Se arma el contenido primero y la tarjeta sale solo si hay algo.
    ob_start();
    if (!empty($producto['periodo_garantia']) && (int)$producto['periodo_garantia'] > 0) {
        vd_row('Garantía (meses)', $producto['periodo_garantia'], 'shield-check');
    }
    vd_row('Inicio garantía',  $producto['fecha_garantia'],  'calendar-check');
    vd_row('Fecha ingreso',    $producto['fecha_ingreso'],    'calendar-plus');
    vd_row('Fecha egreso',     $producto['fecha_egreso'],     'calendar-minus');
    $htmlGarantia = trim(ob_get_clean());
    if ($htmlGarantia !== ''): ?>
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-calendar3"></i> Garantía y fechas</div>
        <div class="vd-grid"><?= $htmlGarantia ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Compra y administración (Infocom) -->
    <?php
    // Estos datos se podian cargar desde el alta y la edicion, pero el detalle
    // no los mostraba: quedaban invisibles salvo que se volviera a editar.
    require_once __DIR__ . '/../core/tenants/infocom.php';
    $ic = infocom_de($conn, $producto_id);

    ob_start();
    vd_row('N.º de pedido',       $ic['numero_pedido']   ?? null, 'receipt');
    vd_row('N.º de remito',       $ic['numero_remito']   ?? null, 'truck');
    vd_row('N.º de factura',      $ic['numero_factura']  ?? null, 'file-earmark-text');
    vd_row('N.º contable',        $ic['numero_contable'] ?? null, 'hash');
    vd_row('Proveedor',           $ic['proveedor']       ?? null, 'shop');
    vd_row('Presupuesto',         $ic['presupuesto']     ?? null, 'wallet2');
    vd_row('Criticidad',          $ic['criticidad']      ?? null, 'exclamation-diamond');
    vd_row('Fecha de orden',      $ic['fecha_orden']             ?? null, 'calendar');
    vd_row('Fecha de entrega',    $ic['fecha_entrega']           ?? null, 'calendar-check');
    vd_row('Puesta en servicio',  $ic['fecha_puesta_servicio']   ?? null, 'play-circle');
    vd_row('Último inventario',   $ic['fecha_ultimo_inventario'] ?? null, 'clipboard-check');
    vd_row('Fecha de decomiso',   $ic['fecha_decomiso']          ?? null, 'calendar-x');
    vd_row('Datos de garantía',   $ic['garantia_info']           ?? null, 'shield-check');
    if (!empty($ic['valor_extension_garantia']) && (float)$ic['valor_extension_garantia'] > 0) {
        vd_row('Extensión garantía', 'USD ' . number_format((float)$ic['valor_extension_garantia'], 2, ',', '.'), 'cash');
    }
    if (!empty($ic['amortizacion_anios']) && (int)$ic['amortizacion_anios'] > 0) {
        $amTipo = INFOCOM_TIPOS_AMORTIZACION[(string)($ic['amortizacion_tipo'] ?? '')] ?? '';
        vd_row('Amortización', (int)$ic['amortizacion_anios'] . ' años' . ($amTipo ? " · {$amTipo}" : ''), 'graph-down-arrow');
    }
    vd_row('Comentarios',         $ic['comentarios'] ?? null, 'chat-left-text');
    $htmlInfocom = trim(ob_get_clean());
    if ($htmlInfocom !== ''): ?>
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-receipt"></i> Compra y administración</div>
        <div class="vd-grid"><?= $htmlInfocom ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Columna derecha -->
  <div>
    <!-- Detalles de subcategoría -->
    <?php
    $hayExtras = array_filter([
        $producto['patchcord_tipo']    ?? '',
        $producto['patchcord_longitud']?? '',
        $producto['switch_gestion']    ?? '',
        $producto['antenas_tipo']      ?? '',
        $producto['bobina_categoria']  ?? '',
        $producto['bobina_ubicacion']  ?? '',
        $producto['bobina_longitud']   ?? '',
        $producto['conectores_tipo']   ?? '',
        $producto['detalle_conectores']?? '',
        $producto['cintas_generacion'] ?? '',
        $producto['detalle_repuesto']  ?? '',
        $producto['detalle_otros']     ?? '',
        $producto['subsoftware']       ?? '',
        $producto['subsoftware_otros'] ?? '',
    ], fn($v) => $v !== '');

    if ($hayExtras): ?>
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-sliders"></i> Detalles específicos</div>
        <div class="vd-grid">
          <?php
          vd_row('Tipo Patchcord',      $producto['patchcord_tipo'],     'ethernet');
          vd_row('Longitud Patchcord',  $producto['patchcord_longitud'],  'rulers');
          vd_row('Switch gestión',      $producto['switch_gestion'],      'diagram-3');
          vd_row('Tipo Antenas',        $producto['antenas_tipo'],        'broadcast');
          vd_row('Categoría Bobina',    $producto['bobina_categoria'],    'inboxes');
          vd_row('Tipo Bobina',         $producto['bobina_ubicacion'],    'layers');
          vd_row('Longitud Bobina',     $producto['bobina_longitud'],     'rulers');
          vd_row('Tipo Conectores',     $producto['conectores_tipo'],     'plug');
          vd_row('Detalle conectores',  $producto['detalle_conectores'],  'pencil');
          vd_row('Generación Cintas',   $producto['cintas_generacion'],   'device-hdd');
          vd_row('Detalle Repuesto',    $producto['detalle_repuesto'],    'wrench');
          vd_row('Otros detalles',      $producto['detalle_otros'],       'three-dots');
          vd_row('Software',            $producto['subsoftware'],         'windows');
          vd_row('Detalle software',    $producto['subsoftware_otros'],   'code-slash');
          ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Campos configurables de la categoría -->
    <?php
    // Los catorce de arriba tienen columna propia en `productos`; estos se
    // definen desde Campos y viven en `campos_valor`. Se muestran aparte para
    // que se vea de dónde sale cada cosa.
    require_once __DIR__ . '/../core/tenants/campos.php';
    $defsCat = campos_para($conn, (string)($producto['categoria'] ?? ''), (string)($producto['subcategoria'] ?? ''));
    $valsCat = campos_valores_de($conn, $producto_id, $producto);
    $propios = [];
    foreach ($defsCat as $dc) {
        if ((string)($dc['columna_legacy'] ?? '') !== '') { continue; }
        $v = trim((string)($valsCat[(string)$dc['clave']] ?? ''));
        if ($v !== '') { $propios[] = [$dc, $v]; }
    }
    if ($propios): ?>
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-sliders"></i> Detalles de la categoría</div>
        <div class="vd-grid">
          <?php foreach ($propios as [$dc, $v]) {
              $mostrar = (string)$dc['tipo'] === 'booleano' ? ($v === '1' ? 'Sí' : 'No') : $v;
              vd_row((string)$dc['etiqueta'], $mostrar, 'dot');
          } ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Drive -->
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;">
        <div class="vd-section-title"><i class="bi bi-cloud"></i> Archivos adjuntos</div>
        <?php if (!empty($producto['carpeta_raiz_drive'])): ?>
          <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($producto['carpeta_raiz_drive'], ENT_QUOTES) ?>"
             target="_blank" rel="noopener" class="vd-drive-btn" style="width:100%;justify-content:center;">
            <?= cloud_icon_svg($cloudProv ?? cloud_provider_actual($conn), 22) ?>
            Ver archivos de este activo en Drive
          </a>
          <div style="font-size:var(--ih-text-xs);color:var(--ih-gray-400);text-align:center;margin-top:6px;">
            Abre la carpeta específica de este activo (<?= htmlspecialchars($producto['servis_tag'] ?: 'activo_'.$producto_id, ENT_QUOTES) ?>)
          </div>
        <?php else: ?>
          <div style="color:var(--ih-gray-400);font-size:var(--ih-text-sm);text-align:center;padding:.5rem 0;">
            <i class="bi bi-cloud-slash" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
            Sin carpeta Drive asignada
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Acciones -->
    <div class="ih-card vd-section">
      <div class="ih-card__body" style="padding:1rem;display:flex;flex-direction:column;gap:10px;">
        <div class="vd-section-title"><i class="bi bi-lightning"></i> Acciones</div>
        <?php if ($_canEdit): ?>
        <a href="/backend/edit.php?id=<?= $producto_id ?>" class="ih-btn ih-btn--primary ih-btn--block">
          <i class="bi bi-pencil-square"></i> Editar activo
        </a>
        <?php endif; ?>
        <?php if ($_canArchive && empty($producto['archivado'])): ?>
        <a href="/backend/delete.php?id=<?= $producto_id ?>" class="ih-btn ih-btn--danger ih-btn--block">
          <i class="bi bi-archive"></i> Archivar activo
        </a>
        <?php endif; ?>
        <a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--block">
          <i class="bi bi-arrow-left"></i> Volver al inventario
        </a>
      </div>
    </div>

  </div><!-- /col derecha -->
</div><!-- /grid -->

</main>
</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
