<?php
declare(strict_types=1);

/*
 * Run from cron, not through the browser.
 * Delete only when free disk space is below the configured threshold.
 */
require_once '/var/www/html/auth/bootstrap.php';
require_once '/var/www/html/api/voicemail_config.php';

const RETENTION_DAYS = 7;              // Set to 1 through 7.
const MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024; // Trigger cleanup below 2 GiB.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (RETENTION_DAYS < 1 || RETENTION_DAYS > 7) { fwrite(STDERR,"Invalid retention period\n"); exit(1); }
$free=disk_free_space(MESSAGE_STORAGE_DIR);
if ($free===false || $free>=MIN_FREE_BYTES) { exit(0); }
$q=$pdo->prepare('SELECT id,stored_name FROM messages WHERE created_at < (NOW() - INTERVAL '.RETENTION_DAYS.' DAY) ORDER BY created_at ASC');
$q->execute();
$delete=$pdo->prepare('DELETE FROM messages WHERE id=?');
while($m=$q->fetch()){
    $path=MESSAGE_STORAGE_DIR.'/'.$m['stored_name'];
    if(is_file($path) && !unlink($path)){fwrite(STDERR,"Could not delete ".$m['stored_name']."\n");continue;}
    $delete->execute([(int)$m['id']]);
    $free=disk_free_space(MESSAGE_STORAGE_DIR);
    if($free!==false && $free>=MIN_FREE_BYTES)break;
}
