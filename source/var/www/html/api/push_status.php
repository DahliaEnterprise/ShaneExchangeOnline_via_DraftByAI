<?php
declare(strict_types=1);
require_once __DIR__ . '/push_common.php';

require_login();

$q = $pdo->prepare(
    'SELECT pending_message_id, pending_conversation_id, pending_since
     FROM push_notification_state WHERE user_id = ?'
);
$q->execute([(int)$_SESSION['user_id']]);
$state = $q->fetch();

json_response([
    'pending' => $state && $state['pending_message_id'] !== null,
    'notification' => $state ?: null
]);
