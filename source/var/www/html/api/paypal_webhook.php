<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once '/var/www-private/shasta_exchange/paypal.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$raw = (string)file_get_contents('php://input');
$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    exit;
}
$headers = [];
foreach ([
    'PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID',
    'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'
] as $name) {
    $serverKey = 'HTTP_' . str_replace('-', '_', $name);
    $headers[$name] = $_SERVER[$serverKey] ?? '';
}
try {
    if (!paypal_verify_webhook($pdo, $event, $headers)) {
        http_response_code(400);
        exit;
    }
    if (($event['event_type'] ?? '') !== 'PAYMENT.CAPTURE.COMPLETED') {
        http_response_code(200);
        echo 'Ignored';
        exit;
    }

    $resource = $event['resource'] ?? [];
    $captureId = (string)($resource['id'] ?? '');
    $orderId = (string)($resource['supplementary_data']['related_ids']['order_id'] ?? '');
    if ($captureId === '' || $orderId === '') {
        http_response_code(200);
        echo 'Missing capture details';
        exit;
    }

    // Resolve the user from the server-created PayPal order, never from webhook
    // data supplied by the browser.
    $order = paypal_request('GET', '/v2/checkout/orders/' . rawurlencode($orderId));
    $customId = (string)($order['purchase_units'][0]['custom_id'] ?? '');
    if (!ctype_digit($customId) || (int)$customId < 1) {
        http_response_code(200);
        echo 'No account reference';
        exit;
    }
    paypal_grant_capture($pdo, $resource, $orderId, (int)$customId);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    error_log('PayPal webhook processing: ' . $e->getMessage());
    http_response_code(500);
    echo 'Webhook processing failed';
}
