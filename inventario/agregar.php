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
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

// Icon map para iconos dinamicos al lado de categoria/subcategoria
$iconMap = require __DIR__ . '/../includes/iconMap.php';

// Politica: subir archivos solo si hay Drive/OneDrive configurado
require_once __DIR__ . '/../includes/cloud_icons.php';
$cloudUploadOk = cloud_upload_disponible($conn);

require_once __DIR__ . '/../core/security/permissions.php';

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

    $tenant_name = 'Mi empresa';
    try {
        require_once __DIR__ . '/../core/db/master_connection.php';
        $masterDb = new MasterDB();
        $companyId = (int)($_SESSION['company_id'] ?? 0);
        if ($companyId > 0) {
            $cstmt = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
            $crow = $cstmt->get_result()->fetch_assoc();
            if ($crow) $tenant_name = $crow['nombre'];
            $cstmt->close();
        }
    } catch (Throwable) {}

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
$topbar_actions  = '<a href="/inventario/index.php" class="ih-btn ih-btn--ghost ih-btn--sm"><i class="bi bi-arrow-left"></i> <span class="ih-hidden-mobile">Volver</span></a>';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_tenant.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($formError !== ''): ?>
    <div class="ih-alert ih-alert--danger" style="margin-bottom:20px;">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <?= htmlspecialchars($formError) ?>
    </div>
    <?php endif; ?>

    <form id="form-agregar" action="/backend/upload_to_drive.php" method="POST" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

      <!-- ── Bloque 1: Datos principales ──────────────────────────── -->
      <div class="ih-card" style="margin-bottom:16px;">
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
                     min="1" value="<?= (int)max(1, (int)$fv('cantidad', 1)) ?>">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-asignado">Asignado a</label>
              <input type="text" id="f-asignado" name="asignado_a" class="ih-input"
                     placeholder="Nombre del responsable o área"
                     value="<?= htmlspecialchars((string)$fv('asignado_a')) ?>">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-ubicacion">Ubicación</label>
              <input type="text" id="f-ubicacion" name="ubicacion" class="ih-input"
                     placeholder="Ej: Oficina 2, Rack A3, Depósito…"
                     value="<?= htmlspecialchars((string)$fv('ubicacion')) ?>">
            </div>

            <div class="ih-field ih-field--required">
              <label class="ih-label" for="f-estado">Estado</label>
              <select id="f-estado" name="estado" class="ih-select" required>
                <option value="">Seleccioná un estado…</option>
                <?php foreach (['Nuevo','Usado','A reparar','Dado de baja'] as $est): ?>
                  <option value="<?= $est ?>" <?= $fv('estado') === $est ? 'selected' : '' ?>><?= $est ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-valor">Valor en USD</label>
              <input type="number" id="f-valor" name="valor_usd" class="ih-input"
                     min="0" step="0.01" placeholder="0.00"
                     value="<?= $fv('valor_usd') !== '' ? (float)$fv('valor_usd') : '' ?>">
            </div>

          </div><!-- .ih-form-grid -->

          <div class="ih-field" style="margin-top:4px;">
            <label class="ih-label" for="f-descripcion">Descripción</label>
            <textarea id="f-descripcion" name="descripcion" class="ih-input" rows="3"
                      placeholder="Notas adicionales, especificaciones técnicas…"><?= htmlspecialchars((string)$fv('descripcion')) ?></textarea>
          </div>
        </div>
      </div>

      <!-- ── Bloque 2: Categoría ────────────────────────────────────── -->
      <div class="ih-card" style="margin-bottom:16px;">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-folder2-open"></i> Categorización</h3>
        </div>
        <div class="ih-card__body">
          <div class="ih-form-grid">

            <div class="ih-field ih-field--required">
              <label class="ih-label" for="f-categoria">
                Categoría <i id="iconCat" class="bi bi-folder" style="margin-left:6px;color:var(--ih-primary);"></i>
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

            <div class="ih-field" id="campo-subcategoria" style="display:none;">
              <label class="ih-label" for="f-subcategoria">
                Subcategoría <i id="iconSub" class="bi bi-folder" style="margin-left:6px;color:var(--ih-primary);"></i>
              </label>
              <select id="f-subcategoria" name="subcategoria" class="ih-select">
                <option value="">Seleccioná…</option>
              </select>
            </div>

            <!-- Software: detalle de licencia -->
            <div class="ih-field" id="campo-subsoftware" style="display:none;">
              <label class="ih-label" for="f-subsoftware">Plataforma / Licencia</label>
              <select id="f-subsoftware" name="subsoftware" class="ih-select">
                <option value="">Seleccioná…</option>
              </select>
            </div>

            <div class="ih-field" id="campo-subsoftware-otros" style="display:none;">
              <label class="ih-label" for="f-subsoftware-otros">Detalle "Otros"</label>
              <input type="text" id="f-subsoftware-otros" name="subsoftware_otros" class="ih-input"
                     placeholder="Describí la plataforma…"
                     value="<?= htmlspecialchars((string)$fv('subsoftware_otros')) ?>">
            </div>

          </div>

          <!-- ── Extras por subcategoría ─────────────────────────── -->

          <!-- Patchcord -->
          <div id="extra-patchcord" style="display:none;" class="ih-form-grid" style="margin-top:12px;">
            <div class="ih-field">
              <label class="ih-label">Tipo de patchcord</label>
              <select name="patchcord_tipo" id="f-patchcord-tipo" class="ih-select">
                <option value="">— Seleccioná —</option>
                <?php foreach (['UTP','STP','FTP'] as $t): ?>
                  <option value="<?= $t ?>" <?= $fv('patchcord_tipo') === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ih-field" id="campo-patchcord-long" style="display:none;">
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
          <div id="extra-switch" style="display:none;" class="ih-form-grid">
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
          <div id="extra-antenas" style="display:none;" class="ih-form-grid">
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
          <div id="extra-bobina" style="display:none;" class="ih-form-grid">
            <div class="ih-field">
              <label class="ih-label">Categoría cable</label>
              <select name="bobina_categoria" id="f-bobina-cat" class="ih-select">
                <option value="">— Seleccioná —</option>
                <option value="CAT5e" <?= $fv('bobina_categoria') === 'CAT5e' ? 'selected' : '' ?>>CAT5e</option>
                <option value="CAT6e" <?= $fv('bobina_categoria') === 'CAT6e' ? 'selected' : '' ?>>CAT6e</option>
              </select>
            </div>
            <div class="ih-field" id="campo-bobina-ubi" style="display:none;">
              <label class="ih-label">Interior / Exterior</label>
              <select name="bobina_ubicacion" id="f-bobina-ubi" class="ih-select">
                <option value="">— Seleccioná —</option>
                <option value="Interior" <?= $fv('bobina_ubicacion') === 'Interior' ? 'selected' : '' ?>>Interior</option>
                <option value="Exterior" <?= $fv('bobina_ubicacion') === 'Exterior' ? 'selected' : '' ?>>Exterior</option>
              </select>
            </div>
            <div class="ih-field" id="campo-bobina-long" style="display:none;">
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
          <div id="extra-conectores" style="display:none;" class="ih-form-grid">
            <div class="ih-field">
              <label class="ih-label">Tipo de conector</label>
              <select name="conectores_tipo" id="f-conectores-tipo" class="ih-select">
                <option value="">Seleccioná…</option>
                <?php foreach (['RJ45','SC','LC','Otros'] as $t): ?>
                  <option value="<?= $t ?>" <?= $fv('conectores_tipo') === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ih-field" id="campo-conectores-otros" style="display:none;">
              <label class="ih-label">Especificá el conector</label>
              <input type="text" name="detalle_conectores" class="ih-input"
                     placeholder="Describí aquí…"
                     value="<?= htmlspecialchars((string)$fv('detalle_conectores')) ?>">
            </div>
          </div>

          <!-- Cintas de respaldo -->
          <div id="extra-cintas" style="display:none;" class="ih-form-grid">
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
          <div id="extra-repuestos" style="display:none;" class="ih-field">
            <label class="ih-label">Detalle del repuesto</label>
            <input type="text" name="detalle_repuesto" class="ih-input"
                   placeholder="Describí el repuesto…"
                   value="<?= htmlspecialchars((string)$fv('detalle_repuesto')) ?>">
          </div>

          <!-- Otros (subcategoría o categoría) -->
          <div id="extra-otros" style="display:none;" class="ih-field">
            <label class="ih-label" id="label-otros">Detalle</label>
            <input type="text" name="detalle_otros" class="ih-input"
                   placeholder="Describí el activo…"
                   value="<?= htmlspecialchars((string)$fv('detalle_otros')) ?>">
          </div>

        </div>
      </div>

      <!-- ── Bloque 3: Garantía y fechas ───────────────────────────── -->
      <div class="ih-card" style="margin-bottom:16px;">
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
                     value="<?= htmlspecialchars((string)$fv('fecha_garantia')) ?>">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha-ingreso">Fecha de recepción</label>
              <input type="date" id="f-fecha-ingreso" name="fecha_ingreso" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_ingreso')) ?>">
            </div>

            <div class="ih-field">
              <label class="ih-label" for="f-fecha-compra">Fecha de compra</label>
              <input type="date" id="f-fecha-compra" name="fecha_compra" class="ih-input"
                     value="<?= htmlspecialchars((string)$fv('fecha_compra')) ?>">
            </div>

          </div>
        </div>
      </div>

      <!-- ── Bloque 4: Adjuntos ──────────────────────────────────────── -->
      <div class="ih-card" style="margin-bottom:24px;">
        <div class="ih-card__header">
          <h3 class="ih-card__title"><i class="bi bi-paperclip"></i> Archivos adjuntos</h3>
        </div>
        <div class="ih-card__body">
        <?php if (!$cloudUploadOk): ?>
          <?= cloud_upload_disabled_notice($user['role'] ?? '') ?>
        <?php else: ?>
          <div class="ih-field">
            <label class="ih-label" for="f-archivo">Subir archivos a <?= strtolower(cloud_provider_actual($conn)) === 'microsoft' ? 'OneDrive' : 'Google Drive' ?></label>
            <div class="ih-dropzone" id="dropzone">
              <i class="bi bi-cloud-upload" style="font-size:2rem;color:var(--ih-gray-400);display:block;margin-bottom:8px;"></i>
              <p style="color:var(--ih-gray-500);margin:0 0 8px;">Arrastrá archivos acá o hacé click para seleccionar</p>
              <p class="ih-text-sm ih-text-muted">Formatos: JPG, PNG, PDF, Word, Excel, PowerPoint · Máximo 5 MB por archivo</p>
              <input type="file" id="f-archivo" name="archivo[]" multiple
                     accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                     style="position:absolute;inset:0;opacity:0;cursor:pointer;">
            </div>
            <div id="lista-archivos" style="margin-top:8px;"></div>
          </div>
        <?php endif; ?>
        </div>
      </div>

      <!-- ── Acciones ─────────────────────────────────────────────────── -->
      <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:40px;">
        <a href="/inventario/index.php" class="ih-btn ih-btn--ghost">Cancelar</a>
        <button type="submit" class="ih-btn ih-btn--accent" id="btn-guardar">
          <i class="bi bi-check-lg"></i> Guardar activo
        </button>
      </div>

    </form>
  </main>
