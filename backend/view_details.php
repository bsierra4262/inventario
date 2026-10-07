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
require_once __DIR__ . '/../includes/volver.php';

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
require_once __DIR__ . '/../core/db/empresa_cache.php';
$tenant_name = empresa_nombre();

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

/* Las acciones del activo viven ACA y en ningun otro lado. Estaban las dos
   veces: arriba «Volver» y «Editar», y abajo una tarjeta «Acciones» con
   «Editar activo», «Archivar activo» y «Volver al inventario» — tres botones
   anchos, de tres colores, uno debajo del otro, que ademas repetian dos de los
   de arriba. Un boton que esta dos veces en la misma pantalla no da dos
   opciones: hace dudar de si hacen lo mismo. */
$topbar_actions = volver_boton('/inventario/index.php');
if ($_canArchive && empty($producto['archivado'])) {
    /* Archivar no es destructivo —se deshace desde la papelera— asi que no va
       en rojo. El rojo es para lo que no se puede deshacer. */
    $topbar_actions .= ' <a href="/backend/delete.php?id=' . $producto_id . '&amp;' . volver_aqui()
        . '" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-archive"></i> <span class="ih-hidden-mobile">Archivar</span></a>';
}
if ($_canEdit) {
    $topbar_actions .= ' <a href="/backend/edit.php?id=' . $producto_id . '&amp;' . volver_aqui()
        . '" class="ih-btn ih-btn--primary ih-btn--sm"><i class="bi bi-pencil"></i> <span class="ih-hidden-mobile">Editar</span></a>';
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
    $ic = $icon ? "<i class=\"bi bi-{$icon}\"></i> " : '';
    echo "<div class=\"ih-dato\"><span class=\"ih-dato__label\">{$ic}{$label}</span>"
       . "<span class=\"ih-dato__valor\">" . htmlspecialchars($v, ENT_QUOTES) . "</span></div>\n";
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



<!-- Encabezado del activo -->
<div class="ih-card ih-mb-5">
  <div class="ih-card__body">
    <div class="ih-barra">
    <div class="ih-barra__texto">
      <div class="ih-flex ih-gap-2 ih-items-center ih-fw ih-mb-1">
        <h2 class="ih-barra__titulo">
          <?= htmlspecialchars($nombreModelo, ENT_QUOTES) ?>
        </h2>
        <?php if ($estado): ?>
          <span class="ih-badge <?= $estadoBadge ?>"><?= htmlspecialchars($estado) ?></span>
        <?php endif; ?>
        <?php if ($archivado): ?>
          <span class="ih-badge ih-badge--neutral"><i class="bi bi-archive"></i> Archivado</span>
        <?php endif; ?>
      </div>
      <p class="ih-barra__ayuda">
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
      <div class="ih-barra__acciones">
        <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($producto['carpeta_raiz_drive'], ENT_QUOTES) ?>"
           target="_blank" rel="noopener" class="ih-btn ih-btn--google">
          <?= cloud_icon_svg($cloudProv, 18) ?> Ver archivos en Drive
        </a>
      </div>
    <?php endif; ?>
    </div>
  </div>
</div>

<?php /* Las tarjetas se acomodan solas en dos columnas y cada una ocupa lo que
         necesita. Antes habia DOS <div> a mano, uno por columna, con las
         tarjetas repartidas de antemano: un activo con pocos datos dejaba la
         columna izquierda corta y la derecha larga, con medio metro de fondo
         vacio en el medio. Ver `.ih-mosaico` en componentes.css. */ ?>
<div class="ih-mosaico">

    <!-- Datos principales -->
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-info-circle"></i> Datos principales</h3>
        <div class="ih-datos ih-datos--dos-columnas">
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
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-currency-dollar"></i> Estado y valores</h3>
        <div class="ih-datos ih-datos--dos-columnas">
          <?php
          vd_row('Cantidad',   $producto['cantidad'],  'boxes');
          if (!empty($producto['valor_usd']) && (float)$producto['valor_usd'] > 0) {
              vd_row('Valor USD', '$' . number_format((float)$producto['valor_usd'], 2), 'currency-dollar');
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
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-calendar3"></i> Garantía y fechas</h3>
        <div class="ih-datos ih-datos--dos-columnas"><?= $htmlGarantia ?></div>
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
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-receipt"></i> Compra y administración</h3>
        <div class="ih-datos ih-datos--dos-columnas"><?= $htmlInfocom ?></div>
      </div>
    </div>
    <?php endif; ?>

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
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-sliders"></i> Detalles específicos</h3>
        <div class="ih-datos ih-datos--dos-columnas">
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
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-sliders"></i> Detalles de la categoría</h3>
        <div class="ih-datos ih-datos--dos-columnas">
          <?php foreach ($propios as [$dc, $v]) {
              $mostrar = (string)$dc['tipo'] === 'booleano' ? ($v === '1' ? 'Sí' : 'No') : $v;
              vd_row((string)$dc['etiqueta'], $mostrar, 'dot');
          } ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php /* Acá vivía una tarjeta «Archivos adjuntos» cuyo único contenido era el
             mismo botón de Drive que ya está en el encabezado, con otro texto. La
             carpeta de archivos es parte de la identidad del activo y va arriba,
             al lado del nombre: una sola vez. */ ?>

    <?php
    // ── Ficha técnica ────────────────────────────────────────────────────
    //   Un resumen de lo que hay cargado y la puerta para cargarlo. El detalle
    //   es la vista de lectura; inventario/ficha.php es donde se edita.
    require_once __DIR__ . '/../core/tenants/sistema_operativo.php';
    require_once __DIR__ . '/../core/tenants/componentes.php';
    require_once __DIR__ . '/../core/tenants/red.php';
    require_once __DIR__ . '/../core/tenants/relaciones.php';
    require_once __DIR__ . '/../core/tenants/contratos.php';
    require_once __DIR__ . '/../core/tenants/documentos.php';

    $fSo    = so_disponible($conn)        ? so_de($conn, $producto_id) : [];
    $fComp  = componentes_disponible($conn) ? componentes_de_activo($conn, $producto_id) : [];
    $fRed   = red_disponible($conn)       ? red_puertos_de($conn, $producto_id) : [];
    $fRel   = relaciones_disponible($conn) ? relaciones_cuantas($conn, $producto_id) : 0;
    $fCon   = contratos_disponible($conn) ? contratos_de_activo($conn, $producto_id) : [];
    $fDoc   = documentos_disponible($conn) ? documentos_de_activo($conn, $producto_id) : [];
    $fNot   = documentos_disponible($conn) ? notas_de_activo($conn, $producto_id) : [];
    $fResumenSo = so_resumen($fSo);
    ?>
    <?php /* Siete renglones diciendo «—» no informan: ocupan el mismo lugar que
             los que sí dicen algo y hay que leerlos uno por uno para descubrir
             que están todos vacíos. Se muestra lo que hay cargado, y lo que falta
             se dice en un renglón al pie. Si no hay nada, se dice una vez. */
    $fichaItems = [
        ['Sistema operativo', $fResumenSo],
        ['Componentes',       count($fComp) > 0 ? count($fComp) . ' cargados' : ''],
        ['Puertos de red',    count($fRed)  > 0 ? count($fRed) . ' con dirección' : ''],
        ['Relaciones',        $fRel         > 0 ? $fRel . ' activos vinculados' : ''],
        ['Contratos',         count($fCon)  > 0 ? count($fCon) . ' que lo cubren' : ''],
        ['Documentos',        count($fDoc)  > 0 ? (string)count($fDoc) : ''],
        ['Notas',             count($fNot)  > 0 ? (string)count($fNot) : ''],
    ];
    $fichaCargado = array_values(array_filter($fichaItems, fn($x) => trim((string)$x[1]) !== ''));
    $fichaFalta   = array_values(array_filter($fichaItems, fn($x) => trim((string)$x[1]) === ''));
    ?>
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-clipboard-data"></i> Ficha técnica</h3>
        <?php if ($fichaCargado): ?>
          <div class="ih-datos">
            <?php foreach ($fichaCargado as [$etiqueta, $valor]) { vd_row($etiqueta, $valor, 'dot'); } ?>
          </div>
          <?php if ($fichaFalta): ?>
            <p class="ih-nota-pie">
              Sin cargar:
              <?= htmlspecialchars(mb_strtolower(implode(', ', array_column($fichaFalta, 0)), 'UTF-8')) ?>.
            </p>
          <?php endif; ?>
        <?php else: ?>
          <div class="ih-vacio-chico">
            <i class="bi bi-clipboard"></i>
            La ficha técnica está vacía: ni sistema operativo, ni componentes, ni red.
          </div>
        <?php endif; ?>
        <a href="/inventario/ficha.php?id=<?= $producto_id ?>&amp;<?= volver_aqui() ?>" class="ih-btn ih-btn--outline ih-btn--block ih-mt-3">
          <i class="bi bi-clipboard-data"></i>
          <?= $fichaCargado ? 'Ver y cargar la ficha completa' : 'Cargar la ficha técnica' ?>
        </a>
      </div>
    </div>

    <?php
    // ── Historial ────────────────────────────────────────────────────────
    //   Qué le pasó a ESTE activo. El detalle mostraba el estado de hoy y
    //   nada de cómo se llegó, así que para saber si un equipo había estado
    //   archivado había que ir a Reportes, elegir una pestaña y filtrar por
    //   modelo. Es la pregunta más común sobre un activo y vivía a cuatro
    //   pantallas de distancia.
    require_once __DIR__ . '/../core/tenants/historial.php';
    $movs = historial_de_activo($conn, $producto_id, 'tn');
    if ($movs): ?>
    <div class="ih-card ih-mb-5">
      <div class="ih-card__body ih-card__body--compacto">
        <h3 class="ih-seccion__titulo"><i class="bi bi-clock-history"></i> Historial</h3>
        <ol class="ih-linea-tiempo">
          <?php foreach ($movs as $mv): $qp = historial_que_paso($mv); $au = historial_autor($mv); ?>
            <li class="ih-linea-tiempo__item<?= $qp['abierto'] ? ' es-abierto' : '' ?>">
              <i class="bi bi-<?= $qp['icono'] ?> ih-linea-tiempo__icono" aria-hidden="true"></i>
              <div class="ih-min-0">
                <div class="ih-celda__nombre"><?= htmlspecialchars($qp['titulo']) ?></div>
                <div class="ih-celda__sub">
                  <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$mv['fecha']))) ?>
                  · <?= htmlspecialchars($au['nombre']) ?>
                </div>
                <?php if ($qp['detalle'] !== ''): ?>
                  <p class="ih-nota-pie"><?= htmlspecialchars($qp['detalle']) ?></p>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </div>
    <?php endif; ?>

</div><!-- /mosaico -->

</main>
</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
