<?php
/**
 * Iconos SVG oficiales a color para los proveedores de cloud storage.
 * Se usan en sidebar (link Drive/Cloud), view_details (boton ver archivos)
 * y donde haga falta el branding del proveedor.
 */

if (!function_exists('cloud_icon_svg')) {
function cloud_icon_svg(string $provider = 'google', int $size = 20): string {
    $w = $size;
    $h = $size;

    if (strtolower($provider) === 'microsoft' || strtolower($provider) === 'onedrive') {
        // OneDrive — nube azul oficial Microsoft
        return <<<SVG
<svg width="{$w}" height="{$h}" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;display:inline-block;">
  <path fill="#0364B8" d="M12.236 16.231l5.027-4.81 5.36 4.027 3.187-1.341a5.726 5.726 0 0 0-.71-.077 5.727 5.727 0 0 0-4.69-4.926A7.643 7.643 0 0 0 12.236 16.231z"/>
  <path fill="#0078D4" d="M17.263 11.421l-5.027 4.81-1.83 2.353 9.21 1.135 4.193-1.815 1.165-2.456-5.36-4.027z"/>
  <path fill="#1490DF" d="M9.464 14.85a4.583 4.583 0 0 0-2.79.952 4.581 4.581 0 0 0-1.674 2.43l4.945 1.351 4.444-1.92 1.83-2.352-5.282-.401a4.582 4.582 0 0 0-1.473-.06z"/>
  <path fill="#28A8EA" d="M27.21 14.107a5.726 5.726 0 0 0-.71-.077l-3.188 1.341-1.165 2.456 4.045 1.776 1.892-.819a3.819 3.819 0 0 0-.874-4.677z"/>
  <path fill="#0078D4" d="M5 18.232c0 1.79.971 3.43 2.529 4.282 1.557.852 3.457.776 4.94-.197l15.115-1.892-.999-1.832-4.045-1.776-9.21-1.135-3.385.097-1.945.453z"/>
</svg>
SVG;
    }

    // Google Drive — logo oficial 6-segmentos a color
    return <<<SVG
<svg width="{$w}" height="{$h}" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;display:inline-block;">
  <path fill="#0066da" d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z"/>
  <path fill="#00ac47" d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44c-.8 1.4-1.2 2.95-1.2 4.5h27.5z"/>
  <path fill="#ea4335" d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z"/>
  <path fill="#00832d" d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z"/>
  <path fill="#2684fc" d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z"/>
  <path fill="#ffba00" d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z"/>
</svg>
SVG;
}
}

/**
 * Detecta el provider activo del tenant leyendo cloud_config.
 * Devuelve 'google' por defecto si no hay nada configurado.
 */
if (!function_exists('cloud_provider_actual')) {
function cloud_provider_actual(mysqli $conn): string {
    try {
        $cfgTable = tn('cloud_config');
        $res = $conn->query("SELECT provider FROM `{$cfgTable}` WHERE config_key = 'storage_provider' AND config_value != '' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return strtolower((string)$row['provider']);
        }
    } catch (Throwable) {}
    return 'google';
}
}

/**
 * Determina si el usuario actual puede subir archivos a la nube:
 *   - Debe haber access_token de OAuth en sesion (SSO con Google/Microsoft)
 *   - Y debe haber credenciales validas (en platform constants o en cloud_config del tenant)
 *
 * Si retorna false, el frontend debe OCULTAR la opcion de subir archivos.
 * No guardamos archivos en Hostinger por politica (seguridad + costo).
 */
if (!function_exists('cloud_upload_disponible')) {
function cloud_upload_disponible(mysqli $conn): bool {
    if (empty($_SESSION['access_token'])) return false;

    // Credenciales a nivel plataforma (recomendado)
    $platformOk = (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '')
               || (defined('MS_CLIENT_ID') && MS_CLIENT_ID !== '');
    if ($platformOk) return true;

    // Credenciales por tenant (cloud_config)
    try {
        $cfgTable = tn('cloud_config');
        $r = $conn->query("SELECT 1 FROM `{$cfgTable}` WHERE config_key IN ('client_id','client_secret') AND config_value != '' LIMIT 1");
        if ($r && $r->num_rows > 0) return true;
    } catch (Throwable) {}
    return false;
}
}

/**
 * Bloque HTML reutilizable para mostrar cuando cloud_upload_disponible() es false.
 * Aviso amigable con CTA a configurar la integracion.
 */
if (!function_exists('cloud_upload_disabled_notice')) {
function cloud_upload_disabled_notice(string $rol = ''): string {
    $isAdmin = ($rol === 'admin');
    $cta = $isAdmin
        ? '<a href="/inventario/configurar_integracion.php" class="ih-btn ih-btn--primary ih-btn--sm" style="margin-top:10px;"><i class="bi bi-gear"></i> Configurar Drive / OneDrive</a>'
        : '<div style="margin-top:8px;font-size:var(--ih-text-xs);color:var(--ih-gray-500);">Pedile al administrador que configure la integración con Google Drive o Microsoft OneDrive.</div>';
    return <<<HTML
<div class="ih-alert ih-alert--info" style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">
  <div style="display:flex;align-items:center;gap:10px;">
    <i class="bi bi-cloud-slash" style="font-size:1.4rem;color:var(--ih-gray-500);"></i>
    <div>
      <strong>Subida de archivos no disponible</strong><br>
      <span style="font-size:var(--ih-text-sm);color:var(--ih-gray-600);">
        Para adjuntar archivos al activo necesitás iniciar sesión con Google o Microsoft.
        Los archivos se guardan en tu nube (Drive / OneDrive), no en nuestro servidor.
      </span>
    </div>
  </div>
  {$cta}
</div>
HTML;
}
}
