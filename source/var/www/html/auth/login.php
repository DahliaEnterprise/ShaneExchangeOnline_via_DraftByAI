<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /view_conversation.php');
    exit;
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    if ($email && is_string($password)) {
        $stmt = $pdo->prepare('SELECT id, password_hash, verified FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!(bool)$user['verified']) {
                $message = 'Please verify your email before logging in.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                header('Location: /view_conversation.php');
                exit;
            }
        } else {
            $message = 'Email or password is incorrect.';
        }
    } else {
        $message = 'Enter a valid email and password.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shasta Exchange Login</title>
<style>body{font:16px Arial;background:#121826;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0}.card{background:#202b3d;padding:28px;border-radius:14px;width:min(390px,88vw)}input,button{box-sizing:border-box;width:100%;padding:12px;margin:8px 0;border-radius:7px;border:0}button{background:#477de0;color:white;font-weight:bold;cursor:pointer}a{color:#9fc2ff}.msg{color:#ffd28a}</style></head><body><main class="card"><h1>Shasta Exchange</h1><h2>Log in</h2><p class="msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<form method="post"><label>Email</label><input type="email" name="email" required autocomplete="email"><label>Password</label><input type="password" name="password" required autocomplete="current-password"><button type="submit">Log in</button></form><p><a href="/auth/register.php">Create an account</a></p></main></body></html>
