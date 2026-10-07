<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../includes/volver.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

// Icon map para iconos dinamicos al lado de categoria/subcategoria
$iconMap = require __DIR__ . '/../includes/iconMap.php';

// Politica: subir archivos solo si hay Drive/OneDrive configurado
require_once __DIR__ . '/../includes/cloud_icons.php';
$cloudUploadOk = cloud_upload_disponible($conn);

require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/campo_catalogo.php';
require_once __DIR__ . '/../includes/campo_so.php';
require_once __DIR__ . '/../includes/campos_ficha.php';
require_once __DIR__ . '/../includes/campos_dinamicos.php';

try {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit();
    }

    $user_id    = (int)$_SESSION['user_id'];
    $usersTable = tn('users');

    $stmt = $conn->prepare("SELECT id, username, email, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        session_destroy();
        header('Location: /auth/login.php');
        exit();
    }

    // Verificar permiso de crear activos
    require_permission('asset.create', $user);

    // ¿Puede ampliar las listas de "Asignado a" y "Ubicación"? (por defecto, admin)
    $puedeGestionarCatalogos = user_can('catalog.manage', $user);

    require_once __DIR__ . '/../core/db/empresa_cache.php';
    $tenant_name = empresa_nombre();

} catch (Throwable $e) {
    log_event('inventario/agregar', 'ERROR', $e->getMessage());
    header('Location: /auth/login.php?err=' . urlencode('Error interno'));
    exit();
}

// Restaurar datos del formulario si venimos de un error
$fd = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// Mensaje de error de sesión (redirect desde backend)
$formError = $_GET['err'] ?? '';

// CSRF token
$csrf_token = generateCsrfToken('upload_producto');

// ── Categorías / Subcategorías ─────────────────────────────────────────────────
$opcionesPorCategoria = [
    'Informática' => [
        'Desktop (PC de escritorio)','Laptop','Notebook','Monitor','Mouse','Teclado',
        'Webcam','Micrófono','Auriculares','Parlantes','Escáner','Numpad','Tableta gráfica',
        'Tablet','All-in-One','Docking station','Cables de alimentación',
        'Soportes / Bases para notebook','Otros'
    ],
    'Redes' => [
        'Patchcord','Switch','Router','Access Point','Módem','Firewall',
        'Repetidor / Extensor Wi-Fi','Antenas','Rack / Gabinete de comunicaciones',
        'Panel de parcheo (Patch panel)','Organizadores de cables','Puntos de red (RJ45)',
        'Bobina UTP','Conectores','Tester de red','POE Injector / POE Switch',
        'Convertidores de medios','Otros'
    ],
    'Servidores y almacenamiento' => [
        'Servidor físico','NAS','SAN','Disco rígido (HDD)','Unidad de estado sólido (SSD)',
        'Cintas de respaldo','Gabinetes de almacenamiento externo','RAID Controller',
        'UPS para servidores','Fuentes redundantes','Otros'
    ],
    'Periféricos y accesorios' => [
        'Impresora térmica','Impresora de tickets','Impresora multifunción',
        'Lector de código de barras','Lector biométrico','Tarjeta de video externa',
        'Adaptadores USB / HDMI / VGA','Hub USB','Cables (HDMI, VGA, DisplayPort, USB, etc.)',
        'Fundas / Mochilas / Maletines','Kit de limpieza','Cargadores y fuentes',
        'Adaptadores eléctricos','Otros'
    ],
    'Herramientas y mantenimiento' => [
        'Destornilladores','Kits de precisión','Pinzas / Alicates','Tester eléctrico',
        'Multímetro','Crimpadoras','Pelacables','Termofusora / Pistola de calor',
        'Etiquetadora','Cepillos antiestáticos','Espuma Antiestática','Aire comprimido',
        'Alcohol isopropílico','Otros'
    ],
    'Seguridad' => [
        'Cámara IP','CCTV','DVR','NVR','Sensores de movimiento','Control de acceso',
        'Cerraduras electrónicas','Alarmas','Detectores de humo / temperatura',
        'UPS para CCTV','Cables coaxiales','Monitores de vigilancia','Otros'
    ],
    'Software y licencias' => [
        'Sistemas operativos','Licencias Office','Antivirus','Software de backup',
        'Software contable / ERP','Licencias de servidores','Herramientas de virtualización',
        'Licencias en la nube','Otros'
    ],
    'Telefonía' => [
        'Teléfonos IP','Teléfonos analógicos','Teléfonos inalámbricos (DECT)',
        'Central telefónica / PBX','Adaptadores ATA','Auriculares con micrófono (headset)',
        'Softphones / Aplicaciones de telefonía','Módulos FXO / FXS','VoIP Gateway',
        'Cables telefónicos','Baterías para teléfonos inalámbricos','Otros'
    ],
    'Electricidad' => [
        'Tomas y enchufes','Zapatillas eléctricas / Múltiples','Cables eléctricos',
        'Disyuntores / térmicas','Luces de emergencia','Lámparas / iluminación LED',
        'Transformadores','Regletas con protección','Estabilizadores de tensión',
        'UPS','Baterías','Pilas','Otros'
    ],
    'Inmobiliario / Muebles' => [
        'Escritorios','Sillas ergonómicas','Sillas de oficina','Mesas de trabajo',
        'Archiveros / Cajas de almacenamiento','Estanterías','Racks','Soportes para monitor',
        'Carros para equipos','Otros'
    ],
    'Stock de Repuestos' => ['Campo a completar'],
    'Otros' => ['Otros'],
];

