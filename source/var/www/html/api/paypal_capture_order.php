<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once '/var/www-private/shasta_exchange/paypal.php';
require_once __DIR__ . '/voicemail_config.php';

require_login();
require_post();
require_csrf();

$input = json_decode((string)file_get_contents('php://input'), true);
$orderId = is_array($input) ? (string)($input['orderID'] ?? '') : '';
if (!preg_match('/^[A-Z0-9-]{8,80}$/i', $orderId)) {
    json_response(['error' => 'Invalid PayPal order.'], 400);
}

try {
    $result = paypal_request('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', []);
    $unit = $result['purchase_units'][0] ?? [];
    $customId = (string)($unit['payments']['captures'][0]['custom_id'] ?? $unit['custom_id'] ?? '');
    $capture = $unit['payments']['captures'][0] ?? [];
    if ($customId !== (string)(int)$_SESSION['user_id']) {
        throw new RuntimeException('PayPal order does not belong to the signed-in account.');
    }
    $granted = paypal_grant_capture($pdo, $capture, $orderId, (int)$_SESSION['user_id']);
    $q = $pdo->prepare('SELECT paid_until FROM users WHERE id = ?');
    $q->execute([(int)$_SESSION['user_id']]);
    json_response(['ok' => true, 'already_applied' => !$granted, 'paid_until' => $q->fetchColumn()]);
} catch (Throwable $e) {
    error_log('PayPal capture: ' . $e->getMessage());
    json_response(['error' => 'Payment could not be confirmed. If PayPal charged you, contact support before paying again.'], 400);
}
