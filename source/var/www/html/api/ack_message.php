<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login(); require_post(); require_csrf();
$messageId=filter_var($_POST['message_id'] ?? null,FILTER_VALIDATE_INT);
if (!$messageId) json_response(['error'=>'Invalid message'],400);
$uid=(int)$_SESSION['user_id'];
$q=$pdo->prepare('SELECT conversation_id,created_at,sender_id FROM messages WHERE id=?');
$q->execute([$messageId]); $m=$q->fetch();
if (!$m || (int)$m['sender_id']===$uid) json_response(['error'=>'Message not found'],404);
require_conversation_member($pdo,(int)$m['conversation_id'],$uid);
$u=$pdo->prepare('UPDATE conversation_participants SET last_read_at=GREATEST(COALESCE(last_read_at,?),?) WHERE conversation_id=? AND user_id=?');
$u->execute([$m['created_at'],$m['created_at'],(int)$m['conversation_id'],$uid]);
json_response(['ok'=>true]);
