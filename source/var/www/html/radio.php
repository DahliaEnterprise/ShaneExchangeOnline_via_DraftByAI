<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/api/voicemail_config.php';
require_login();
$token = csrf_token();
$userId = (int)$_SESSION['user_id'];
$email = (string)($_SESSION['email'] ?? 'Member');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<title>Shasta County Voice Radio — Shasta Exchange</title>
<link rel="stylesheet" href="/assets/css/radio.css">
</head>
<body>
<header class="radio-topbar">
  <a class="radio-brand" href="/voicemail.php"><span class="brand-mark">◉</span><span>SHASTA EXCHANGE<small>COUNTY VOICE RADIO</small></span></a>
  <nav><a href="/voicemail.php">Inbox</a><span><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></span><a href="/auth/logout.php">Log out</a></nav>
</header>
<main class="radio-shell">
  <section class="radio-heading">
    <div><p class="eyebrow">COMMUNITY CHANNEL</p><h1>Shasta County Radio</h1><p class="subheading">Listen live or replay recent transmissions.</p></div>
    <span id="connectionBadge" class="status-badge status-wait">CONNECTING</span>
  </section>

  <section class="transmit-card">
    <div class="card-topline"><div><p class="eyebrow">YOUR TRANSMITTER</p><h2 id="transmitStatus">Standby</h2></div><span id="gpsBadge" class="status-badge status-wait">LOCATION CHECK</span></div>
    <button id="transmitButton" class="transmit-button" type="button" disabled aria-pressed="false"><span class="mic-icon">🎙</span><strong id="transmitButtonText">CHECKING LOCATION</strong></button>
    <p id="locationMessage" class="help-text">Allow location access to verify that you are inside Shasta County before transmitting.</p>
    <p id="radioNotice" class="notice" role="status"></p>
  </section>

  <section class="stats-grid">
    <div class="stat-card"><span>ACTIVE TRANSMITTERS</span><strong id="transmitterCount">0</strong></div>
    <div class="stat-card"><span>LISTENERS ONLINE</span><strong id="listenerCount">0</strong></div>
  </section>

  <section class="content-grid">
    <section class="panel">
      <div class="panel-heading"><div><p class="eyebrow">LIVE CHANNEL</p><h2>Channel activity</h2></div><span class="live-dot">● LIVE</span></div>
      <p class="help-text">Only one voice plays at a time. The most recently activated transmitter has priority; if they stop, the next active transmitter takes over.</p>
      <div id="speakerList" class="speaker-list"><p class="empty-state">Waiting for radio members…</p></div>
      <div class="audio-row"><span>Live audio</span><button id="muteButton" class="secondary-button" type="button">Mute audio</button></div>
      <div id="remoteAudio" class="remote-audio" aria-hidden="true"></div>
    </section>

    <section class="panel archive-panel">
      <div class="panel-heading"><div><p class="eyebrow">RECENT TRANSMISSIONS</p><h2>30-minute archive</h2></div><span class="archive-count" id="archiveCount">0 files</span></div>
      <p class="help-text">Each completed audible turn is saved as a separate recording. Files expire 30 minutes after the transmission ends.</p>
      <div id="archiveList" class="archive-list"><p class="empty-state">Loading recordings…</p></div>
      <audio id="archivePlayer" controls preload="none"></audio>
      <p id="archiveStatus" class="help-text" aria-live="polite">Select a recording to play it.</p>
    </section>
  </section>
  <footer class="radio-footer">SHASTA EXCHANGE · MEMBERS ONLY · TRANSMIT ACCESS REQUIRES SHASTA COUNTY LOCATION VERIFICATION</footer>
</main>
<script>
window.SHASTA_RADIO = <?= json_encode([
    'userId' => $userId,
    'api' => '/api/radio/radio.php',
    'csrf' => $token
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/radio.js" defer></script>
</body>
</html>
