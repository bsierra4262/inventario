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
    if(!$ok){ die('CSRF inválido'); }
}
