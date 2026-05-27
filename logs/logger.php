<?php
/**
 * logger.php
 * Sistema central de log para Inventario
 * Guarda todo en logs/audit.log en formato CSV.
 */

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Si en otro lado definen LOG_PATH lo respetamos.
// Si no, por defecto usamos logs/audit.log (mismo directorio que este archivo).
if (!defined('LOG_PATH')) {
    define('LOG_PATH', __DIR__ . '/audit.log');
}

/**
 * Registra un evento de log en formato CSV.
 *
 * Firma compatible con el resto del proyecto:
 *  log_event($origin, $type, $msg, $tag = 'app')
 *
 * @param string $origin  Script/origen del evento (login.php, productos.php, etc.)
 * @param string $type    Tipo / categoría (INFO, ERROR, LOGIN, etc.)
 * @param string $msg     Descripción detallada del evento
 * @param string $tag     Tag/módulo (auth, app, install, etc.)
 */
if (!function_exists('log_event')) {
function log_event(string|int $origin, string $type, string $msg, string $tag = 'app'): void
{
    // Acepta int o string (user_id casteado a int en sesion, o nombre de script).
    $origin = (string)$origin;

    $logFile = LOG_PATH;
    $logDir  = dirname($logFile);

    // Asegurar que el directorio exista
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

    // Sanitizar campos para no romper el CSV
    $origin = str_replace(['"', "\n", "\r", ','], ' ', $origin);
    $type   = str_replace(['"', "\n", "\r", ','], ' ', $type);
    $msg    = str_replace(['"', "\n", "\r", ','], ' ', $msg);
    $tag    = str_replace(['"', "\n", "\r", ','], ' ', $tag);

    $linea = "\"$fecha\",\"$hora\",\"$origin\",\"$type\",\"$msg\",\"$tag\",\"$ip\"\n";

    // Si el archivo no existe, agregamos encabezado
    if (!file_exists($logFile)) {
        $encabezado = "\"Fecha\",\"Hora\",\"Origen\",\"Tipo\",\"Mensaje\",\"Tag\",\"IP\"\n";
        file_put_contents($logFile, $encabezado, LOCK_EX);
    }

    // Append de la línea de log
    file_put_contents($logFile, $linea, FILE_APPEND | LOCK_EX);
}
}
