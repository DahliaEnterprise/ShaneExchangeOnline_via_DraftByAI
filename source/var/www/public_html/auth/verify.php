<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$token = $_GET['token'] ?? '';
$message = 'This verification link is invalid or expired.';
if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE verification_token_hash = ? AND verification_expires > NOW() AND verified = 0'
    );
    $stmt->execute([$hash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $update = $pdo->prepare(
            'UPDATE users SET verified = 1, verification_token_hash = NULL, verification_expires = NULL WHERE id = ?'
        );
        $update->execute([$user['id']]);
        $message = 'Your email is verified. You can now log in.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email verification</title></head><body style="font:16px Arial;background:#121826;color:white;padding:40px"><h1>Email verification</h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><a style="color:#9fc2ff" href="/auth/login.php">Go to login</a></body></html>