$subOpcionesSoftware = [
    'Sistemas operativos'          => ['Windows', 'Linux', 'macOS', 'Otro'],
    'Licencias Office'             => ['Microsoft Office', 'Office 365', 'Otro'],
    'Antivirus'                    => ['ESET', 'Kaspersky', 'Norton', 'Avast', 'Otro'],
    'Software de backup'           => ['Acronis', 'Veeam', 'Backup Exec', 'Otro'],
    'Software contable / ERP'      => ['Tango', 'Bejerman', 'SAP', 'Otro'],
    'Licencias de servidores'      => ['Windows Server', 'CALs', 'Linux Server', 'Otro'],
    'Herramientas de virtualización' => ['VMware', 'Hyper-V', 'Proxmox', 'VirtualBox', 'Otro'],
    'Licencias en la nube'         => ['Google Workspace', 'Microsoft 365', 'AWS', 'Otro'],
];

// Helper para pre-poblar valores del formulario (tras error)
$fv = function(string $key, mixed $default = '') use ($fd): mixed {
    return $fd[$key] ?? $default;
};

// ── Variables de layout ─────────────────────────────────────────────────────
$page_title      = 'Agregar Activo — Asset Vault';
$page_section    = 'agregar';
$topbar_title    = 'Agregar Activo';
$topbar_subtitle = 'Registrá un nuevo equipo en el inventario';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = volver_boton('/inventario/index.php');

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($formError !== ''): ?>
    <div class="ih-alert ih-alert--danger">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <?= htmlspecialchars($formError) ?>
    </div>
    <?php endif; ?>

    <form id="form-agregar" action="/backend/upload_to_drive.php" method="POST" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

      <!-- ── Bloque 1: Datos principales ──────────────────────────── -->
      <div class="ih-card ih-mb-4">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-box-seam"></i> Datos principales</h3>
        </div>
        <div class="ih-card__body">
          <div class="ih-form-grid">

            <div class="ih-field ih-field--required">
              <label class="ih-label" for="f-nombre">Nombre / Modelo</label>
              <input type="text" id="f-nombre" name="nombre" class="ih-input"
                     placeholder="Ej: ThinkPad E15, Cisco SG350…"
                     value="<?= htmlspecialchars((string)$fv('modelo')) ?>" required>
              <span class="ih-field__hint">Identificador principal del activo</span>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-marca">Marca</label>
              <input type="text" id="f-marca" name="marca" class="ih-input"
                     placeholder="Ej: Lenovo, Cisco, HP…"
                     value="<?= htmlspecialchars((string)$fv('marca')) ?>">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-servis-tag">S/N — Service Tag</label>
              <input type="text" id="f-servis-tag" name="servis_tag" class="ih-input"
                     placeholder="Número de serie único"
                     value="<?= htmlspecialchars((string)$fv('servis_tag')) ?>">
              <span class="ih-field__hint">Si ingresás un S/N, cantidad se fija en 1</span>
            </div>

            <div class="ih-field" id="campo-cantidad">
              <label class="ih-label" for="f-cantidad">Cantidad</label>
              <input type="number" id="f-cantidad" name="cantidad" class="ih-input"
                     min="1" value="<?= (int)max(1, (int)$fv('cantidad', 1)) ?>" placeholder="Ej.: 1">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-asignado_a">Asignado a</label>
              <?php campo_catalogo($conn, 'asignado_a', (string)$fv('asignado_a'), $puedeGestionarCatalogos); ?>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-ubicacion">Ubicación</label>
              <?php campo_catalogo($conn, 'ubicacion', (string)$fv('ubicacion'), $puedeGestionarCatalogos); ?>
            </div>

            <div class="ih-field ih-field--required">
              <label class="ih-label" for="f-estado">Estado</label>
              <?php /* Era una lista fija escrita acá: Nuevo, Usado, A reparar,
                       Dado de baja. La edición del activo leía otra cosa, así que
                       las dos pantallas podían ofrecer estados distintos. Ahora
                       las dos salen del mismo catálogo, y cada empresa define su
                       escala. Los cuatro valores de antes los siembra la migración. */ ?>
              <?php campo_catalogo($conn, 'estado', (string)$fv('estado'), $puedeGestionarCatalogos); ?>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-valor">Valor en USD</label>
              <input type="number" id="f-valor" name="valor_usd" class="ih-input"
                     min="0" step="0.01" placeholder="0.00"
                     value="<?= $fv('valor_usd') !== '' ? (float)$fv('valor_usd') : '' ?>">
            </div>

          </div><!-- .ih-form-grid -->

          <div class="ih-field">
            <label class="ih-label" for="f-descripcion">Descripción</label>
            <textarea id="f-descripcion" name="descripcion" class="ih-input" rows="3"
                      placeholder="Notas adicionales, especificaciones técnicas…"><?= htmlspecialchars((string)$fv('descripcion')) ?></textarea>
          </div>
        </div>
      </div>

      <!-- ── Bloque 2: Categoría ────────────────────────────────────── -->
      <div class="ih-card ih-mb-4">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-folder2-open"></i> Categorización</h3>
        </div>
        <div class="ih-card__body">
          <div class="ih-form-grid">

            <div class="ih-field ih-field--required">
              <label class="ih-label" for="f-categoria">
                Categoría <i id="iconCat" class="bi bi-folder ih-label__icono"></i>
              </label>
              <select id="f-categoria" name="categoria" class="ih-select" required>
                <option value="">Seleccioná…</option>
                <?php foreach (array_keys($opcionesPorCategoria) as $cat): ?>
                  <option value="<?= htmlspecialchars($cat) ?>" <?= $fv('categoria') === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php /* Siempre visible, aunque todavía no aplique: escondido, esta
                     tarjeta quedaba con un solo campo y media fila en blanco, y al
                     elegir categoría el formulario pegaba un salto. Deshabilitado
                     dice por qué no se puede usar todavía. */ ?>
            <div class="ih-field" id="campo-subcategoria">
              <label class="ih-label" for="f-subcategoria">
                Subcategoría <i id="iconSub" class="bi bi-folder ih-label__icono"></i>
              </label>
              <select id="f-subcategoria" name="subcategoria" class="ih-select" disabled>
                <option value="">Elegí primero una categoría</option>
              </select>
            </div>

            <!-- Software: detalle de licencia -->
            <div class="ih-field" id="campo-subsoftware" hidden>
              <label class="ih-label" for="f-subsoftware">Plataforma / Licencia</label>
              <select id="f-subsoftware" name="subsoftware" class="ih-select">
                <option value="">Seleccioná…</option>
              </select>
            </div>

            <div class="ih-field" id="campo-subsoftware-otros" hidden>
              <label class="ih-label" for="f-subsoftware-otros">Detalle "Otros"</label>
              <input type="text" id="f-subsoftware-otros" name="subsoftware_otros" class="ih-input"
                     placeholder="Describí la plataforma…"
                     value="<?= htmlspecialchars((string)$fv('subsoftware_otros')) ?>">
            </div>

          </div>

          <!-- ── Extras por subcategoría ─────────────────────────── -->

          <!-- Patchcord -->
          <div id="extra-patchcord" class="ih-form-grid" hidden>
            <div class="ih-field">
              <label class="ih-label">Tipo de patchcord</label>
              <select name="patchcord_tipo" id="f-patchcord-tipo" class="ih-select">
                <option value="">— Seleccioná —</option>
                <?php foreach (['UTP','STP','FTP'] as $t): ?>
                  <option value="<?= $t ?>" <?= $fv('patchcord_tipo') === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ih-field" id="campo-patchcord-long" hidden>
              <label class="ih-label">Longitud</label>
              <select name="patchcord_longitud" id="f-patchcord-long" class="ih-select">
                <option value="">— Seleccioná —</option>
                <?php foreach (['1','3','5','10','>10'] as $l): ?>
                  <option value="<?= $l ?>" <?= $fv('patchcord_longitud') === $l ? 'selected' : '' ?>><?= $l ?> m</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Switch -->
          <div id="extra-switch" class="ih-form-grid" hidden>
            <div class="ih-field">
              <label class="ih-label">Gestión</label>
              <select name="switch_gestion" id="f-switch-gestion" class="ih-select">
                <option value="">Seleccioná…</option>
                <option value="No gestionado" <?= $fv('switch_gestion') === 'No gestionado' ? 'selected' : '' ?>>No gestionado</option>
                <option value="Gestionado"    <?= $fv('switch_gestion') === 'Gestionado' ? 'selected' : '' ?>>Gestionado</option>
              </select>
            </div>
          </div>

          <!-- Antenas -->
          <div id="extra-antenas" class="ih-form-grid" hidden>
            <div class="ih-field">
              <label class="ih-label">Tipo de antena</label>
              <select name="antenas_tipo" id="f-antenas-tipo" class="ih-select">
                <option value="">Seleccioná…</option>
                <option value="Omnidireccional" <?= $fv('antenas_tipo') === 'Omnidireccional' ? 'selected' : '' ?>>Omnidireccional</option>
                <option value="Direccional"     <?= $fv('antenas_tipo') === 'Direccional' ? 'selected' : '' ?>>Direccional</option>
              </select>
            </div>
          </div>

          <!-- Bobina UTP -->
          <div id="extra-bobina" class="ih-form-grid" hidden>
            <div class="ih-field">
              <label class="ih-label">Categoría cable</label>
              <select name="bobina_categoria" id="f-bobina-cat" class="ih-select">
                <option value="">— Seleccioná —</option>
                <option value="CAT5e" <?= $fv('bobina_categoria') === 'CAT5e' ? 'selected' : '' ?>>CAT5e</option>
                <option value="CAT6e" <?= $fv('bobina_categoria') === 'CAT6e' ? 'selected' : '' ?>>CAT6e</option>
              </select>
            </div>
            <div class="ih-field" id="campo-bobina-ubi" hidden>
              <label class="ih-label">Interior / Exterior</label>
              <select name="bobina_ubicacion" id="f-bobina-ubi" class="ih-select">
                <option value="">— Seleccioná —</option>
                <option value="Interior" <?= $fv('bobina_ubicacion') === 'Interior' ? 'selected' : '' ?>>Interior</option>
                <option value="Exterior" <?= $fv('bobina_ubicacion') === 'Exterior' ? 'selected' : '' ?>>Exterior</option>
              </select>
            </div>
            <div class="ih-field" id="campo-bobina-long" hidden>
              <label class="ih-label">Longitud (m)</label>
              <select name="bobina_longitud" id="f-bobina-long" class="ih-select">
                <option value="">— Seleccioná —</option>
                <?php foreach (['10','25','50','100','>100'] as $l): ?>
                  <option value="<?= $l ?>" <?= $fv('bobina_longitud') === $l ? 'selected' : '' ?>><?= $l ?> m</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Conectores -->
          <div id="extra-conectores" class="ih-form-grid" hidden>
            <div class="ih-field">
              <label class="ih-label">Tipo de conector</label>
              <select name="conectores_tipo" id="f-conectores-tipo" class="ih-select">
                <option value="">Seleccioná…</option>
                <?php foreach (['RJ45','SC','LC','Otros'] as $t): ?>
                  <option value="<?= $t ?>" <?= $fv('conectores_tipo') === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ih-field" id="campo-conectores-otros" hidden>
              <label class="ih-label">Especificá el conector</label>
              <input type="text" name="detalle_conectores" class="ih-input"
                     placeholder="Describí aquí…"
                     value="<?= htmlspecialchars((string)$fv('detalle_conectores')) ?>">
            </div>
          </div>

          <!-- Cintas de respaldo -->
          <div id="extra-cintas" class="ih-form-grid" hidden>
            <div class="ih-field">
              <label class="ih-label">Generación LTO</label>
              <select name="cintas_generacion" id="f-cintas-gen" class="ih-select">
                <option value="">Seleccioná…</option>
                <?php foreach (['LTO-1','LTO-2','LTO-3','LTO-4','LTO-5'] as $g): ?>
                  <option value="<?= $g ?>" <?= $fv('cintas_generacion') === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Repuestos -->
          <div id="extra-repuestos" class="ih-field" hidden>
            <label class="ih-label">Detalle del repuesto</label>
            <input type="text" name="detalle_repuesto" class="ih-input"
                   placeholder="Describí el repuesto…"
                   value="<?= htmlspecialchars((string)$fv('detalle_repuesto')) ?>">
          </div>

          <!-- Otros (subcategoría o categoría) -->
          <div id="extra-otros" class="ih-field" hidden>
            <label class="ih-label" id="label-otros">Detalle</label>
            <input type="text" name="detalle_otros" class="ih-input"
                   placeholder="Describí el activo…"
                   value="<?= htmlspecialchars((string)$fv('detalle_otros')) ?>">
          </div>

        </div>
      </div>

      <?php /* Campos especificos de la categoria definidos en datos
               (campos_definicion). Los catorce heredados los siguen dibujando los
               bloques fijos de arriba; aca salen los que se agregan sin tocar el
               esquema. Si no hay ninguno, la tarjeta entera no se dibuja. */ ?>
      <?php ob_start(); campos_dinamicos_render($conn); $camposHtml = ob_get_clean(); ?>
      <?php if (str_contains($camposHtml, 'campos-dinamicos__grupo')): ?>
      <div class="ih-card ih-mb-4">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-sliders"></i> Detalles de la categoría</h3>
        </div>
        <div class="ih-card__body">
          <?= $camposHtml ?>
        </div>
      </div>
      <?php endif; ?>

      <?php /* ── Bloque: sistema operativo ────────────────────────────────
               Se carga acá y no sólo en la ficha técnica porque es una fila por
               activo: entra en la misma transacción del alta. Lo que sí
               necesita que el activo ya exista —componentes, puertos de red,
               relaciones, contratos— se carga en la ficha, y el alta termina
               llevando ahí directamente. */ ?>
      <div class="ih-card ih-mb-4">
        <div class="ih-card__header">
          <div>
            <h3 class="ih-card__title"><i class="bi bi-windows"></i> Sistema operativo</h3>
            <p class="ih-card__subtitle">Opcional. Si el activo no corre ningún sistema —un cable,
            una bobina, un mueble— dejalo en blanco.</p>
          </div>
        </div>
        <div class="ih-card__body">
          <div class="ih-form-grid">
            <div class="ih-field">
              <label class="ih-label" for="f-so">Sistema</label>
              <?php campo_catalogo($conn, 'so', '', $puedeGestionarCatalogos, 'f-so'); ?>
              <div class="ih-field__hint">Ej: Windows 11, Ubuntu Server, VMware ESXi, MikroTik RouterOS</div>
            </div>
            <?php /* Version y Edicion siguen al sistema: eligiendo «Windows 11»
                     la lista pasa a ser 25H2, 24H2, 23H2… Antes los dos
                     desplegables salian vacios y habia que ir a cargarlos a otra
                     pantalla antes de poder anotar nada. */ ?>
            <div class="ih-field">
              <label class="ih-label" for="f-so_version">Versión</label>
              <?php campo_so_dependiente($conn, 'versiones', 'so_version', '', $puedeGestionarCatalogos, 'f-so_version'); ?>
              <div class="ih-field__hint">Se llena sola al elegir el sistema. Ej: 24H2, 22.04 LTS, 8.0</div>
              <div class="ih-field__hint cat-sin-opciones" hidden>
                Ese sistema no tiene versiones conocidas. Si la tenés, agregala con el botón +.
              </div>
            </div>
            <div class="ih-field">
              <label class="ih-label" for="f-so_edicion">Edición</label>
              <?php campo_so_dependiente($conn, 'ediciones', 'so_edicion', '', $puedeGestionarCatalogos, 'f-so_edicion'); ?>
              <div class="ih-field__hint">Se llena sola al elegir el sistema. Ej: Pro, Enterprise, Datacenter</div>
              <div class="ih-field__hint cat-sin-opciones" hidden>
                Ese sistema no distingue ediciones.
              </div>
            </div>
            <div class="ih-field">
              <label class="ih-label" for="f-so_arquitectura">Arquitectura</label>
              <?php campo_catalogo($conn, 'so_arquitectura', '', $puedeGestionarCatalogos, 'f-so_arquitectura'); ?>
              <div class="ih-field__hint">Ej: x86_64 en un PC o servidor, ARM64 en una Mac con chip M</div>
            </div>
            <div class="ih-field">
              <label class="ih-label" for="f-so-kernel">Kernel o build</label>
              <input class="ih-input" id="f-so-kernel" name="kernel" placeholder="Ej: 5.15.0-91-generic">
              <div class="ih-field__hint">En Linux, lo que devuelve <code>uname -r</code>. En Windows, el número de compilación</div>
            </div>
            <div class="ih-field">
              <label class="ih-label" for="f-so-clave">Clave de producto</label>
              <input class="ih-input" id="f-so-clave" name="clave_producto"
                     placeholder="Ej: XXXXX-XXXXX-XXXXX-XXXXX-XXXXX">
              <div class="ih-field__hint">La clave con la que se activó el sistema</div>
            </div>
            <div class="ih-field">
              <label class="ih-label" for="f-so-fecha">Instalado el</label>
              <input class="ih-input" type="date" id="f-so-fecha" name="instalado_en">
              <div class="ih-field__hint">Cuándo se instaló o se reinstaló por última vez</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Bloque 3: Garantía y fechas ───────────────────────────── -->
      <div class="ih-card ih-mb-4">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-shield-check"></i> Garantía y fechas</h3>
        </div>
        <div class="ih-card__body">
          <div class="ih-form-grid">

            <div class="ih-field">
              <label class="ih-label" for="f-periodo-garantia">Período de garantía (meses)</label>
              <input type="number" id="f-periodo-garantia" name="periodo_garantia" class="ih-input"
                     min="0" placeholder="0 = sin garantía"
                     value="<?= (int)$fv('periodo_garantia', 0) ?: '' ?>">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha-garantia">Inicio de garantía</label>
              <input type="date" id="f-fecha-garantia" name="fecha_garantia" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_garantia')) ?>" placeholder="dd/mm/aaaa">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha-ingreso">Fecha de recepción</label>
              <input type="date" id="f-fecha-ingreso" name="fecha_ingreso" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_ingreso')) ?>" placeholder="dd/mm/aaaa">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha-compra">Fecha de compra</label>
              <input type="date" id="f-fecha-compra" name="fecha_compra" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_compra')) ?>" placeholder="dd/mm/aaaa">
            </div>

          </div>
        </div>
      </div>

      <!-- ── Bloque 4: Adjuntos ──────────────────────────────────────── -->
            <!-- ── Bloque 4: Compra y administración (Infocom) ─────────────── -->
      <?php /* Datos financieros y administrativos. Todos opcionales: el alta no
               se traba por no tener la factura a mano y se completan despues
               desde la edicion del activo. */ ?>
      <div class="ih-card ih-mb-4">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-receipt"></i> Compra y administración</h3>
        </div>
        <div class="ih-card__body">
          <div class="ih-form-grid">

            <div class="ih-field">
              <label class="ih-label" for="f-numero_pedido">N.º de pedido</label>
              <input type="text" id="f-numero_pedido" name="numero_pedido" class="ih-input" maxlength="120"
                     value="<?= htmlspecialchars((string)$fv('numero_pedido')) ?>" placeholder="Ej.: OC-2026-0184">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-numero_remito">N.º de remito</label>
              <input type="text" id="f-numero_remito" name="numero_remito" class="ih-input" maxlength="120"
                     value="<?= htmlspecialchars((string)$fv('numero_remito')) ?>" placeholder="Ej.: 0001-00045212">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-numero_factura">N.º de factura</label>
              <input type="text" id="f-numero_factura" name="numero_factura" class="ih-input" maxlength="120"
                     value="<?= htmlspecialchars((string)$fv('numero_factura')) ?>" placeholder="Ej.: A 0003-00012845">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-proveedor">Proveedor</label>
              <?php campo_catalogo($conn, 'proveedor', (string)$fv('proveedor'), $puedeGestionarCatalogos); ?>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-presupuesto">Presupuesto</label>
              <?php campo_catalogo($conn, 'presupuesto', (string)$fv('presupuesto'), $puedeGestionarCatalogos); ?>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-criticidad">Criticidad de negocio</label>
              <?php campo_catalogo($conn, 'criticidad', (string)$fv('criticidad'), $puedeGestionarCatalogos); ?>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha_entrega">Fecha de entrega</label>
              <input type="date" id="f-fecha_entrega" name="fecha_entrega" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_entrega')) ?>" placeholder="dd/mm/aaaa">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha_puesta_servicio">Puesta en servicio</label>
              <input type="date" id="f-fecha_puesta_servicio" name="fecha_puesta_servicio" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_puesta_servicio')) ?>" placeholder="dd/mm/aaaa">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-amortizacion_anios">Años de amortización</label>
              <input type="number" id="f-amortizacion_anios" name="amortizacion_anios" class="ih-input" min="0" step="1"
                     value="<?= htmlspecialchars((string)$fv('amortizacion_anios')) ?>">
              <span class="ih-field__hint">Si queda vacío, el reporte usa el plazo general</span>
            </div>

          </div>
        </div>
      </div>

      <?php /* ── La ficha tecnica, en el mismo paso ──────────────────
               Antes el alta creaba el activo y mandaba a la ficha para cargar
               componentes, red, relaciones, contratos, documentos y notas: dos
               pantallas y ocho formularios separados para dar de alta UNA
               computadora, cada uno con su propio envio. Ahora entra todo junto
               y lo guarda ficha_alta_guardar() con el activo ya creado. */ ?>
      <?php campos_ficha_alta($conn); ?>

      <!-- ── Bloque 5: Adjuntos ──────────────────────────────────────── -->
      <div class="ih-card ih-mb-5">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-paperclip"></i> Archivos adjuntos</h3>
        </div>
        <div class="ih-card__body">
        <?php if (!$cloudUploadOk): ?>
          <?= cloud_upload_disabled_notice($user['role'] ?? '') ?>
        <?php else: ?>
          <?php /* El campo de archivo es el del sistema: arrastrar, la lista de
                   lo elegido con su peso, quitar uno, y el aviso de lo que no
                   entra por tamaño o por formato. Lo único que pone esta
                   pantalla es cuánto pesa el límite y qué formatos acepta. */ ?>
          <div class="ih-field">
            <label class="ih-label" for="f-archivo">
              Archivos para subir a
              <?= strtolower(cloud_provider_actual($conn)) === 'microsoft' ? 'OneDrive' : 'Google Drive' ?>
            </label>
            <input type="file" id="f-archivo" name="archivo[]" multiple
                   accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                   data-max-mb="5"
                   data-ayuda="Fotos, PDF, Word, Excel o PowerPoint · hasta 5 MB cada uno">
          </div>
        <?php endif; ?>
        </div>
      </div>

      <!-- ── Acciones ─────────────────────────────────────────────────── -->
      <div class="ih-barra__acciones ih-justify-end ih-mb-6">
        <a href="<?= htmlspecialchars(volver_destino('/inventario/index.php'), ENT_QUOTES) ?>"
           class="ih-btn ih-btn--ghost">Cancelar</a>
        <button type="submit" class="ih-btn ih-btn--accent" id="btn-guardar">
          <i class="bi bi-check-lg"></i> Guardar activo
        </button>
      </div>

    </form>
  </main>
