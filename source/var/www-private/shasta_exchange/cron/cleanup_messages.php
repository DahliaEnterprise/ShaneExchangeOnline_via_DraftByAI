<?php
declare(strict_types=1);

/*
 * CLI only. Expired invitation codes are removed on every run.
 * The existing low-disk audio-retention behavior is preserved.
 */
require_once '/var/www/html/auth/bootstrap.php';
require_once '/var/www/html/api/voicemail_config.php';

const RETENTION_DAYS = 7;
const MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Always delete expired invitations, even when disk space is plentiful.
$pdo->exec(
    'DELETE FROM conversation_invite_codes
     WHERE expires_at <= UTC_TIMESTAMP()'
);

$free = disk_free_space(MESSAGE_STORAGE_DIR);
if ($free === false || $free >= MIN_FREE_BYTES) {
    exit(0);
}

$sql = 'SELECT m.id, m.stored_name
        FROM messages m
        INNER JOIN users u ON u.id = m.sender_id
        WHERE m.created_at < (NOW() - INTERVAL ' . RETENTION_DAYS . ' DAY)
          AND (u.paid_until IS NULL OR u.paid_until <= NOW())
        ORDER BY m.created_at ASC';
$q = $pdo->query($sql);
$delete = $pdo->prepare('DELETE FROM messages WHERE id = ?');

while ($m = $q->fetch(PDO::FETCH_ASSOC)) {
    $path = MESSAGE_STORAGE_DIR . '/' . basename((string)$m['stored_name']);
    if (is_file($path) && !unlink($path)) {
        fwrite(STDERR, "Could not delete " . $m['stored_name'] . "\n");
        continue;
    }
    $delete->execute([(int)$m['id']]);
    $free = disk_free_space(MESSAGE_STORAGE_DIR);
    if ($free !== false && $free >= MIN_FREE_BYTES) {
        break;
    }
}
