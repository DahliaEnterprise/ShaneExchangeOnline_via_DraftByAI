<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/api/voicemail_config.php';
require_login();

$token = csrf_token();
$passQuery = $pdo->prepare('SELECT paid_until FROM users WHERE id = ?');
$passQuery->execute([(int)$_SESSION['user_id']]);
$paidUntil = $passQuery->fetchColumn();
$passActive = $paidUntil && strtotime((string)$paidUntil) > time();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<title>Shasta Exchange — Voicemail</title>
<link rel="stylesheet" href="/assets/css/voicemail.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/voicemail.php">Shasta Exchange</a>
  <nav>
    <span><?= htmlspecialchars((string)($_SESSION['email'] ?? 'Account'), ENT_QUOTES, 'UTF-8') ?></span>
    <?php if ($passActive): ?>
      <span class="small">Pass active until <?= htmlspecialchars((string)$paidUntil, ENT_QUOTES, 'UTF-8') ?></span>
    <?php else: ?>
      <a href="/billing.php">Get 30-day pass — $2</a>
    <?php endif; ?>
    <a href="/auth/logout.php">Log out</a>
  </nav>
</header>
<main class="layout">
  <section class="panel">
    <div class="section-heading">
      <div><p class="eyebrow">YOUR INBOX</p><h1>Conversations</h1></div>
      <button id="newConversation" class="primary">+ New conversation</button>
    </div>
    <div id="notice" class="notice" role="status"></div>
    <div id="conversationList" class="conversation-list" aria-live="polite">
      <p class="muted">Loading conversations…</p>
    </div>
  </section>
  <aside class="help-panel">
    <div class="circle-icon">♪</div>
    <h2>Your voice inbox</h2>
    <p>Choose a conversation to listen to messages, or start a new one using another registered Shasta Exchange email address.</p>
    <p class="small">Recordings: up to 60 seconds and 2 MB per upload.</p>
    <p><a href="/billing.php">View prepaid message pass</a></p>
  </aside>
</main>
<dialog id="newDialog">
  <form id="newConversationForm" method="dialog">
    <button type="button" class="dialog-close" id="closeDialog" aria-label="Close">×</button>
    <p class="eyebrow">NEW MESSAGE THREAD</p><h2>Start a conversation</h2>
    <label for="recipientEmail">Recipient's registered email</label>
    <input id="recipientEmail" type="email" autocomplete="email" required maxlength="254" placeholder="person@example.com">
    <p id="dialogError" class="error"></p>
    <div class="dialog-actions"><button type="button" class="secondary" id="cancelDialog">Cancel</button><button class="primary" type="submit">Open conversation</button></div>
  </form>
</dialog>
<script src="/assets/js/voicemail.js" defer></script>
</body>
</html>
