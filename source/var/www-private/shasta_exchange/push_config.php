<?php
declare(strict_types=1);

/*
 * Copy this file to:
 * /var/www-private/shasta_exchange/push_config.php
 *
 * Set the values using environment variables or replace the empty strings.
 * Generate a VAPID key pair with:
 *   vendor/bin/web-push generate:vapid
 *
 * Keep the PRIVATE key secret. Never put it under /var/www/html.
 */
define('SHASTA_VAPID_PUBLIC_KEY', getenv('SHASTA_VAPID_PUBLIC_KEY') ?: 'PUT_PUBLIC_KEY_HERE');
define('SHASTA_VAPID_PRIVATE_KEY', getenv('SHASTA_VAPID_PRIVATE_KEY') ?: 'PUT_PRIVATE_KEY_HERE');
define('SHASTA_VAPID_SUBJECT', getenv('SHASTA_VAPID_SUBJECT') ?: 'mailto:admin@example.com');
