<?php
/**
 * Cabecera HTML reutilizable — INTELLIHELP
 * Variables esperadas (opcionales):
 *   $page_title    string  — Título de la pestaña del navegador
 *   $page_section  string  — Sección activa para resaltar en el sidebar
 *   $extra_css     string  — CSS adicional inline o link
 */
$page_title   = $page_title   ?? 'Asset Vault';
$page_section = $page_section ?? '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#663399">
  <title><?= htmlspecialchars($page_title) ?> · INTELLIHELP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%23663399'/><text x='32' y='42' font-family='Saira,sans-serif' font-weight='800' font-size='30' fill='white' text-anchor='middle'>i</text><circle cx='46' cy='46' r='6' fill='%239CC930'/></svg>">
  <?php if (!empty($extra_css)) echo $extra_css; ?>
</head>
<body>
