<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit; }
$uid=(int)$_SESSION['user_id'];
$q=$pdo->prepare('SELECT m.stored_name,m.mime_type FROM messages m JOIN conversation_participants cp ON cp.conversation_id=m.conversation_id WHERE m.id=? AND cp.user_id=?');
$q->execute([$id,$uid]); $m=$q->fetch();
if (!$m) { http_response_code(404); exit; }
$path=MESSAGE_STORAGE_DIR.'/'.$m['stored_name'];
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: '.($m['mime_type'] === 'audio/webm' ? 'audio/webm' : 'audio/ogg'));
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="message-'.$id.'.ogg"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
