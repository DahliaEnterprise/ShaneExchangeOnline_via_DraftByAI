<?php
declare(strict_types=1);
require_once __DIR__ . '/push_common.php';

require_login();
require_post();
require_csrf();

$conversationId = filter_var($_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT);
if (!$conversationId) {
    json_response(['error' => 'Invalid conversation'], 400);
}

$uid = (int)$_SESSION['user_id'];
require_conversation_member($pdo, (int)$conversationId, $uid);

$pdo->beginTransaction();
try {
    $read = $pdo->prepare(
        'UPDATE conversation_participants
         SET last_read_at = NOW()
         WHERE conversation_id = ? AND user_id = ?'
    );
    $read->execute([(int)$conversationId, $uid]);

    $clear = $pdo->prepare(
        'UPDATE push_notification_state
         SET pending_message_id = NULL, pending_conversation_id = NULL, pending_since = NULL
         WHERE user_id = ? AND pending_conversation_id = ?'
    );
    $clear->execute([$uid, (int)$conversationId]);

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Shasta mark notification read error: ' . $e->getMessage());
    json_response(['error' => 'Could not mark notification as read'], 500);
}
