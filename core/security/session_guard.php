<?php
/**
 * Manejo seguro de la sesión.
 *
 * Antes: ningún login llamaba a `session_regenerate_id()`. Se usaba
 * `session_unset()` con este comentario:
 *
 *   "session_regenerate_id(true) en algunos hostings shared envía cookie con
 *    nuevo ID pero no escribe el archivo → sesión vacía en el siguiente request"
 *
 * El síntoma era real, pero la causa no es la regeneración: es que el redirect
 * salía antes de que PHP cerrara y escribiera el archivo de sesión. La solución
 * correcta es regenerar y después `session_write_close()` ANTES del
 * `header('Location:')` — que es justo lo que hace `app_session_rotar()`.
 *
 * Sin regeneración, con `session.use_strict_mode` apagado y un WordPress en el
 * dominio padre, un atacante podía fijar un PHPSESSID para `.intellihelp.tech`
 * y quedarse con la sesión del usuario después de que este se autenticara.
 */

declare(strict_types=1);

if (!function_exists('app_session_cookie_params')) {
    /**
     * Parámetros de cookie de sesión. Refuerza lo que ya declara `.user.ini`
     * para el caso de que el hosting no lo lea (CLI, Apache mod_php, etc).
     */
    function app_session_cookie_params(): array {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        return [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',      // host actual, sin compartir con el dominio padre
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}

if (!function_exists('app_session_start')) {
    /** Arranca la sesión con parámetros seguros. Idempotente. */
    function app_session_start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        if (!headers_sent()) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            session_set_cookie_params(app_session_cookie_params());
        }
        session_start();
    }
}

if (!function_exists('app_session_rotar')) {
    /**
     * Rota el ID de sesión conservando los datos indicados y descartando el resto.
     * Se llama SIEMPRE al autenticar (login local, SSO tenant, SSO superadmin).
     *
     * @param array $conservar Claves de $_SESSION que sobreviven a la rotación.
     */
    function app_session_rotar(array $conservar = []): void {
        if (session_status() !== PHP_SESSION_ACTIVE) { app_session_start(); }

        $guardado = [];
        foreach ($conservar as $k) {
            if (array_key_exists($k, $_SESSION)) { $guardado[$k] = $_SESSION[$k]; }
        }

        // Descartar todo lo anterior (incluye flags de otro rol y state de OAuth ya usado).
        $_SESSION = [];

        // El ID viejo queda inutilizable: si alguien lo había fijado, no sirve más.
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }

        foreach ($guardado as $k => $v) { $_SESSION[$k] = $v; }

        $_SESSION['_auth_at'] = time();
    }
}

if (!function_exists('app_session_cerrar_y_redirigir')) {
    /**
     * Escribe la sesión en disco y recién ahí redirige.
     * El orden importa: si se manda el Location antes del write_close, en
     * hosting compartido el request siguiente puede leer una sesión vacía.
     */
    function app_session_cerrar_y_redirigir(string $url): never {
        session_write_close();
        header('Location: ' . $url);
        exit;
    }
}
