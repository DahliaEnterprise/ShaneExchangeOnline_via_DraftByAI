<?php
declare(strict_types=1);

// ========================================
// Shasta Exchange Configuration
// ========================================

// Database settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'shasta_exchange');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

// Website settings
define('APP_BASE_URL', 'https://shastaexchange.online');

// Email settings
define('MAIL_FROM', 'shanebetz@shastaexchange.online');
define('MAIL_FROM_NAME', 'Shasta Exchange');

// Namecheap SMTP settings
define('SMTP_HOST', 'mail.privateemail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'shanebetz@shastaexchange.online');
define('SMTP_PASSWORD', 'YOUR_NAMECHEAP_MAILBOX_PASSWORD');

// PayPal payment settings
// Never put the PayPal secret or webhook ID in a public directory.
define('PAYPAL_MODE', 'live'); // 'live' or 'sandbox'
define('PAYPAL_CLIENT_ID', 'CLIENT ID');
define('PAYPAL_CLIENT_SECRET', 'Client SECRET');
define('PAYPAL_WEBHOOK_ID', 'webhook id');
define('PAYPAL_CURRENCY', 'USD');
define('PAYPAL_PASS_PRICE', '2.00');
?>