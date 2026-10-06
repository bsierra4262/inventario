<?php
/**
 * Aplica las migraciones de esquema pendientes a uno o a todos los tenants.
 *
 * Uso:
 *   php bin/migrar.php            # todas las empresas activas
 *   php bin/migrar.php ITH        # sólo esa (por subdomain o id)
 *   php bin/migrar.php --estado   # sólo informa, no aplica nada
 */

require_once __DIR__ . '/_cli.php';
require_once APP_ROOT . '/core/db/master_connection.php';
require_once APP_ROOT . '/core/db/migraciones.php';
require_once APP_ROOT . '/core/tenants/install_tenant.php';

$soloEstado = in_array('--estado', $argv, true);
$ref = '';
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) { $ref = $a; break; }
}

cli_title($soloEstado ? 'Estado del esquema' : 'Migración de esquema');
cli_info('Versión objetivo del código: tenant v' . schema_version_objetivo()
       . ' · master v' . master_schema_version_objetivo());

$db = new MasterDB();

// ── Master primero ───────────────────────────────────────────────────────────
// El esquema de la plataforma se migra antes que los tenants: si cambia algo de
// `companies`, el recorrido siguiente ya lo necesita leído con la forma nueva.
// Se saltea cuando se pidió una empresa puntual, porque ahí la intención es
// tocar ese tenant y nada más.
if ($ref === '') {
    $mActual = master_schema_version_actual($db->conn);
    if ($soloEstado) {
        $estadoM = $mActual >= master_schema_version_objetivo() ? 'al día' : 'PENDIENTE';
        cli_info(sprintf('%-40s v%-3d  %s', 'MASTER (plataforma)', $mActual, $estadoM));
    } else {
        try {
            $aplM = migrar_master($db->conn, function (string $m) { cli_info('   ' . $m); });
            if ($aplM) {
                cli_ok('MASTER (plataforma) → v' . master_schema_version_actual($db->conn)
                     . ' (aplicadas: ' . implode(', ', $aplM) . ')');
            } else {
                cli_info('MASTER (plataforma) → ya estaba en v' . $mActual);
            }
        } catch (Throwable $exM) {
            cli_err('MASTER (plataforma): ' . $exM->getMessage());
        }
    }
    echo "\n";
}

if ($ref !== '') {
    if (ctype_digit($ref)) {
        $st = $db->pquery('SELECT id, nombre, subdomain FROM companies WHERE id = ?', 'i', [(int)$ref]);
    } else {
        $st = $db->pquery('SELECT id, nombre, subdomain FROM companies WHERE UPPER(subdomain) = UPPER(?)', 's', [$ref]);
    }
} else {
    $st = $db->pquery('SELECT id, nombre, subdomain FROM companies WHERE active = 1 ORDER BY id');
}
$empresas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

if (!$empresas) {
    cli_err('No se encontró ninguna empresa.');
    exit(1);
}

$fallos = 0;
foreach ($empresas as $e) {
    $etiqueta = $e['nombre'] . ' (' . ($e['subdomain'] ?: $e['id']) . ')';
    try {
        ['conn' => $c, 'prefix' => $p] = tenant_conn_by_company((int)$e['id']);
        $actual = schema_version_actual($c, $p);

        if ($soloEstado) {
            $estado = $actual >= schema_version_objetivo() ? 'al día' : 'PENDIENTE';
            cli_info(sprintf('%-40s v%-3d  %s', $etiqueta, $actual, $estado));
            continue;
        }

        $aplicadas = migrar_tenant($c, $p, function (string $m) { cli_info('   ' . $m); });
        if ($aplicadas) {
            cli_ok($etiqueta . ' → v' . schema_version_actual($c, $p)
                 . ' (aplicadas: ' . implode(', ', $aplicadas) . ')');
        } else {
            cli_info($etiqueta . ' → ya estaba en v' . $actual);
        }
    } catch (Throwable $ex) {
        $fallos++;
        cli_err($etiqueta . ': ' . $ex->getMessage());
    }
}

echo "\n";
if ($fallos) {
    cli_err("{$fallos} empresa(s) con error.");
    exit(1);
}
cli_ok('Listo.');
