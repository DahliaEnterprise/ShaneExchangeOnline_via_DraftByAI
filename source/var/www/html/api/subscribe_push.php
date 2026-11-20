<?php
declare(strict_types=1);
require_once __DIR__ . '/push_common.php';

require_login();
require_post();
require_csrf();

$data = json_decode(file_get_contents('php://input'), true);
$sub = $data['subscription'] ?? null;

if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
    json_response(['error' => 'Invalid push subscription'], 400);
}

$endpoint = (string)$sub['endpoint'];
if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['https'], true)) {
    json_response(['error' => 'Invalid subscription endpoint'], 400);
}

$uid = (int)$_SESSION['user_id'];
$hash = hash('sha256', $endpoint);

$q = $pdo->prepare(
    'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint),
       p256dh = VALUES(p256dh), auth = VALUES(auth), updated_at = CURRENT_TIMESTAMP'
);
$q->execute([$uid, $endpoint, $hash, (string)$sub['keys']['p256dh'], (string)$sub['keys']['auth']]);

json_response(['ok' => true]);
