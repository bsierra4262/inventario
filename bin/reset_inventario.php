<?php
/**
 * Vacía el inventario de UNA empresa (activos + historial de movimientos).
 *
 * NO toca usuarios, roles, cloud_config, la empresa en el master ni el login.
 * Reemplaza a `reset_inventario_ith.php`, que vivía en el web root con un gate
 * roto y respondía 200 a cualquiera en internet.
 *
 * Uso:
 *   php bin/reset_inventario.php <subdomain|id>
 *   php bin/reset_inventario.php ITH
 */

require_once __DIR__ . '/_cli.php';
require_once APP_ROOT . '/core/db/master_connection.php';
require_once APP_ROOT . '/core/tenants/install_tenant.php';

cli_requiere_dev();

$ref = $argv[1] ?? '';
if ($ref === '') {
    echo "Uso: php bin/reset_inventario.php <subdomain|id>\n";
    exit(1);
}

cli_title('Vaciar inventario de una empresa');

$db = new MasterDB();
if (ctype_digit($ref)) {
    $st = $db->pquery('SELECT id, nombre, subdomain, tenant_mode FROM companies WHERE id = ? LIMIT 1', 'i', [(int)$ref]);
} else {
    $st = $db->pquery('SELECT id, nombre, subdomain, tenant_mode FROM companies WHERE UPPER(subdomain) = UPPER(?) LIMIT 1', 's', [$ref]);
}
$empresa = $st->get_result()->fetch_assoc();
$st->close();

if (!$empresa) {
    cli_err("No existe ninguna empresa con referencia «{$ref}».");
    exit(1);
}

['conn' => $conn, 'prefix' => $pf] = tenant_conn_by_company((int)$empresa['id']);

$tProd = $pf . 'productos';
$tMov  = $pf . 'producto_movimientos';

$nProd = (int)($conn->query("SELECT COUNT(*) n FROM `{$tProd}`")->fetch_assoc()['n'] ?? 0);
$nMov  = (int)($conn->query("SELECT COUNT(*) n FROM `{$tMov}`")->fetch_assoc()['n'] ?? 0);

cli_info("Empresa : {$empresa['nombre']} (id {$empresa['id']}, subdomain " . ($empresa['subdomain'] ?: '—') . ")");
cli_info("Activos : {$nProd}");
cli_info("Historial: {$nMov}");
cli_info('Usuarios, roles, login y config NO se tocan.');

cli_confirmar('VACIAR INVENTARIO ' . strtoupper((string)($empresa['subdomain'] ?: $empresa['id'])));

$conn->query('SET FOREIGN_KEY_CHECKS = 0');
$conn->query("DELETE FROM `{$tMov}`")  ? cli_ok("Historial vaciado ({$tMov})")  : cli_err('No se pudo vaciar el historial: ' . $conn->error);
$conn->query("ALTER TABLE `{$tMov}` AUTO_INCREMENT = 1");
$conn->query("DELETE FROM `{$tProd}`") ? cli_ok("Activos vaciados ({$tProd})") : cli_err('No se pudieron vaciar los activos: ' . $conn->error);
$conn->query("ALTER TABLE `{$tProd}` AUTO_INCREMENT = 1");
$conn->query('SET FOREIGN_KEY_CHECKS = 1');

cli_ok('Listo.');
