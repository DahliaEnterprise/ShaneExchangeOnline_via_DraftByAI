<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/api/voicemail_config.php';
require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$draftEmail = filter_var($_GET['to'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$id && !$draftEmail) { http_response_code(400); exit('Conversation or recipient required.'); }
if ($id) {
    require_conversation_member($pdo, (int)$id, (int)$_SESSION['user_id']);
    $q = $pdo->prepare('SELECT u.email FROM conversation_participants cp JOIN users u ON u.id=cp.user_id WHERE cp.conversation_id=? AND cp.user_id<>? LIMIT 1');
    $q->execute([(int)$id, (int)$_SESSION['user_id']]);
    $otherEmail = (string)($q->fetchColumn() ?: 'Conversation');
} else {
    $otherEmail = (string)$draftEmail;
    $q = $pdo->prepare('SELECT id FROM users WHERE email=? AND verified=1');
    $q->execute([$otherEmail]);
    if (!$q->fetchColumn()) { http_response_code(404); exit('Registered recipient not found.'); }
}
$token = csrf_token();
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<title>Conversation — Shasta Exchange</title><link rel="stylesheet" href="/assets/css/voicemail.css">
</head><body>
<header class="topbar"><a class="brand" href="/voicemail.php">← Shasta Exchange</a><a href="/auth/logout.php">Log out</a></header>
<main class="chat-layout">
  <section class="panel chat-panel">
    <div class="chat-heading"><div><p class="eyebrow">VOICE CONVERSATION</p><h1><?= htmlspecialchars($otherEmail, ENT_QUOTES, 'UTF-8') ?></h1></div><span id="queueCount" class="count">0 new</span></div>
    <div id="messageList" class="message-list"><p class="muted">Loading messages…</p></div>
    <div class="recorder">
      <div class="record-row"><div><strong>Record a voice message</strong><p class="small">Maximum 60 seconds · Maximum 2 MB · Ogg Opus</p></div><span id="recordTimer" class="timer">00:00 / 01:00</span></div>
      <div class="meter"><div id="recordProgress"></div></div>
      <div class="record-controls"><button id="recordButton" class="record-button">● Start recording</button><button id="sendButton" class="primary" disabled>Send message</button></div>
      <p id="recordStatus" class="notice" role="status"></p>
    </div>
  </section>
</main>
<script>
window.SHASTA_CONVERSATION = <?= json_encode(['id'=>$id ?: null, 'to'=>$id ? null : $otherEmail, 'userId'=>(int)$_SESSION['user_id']], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/conversation.js" defer></script>
</body></html>
