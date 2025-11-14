<?php
require_once __DIR__ . '/../config/platform.php';

function superadmin_require_login(){
    if(session_status() !== PHP_SESSION_ACTIVE){ session_start(); }
    if(empty($_SESSION[SESSION_SUPERADMIN_FLAG]) || $_SESSION[SESSION_SUPERADMIN_FLAG] !== true){
        header('Location: /auth/superadmin_login.php');
        exit;
    }
}

function superadmin_login($userId){
    if(session_status() !== PHP_SESSION_ACTIVE){ session_start(); }
    $_SESSION[SESSION_SUPERADMIN_FLAG] = true;
    $_SESSION['superadmin_user_id'] = (int)$userId;
}

function superadmin_logout(){
    if(session_status() !== PHP_SESSION_ACTIVE){ session_start(); }
    $_SESSION = [];
    session_destroy();
}