</div>

<style>
.ih-form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.ih-field { display: flex; flex-direction: column; gap: 4px; }
.ih-field--required .ih-label::after { content: ' *'; color: var(--ih-danger); }
.ih-field__hint { font-size: 11px; color: var(--ih-gray-400); }
.ih-label { font-size: 13px; font-weight: 600; color: var(--ih-gray-700); }
.ih-alert {
  padding: 12px 16px; border-radius: 8px; display: flex; align-items: center; gap: 10px;
}
.ih-alert--danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.ih-dropzone {
  border: 2px dashed var(--ih-gray-300); border-radius: 10px;
  padding: 32px 24px; text-align: center; cursor: pointer; position: relative;
  transition: border-color .2s, background .2s;
}
.ih-dropzone:hover, .ih-dropzone.is-over { border-color: var(--ih-green); background: #f0faf0; }
.file-chip {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--ih-gray-100); border-radius: 20px;
  padding: 4px 10px; font-size: 12px; margin: 4px 2px;
}
.file-chip__remove { cursor: pointer; color: var(--ih-danger); font-size: 14px; }
</style>

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
const subCont  = document.getElementById('campo-subcategoria');
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

// ── Cantidad oculta si hay S/N ────────────────────────────
function toggleCantidad() {
  cantCont.style.display = servisTag.value.trim() ? 'none' : '';
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
    if (el) el.style.display = 'none';
  });

  if (extras[subcat]) {
    document.getElementById(extras[subcat]).style.display = '';
  } else if (subcat === 'Otros' || catSel.value === 'Otros' || catSel.value === 'Stock de Repuestos') {
    const el = document.getElementById('extra-otros');
    el.style.display = '';
    const lbl = document.getElementById('label-otros');
    if (catSel.value === 'Stock de Repuestos') lbl.textContent = 'Detalle de repuesto';
    else if (catSel.value === 'Otros') lbl.textContent = 'Detalle del activo (Otros)';
    else lbl.textContent = 'Detalle';
  }
}

