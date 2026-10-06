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

        6 => function (mysqli $c, string $p): void {
            // ═══════════════════════════════════════════════════════════════
            //  Modelo de inventario, fases 1 y 2 — tomadas de GLPI
            //
            //  GLPI no resuelve "campos por categoría" con columnas: usa
            //  catálogos (dropdowns), ubicaciones jerárquicas y una tabla
            //  aparte para lo financiero y administrativo (Infocom). Acá se
            //  replican esas tres piezas sobre el esquema que ya existe, sin
            //  tocar las columnas que usa el código de hoy.
            // ═══════════════════════════════════════════════════════════════

            // ── Fase 1a · Catálogos para los campos que seguían siendo texto ──
            // La migración 3 puso bajo catálogo "Asignado a" y "Ubicación", pero
            // marca, modelo, categoría, subcategoría y estado se seguían
            // escribiendo a mano: el mismo problema de "Depósito / deposito /
            // Dep." que aquella migración vino a resolver, intacto en cinco
            // campos más. Se siembran con lo que ya hay cargado, igual que
            // entonces, así no se pierde nada.
            //
            // El `tipo` del catálogo es a propósito el nombre de la columna en
            // `productos`: así catalogo_uso() y catalogo_renombrar() funcionan
            // para estos campos sin ningún caso especial.
            foreach (['marca', 'modelo', 'categoria', 'subcategoria', 'estado'] as $campo) {
                $res = @$c->query("SELECT DISTINCT TRIM(`{$campo}`) AS v
                                     FROM `{$p}productos`
                                    WHERE `{$campo}` IS NOT NULL AND TRIM(`{$campo}`) <> ''");
                if (!$res) { continue; }
                $ins = $c->prepare("INSERT IGNORE INTO `{$p}catalogos` (tipo, valor) VALUES (?, ?)");
                if (!$ins) { continue; }
                while ($r = $res->fetch_assoc()) {
                    $v = (string)$r['v'];
                    $ins->bind_param('ss', $campo, $v);
                    $ins->execute();
                }
                $ins->close();
            }

            // ── Fase 1b · Ubicaciones jerárquicas ────────────────────────────
            // En GLPI una ubicación es un árbol con dirección, edificio,
            // habitación y coordenadas: "Sede Centro > Piso 2 > Depósito". Acá
            // era un string plano, así que no se podía preguntar "todo lo que
            // hay en la Sede Centro" sin depender de cómo lo escribió cada uno.
            $c->query("CREATE TABLE IF NOT EXISTS `{$p}ubicaciones` (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                padre_id      INT          NULL DEFAULT NULL,
                nombre        VARCHAR(255) NOT NULL,
                ruta          VARCHAR(500) NOT NULL,
                direccion     VARCHAR(255) NULL,
                codigo_postal VARCHAR(20)  NULL,
                localidad     VARCHAR(120) NULL,
                provincia     VARCHAR(120) NULL,
                pais          VARCHAR(120) NULL,
                edificio      VARCHAR(120) NULL,
                habitacion    VARCHAR(120) NULL,
                latitud       DECIMAL(10,7) NULL,
                longitud      DECIMAL(10,7) NULL,
                comentarios   TEXT         NULL,
                activo        TINYINT(1)   NOT NULL DEFAULT 1,
                orden         INT          NOT NULL DEFAULT 0,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ruta (ruta(191)),
                KEY idx_padre (padre_id),
                KEY idx_activo (activo, orden)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Semilla. Entran como raíces, conservando el estado y el orden que
            // ya tenían: la jerarquía la arma el admin después, moviendo cada
            // una bajo su padre. Nada se pierde ni cambia de nombre.
            $ins = $c->prepare("INSERT IGNORE INTO `{$p}ubicaciones` (nombre, ruta, activo, orden) VALUES (?, ?, ?, ?)");
            if ($ins) {
                $res = @$c->query("SELECT valor, activo, orden FROM `{$p}catalogos` WHERE tipo = 'ubicacion'");
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $v = (string)$r['valor']; $a = (int)$r['activo']; $o = (int)$r['orden'];
                        $ins->bind_param('ssii', $v, $v, $a, $o);
                        $ins->execute();
                    }
                }
                // Y lo que esté en los activos pero nunca llegó al catálogo.
                $res = @$c->query("SELECT DISTINCT TRIM(ubicacion) AS v FROM `{$p}productos`
                                    WHERE ubicacion IS NOT NULL AND TRIM(ubicacion) <> ''");
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $v = (string)$r['v']; $a = 1; $o = 0;
                        $ins->bind_param('ssii', $v, $v, $a, $o);
                        $ins->execute();
                    }
                }
                $ins->close();
            }

            // La fuente de verdad pasa a ser `ubicaciones`. Dejar las filas en
            // `catalogos` sería tener el mismo dato en dos lados, que es
            // exactamente de donde salen las inconsistencias.
            @$c->query("DELETE FROM `{$p}catalogos` WHERE tipo = 'ubicacion'");

            // `productos.ubicacion` se conserva con la ruta completa: todos los
            // filtros, reportes y exportaciones de hoy siguen funcionando sin
            // tocar una línea. `ubicacion_id` agrega el enlace preciso para
            // poder preguntar por rama del árbol.
            @$c->query("ALTER TABLE `{$p}productos` ADD COLUMN IF NOT EXISTS `ubicacion_id` INT NULL DEFAULT NULL");
            $existe = @$c->query("SHOW INDEX FROM `{$p}productos` WHERE Key_name = 'idx_ubicacion_id'");
            if (!$existe || $existe->num_rows === 0) {
                @$c->query("ALTER TABLE `{$p}productos` ADD INDEX `idx_ubicacion_id` (`ubicacion_id`)");
            }
            @$c->query("UPDATE `{$p}productos` pr
                          JOIN `{$p}ubicaciones` u ON u.ruta = TRIM(pr.ubicacion)
                           SET pr.ubicacion_id = u.id
                         WHERE pr.ubicacion_id IS NULL AND TRIM(COALESCE(pr.ubicacion,'')) <> ''");

            // ── Fase 2 · Infocom: información financiera y administrativa ────
            // El bloque que GLPI llama Infocom. De sus ~20 campos acá existían
            // seis (valor, cantidad, fecha de compra, fecha de garantía, período
            // de garantía, fecha de baja) y viven en `productos`: se quedan ahí,
            // porque los usan el listado, los reportes, el CSV y el backup. Esta
            // tabla suma los que faltaban, sin duplicar ninguno — dos fuentes
            // para el mismo dato terminan siempre en que no coinciden.
            //
            // Lo más concreto que destraba: la amortización. El reporte de
            // amortización calcula con un `anios` que viene del querystring,
            // igual para todos los activos, porque no había dónde guardarla por
            // activo. GLPI la tiene por activo, con tipo y coeficiente.
            $c->query("CREATE TABLE IF NOT EXISTS `{$p}activo_infocom` (
                producto_id              INT NOT NULL PRIMARY KEY,
                numero_pedido            VARCHAR(120) NULL,
                numero_remito            VARCHAR(120) NULL,
                numero_factura           VARCHAR(120) NULL,
                numero_contable          VARCHAR(120) NULL,
                proveedor                VARCHAR(255) NULL,
                presupuesto              VARCHAR(255) NULL,
                fecha_orden              DATE NULL,
                fecha_entrega            DATE NULL,
                fecha_puesta_servicio    DATE NULL,
                fecha_ultimo_inventario  DATE NULL,
                fecha_decomiso           DATE NULL,
                garantia_info            VARCHAR(255) NULL,
                valor_extension_garantia DECIMAL(12,2) NULL,
                amortizacion_anios       INT NULL,
                amortizacion_tipo        VARCHAR(20) NULL,
                amortizacion_coeficiente DECIMAL(6,3) NULL,
                criticidad               VARCHAR(60) NULL,
                alertas_email            TINYINT(1) NOT NULL DEFAULT 0,
                comentarios              TEXT NULL,
                created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_proveedor (proveedor),
                KEY idx_criticidad (criticidad),
                KEY idx_decomiso (fecha_decomiso),
                KEY idx_puesta_servicio (fecha_puesta_servicio)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Una fila por activo, vacía. Así la edición siempre hace UPDATE y
            // ninguna pantalla tiene que decidir entre INSERT y UPDATE.
            // Sin clave foránea, igual que el resto del esquema: la relación la
            // sostiene la aplicación, y agregar FKs ahora obligaría a revisar
            // los borrados de todas las pantallas.
            @$c->query("INSERT IGNORE INTO `{$p}activo_infocom` (producto_id) SELECT id FROM `{$p}productos`");

            // Catálogos con valores por defecto.
            //
            // `estado` llevaba una lista fija escrita dentro del formulario de
            // alta (Nuevo, Usado, A reparar, Dado de baja) mientras la edición
            // ofrecía sólo Nuevo/Usado: las dos pantallas daban opciones
            // distintas para el mismo campo. Ahora salen las dos del catálogo, y
            // esos cuatro valores se siembran para no perder ninguno.
            //
            // `criticidad` arranca con la escala de GLPI. Proveedor y presupuesto
            // quedan vacíos: los carga el admin.
            $ins = $c->prepare("INSERT IGNORE INTO `{$p}catalogos` (tipo, valor, orden) VALUES (?, ?, ?)");
            if ($ins) {
                $porDefecto = [
                    'estado'     => ['Nuevo', 'Usado', 'A reparar', 'Dado de baja'],
                    'criticidad' => ['Muy baja', 'Baja', 'Media', 'Alta', 'Muy alta'],
                ];
                foreach ($porDefecto as $tipo => $valores) {
                    foreach ($valores as $i => $valor) {
                        $orden = $i + 1;
                        $ins->bind_param('ssi', $tipo, $valor, $orden);
                        $ins->execute();
                    }
                }
                $ins->close();
            }
        },

        7 => function (mysqli $c, string $p): void {
            // ═══════════════════════════════════════════════════════════════
            //  Fase 3 — campos específicos por categoría, definidos en datos
            //
            //  `productos` tenía CATORCE columnas para campos que sólo aplican a
            //  una subcategoría: patchcord_tipo, patchcord_longitud,
            //  switch_gestion, antenas_tipo, bobina_categoria, bobina_ubicacion,
            //  bobina_longitud, conectores_tipo, detalle_conectores,
            //  cintas_generacion, detalle_repuesto, detalle_otros, subsoftware y
            //  subsoftware_otros.
            //
            //  Agregar una categoría era un ALTER TABLE más tocar el alta, la
            //  edición, el detalle, los dos export y los dos import: ~450
            //  menciones en trece archivos. Es la misma familia de la que salió
            //  el HTTP 500 al editar —listas de columnas y tipos de bind_param
            //  escritas a mano que se desincronizan—.
            // ═══════════════════════════════════════════════════════════════
            $c->query("CREATE TABLE IF NOT EXISTS `{$p}campos_definicion` (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                categoria      VARCHAR(100) NULL DEFAULT NULL,
                subcategoria   VARCHAR(100) NULL DEFAULT NULL,
                clave          VARCHAR(60)  NOT NULL,
                etiqueta       VARCHAR(120) NOT NULL,
                tipo           VARCHAR(20)  NOT NULL DEFAULT 'texto',
                opciones       TEXT         NULL,
                requerido      TINYINT(1)   NOT NULL DEFAULT 0,
                orden          INT          NOT NULL DEFAULT 0,
                activo         TINYINT(1)   NOT NULL DEFAULT 1,
                -- Si apunta a una columna real de `productos`, el valor vive ahí
                -- y no en `campos_valor`. Es lo que deja seguir funcionando a los
                -- export, los import y el respaldo sin tocarlos, y lo que evita
                -- mover datos en esta migración.
                columna_legacy VARCHAR(60)  NULL DEFAULT NULL,
                created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ambito_clave (categoria, subcategoria, clave),
                KEY idx_ambito (categoria, subcategoria, activo, orden)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $c->query("CREATE TABLE IF NOT EXISTS `{$p}campos_valor` (
                producto_id INT         NOT NULL,
                clave       VARCHAR(60) NOT NULL,
                valor       TEXT        NULL,
                PRIMARY KEY (producto_id, clave),
                KEY idx_clave (clave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Siembra: los catorce campos que ya existían, declarados como
            // definiciones y apuntando a su columna. Los datos NO se mueven.
            // Primero se agrega la forma nueva y recién después, cuando esté
            // probada, se retira la vieja: las dos cosas en un solo paso es como
            // se pierden datos.
            //
            // Las claves de ámbito son SUBCATEGORÍAS ("Patchcord", "Bobina UTP"),
            // no categorías: así estaba armado el formulario de alta.
            $defs = [
                // [categoria, subcategoria, clave, etiqueta, tipo, opciones, orden]
                [null, 'Patchcord',          'patchcord_tipo',     'Tipo de patchcord',      'lista', 'UTP|STP|FTP',                  1],
                [null, 'Patchcord',          'patchcord_longitud', 'Longitud (m)',           'lista', '1|3|5|10|>10',                 2],
                [null, 'Switch',             'switch_gestion',     'Gestión',                'lista', 'No gestionado|Gestionado',     1],
                [null, 'Antenas',            'antenas_tipo',       'Tipo de antena',         'lista', 'Omnidireccional|Direccional',  1],
                [null, 'Bobina UTP',         'bobina_categoria',   'Categoría del cable',    'lista', 'CAT5e|CAT6e',                  1],
                [null, 'Bobina UTP',         'bobina_ubicacion',   'Interior / Exterior',    'lista', 'Interior|Exterior',            2],
                [null, 'Bobina UTP',         'bobina_longitud',    'Longitud (m)',           'lista', '10|25|50|100|>100',            3],
                [null, 'Conectores',         'conectores_tipo',    'Tipo de conector',       'lista', 'RJ45|SC|LC|Otros',             1],
                [null, 'Conectores',         'detalle_conectores', 'Especificá el conector', 'texto', null,                           2],
                [null, 'Cintas de respaldo', 'cintas_generacion',  'Generación LTO',         'lista', 'LTO-1|LTO-2|LTO-3|LTO-4|LTO-5', 1],
                [null, 'Campo a completar',  'detalle_repuesto',   'Detalle del repuesto',   'texto', null,                           1],
                [null, 'Otros',              'detalle_otros',      'Detalle',                'texto', null,                           1],
                ['Software y licencias', null, 'subsoftware',       'Software',              'texto', null,                           1],
                ['Software y licencias', null, 'subsoftware_otros', 'Otro software',         'texto', null,                           2],
            ];

            $ins = $c->prepare("INSERT IGNORE INTO `{$p}campos_definicion`
                (categoria, subcategoria, clave, etiqueta, tipo, opciones, orden, columna_legacy)
                VALUES (?,?,?,?,?,?,?,?)");
            if ($ins) {
                foreach ($defs as $d) {
                    [$cat, $sub, $clave, $etq, $tipo, $ops, $ord] = $d;
                    $legacy = $clave; // la columna se llama igual que la clave
                    $ins->bind_param('ssssssis', $cat, $sub, $clave, $etq, $tipo, $ops, $ord, $legacy);
                    $ins->execute();
                }
                $ins->close();
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

// ═══════════════════════════════════════════════════════════════════════════
//  MASTER
//
//  El esquema de la master (companies, users_platform, platform_config) no
//  tenía migraciones: lo creaba install.php y después cada página lo iba
//  parchando con ALTER TABLE sueltos en cada request —superadmin_login.php,
//  superadmin_callback.php y superadmin_usuarios.php lo siguen haciendo. Eso
//  es lo que esta vía viene a reemplazar: mismo motor que el tenant, versión
//  guardada en platform_config.
//
//  Para agregar una migración: sumá una entrada al final de
//  migraciones_master(). Nunca edites una ya aplicada.
// ═══════════════════════════════════════════════════════════════════════════

if (!defined('MASTER_SCHEMA_VERSION_KEY')) {
    define('MASTER_SCHEMA_VERSION_KEY', 'master_schema_version');
}

if (!function_exists('migraciones_master')) {
function migraciones_master(): array {
    return [

        1 => function (mysqli $c): void {
            // platform_config primero: es donde se guarda la propia versión.
            // Idempotente y con la misma forma que install.php.
            $c->query("CREATE TABLE IF NOT EXISTS `platform_config` (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                config_key   VARCHAR(100) NOT NULL UNIQUE,
                config_value TEXT NOT NULL,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // `users_platform.role` era ENUM('superadmin','support'). La matriz
            // de permisos de plataforma define cuatro roles (superadmin, admin,
            // support, viewer) y el panel los ofrecía, pero MySQL rechazaba
            // `admin` y `viewer` al guardar. En una instalación limpia esos dos
            // roles eran inasignables.
            //
            // VARCHAR(40) y no un ENUM de cuatro valores: es lo mismo que se
            // hizo del lado del tenant, y deja asignables los roles de
            // plataforma personalizados sin otro ALTER.
            //
            // Nota de arqueología: en producción `admin` y `viewer` sí se podían
            // asignar, pero no porque el esquema estuviera bien. Lo arreglaba
            // dashboard/superadmin_usuarios.php, que en CADA carga corría un
            // `MODIFY COLUMN role ENUM('superadmin','admin','support','viewer')`.
            // O sea que la tabla quedaba bien sólo después de que alguien
            // visitara esa página. Acá se hace una vez y queda.
            //
            // Cada sentencia va tolerada por separado: `ADD COLUMN IF NOT EXISTS`
            // es sintaxis de MariaDB y MySQL 8 no la acepta. Si una falla, la
            // columna ya existe o el motor no soporta la forma, y en ninguno de
            // los dos casos tiene sentido abortar la migración entera.
            $ddl = [
                "ALTER TABLE `users_platform` MODIFY COLUMN `role` VARCHAR(40) NOT NULL DEFAULT 'superadmin'",
                "ALTER TABLE `users_platform` MODIFY COLUMN `password` VARCHAR(255) DEFAULT NULL",
                "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `google_sub` VARCHAR(64)  DEFAULT NULL",
                "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `google_pic` VARCHAR(500) DEFAULT NULL",
                "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_sub`     VARCHAR(64)  DEFAULT NULL",
                "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_pic`     VARCHAR(500) DEFAULT NULL",
                "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `last_login` DATETIME     DEFAULT NULL",
                "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `active`     TINYINT(1)   NOT NULL DEFAULT 1",
            ];
            foreach ($ddl as $q) { try { @$c->query($q); } catch (Throwable) {} }
        },

    ];
}
}

/** Versión de esquema aplicada actualmente en la master. */
if (!function_exists('master_schema_version_actual')) {
function master_schema_version_actual(mysqli $c): int {
    $chk = @$c->query("SHOW TABLES LIKE 'platform_config'");
    if (!$chk || $chk->num_rows === 0) { return 0; }
    $st = @$c->prepare('SELECT config_value FROM `platform_config` WHERE config_key = ? LIMIT 1');
    if (!$st) { return 0; }
    $k = MASTER_SCHEMA_VERSION_KEY;
    $st->bind_param('s', $k);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['config_value'] ?? 0);
}
}

/** Última versión de master disponible en el código. */
if (!function_exists('master_schema_version_objetivo')) {
function master_schema_version_objetivo(): int {
    $m = migraciones_master();
    return $m ? max(array_keys($m)) : 0;
}
}

/**
 * Aplica las migraciones pendientes de la master. Devuelve las versiones aplicadas.
 *
 * @param callable|null $log function(string $msg): void
 */
if (!function_exists('migrar_master')) {
function migrar_master(mysqli $c, ?callable $log = null): array {
    $log = $log ?? function (string $m): void {};
    $desde = master_schema_version_actual($c);
    $aplicadas = [];

    foreach (migraciones_master() as $version => $fn) {
        if ($version <= $desde) { continue; }
        $log("aplicando migración master {$version}…");
        $fn($c);
        // La 1 crea platform_config, así que recién a partir de ahí se guarda.
        $st = $c->prepare('INSERT INTO `platform_config` (config_key, config_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)');
        if ($st) {
            $k = MASTER_SCHEMA_VERSION_KEY;
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
