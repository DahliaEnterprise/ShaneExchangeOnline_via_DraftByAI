<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();
$id=filter_input(INPUT_GET,'conversation_id',FILTER_VALIDATE_INT);
if (!$id) json_response(['error'=>'Invalid conversation'],400);
$uid=(int)$_SESSION['user_id']; require_conversation_member($pdo,(int)$id,$uid);
$q=$pdo->prepare("SELECT m.id,m.sender_id,u.email AS sender_email,m.duration_seconds,m.created_at
 FROM messages m JOIN users u ON u.id=m.sender_id
 JOIN conversation_participants cp ON cp.conversation_id=m.conversation_id AND cp.user_id=?
 WHERE m.conversation_id=? AND m.sender_id<>?
 AND (cp.last_read_at IS NULL OR m.created_at>cp.last_read_at)
 ORDER BY m.id ASC LIMIT 1");
$q->execute([$uid,(int)$id,$uid]); $m=$q->fetch();
if (!$m) json_response(['status'=>'empty']);
json_response(['status'=>'message','message'=>['id'=>(int)$m['id'],'sender_email'=>$m['sender_email'],'duration_seconds'=>$m['duration_seconds'],'created_at'=>$m['created_at'],'audio_url'=>'/api/audio.php?id='.(int)$m['id']]]);