</div>

<script>
const opcionesPorCategoria = <?= json_encode($opcionesPorCategoria, JSON_UNESCAPED_UNICODE) ?>;
const subOpcionesSoftware  = <?= json_encode($subOpcionesSoftware,  JSON_UNESCAPED_UNICODE) ?>;
const iconMapJS            = <?= json_encode($iconMap, JSON_UNESCAPED_UNICODE) ?>;

// Valores pre-cargados (desde sesión en caso de error)
const fdCategoria    = <?= json_encode((string)$fv('categoria')) ?>;
const fdSubcategoria = <?= json_encode((string)$fv('subcategoria')) ?>;
const fdSubsoftware  = <?= json_encode((string)$fv('subsoftware')) ?>;

const catSel   = document.getElementById('f-categoria');
const subSel   = document.getElementById('f-subcategoria');
const subSwCont = document.getElementById('campo-subsoftware');
const subSwSel  = document.getElementById('f-subsoftware');
const subSwOtros = document.getElementById('campo-subsoftware-otros');
const servisTag  = document.getElementById('f-servis-tag');
const cantCont   = document.getElementById('campo-cantidad');
const iconCat    = document.getElementById('iconCat');
const iconSub    = document.getElementById('iconSub');

// Helper: setear icono manteniendo el estilo de color
function setIcon(el, claseBi) {
  if (!el) return;
  const fallback = 'bi bi-folder';
  el.className = claseBi || fallback;
}

