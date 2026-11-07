<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/bootstrap.php';
require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shasta Exchange — Proximity Chat</title>
  <style>
    body{font:16px Arial;background:#121826;color:#fff;margin:0;padding:28px;text-align:center}
    main{max-width:760px;margin:auto;background:#202b3d;padding:28px;border-radius:14px}
    button{padding:12px 20px;margin:8px;border:0;border-radius:7px;cursor:pointer}
    #status{margin:18px;color:#a9c5ff}
  </style>
</head>
<body>
<main>
  <h1>Shasta Exchange</h1><h2>Proximity Voice Chat</h2>
  <p id="status">Not connected</p>
  <button id="connect">Connect</button>
  <button id="disconnect">Disconnect</button>
  <button id="mic">Enable microphone</button>
  <p><a style="color:#9fc2ff" href="/auth/logout.php">Log out</a></p>
  <p>This is a protected starter page. Insert the original index.html interface and its Opus/WebSocket JavaScript here.</p>
</main>
<script>
/*
 * ORIGINAL CLIENT CODE NOT INCLUDED:
 * Replace this placeholder with the JavaScript from your existing index.html.
 * Configure the WebSocket URL to your actual Qt server address.
 * Do not expose an unauthenticated audio endpoint: validate an auth token
 * on the Qt/WebSocket server before accepting audio connections.
 */
</script>
</body>
</html>
