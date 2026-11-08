<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();
$uid = (int)$_SESSION['user_id'];
$sql = "SELECT c.id, c.updated_at, other.email AS other_email,
        (SELECT COUNT(*) FROM messages m
          WHERE m.conversation_id=c.id AND m.sender_id<>?
          AND (cp.last_read_at IS NULL OR m.created_at>cp.last_read_at)) AS unread_count,
        (SELECT m2.created_at FROM messages m2 WHERE m2.conversation_id=c.id
          ORDER BY m2.id DESC LIMIT 1) AS latest_message_at
        FROM conversation_participants cp
        JOIN conversations c ON c.id=cp.conversation_id
        JOIN conversation_participants op ON op.conversation_id=c.id AND op.user_id<>cp.user_id
        JOIN users other ON other.id=op.user_id
        WHERE cp.user_id=? ORDER BY COALESCE(latest_message_at,c.updated_at) DESC";
$q=$pdo->prepare($sql); $q->execute([$uid,$uid]);
json_response(['conversations'=>$q->fetchAll()]);
