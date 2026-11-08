<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login(); require_post(); require_csrf();
$email=filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$email) json_response(['error'=>'Enter a valid email address'],400);
$q=$pdo->prepare('SELECT id FROM users WHERE email=? AND verified=1');
$q->execute([$email]); $recipient=$q->fetchColumn();
if (!$recipient) json_response(['error'=>'That email is not a verified Shasta Exchange account'],404);
if ((int)$recipient === (int)$_SESSION['user_id']) json_response(['error'=>'You cannot start a conversation with yourself'],400);
json_response(['ok'=>true,'email'=>$email,'draft'=>true]);
