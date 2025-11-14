<?php
require_once __DIR__ . '/../core/security/session_role.php';
superadmin_logout();
header('Location: /auth/superadmin_login.php');
