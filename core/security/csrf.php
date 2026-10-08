<?php
/**
 * CSRF del panel — una fachada sobre la implementación de `backend/csrf.php`.
 *
 * Acá vivía una segunda implementación, de cuarenta y cinco líneas: UN token
 * para toda la sesión, sin vencimiento, sin huella del cliente, sin scope, y
 * con otro nombre de campo (`_csrf` contra `csrf_token`). No estaba rota, pero
 * era la más débil de las dos y obligaba a saber en qué portal estabas parado
 * para armar un formulario.
 *
 * Dos implementaciones del mismo mecanismo de seguridad se separan con el
 * tiempo, y la que se arregla es siempre la que alguien está mirando. Ahora hay
 * una sola, y el panel usa el scope «panel» dentro de ella.
 *
 * Los cuatro nombres de función se conservan porque los llaman doce pantallas
 * del panel, y `csrf_ok()` es nuevo: dos de ellas se chequeaban el token a mano
 * «porque csrf_validate hace die()».
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/csrf.php';
require_once __DIR__ . '/../config/platform.php';

/** El scope de todo lo que se manda desde el panel. */
const CSRF_SCOPE_PANEL = 'panel';

if (!function_exists('csrf_start')) {
/**
 * Prepara la sesión y deja dicho que lo que siga es del panel.
 *
 * Lo segundo es para la pantalla de 419: la comparten los dos portales y, sin
 * esto, desde el panel ofrecería «Ir al inventario» —una dirección que para un
 * superadmin no existe—.
 */
function csrf_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    csrf_inicio('/dashboard/dashboard_superadmin.php');
}
}

if (!function_exists('csrf_token')) {
/** El token vigente del panel. Se reusa mientras no venza. */
function csrf_token(): string {
    return generateCsrfToken(CSRF_SCOPE_PANEL);
}
}

if (!function_exists('csrf_input')) {
/** El campo oculto, con el mismo nombre que en el portal del cliente. */
function csrf_input(): void {
    csrfInput(CSRF_SCOPE_PANEL);
}
}

if (!function_exists('csrf_ok')) {
/**
 * ¿El token del pedido es válido? Sin dibujar nada ni cortar.
 *
 * Para las pantallas que prefieren mostrar su propio aviso dentro de la página
 * en vez de reemplazarla entera.
 */
function csrf_ok(): bool {
    return verifyCsrfToken(null, CSRF_SCOPE_PANEL);
}
}

if (!function_exists('csrf_validate')) {
/** Valida o muestra la pantalla de 419 y termina el pedido. */
function csrf_validate(): void {
    csrf_or_fail(CSRF_SCOPE_PANEL);
}
}
