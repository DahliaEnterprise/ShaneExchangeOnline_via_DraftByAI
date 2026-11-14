<?php
declare(strict_types=1);
require_once '/var/www/html/auth/bootstrap.php';
const RADIO_STORAGE_DIR = '/var/lib/shastaexchange/radio';
$q = $pdo->query("SELECT id,stored_name FROM radio_recordings WHERE ended_at < (NOW() - INTERVAL 30 MINUTE)");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $path = RADIO_STORAGE_DIR . '/recordings/' . basename((string)$row['stored_name']);
    if (is_file($path)) @unlink($path);
}
$pdo->exec("DELETE FROM radio_recordings WHERE ended_at < (NOW() - INTERVAL 30 MINUTE)");
$pdo->exec("DELETE FROM radio_presence WHERE last_seen < (NOW() - INTERVAL 18 SECOND)");
$pdo->exec("DELETE FROM radio_signals WHERE created_at < (NOW() - INTERVAL 2 MINUTE)");