function mostrarSubSoftware(subcat, selectedValue) {
  if (catSel.value === 'Software y licencias' && subOpcionesSoftware[subcat]) {
    subSwCont.style.display = '';
    subSwSel.innerHTML = '<option value="">Seleccioná…</option>';
    subOpcionesSoftware[subcat].forEach(opt => {
      subSwSel.innerHTML += `<option value="${opt}"${opt === selectedValue ? ' selected' : ''}>${opt}</option>`;
    });
    subSwOtros.style.display = selectedValue === 'Otro' ? '' : 'none';
  } else {
    subSwCont.style.display = 'none';
    subSwOtros.style.display = 'none';
  }
}

function actualizarSubcategorias(catValue, subcatValue, preserveSubcat) {
  const lista = opcionesPorCategoria[catValue] || [];
  subSel.innerHTML = '<option value="">Seleccioná…</option>';
  lista.forEach(opt => {
    subSel.innerHTML += `<option value="${opt}"${opt === subcatValue ? ' selected' : ''}>${opt}</option>`;
  });

  const esOtros = catValue === 'Otros' || catValue === 'Stock de Repuestos';
  subCont.style.display = (lista.length && !esOtros) ? '' : 'none';

  if (esOtros) {
    mostrarExtras('');
    document.getElementById('extra-otros').style.display = '';
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
  subSwOtros.style.display = subSwSel.value === 'Otro' ? '' : 'none';
});

