<?php
/**
 * Cabecera HTML reutilizable — INTELLIHELP
 *
 * Variables esperadas (opcionales):
 *   $page_title    string — Título de la pestaña del navegador
 *   $page_section  string — Sección activa para resaltar en la navegación
 *   $extra_css     string — CSS adicional inline o link
 *   $page_portal   string — 'tenant' | 'superadmin' (si no se define, se deduce)
 *
 * Hay DOS hojas de estilo:
 *   assets/css/app.css         → portal tenant, con el mismo lenguaje visual que
 *                                 el CRM y APP Tickets (Saira, #663399, #161922,
 *                                 acento #9CC930, radios de 15px, topbar+pestañas)
 *   assets/css/intellihelp.css → panel superadmin, que conserva su diseño.
 */
$page_title   = $page_title   ?? 'Asset Vault';
$page_section = $page_section ?? '';

// El portal se deduce de la sesión: si hay flag de superadmin, es el panel.
if (!isset($page_portal)) {
    $page_portal = (!empty($_SESSION[SESSION_SUPERADMIN_FLAG] ?? null)) ? 'superadmin' : 'tenant';
}
$hoja = $page_portal === 'superadmin' ? '/assets/css/intellihelp.css' : '/assets/css/app.css';

// Los componentes propios (desplegables, calendario, confirmación, archivos)
// son los MISMOS en los dos portales: una sola hoja y un solo script, para que
// no se bifurquen como ya pasó con los estilos del logo del topbar.
// `define` con guarda y no `const`: si alguna pantalla llegara a incluir la
// cabecera dos veces, `const` seria un fatal.
if (!defined('ASSETS_COMPONENTES_CSS')) {
    define('ASSETS_COMPONENTES_CSS', '/assets/css/componentes.css');
    define('ASSETS_COMPONENTES_JS',  '/assets/js/componentes.js');
}

// Versión de assets: sin esto, un cambio de CSS lo sigue tapando la caché del
// navegador (y del CDN de Hostinger) hasta que el usuario hace Ctrl+F5.
//
// Se toma la fecha MÁS NUEVA de los tres archivos, no sólo la de la hoja del
// portal: si mirara una sola, tocar componentes.css o componentes.js no movería
// la versión y el cambio quedaría tapado igual.
if (!defined('ASSETS_V')) {
    $__raiz = __DIR__ . '/../..';
    $__t = 0;
    foreach ([$hoja, ASSETS_COMPONENTES_CSS, ASSETS_COMPONENTES_JS] as $__a) {
        $__f = $__raiz . $__a;
        if (is_file($__f)) { $__t = max($__t, (int)filemtime($__f)); }
    }
    define('ASSETS_V', $__t > 0 ? (string)$__t : '1');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#663399">
  <title><?= htmlspecialchars($page_title) ?> · INTELLIHELP</title>

  <?php /* Saira por <link> y no por @import dentro del CSS: el @import obliga al
           navegador a bajar y parsear la hoja entera antes de descubrir la fuente. */ ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= $hoja ?>?v=<?= ASSETS_V ?>" rel="stylesheet">
  <link href="<?= ASSETS_COMPONENTES_CSS ?>?v=<?= ASSETS_V ?>" rel="stylesheet">

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%23663399'/><text x='32' y='42' font-family='Saira,sans-serif' font-weight='800' font-size='30' fill='white' text-anchor='middle'>i</text><circle cx='46' cy='46' r='6' fill='%239CC930'/></svg>">
  <?php if (!empty($extra_css)) echo $extra_css; ?>
</head>
<body class="ih-portal-<?= htmlspecialchars($page_portal) ?>">
