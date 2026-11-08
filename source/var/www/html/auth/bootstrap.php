<?php
declare(strict_types=1);

session_start();
require_once '/var/www-private/shasta_exchange/config.php';
require_once '/var/www-private/shasta_exchange/database.php';

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}
