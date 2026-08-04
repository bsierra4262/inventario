<?php
/**
 * Guardia común de las herramientas de bin/.
 *
 * Motivo: `bay.php` y `reset_inventario_ith.php` vivían en el web root y se
 * autorizaban comparando el nombre del host contra `desarrollo.`. Como el host
 * de producción ES `desarrollo.intellihelp.tech`, el chequeo pasaba siempre y
 * cualquiera en internet podía borrar la plataforma entera con un POST.
 *
 * La lección: una herramienta destructiva no se protege con un `if`. Se saca
 * del alcance del servidor web. Estos scripts sólo corren por CLI, `.htaccess`
 * bloquea `bin/` por HTTP y además abortan si detectan contexto web.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Cinturón y tirantes: si algún SAPI raro se hace pasar por cli.
if (!empty($_SERVER['REQUEST_METHOD']) || !empty($_SERVER['HTTP_HOST'])) {
    fwrite(STDERR, "Esta herramienta sólo se ejecuta por línea de comandos.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/core/config/platform.php';

/** Escribe una línea con color si la terminal lo soporta. */
function cli_line(string $icon, string $msg): void {
    echo " {$icon}  {$msg}\n";
}
function cli_ok(string $m): void    { cli_line('OK  ', $m); }
function cli_err(string $m): void   { cli_line('ERR ', $m); }
function cli_info(string $m): void  { cli_line('··  ', $m); }
function cli_title(string $m): void { echo "\n" . str_repeat('─', 68) . "\n  {$m}\n" . str_repeat('─', 68) . "\n"; }

/**
 * Pide confirmación escribiendo una frase exacta. Sin `--si` interactivo:
 * el operador tiene que tipear la frase completa, no apretar Enter.
 */
function cli_confirmar(string $frase): void {
    echo "\n  Para continuar escribí exactamente: {$frase}\n  > ";
    $in = trim((string)fgets(STDIN));
    if ($in !== $frase) {
        cli_err('Cancelado (la frase no coincide).');
        exit(1);
    }
}

/** Aborta si no está habilitado el modo dev. */
function cli_requiere_dev(): void {
    $flag = platform_secret('ASSET_VAULT_DEV', '');
    $ok = ($flag === '1' || strtolower($flag) === 'true') || getenv('ASSET_VAULT_DEV') === '1';
    if (!$ok) {
        cli_err('Bloqueado: esta herramienta requiere ASSET_VAULT_DEV=1');
        cli_info('Activalo en secure/config/secrets.php o con: ASSET_VAULT_DEV=1 php ' . ($_SERVER['argv'][0] ?? ''));
        exit(1);
    }
}