/* Mostrar y esconder va por el atributo `hidden` y no por `style.display`.
   Los dos funcionan, pero mezclarlos no: el HTML trae los bloques con
   `hidden`, y un `style.display = ''` no limpia ese atributo —la hoja del
   sistema declara `[hidden] { display: none !important }`—, asi que el bloque
   se quedaba escondido y la seccion de campos por categoria no aparecia nunca. */

// ── Cantidad oculta si hay S/N ────────────────────────────
function toggleCantidad() {
  cantCont.hidden = !!(servisTag.value.trim());
}
servisTag.addEventListener('input', toggleCantidad);
toggleCantidad();

// ── Subcategoría al cambiar categoría ─────────────────────
function mostrarExtras(subcat) {
  const extras = {
    'Patchcord':           'extra-patchcord',
    'Switch':              'extra-switch',
    'Antenas':             'extra-antenas',
    'Bobina UTP':          'extra-bobina',
    'Conectores':          'extra-conectores',
    'Cintas de respaldo':  'extra-cintas',
    'Campo a completar':   'extra-repuestos',
  };
  // Ocultar todos primero
  ['extra-patchcord','extra-switch','extra-antenas','extra-bobina','extra-conectores','extra-cintas','extra-repuestos','extra-otros'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.hidden = true;
  });

  if (extras[subcat]) {
    document.getElementById(extras[subcat]).hidden = false;
  } else if (subcat === 'Otros' || catSel.value === 'Otros' || catSel.value === 'Stock de Repuestos') {
    const el = document.getElementById('extra-otros');
    el.hidden = false;
    const lbl = document.getElementById('label-otros');
    if (catSel.value === 'Stock de Repuestos') lbl.textContent = 'Detalle de repuesto';
    else if (catSel.value === 'Otros') lbl.textContent = 'Detalle del activo (Otros)';
    else lbl.textContent = 'Detalle';
  }
}

