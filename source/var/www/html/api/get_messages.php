<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();
$id=filter_input(INPUT_GET,'conversation_id',FILTER_VALIDATE_INT);
if (!$id) json_response(['error'=>'Invalid conversation'],400);
$uid=(int)$_SESSION['user_id']; require_conversation_member($pdo,(int)$id,$uid);
$q=$pdo->prepare('SELECT m.id,m.sender_id,u.email AS sender_email,m.byte_size,m.duration_seconds,m.created_at FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id ASC');
$q->execute([(int)$id]);
json_response(['messages'=>$q->fetchAll()]);
