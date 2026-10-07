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
<svg width="{$w}" height="{$h}" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" class="ih-logo-inline">
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
<svg width="{$w}" height="{$h}" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg" class="ih-logo-inline">
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
 * El logotipo de una marca ajena, el de verdad.
 *
 * Google Drive y OneDrive ya tenían el suyo acá arriba, pero el botón de
 * «Continuar con Google», el de Microsoft y las pestañas de la pantalla de
 * integración usaban `<i class="bi bi-google">` y `<i class="bi bi-microsoft">`:
 * los iconos genéricos de Bootstrap, de un solo color y con la forma
 * aproximada. La G de Google se reconoce porque es roja, azul, amarilla y
 * verde, y el cuadrado de Microsoft porque son cuatro de colores distintos;
 * dibujados en gris, o en violeta, dejan de decir de quién son. Es justo
 * donde más importa: en el botón con el que alguien decide entrar.
 *
 * Va en un SVG en línea y no en una imagen de un servidor ajeno: así no se cae
 * si el otro cambia la URL, no filtra a dónde entra cada persona, y no hay un
 * pedido de red para dibujar un botón.
 *
 * Los colores están en la lista de `test/colores.php`: son los únicos ajenos
 * que la paleta admite, y sólo adentro de un logotipo.
 *
 * @param string $marca google | microsoft | drive | onedrive
 */
if (!function_exists('marca_svg')) {
function marca_svg(string $marca, int $size = 18): string {
    $m = strtolower(trim($marca));

    if ($m === 'drive' || $m === 'google-drive' || $m === 'googledrive') {
        return cloud_icon_svg('google', $size);
    }
    if ($m === 'onedrive' || $m === 'microsoft-onedrive') {
        return cloud_icon_svg('microsoft', $size);
    }

    if ($m === 'microsoft' || $m === 'ms' || $m === 'microsoft365') {
        // Los cuatro cuadrados, en la proporción oficial.
        return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg"
     class="ih-logo-inline" aria-hidden="true" focusable="false">
  <path fill="#f25022" d="M1 1h10v10H1z"/>
  <path fill="#7fba00" d="M12 1h10v10H12z"/>
  <path fill="#00a4ef" d="M1 12h10v10H1z"/>
  <path fill="#ffb900" d="M12 12h10v10H12z"/>
</svg>
SVG;
    }

    // La G de Google.
    return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"
     class="ih-logo-inline" aria-hidden="true" focusable="false">
  <path fill="#ea4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
  <path fill="#4285f4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
  <path fill="#fbbc05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
  <path fill="#34a853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
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
    /* Para quien puede resolverlo, el boton que lo resuelve. Para quien no, a
       quien pedirselo —un aviso que dice «no se puede» y no dice quien puede
       destrabarlo deja a la persona sin salida. */
    $cta = $isAdmin
        ? '<a href="/inventario/configurar_integracion.php" class="ih-btn ih-btn--primary ih-btn--sm">'
          . '<i class="bi bi-gear"></i> Conectar Google Drive o OneDrive</a>'
        : '<p class="ih-nota__firma">Pedíselo a quien administra el inventario: se conecta una vez '
          . 'y queda andando para todos.</p>';
    return <<<HTML
<div class="ih-alert ih-alert--info ih-alert--accion">
  <i class="bi bi-cloud-slash"></i>
  <div class="ih-alert__cuerpo">
    <strong>Por ahora no se pueden adjuntar archivos</strong><br>
    Los archivos de cada activo se guardan en la nube de la empresa —Google Drive o
    OneDrive— y no en este servidor. Hasta que se conecte una de las dos, el activo se puede
    cargar igual: lo único que falta es poder adjuntarle archivos.
    {$cta}
  </div>
</div>
HTML;
}
}
