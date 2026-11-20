<?php
declare(strict_types=1);

require_once '/var/www-private/shasta_exchange/config.php';

const SHASTA_SESSION_TTL = 2592000; // 30 days

ini_set('session.gc_maxlifetime', (string)SHASTA_SESSION_TTL);
ini_set('session.cookie_lifetime', (string)SHASTA_SESSION_TTL);
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.lazy_write', '0');

$httpsConfigured = (
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || parse_url(APP_BASE_URL, PHP_URL_SCHEME) === 'https'
);

$cookieOptions = [
    'lifetime' => SHASTA_SESSION_TTL,
    'path' => '/',
    'secure' => $httpsConfigured,
    'httponly' => true,
    'samesite' => 'Lax',
];

session_set_cookie_params($cookieOptions);
session_start();

require_once '/var/www-private/shasta_exchange/database.php';

if (!empty($_SESSION['user_id'])) {
    // Touch the session data so its server-side timestamp is refreshed.
    $_SESSION['__shasta_session_activity'] = time();

    // Slide the browser cookie expiration forward by another 30 days.
    setcookie(session_name(), session_id(), [
        'expires' => time() + SHASTA_SESSION_TTL,
        'path' => '/',
        'secure' => $httpsConfigured,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}
