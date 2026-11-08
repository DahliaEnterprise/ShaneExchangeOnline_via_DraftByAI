<?php
declare(strict_types=1);

/*
 * CLI only. Preserve the low-disk trigger.
 * Active paid senders are protected; free/expired senders' messages older
 * than seven days are deleted once cleanup is triggered.
 */
require_once '/var/www/html/auth/bootstrap.php';
require_once '/var/www/html/api/voicemail_config.php';

const RETENTION_DAYS = 7;
const MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (RETENTION_DAYS < 1 || RETENTION_DAYS > 7) {
    fwrite(STDERR, "Invalid retention period\n");
    exit(1);
}

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
