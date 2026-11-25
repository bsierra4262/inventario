<?php
/**
 * auth/superadmin_ithub_logout.php — Aviso de cierre de ITHub para el panel
 * SuperAdmin (OpenID Connect back-channel logout, cliente
 * `inventario-superadmin`).
 *
 * Lo llama el servidor de ITHub, no un navegador: no hay cookie ni CSRF; lo
 * que se valida es la firma del logout_token. Anota el `sid` como cerrado y
 * superadmin_require_login() corta en su pedido siguiente la sesión del panel
 * abierta con él. 200 si se procesó, 400 si el token no es válido.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/ithub.php';

ithub_atender_aviso_cierre('superadmin');