function mostrarSubSoftware(subcat, selectedValue) {
  if (catSel.value === 'Software y licencias' && subOpcionesSoftware[subcat]) {
    subSwCont.hidden = false;
    subSwSel.innerHTML = '<option value="">Seleccioná…</option>';
    subOpcionesSoftware[subcat].forEach(opt => {
      subSwSel.innerHTML += `<option value="${opt}"${opt === selectedValue ? ' selected' : ''}>${opt}</option>`;
    });
    subSwOtros.hidden = !(selectedValue === 'Otro');
  } else {
    subSwCont.hidden = true;
    subSwOtros.hidden = true;
  }
}

function actualizarSubcategorias(catValue, subcatValue, preserveSubcat) {
  const lista = opcionesPorCategoria[catValue] || [];
  subSel.innerHTML = '<option value="">Seleccioná…</option>';
  lista.forEach(opt => {
    subSel.innerHTML += `<option value="${opt}"${opt === subcatValue ? ' selected' : ''}>${opt}</option>`;
  });

  // El campo queda siempre a la vista. Cuando no aplica se deshabilita y el
  // texto dice por qué, en vez de desaparecer y dejar la fila a medias.
  const esOtros = catValue === 'Otros' || catValue === 'Stock de Repuestos';
  const aplica  = lista.length && !esOtros;
  subSel.disabled = !aplica;
  if (!aplica) {
    subSel.innerHTML = '<option value="">'
      + (catValue === '' ? 'Elegí primero una categoría' : 'Esta categoría no usa subcategoría')
      + '</option>';
  }

  if (esOtros) {
    mostrarExtras('');
    document.getElementById('extra-otros').hidden = false;
    const lbl = document.getElementById('label-otros');
    lbl.textContent = catValue === 'Stock de Repuestos' ? 'Detalle de repuesto' : 'Detalle del activo';
  } else {
    mostrarExtras(subcatValue);
  }

  mostrarSubSoftware(subcatValue, fdSubsoftware);
}

