<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';

require_login();
require_post();
require_csrf();
ensure_message_storage();

// Check uploaded file.
if (
    !isset($_FILES['audio']) ||
    $_FILES['audio']['error'] !== UPLOAD_ERR_OK
) {
    json_response([
        'error' => 'Audio upload failed'
    ], 400);
}

$f = $_FILES['audio'];

// Enforce file size limit.
if (
    $f['size'] < 1 ||
    $f['size'] > MAX_AUDIO_BYTES
) {
    json_response([
        'error' => 'File must be no larger than 2 MB'
    ], 413);
}

// Generate a unique filename.
$extension = pathinfo(
    $f['name'],
    PATHINFO_EXTENSION
);

$extension = preg_replace(
    '/[^a-zA-Z0-9]/',
    '',
    $extension
);

if (!$extension) {
    $extension = 'audio';
}

$stored = bin2hex(random_bytes(24))
        . '.' . strtolower($extension);

$destination = MESSAGE_STORAGE_DIR . '/' . $stored;

// Move uploaded file into private storage.
if (!move_uploaded_file(
    $f['tmp_name'],
    $destination
)) {
    json_response([
        'error' => 'Could not store audio file'
    ], 500);
}

// Use browser-provided content type as metadata only.
$mimeType = $f['type'] ?: 'application/octet-stream';

$uid = (int)$_SESSION['user_id'];

$conversationId = filter_var(
    $_POST['conversation_id'] ?? null,
    FILTER_VALIDATE_INT
);

$recipientId = null;

// New conversation.
if (!$conversationId) {

    $email = filter_var(
        trim($_POST['recipient_email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    );

    if (!$email) {
        @unlink($destination);

        json_response([
            'error' => 'A valid recipient is required'
        ], 400);
    }

    $q = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email = ?
         AND verified = 1
         LIMIT 1'
    );

    $q->execute([$email]);

    $recipientId = $q->fetchColumn();

    if (
        !$recipientId ||
        (int)$recipientId === $uid
    ) {
        @unlink($destination);

        json_response([
            'error' => 'Recipient is not available'
        ], 404);
    }
}

try {

    $pdo->beginTransaction();

    if (!$conversationId) {

        // Look for an existing direct conversation.
        $find = $pdo->prepare(
            'SELECT c.id
             FROM conversations c
             INNER JOIN conversation_participants a
                ON a.conversation_id = c.id
                AND a.user_id = ?
             INNER JOIN conversation_participants b
                ON b.conversation_id = c.id
                AND b.user_id = ?
             LIMIT 1'
        );

        $find->execute([
            $uid,
            (int)$recipientId
        ]);

        $conversationId = (int)(
            $find->fetchColumn() ?: 0
        );

        // Create a new conversation if necessary.
        if (!$conversationId) {

            $pdo->exec(
                'INSERT INTO conversations () VALUES ()'
            );

            $conversationId = (int)$pdo->lastInsertId();

            $participants = $pdo->prepare(
                'INSERT INTO conversation_participants
                 (conversation_id, user_id)
                 VALUES (?, ?), (?, ?)'
            );

            $participants->execute([
                $conversationId,
                $uid,
                $conversationId,
                (int)$recipientId
            ]);
        }

    } else {

        // Verify that the logged-in user belongs
        // to the requested conversation.
        require_conversation_member(
            $pdo,
            (int)$conversationId,
            $uid
        );
    }

    // Insert message record.
    $stmt = $pdo->prepare(
        'INSERT INTO messages
        (
            conversation_id,
            sender_id,
            stored_name,
            original_name,
            mime_type,
            byte_size,
            duration_seconds
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        (int)$conversationId,
        $uid,
        $stored,
        basename($f['name']),
        $mimeType,
        (int)filesize($destination),
        null
    ]);

    $messageId = (int)$pdo->lastInsertId();

    // Update conversation timestamp.
    $update = $pdo->prepare(
        'UPDATE conversations
         SET updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );

    $update->execute([
        (int)$conversationId
    ]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'conversation_id' => (int)$conversationId,
        'message_id' => $messageId
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    @unlink($destination);

    error_log(
        'Shasta Exchange upload error: ' .
        $e->getMessage()
    );

    json_response([
        'error' => 'Could not save message'
    ], 500);
}
