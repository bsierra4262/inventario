<?php
/**
 * Plantilla de secretos — copiar a `secure/config/secrets.php` en cada entorno.
 *
 *   cp core/config/secrets.example.php secure/config/secrets.php
 *   chmod 600 secure/config/secrets.php
 *
 * `secure/config/` está en .gitignore y `secure/.htaccess` lo bloquea por HTTP,
 * así que el archivo real NUNCA se versiona ni se sirve.
 *
 * Si el archivo no existe, platform.php cae en las variables de entorno
 * (MASTER_DB_PASS, GOOGLE_CLIENT_ID, ...). Si tampoco están, la app aborta
 * con un mensaje claro en vez de conectar con credenciales vacías.
 */

return [
    // === MASTER DB (plataforma) ===
    'MASTER_DB_HOST' => 'localhost',
    'MASTER_DB_NAME' => 'CAMBIAR_nombre_de_la_base',
    'MASTER_DB_USER' => 'CAMBIAR_usuario',
    'MASTER_DB_PASS' => 'CAMBIAR_contrasena',

    // === OAuth Google — credenciales de plataforma (opcional) ===
    // Si quedan vacías, se usan las credenciales por tenant de `cloud_config`.
    'GOOGLE_CLIENT_ID'     => '',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_REDIRECT_URI'  => '',

    // === OAuth Microsoft — credenciales de plataforma (opcional) ===
    'MS_CLIENT_ID'     => '',
    'MS_CLIENT_SECRET' => '',
    'MS_TENANT_ID'     => 'common',
    'MS_REDIRECT_URI'  => '',

    // === Ingreso con ITHub (core/security/ithub.php) — opcional ===
    // Sólo la empresa ITHUB_EMPRESA (ITH) y el panel SuperAdmin. Dos clientes
    // de OIDC distintos, cada uno con su secreto (se genera en ITHub y se
    // muestra una sola vez). Sin client_id y secreto, ese portal sigue con su
    // login de siempre; las empresas clientes nunca cambian.
    'ITHUB_ISSUER'           => 'https://ithub.intellihelp.tech',
    'ITHUB_BASE_URL'         => '',          // https://desarrollo.intellihelp.tech
    'ITHUB_EMPRESA'          => 'ITH',       // companies.subdomain
    'ITHUB_CLIENT_ID'        => '',          // inventario
    'ITHUB_CLIENT_SECRET'    => '',
    'ITHUB_SA_CLIENT_ID'     => '',          // inventario-superadmin
    'ITHUB_SA_CLIENT_SECRET' => '',

    // === Herramientas destructivas (bin/) ===
    // Dejar en false salvo que se esté ejecutando un reset intencional.
    'ASSET_VAULT_DEV' => false,
];
