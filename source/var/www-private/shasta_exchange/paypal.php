<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function paypal_base_url(): string {
    return defined('PAYPAL_MODE') && PAYPAL_MODE === 'sandbox'
        ? 'https://api-m.sandbox.paypal.com'
        : 'https://api-m.paypal.com';
}

function paypal_request(string $method, string $path, ?array $payload = null): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL extension is required for PayPal.');
    }
    if (!defined('PAYPAL_CLIENT_ID') || !defined('PAYPAL_CLIENT_SECRET')
        || PAYPAL_CLIENT_ID === '' || PAYPAL_CLIENT_SECRET === '') {
        throw new RuntimeException('PayPal API credentials are not configured.');
    }

    $token = paypal_access_token();
    $ch = curl_init(paypal_base_url() . $path);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($payload !== null) {
        $json = ($payload === [])
            ? '{}'
            : json_encode($payload, JSON_THROW_ON_ERROR);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('PayPal request failed: ' . $error);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('PayPal returned an unreadable response.');
    }
    if ($status < 200 || $status >= 300) {
        error_log('PayPal API error (' . $status . '): ' . $raw);
        throw new RuntimeException('PayPal could not complete the request.');
    }
    return $data;
}

function paypal_access_token(): string {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL extension is required for PayPal.');
    }
    $ch = curl_init(paypal_base_url() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        error_log('PayPal OAuth error: ' . ($error ?: (string)$raw));
        throw new RuntimeException('Unable to authenticate with PayPal.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException('PayPal did not return an access token.');
    }
    return (string)$data['access_token'];
}

function paypal_grant_capture(PDO $pdo, array $capture, string $orderId, int $userId): bool {
    $captureId = (string)($capture['id'] ?? '');
    $status = (string)($capture['status'] ?? '');
    $amount = $capture['amount'] ?? [];
    $value = (string)($amount['value'] ?? '');
    $currency = (string)($amount['currency_code'] ?? '');

    if ($captureId === '' || $status !== 'COMPLETED'
        || $value !== PAYPAL_PASS_PRICE || $currency !== PAYPAL_CURRENCY) {
        throw new RuntimeException('Payment capture did not match the required completed $2 USD payment.');
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO paypal_payments (capture_id, order_id, user_id, amount, currency, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        try {
            $insert->execute([$captureId, $orderId, $userId, $value, $currency]);
        } catch (PDOException $e) {
            // Duplicate capture ID means this payment has already been applied.
            if ($e->getCode() === '23000') {
                $pdo->rollBack();
                return false;
            }
            throw $e;
        }

        $update = $pdo->prepare(
            'UPDATE users
             SET paid_until = DATE_ADD(
                 GREATEST(COALESCE(paid_until, NOW()), NOW()),
                 INTERVAL 30 DAY
             )
             WHERE id = ?'
        );
        $update->execute([$userId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Could not update the account pass expiration.');
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function paypal_verify_webhook(PDO $pdo, array $event, array $headers): bool {
    if (!defined('PAYPAL_WEBHOOK_ID') || PAYPAL_WEBHOOK_ID === '') {
        throw new RuntimeException('PayPal webhook ID is not configured.');
    }
    $required = [
        'PAYPAL-AUTH-ALGO' => 'auth_algo',
        'PAYPAL-CERT-URL' => 'cert_url',
        'PAYPAL-TRANSMISSION-ID' => 'transmission_id',
        'PAYPAL-TRANSMISSION-SIG' => 'transmission_sig',
        'PAYPAL-TRANSMISSION-TIME' => 'transmission_time',
    ];
    $verification = [
        'webhook_id' => PAYPAL_WEBHOOK_ID,
        'webhook_event' => $event,
    ];
    foreach ($required as $header => $key) {
        if (empty($headers[$header])) return false;
        $verification[$key] = $headers[$header];
    }
    $result = paypal_request('POST', '/v1/notifications/verify-webhook-signature', $verification);
    return ($result['verification_status'] ?? '') === 'SUCCESS';
}
