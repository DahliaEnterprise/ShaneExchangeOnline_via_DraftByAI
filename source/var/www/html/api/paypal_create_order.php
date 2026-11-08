<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once '/var/www-private/shasta_exchange/paypal.php';
require_once __DIR__ . '/voicemail_config.php';

require_login();
require_post();
require_csrf();

try {
    $order = paypal_request('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'shasta-30-day-pass',
            'custom_id' => (string)(int)$_SESSION['user_id'],
            'description' => 'Shasta Exchange — 30-day unlimited message pass',
            'amount' => [
                'currency_code' => PAYPAL_CURRENCY,
                'value' => PAYPAL_PASS_PRICE,
            ],
        ]],
        'application_context' => [
            'brand_name' => 'Shasta Exchange',
            'user_action' => 'PAY_NOW',
            'return_url' => APP_BASE_URL . '/billing.php',
            'cancel_url' => APP_BASE_URL . '/billing.php?cancelled=1',
        ],
    ]);
    if (empty($order['id'])) throw new RuntimeException('PayPal did not create an order.');
    json_response(['id' => $order['id']]);
} catch (Throwable $e) {
    error_log('PayPal create order: ' . $e->getMessage());
    json_response(['error' => 'Unable to start PayPal checkout.'], 502);
}
