<?php
declare(strict_types=1);
require_once __DIR__ . '/push_common.php';

require_login();
require_post();
require_csrf();

$data = json_decode(file_get_contents('php://input'), true);
$endpoint = (string)($data['endpoint'] ?? '');
if ($endpoint === '') {
    json_response(['error' => 'Subscription endpoint required'], 400);
}

$q = $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?');
$q->execute([(int)$_SESSION['user_id'], hash('sha256', $endpoint)]);

json_response(['ok' => true]);
