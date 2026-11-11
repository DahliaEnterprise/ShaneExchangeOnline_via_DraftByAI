<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();
require_post();
require_csrf();

$userId = (int) $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // Keep one active invitation per account.
    $pdo->prepare(
        'DELETE FROM conversation_invite_codes WHERE creator_user_id = ?'
    )->execute([$userId]);

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Could not hash invitation code.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO conversation_invite_codes
            (creator_user_id, code_hash, expires_at)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))'
    );
    $insert->execute([$userId, $hash]);

    $expires = $pdo->query(
        'SELECT DATE_FORMAT(expires_at, "%Y-%m-%d %H:%i:%s")
         FROM conversation_invite_codes WHERE id = ' . (int)$pdo->lastInsertId()
    )->fetchColumn();

    $pdo->commit();
    json_response([
        'ok' => true,
        'code' => $code,
        'expires_at_utc' => $expires
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Invitation code generation failed: ' . $e->getMessage());
    json_response(['error' => 'Could not generate an invitation code. Please try again.'], 500);
}
