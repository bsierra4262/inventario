<?php
require_once __DIR__ . '/../config/platform.php';

function csrf_start(){
    if(session_status() !== PHP_SESSION_ACTIVE){ session_start(); }
    if(empty($_SESSION[CSRF_TOKEN_NAME])){
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
}

function csrf_token(){
    return $_SESSION[CSRF_TOKEN_NAME] ?? '';
}

function csrf_input(){
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$t.'">';
}

function csrf_validate(){
    $ok = isset($_POST[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $_POST[CSRF_TOKEN_NAME]);
    if ($ok) { return; }

    /* Salía `die('CSRF inválido')`: dos palabras en Times New Roman sobre fondo
       blanco, sin decir qué pasó ni cómo volver. Acá el token no vence por su
       cuenta —vive toda la sesión—, así que llegar hasta acá significa que la
       sesión se cerró en otra pestaña, o que el pedido no salió de la pantalla
       que dice salir. Las dos cosas se arreglan volviendo a entrar. */
    require_once __DIR__ . '/../../includes/pantalla_aviso.php';
    pantalla_aviso([
        'titulo'    => 'La sesión ya no es la misma',
        'subtitulo' => 'No se hizo nada: la acción quedó sin efecto',
        'detalle'   => 'Puede que hayas cerrado sesión en otra pestaña, o que la pantalla '
                     . 'llevara abierta desde antes. Entrá de nuevo y repetí la acción.',
        'tono'      => 'warning',
        'icono'     => 'shield-exclamation',
        'codigo'    => 419,
        'acciones'  => [
            ['texto' => 'Volver a la pantalla anterior', 'icono' => 'arrow-left',
             'href'  => '#', 'onclick' => 'history.back(); return false;'],
            ['texto' => 'Ir al panel', 'icono' => 'speedometer2',
             'href'  => '/dashboard/dashboard_superadmin.php', 'tipo' => 'ghost'],
        ],
    ]);
}
