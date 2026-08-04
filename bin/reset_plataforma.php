<?php
/**
 * Reset TOTAL de la plataforma: borra las tablas de cada tenant y vacía el master.
 *
 * Reemplaza a `bay.php`. Aquel archivo vivía en el web root y se autorizaba con
 * `str_starts_with($host, 'desarrollo.')`; como el host productivo es
 * `desarrollo.intellihelp.tech`, el chequeo daba true para cualquier visitante:
 * un solo POST sin sesión ni CSRF destruía el inventario de todos los clientes.
 *
 * Uso:
 *   ASSET_VAULT_DEV=1 php bin/reset_plataforma.php
 */

require_once __DIR__ . '/_cli.php';
require_once APP_ROOT . '/core/db/master_connection.php';

cli_requiere_dev();

cli_title('RESET TOTAL DE LA PLATAFORMA');
cli_err('Esto borra, sin vuelta atrás:');
cli_info('  · todas las tablas de cada empresa registrada');
cli_info('  · companies, users_platform y platform_config del master');

$master = new MasterDB();

$empresas = [];
$chk = $master->conn->query("SHOW TABLES LIKE 'companies'");
if ($chk && $chk->num_rows > 0) {
    $res = $master->conn->query('SELECT id, nombre, tenant_mode, db_host, db_name, db_user, db_pass FROM companies ORDER BY id ASC');
    if ($res) { while ($r = $res->fetch_assoc()) { $empresas[] = $r; } }
}

if ($empresas) {
    echo "\n";
    cli_info('Empresas que se van a limpiar:');
    foreach ($empresas as $e) {
        cli_info("  · {$e['nombre']} — " . ($e['db_name'] ?: 'prefijo en master') . " ({$e['tenant_mode']})");
    }
} else {
    cli_info('No hay empresas registradas.');
}

cli_confirmar('RESETEAR TODA LA PLATAFORMA');

foreach ($empresas as $c) {
    if ($c['tenant_mode'] === 'external') {
        try {
            $tc = @new mysqli($c['db_host'] ?: 'localhost', $c['db_user'], $c['db_pass'], $c['db_name']);
            if ($tc->connect_errno) { throw new Exception($tc->connect_error); }
            $tc->query('SET FOREIGN_KEY_CHECKS = 0');
            $n = 0;
            $tr = $tc->query('SHOW TABLES');
            if ($tr) {
                while ($row = $tr->fetch_row()) {
                    $tc->query('DROP TABLE IF EXISTS `' . $tc->real_escape_string($row[0]) . '`');
                    $n++;
                }
            }
            $tc->query('SET FOREIGN_KEY_CHECKS = 1');
            $tc->close();
            cli_ok("«{$c['nombre']}» ({$c['db_name']}): {$n} tablas eliminadas");
        } catch (Throwable $e) {
            cli_err("«{$c['nombre']}»: " . $e->getMessage());
        }
    } else {
        $prefix = TENANT_PREFIX_BASE . ((int)$c['id']) . '_';
        $safe   = $master->conn->real_escape_string($prefix);
        $n = 0;
        $tr = $master->conn->query("SHOW TABLES LIKE '{$safe}%'");
        if ($tr && $tr->num_rows > 0) {
            $master->conn->query('SET FOREIGN_KEY_CHECKS = 0');
            while ($row = $tr->fetch_row()) {
                $master->conn->query('DROP TABLE IF EXISTS `' . $master->conn->real_escape_string($row[0]) . '`');
                $n++;
            }
            $master->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        cli_ok("«{$c['nombre']}» (prefijo {$prefix}): {$n} tablas eliminadas");
    }
}

foreach (['platform_config', 'companies', 'users_platform'] as $t) {
    $ex = $master->conn->query("SHOW TABLES LIKE '{$t}'");
    if ($ex && $ex->num_rows > 0) {
        $master->conn->query("DELETE FROM `{$t}`");
        $master->conn->query("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
        cli_ok("Master: `{$t}` vaciada");
    }
}

echo "\n";
cli_ok('Reset completado.');
cli_info('Siguiente paso: php bin/setup.php  y luego  php bin/crear_superadmin.php <usuario> <email>');
