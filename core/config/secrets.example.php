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

    // === Herramientas destructivas (bin/) ===
    // Dejar en false salvo que se esté ejecutando un reset intencional.
    'ASSET_VAULT_DEV' => false,
];