catSel.addEventListener('change', () => {
  setIcon(iconCat, iconMapJS[catSel.value] || 'bi bi-folder');
  setIcon(iconSub, 'bi bi-folder');
  actualizarSubcategorias(catSel.value, '', false);
});

subSel.addEventListener('change', () => {
  setIcon(iconSub, iconMapJS[subSel.value] || 'bi bi-folder');
  mostrarExtras(subSel.value);
  mostrarSubSoftware(subSel.value, '');
});

subSwSel.addEventListener('change', () => {
  subSwOtros.hidden = !(subSwSel.value === 'Otro');
});

// ── Cascada de Patchcord ──────────────────────────────────
document.getElementById('f-patchcord-tipo')?.addEventListener('change', function() {
  document.getElementById('campo-patchcord-long').hidden = !(this.value);
});

// ── Cascada de Bobina ─────────────────────────────────────
document.getElementById('f-bobina-cat')?.addEventListener('change', function() {
  document.getElementById('campo-bobina-ubi').hidden = !(this.value);
  document.getElementById('campo-bobina-long').hidden = true;
});
document.getElementById('f-bobina-ubi')?.addEventListener('change', function() {
  document.getElementById('campo-bobina-long').hidden = !(this.value);
});

// ── Conectores Otros ──────────────────────────────────────
document.getElementById('f-conectores-tipo')?.addEventListener('change', function() {
  document.getElementById('campo-conectores-otros').hidden = !(this.value === 'Otros');
});

