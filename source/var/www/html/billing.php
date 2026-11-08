<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/api/voicemail_config.php';
require_login();

$q = $pdo->prepare('SELECT paid_until FROM users WHERE id = ?');
$q->execute([(int)$_SESSION['user_id']]);
$paidUntil = $q->fetchColumn();
$active = $paidUntil && strtotime((string)$paidUntil) > time();
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<title>30-Day Pass — Shasta Exchange</title>
<link rel="stylesheet" href="/assets/css/voicemail.css">
<script src="https://www.paypal.com/sdk/js?client-id=<?= rawurlencode(PAYPAL_CLIENT_ID) ?>&currency=<?= rawurlencode(PAYPAL_CURRENCY) ?>&intent=capture"></script>
</head>
<body>
<header class="topbar"><a class="brand" href="/voicemail.php">Shasta Exchange</a><nav><a href="/voicemail.php">Inbox</a><a href="/auth/logout.php">Log out</a></nav></header>
<main class="chat-layout">
<section class="panel">
<p class="eyebrow">PREPAID ACCESS</p>
<h1>30-day unlimited message pass</h1>
<p>One-time payment of <strong>$2.00 USD</strong>. No subscription and no automatic renewal.</p>
<?php if ($active): ?>
<p>Your pass is active until <strong><?= htmlspecialchars((string)$paidUntil, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
<?php else: ?>
<p>Your account is currently on the free tier.</p>
<?php endif; ?>
<p>Purchasing another pass adds 30 days to your current expiration date if it has not expired yet.</p>
<div id="paypal-button-container"></div>
<p id="billing-status" class="notice" role="status"></p>
</section>
</main>
<script>
(() => {
 const token = document.querySelector('meta[name="csrf-token"]').content;
 const status = document.getElementById('billing-status');
 paypal.Buttons({
   createOrder: async () => {
     const r = await fetch('/api/paypal_create_order.php', {
       method:'POST', credentials:'same-origin',
       headers:{'X-CSRF-Token':token}
     });
     const d = await r.json();
     if (!r.ok || !d.id) throw new Error(d.error || 'Could not create PayPal order.');
     return d.id;
   },
   onApprove: async data => {
     status.textContent = 'Confirming payment…';
     const r = await fetch('/api/paypal_capture_order.php', {
       method:'POST', credentials:'same-origin',
       headers:{'Content-Type':'application/json','X-CSRF-Token':token},
       body:JSON.stringify({orderID:data.orderID})
     });
     const d = await r.json();
     if (!r.ok) throw new Error(d.error || 'Payment confirmation failed.');
     status.textContent = d.already_applied
       ? 'This payment was already applied. Your pass expiration is ' + d.paid_until + '.'
       : 'Payment confirmed. Your pass is active until ' + d.paid_until + '.';
     setTimeout(() => location.reload(), 1800);
   },
   onCancel: () => { status.textContent = 'Payment cancelled. No pass was added.'; },
   onError: err => { console.error(err); status.textContent = 'PayPal checkout could not be completed.'; }
 }).render('#paypal-button-container');
})();
</script>
</body>
</html>
