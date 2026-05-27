<?php
/**
 * Helper para construir URLs de login del tenant, preferindo el subdomain
 * (humano y estable, ej: 'ITH') sobre el id numerico (1, 2, ...).
 *
 * Razon: cuando un usuario es redirigido al login despues de cerrar sesion o
 * por timeout, la URL debe identificar la empresa con un nombre legible para:
 *   - evitar confusion entre clientes (?company=ITH vs ?company=1)
 *   - mantener consistencia visual / branding
 *   - permitir bookmarks por empresa
 */

declare(strict_types=1);

if (!function_exists('tenant_login_url')) {
function tenant_login_url(int $companyId, array $extraParams = []): string {
    $base = '/auth/login.php';
    if ($companyId <= 0) {
        $qs = http_build_query($extraParams);
        return $base . ($qs !== '' ? '?' . $qs : '');
    }

    // Cache estatico por request — evita N queries al master por el mismo tenant
    static $cache = [];
    if (!isset($cache[$companyId])) {
        $slug = '';
        try {
            require_once __DIR__ . '/../config/platform.php';
            require_once __DIR__ . '/../db/master_connection.php';
            $mdb = new MasterDB();
            $st  = $mdb->pquery('SELECT subdomain FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row && !empty($row['subdomain'])) {
                $slug = (string)$row['subdomain'];
            }
        } catch (Throwable) {}
        $cache[$companyId] = $slug;
    }

    $identifier = $cache[$companyId] !== '' ? $cache[$companyId] : (string)$companyId;
    $params = array_merge(['company' => $identifier], $extraParams);
    return $base . '?' . http_build_query($params);
}
}