// ── Pre-cargar valores tras error de formulario ───────────
document.addEventListener('DOMContentLoaded', () => {
  if (fdCategoria) {
    setIcon(iconCat, iconMapJS[fdCategoria] || 'bi bi-folder');
    if (fdSubcategoria) setIcon(iconSub, iconMapJS[fdSubcategoria] || 'bi bi-folder');
    actualizarSubcategorias(fdCategoria, fdSubcategoria, true);
    // Esperar a que el DOM esté listo para activar cascadas
    setTimeout(() => {
      const patchTipo = document.getElementById('f-patchcord-tipo');
      if (patchTipo?.value) document.getElementById('campo-patchcord-long').hidden = false;
      const bobCat = document.getElementById('f-bobina-cat');
      if (bobCat?.value) {
        document.getElementById('campo-bobina-ubi').hidden = false;
        if (document.getElementById('f-bobina-ubi')?.value)
          document.getElementById('campo-bobina-long').hidden = false;
      }
      const conTipo = document.getElementById('f-conectores-tipo');
      if (conTipo?.value === 'Otros') document.getElementById('campo-conectores-otros').hidden = false;
    }, 50);
  }
  toggleCantidad();
});

// ── Submit ─────────────────────────────────────────────────
document.getElementById('form-agregar').addEventListener('submit', function(e) {
  // Habilitar selects ocultos para que pasen en el POST
  ['f-patchcord-tipo','f-patchcord-long','f-switch-gestion','f-antenas-tipo',
   'f-bobina-cat','f-bobina-ubi','f-bobina-long','f-conectores-tipo','f-cintas-gen','f-subsoftware'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = false;
  });

  // Deshabilitar botón para evitar doble submit
  const btn = document.getElementById('btn-guardar');
  btn.disabled = true;
  btn.innerHTML = '<span class="ih-spinner"></span> Guardando…';
});
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
