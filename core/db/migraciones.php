<?php
/**
 * Migraciones versionadas del esquema de tenant.
 *
 * Antes el DDL estaba desparramado y corría EN CADA REQUEST:
 *   - backend/db.php hacía SHOW TABLES + 2 ALTER TABLE + SHOW COLUMNS
 *   - permissions_db / tenant_settings / custom_roles hacían CREATE TABLE IF NOT EXISTS
 * Eso son 5-8 consultas de metadata antes de la primera consulta útil, en cada
 * página. Ahora todo eso vive acá y se aplica una sola vez con `php bin/migrar.php`;
 * la app sólo consulta la versión (una fila) y avisa si falta migrar.
 *
 * Para agregar una migración: sumá una entrada al final de migraciones_tenant().
 * Nunca edites una ya aplicada — agregá otra.
 */

declare(strict_types=1);

if (!defined('SCHEMA_VERSION_KEY')) {
    define('SCHEMA_VERSION_KEY', 'schema_version');
}

/**
 * Lista ordenada de migraciones. Clave = versión, valor = closure(mysqli, prefijo).
 */
if (!function_exists('migraciones_tenant')) {
function migraciones_tenant(): array {
    return [

        1 => function (mysqli $c, string $p): void {
            // Estado base: tablas núcleo (idempotente, refleja install_tenant.php).
            $c->query("CREATE TABLE IF NOT EXISTS `{$p}tenant_settings` (
                setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $c->query("CREATE TABLE IF NOT EXISTS `{$p}custom_roles` (
                role_key   VARCHAR(40) NOT NULL PRIMARY KEY,
                label      VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $c->query("CREATE TABLE IF NOT EXISTS `{$p}role_permissions` (
                role   VARCHAR(40) NOT NULL,
                action VARCHAR(80) NOT NULL,
                PRIMARY KEY (role, action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            @$c->query("ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1");
            @$c->query("ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");

            // role debe ser VARCHAR para admitir roles personalizados; si quedó
            // como ENUM de una instalación vieja, los customs se guardaban como ''.
            $rc = @$c->query("SHOW COLUMNS FROM `{$p}users` LIKE 'role'");
            if ($rc && ($rr = $rc->fetch_assoc())) {
                if (str_starts_with(strtolower((string)$rr['Type']), 'enum')) {
                    @$c->query("ALTER TABLE `{$p}users` MODIFY COLUMN `role` VARCHAR(40) NOT NULL DEFAULT 'user'");
                }
            }
        },

        2 => function (mysqli $c, string $p): void {
            // Índices. `productos` no tenía NINGUNO fuera de la PK, así que cada
            // filtro del listado y de reportes era un full table scan.
            $idx = [
                "{$p}productos"            => [
                    'idx_archivado'    => '(archivado)',
                    'idx_categoria'    => '(categoria)',
                    'idx_estado'       => '(estado)',
                    'idx_servis_tag'   => '(servis_tag)',
                    'idx_asignado'     => '(asignado_a)',
                    'idx_ubicacion'    => '(ubicacion)',
                    'idx_garantia'     => '(fecha_garantia)',
                    'idx_arch_cat'     => '(archivado, categoria)',
                ],
                "{$p}producto_movimientos" => [
                    'idx_fecha'        => '(fecha)',
                    'idx_accion'       => '(accion)',
                    'idx_prod_fecha'   => '(producto_id, fecha)',
                ],
            ];
            foreach ($idx as $tabla => $defs) {
                foreach ($defs as $nombre => $cols) {
                    $existe = @$c->query("SHOW INDEX FROM `{$tabla}` WHERE Key_name = '{$nombre}'");
                    if ($existe && $existe->num_rows > 0) { continue; }
                    @$c->query("ALTER TABLE `{$tabla}` ADD INDEX `{$nombre}` {$cols}");
                }
            }
        },

        3 => function (mysqli $c, string $p): void {
            // Catálogos: "Asignado a" y "Ubicación" pasan de texto libre a listas
            // gestionadas. Con texto libre cada persona escribía distinto
            // ("Depósito", "deposito", "Dep.") y los reportes por ubicación no
            // agrupaban nada.
            $c->query("CREATE TABLE IF NOT EXISTS `{$p}catalogos` (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                tipo       VARCHAR(40)  NOT NULL,
                valor      VARCHAR(255) NOT NULL,
                activo     TINYINT(1)   NOT NULL DEFAULT 1,
                orden      INT          NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_tipo_valor (tipo, valor),
                KEY idx_tipo_activo (tipo, activo, orden)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Sembrar con lo que ya está cargado, para no perder nada al pasar a
            // desplegable: cada valor distinto que exista hoy entra al catálogo.
            foreach (['asignado_a', 'ubicacion'] as $campo) {
                $res = @$c->query("SELECT DISTINCT TRIM(`{$campo}`) v
                                     FROM `{$p}productos`
                                    WHERE `{$campo}` IS NOT NULL AND TRIM(`{$campo}`) <> ''");
                if (!$res) { continue; }
                $ins = $c->prepare("INSERT IGNORE INTO `{$p}catalogos` (tipo, valor) VALUES (?, ?)");
                while ($r = $res->fetch_assoc()) {
                    $v = (string)$r['v'];
                    $ins->bind_param('ss', $campo, $v);
                    $ins->execute();
                }
                $ins->close();
            }
        },

        4 => function (mysqli $c, string $p): void {
            // Papelera: para poder restaurar un activo archivado por error hace
            // falta saber cuándo se archivó, quién lo hizo y de qué carpeta de
            // Drive salió (al archivar se mueve a la carpeta "Archivados").
            @$c->query("ALTER TABLE `{$p}productos` ADD COLUMN IF NOT EXISTS `archivado_en` DATETIME NULL DEFAULT NULL");
            @$c->query("ALTER TABLE `{$p}productos` ADD COLUMN IF NOT EXISTS `archivado_por` VARCHAR(255) NULL DEFAULT NULL");
            @$c->query("ALTER TABLE `{$p}productos` ADD COLUMN IF NOT EXISTS `archivado_motivo` VARCHAR(255) NULL DEFAULT NULL");
            @$c->query("ALTER TABLE `{$p}productos` ADD COLUMN IF NOT EXISTS `drive_padre_previo` VARCHAR(255) NULL DEFAULT NULL");
            $existe = @$c->query("SHOW INDEX FROM `{$p}productos` WHERE Key_name = 'idx_archivado_en'");
            if (!$existe || $existe->num_rows === 0) {
                @$c->query("ALTER TABLE `{$p}productos` ADD INDEX `idx_archivado_en` (`archivado_en`)");
            }
        },

        5 => function (mysqli $c, string $p): void {
            // Unificar collation: tenant_settings quedó en utf8mb4_unicode_ci y el
            // resto en la del servidor. Un JOIN entre ambas da error 1267.
            $res = @$c->query("SELECT @@collation_database c");
            $col = $res ? (string)($res->fetch_assoc()['c'] ?? '') : '';
            if ($col !== '') {
                $charset = explode('_', $col)[0];
                @$c->query("ALTER TABLE `{$p}tenant_settings` CONVERT TO CHARACTER SET {$charset} COLLATE {$col}");
            }
        },

    ];
}
}

/** Versión de esquema aplicada actualmente en el tenant. */
if (!function_exists('schema_version_actual')) {
function schema_version_actual(mysqli $c, string $p): int {
    $t = $p . 'tenant_settings';
    $chk = @$c->query("SHOW TABLES LIKE '" . $c->real_escape_string($t) . "'");
    if (!$chk || $chk->num_rows === 0) { return 0; }
    $st = @$c->prepare("SELECT setting_value FROM `{$t}` WHERE setting_key = ? LIMIT 1");
    if (!$st) { return 0; }
    $k = SCHEMA_VERSION_KEY;
    $st->bind_param('s', $k);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['setting_value'] ?? 0);
}
}

/** Última versión disponible en el código. */
if (!function_exists('schema_version_objetivo')) {
function schema_version_objetivo(): int {
    $m = migraciones_tenant();
    return $m ? max(array_keys($m)) : 0;
}
}

/**
 * Aplica las migraciones pendientes. Devuelve la lista de versiones aplicadas.
 *
 * @param callable|null $log function(string $msg): void
 */
if (!function_exists('migrar_tenant')) {
function migrar_tenant(mysqli $c, string $p = '', ?callable $log = null): array {
    $log = $log ?? function (string $m): void {};
    $desde = schema_version_actual($c, $p);
    $aplicadas = [];

    foreach (migraciones_tenant() as $version => $fn) {
        if ($version <= $desde) { continue; }
        $log("aplicando migración {$version}…");
        $fn($c, $p);
        // La 1 crea tenant_settings, así que recién a partir de ahí podemos guardar.
        $t = $p . 'tenant_settings';
        $st = $c->prepare("INSERT INTO `{$t}` (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($st) {
            $k = SCHEMA_VERSION_KEY;
            $v = (string)$version;
            $st->bind_param('ss', $k, $v);
            $st->execute();
            $st->close();
        }
        $aplicadas[] = $version;
    }
    return $aplicadas;
}
}