// ── Cascada de Patchcord ──────────────────────────────────
document.getElementById('f-patchcord-tipo')?.addEventListener('change', function() {
  document.getElementById('campo-patchcord-long').style.display = this.value ? '' : 'none';
});

// ── Cascada de Bobina ─────────────────────────────────────
document.getElementById('f-bobina-cat')?.addEventListener('change', function() {
  document.getElementById('campo-bobina-ubi').style.display = this.value ? '' : 'none';
  document.getElementById('campo-bobina-long').style.display = 'none';
});
document.getElementById('f-bobina-ubi')?.addEventListener('change', function() {
  document.getElementById('campo-bobina-long').style.display = this.value ? '' : 'none';
});

// ── Conectores Otros ──────────────────────────────────────
document.getElementById('f-conectores-tipo')?.addEventListener('change', function() {
  document.getElementById('campo-conectores-otros').style.display = this.value === 'Otros' ? '' : 'none';
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
      if (patchTipo?.value) document.getElementById('campo-patchcord-long').style.display = '';
      const bobCat = document.getElementById('f-bobina-cat');
      if (bobCat?.value) {
        document.getElementById('campo-bobina-ubi').style.display = '';
        if (document.getElementById('f-bobina-ubi')?.value)
          document.getElementById('campo-bobina-long').style.display = '';
      }
      const conTipo = document.getElementById('f-conectores-tipo');
      if (conTipo?.value === 'Otros') document.getElementById('campo-conectores-otros').style.display = '';
    }, 50);
  }
  toggleCantidad();
});

// ── Dropzone ──────────────────────────────────────────────
const dropzone   = document.getElementById('dropzone');
const fileInput  = document.getElementById('f-archivo');
const listaFiles = document.getElementById('lista-archivos');
const tiposOK    = ['image/jpeg','image/png','image/webp','image/gif','application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.ms-powerpoint',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation'
];

let archivosSel = [];

function renderChips() {
  listaFiles.innerHTML = '';
  archivosSel.forEach((f, i) => {
    const chip = document.createElement('span');
    chip.className = 'file-chip';
    chip.innerHTML = `<i class="bi bi-file-earmark"></i> ${f.name} <span class="file-chip__remove" data-i="${i}">✕</span>`;
    listaFiles.appendChild(chip);
  });
  listaFiles.querySelectorAll('.file-chip__remove').forEach(btn => {
    btn.addEventListener('click', () => {
      archivosSel.splice(+btn.dataset.i, 1);
      renderChips();
    });
  });
}

function agregarArchivos(files) {
  for (const f of files) {
    if (f.size > 5 * 1024 * 1024) { alert(`"${f.name}" excede 5 MB.`); continue; }
    if (!tiposOK.includes(f.type)) { alert(`Formato de "${f.name}" no permitido.`); continue; }
    if (!archivosSel.find(a => a.name === f.name && a.size === f.size)) archivosSel.push(f);
  }
  renderChips();
}

dropzone.addEventListener('dragover',  e => { e.preventDefault(); dropzone.classList.add('is-over'); });
dropzone.addEventListener('dragleave', ()  => dropzone.classList.remove('is-over'));
dropzone.addEventListener('drop',      e  => {
  e.preventDefault(); dropzone.classList.remove('is-over');
  agregarArchivos(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => { agregarArchivos(fileInput.files); fileInput.value = ''; });

// ── Submit ─────────────────────────────────────────────────
document.getElementById('form-agregar').addEventListener('submit', function(e) {
  // Habilitar selects ocultos para que pasen en el POST
  ['f-patchcord-tipo','f-patchcord-long','f-switch-gestion','f-antenas-tipo',
   'f-bobina-cat','f-bobina-ubi','f-bobina-long','f-conectores-tipo','f-cintas-gen','f-subsoftware'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = false;
  });

  // Inyectar archivos seleccionados en el input real
  if (archivosSel.length) {
    const dt = new DataTransfer();
    archivosSel.forEach(f => dt.items.add(f));
    fileInput.files = dt.files;
  }

  // Deshabilitar botón para evitar doble submit
  const btn = document.getElementById('btn-guardar');
  btn.disabled = true;
  btn.innerHTML = '<span class="ih-spinner"></span> Guardando…';
});
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
