<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();
require_post();
require_csrf();

$code = trim((string)($_POST['code'] ?? ''));
if (!preg_match('/^[0-9]{6}$/D', $code)) {
    json_response(['error' => 'Enter the six-digit invitation code.'], 400);
}

$userId = (int)$_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // Lock active codes so concurrent redemption attempts cannot both succeed.
    $q = $pdo->query(
        'SELECT id, creator_user_id, code_hash
           FROM conversation_invite_codes
          WHERE expires_at > UTC_TIMESTAMP()
          ORDER BY id
          FOR UPDATE'
    );

    $match = null;
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        if (password_verify($code, (string)$row['code_hash'])) {
            $match = $row;
            break;
        }
    }

    if (!$match) {
        $pdo->rollBack();
        json_response(['error' => 'That code is invalid or has expired.'], 404);
    }

    $creatorId = (int)$match['creator_user_id'];
    if ($creatorId === $userId) {
        $pdo->rollBack();
        json_response(['error' => 'You cannot redeem your own invitation code.'], 400);
    }

    // Reuse an existing one-to-one conversation between these two users.
    $existing = $pdo->prepare(
        'SELECT cp1.conversation_id
           FROM conversation_participants cp1
           JOIN conversation_participants cp2
             ON cp2.conversation_id = cp1.conversation_id
          WHERE cp1.user_id = ?
            AND cp2.user_id = ?
            AND (SELECT COUNT(*)
                   FROM conversation_participants cp3
                  WHERE cp3.conversation_id = cp1.conversation_id) = 2
          LIMIT 1'
    );
    $existing->execute([$userId, $creatorId]);
    $conversationId = $existing->fetchColumn();

    if (!$conversationId) {
        $pdo->exec('INSERT INTO conversations () VALUES ()');
        $conversationId = (int)$pdo->lastInsertId();

        $addParticipants = $pdo->prepare(
            'INSERT INTO conversation_participants
                (conversation_id, user_id)
             VALUES (?, ?), (?, ?)'
        );
        $addParticipants->execute([
            $conversationId, $userId,
            $conversationId, $creatorId
        ]);
    } else {
        $conversationId = (int)$conversationId;
    }

    // Consume the invitation only after the conversation operation succeeds.
    $delete = $pdo->prepare(
        'DELETE FROM conversation_invite_codes WHERE id = ?'
    );
    $delete->execute([(int)$match['id']]);

    $pdo->commit();
    json_response([
        'ok' => true,
        'conversation_id' => $conversationId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Invitation redemption failed: ' . $e->getMessage());
    json_response(['error' => 'Could not create the conversation. Please try again.'], 500);
}
