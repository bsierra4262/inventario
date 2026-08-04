<?php
/**
 * Crea (o repone) el usuario superadmin de la plataforma.
 *
 * Reemplaza el auto-seed que vivía en `auth/superadmin_login.php` e `install.php`:
 * esas páginas son PÚBLICAS y re-creaban `bsierra` con la contraseña fija
 * `***SECRETO-PURGADO***` cada vez que `users_platform` quedaba vacía. Encadenado con
 * el reset abierto de `bay.php`, eso permitía tomar la plataforma completa sin
 * ninguna credencial previa.
 *
 * Ahora la contraseña se genera al azar y se muestra UNA sola vez.
 *
 * Uso:
 *   php bin/crear_superadmin.php <usuario> <email>
 */

require_once __DIR__ . '/_cli.php';
require_once APP_ROOT . '/core/db/master_connection.php';

$usuario = $argv[1] ?? '';
$email   = $argv[2] ?? '';

if ($usuario === '' || $email === '') {
    echo "Uso: php bin/crear_superadmin.php <usuario> <email>\n";
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cli_err("Email inválido: {$email}");
    exit(1);
}

cli_title('Alta de superadmin');

try {
    $db = new MasterDB();
} catch (Throwable $e) {
    cli_err('No se puede conectar al master DB: ' . $e->getMessage());
    cli_info('Revisá secure/config/secrets.php');
    exit(1);
}

$st  = $db->pquery('SELECT id FROM users_platform WHERE username = ? LIMIT 1', 's', [$usuario]);
$row = $st->get_result()->fetch_assoc();
$st->close();

// Contraseña aleatoria: 24 caracteres de un alfabeto sin ambigüedades (0/O, 1/l).
$alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
$pass = '';
for ($i = 0; $i < 24; $i++) { $pass .= $alfabeto[random_int(0, strlen($alfabeto) - 1)]; }
$hash = password_hash($pass, PASSWORD_DEFAULT);

if ($row) {
    cli_info("El usuario «{$usuario}» ya existe (id {$row['id']}).");
    cli_confirmar('RESETEAR CONTRASENA');
    $db->pquery(
        'UPDATE users_platform SET password = ?, email = ?, active = 1 WHERE id = ?',
        'ssi', [$hash, $email, (int)$row['id']]
    );
    cli_ok("Contraseña de «{$usuario}» restablecida.");
} else {
    $db->pquery(
        "INSERT INTO users_platform (username, password, email, role, active) VALUES (?, ?, ?, 'superadmin', 1)",
        'sss', [$usuario, $hash, $email]
    );
    cli_ok("Superadmin «{$usuario}» creado.");
}

echo "\n";
cli_info('Contraseña generada (se muestra una sola vez):');
echo "\n      {$pass}\n\n";
cli_info('Guardala en el gestor de contraseñas y cambiala al primer ingreso.');
echo "\n";
