<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit; }

$uid = (int)$_SESSION['user_id'];
$q = $pdo->prepare(
    'SELECT m.stored_name,m.mime_type
     FROM messages m
     JOIN conversation_participants cp ON cp.conversation_id=m.conversation_id
     WHERE m.id=? AND cp.user_id=?'
);
$q->execute([$id, $uid]);
$m = $q->fetch();
if (!$m) { http_response_code(404); exit; }

$storedName = basename((string)$m['stored_name']);
$path = MESSAGE_STORAGE_DIR . '/' . $storedName;
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit; }

$size = filesize($path);
if ($size === false) { http_response_code(500); exit; }

$mime = strtolower(trim(explode(';', (string)($m['mime_type'] ?? ''))[0]));
$aliases = ['audio/mp3'=>'audio/mpeg','audio/x-wav'=>'audio/wav',
            'video/webm'=>'audio/webm','video/mp4'=>'audio/mp4'];
$mime = $aliases[$mime] ?? $mime;
$allowed = ['audio/mp4','audio/webm','audio/ogg','audio/mpeg',
            'audio/wav','audio/aac','audio/3gpp'];

if (!in_array($mime, $allowed, true)) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $detected = strtolower((string)($fi->file($path) ?: ''));
    $detected = $aliases[$detected] ?? $detected;
    $mime = in_array($detected, $allowed, true) ? $detected : 'application/octet-stream';
}

$ext = ['audio/mp4'=>'m4a','audio/webm'=>'webm','audio/ogg'=>'ogg',
        'audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/aac'=>'aac',
        'audio/3gpp'=>'3gp'][$mime] ?? 'audio';

header('Content-Type: '.$mime);
header('Content-Disposition: inline; filename="message-'.$id.'.'.$ext.'"');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Accel-Buffering: no');

$start = 0;
$end = max(0, $size - 1);
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $mch)) {
        header('Content-Range: bytes */'.$size);
        http_response_code(416);
        exit;
    }
    if ($mch[1] === '' && $mch[2] !== '') {
        $suffix = (int)$mch[2];
        if ($suffix > 0) { $start = max(0, $size - $suffix); }
    } else {
        $start = ($mch[1] === '') ? 0 : (int)$mch[1];
        if ($mch[2] !== '') { $end = (int)$mch[2]; }
    }
    if ($start >= $size || $start < 0 || $end < $start) {
        header('Content-Range: bytes */'.$size);
        http_response_code(416);
        exit;
    }
    $end = min($end, $size - 1);
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$length = ($size === 0) ? 0 : ($end - $start + 1);
header('Content-Length: '.$length);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) { exit; }

while (ob_get_level() > 0) { ob_end_clean(); }
$handle = fopen($path, 'rb');
if ($handle === false) { http_response_code(500); exit; }
if ($start > 0 && fseek($handle, $start) !== 0) {
    fclose($handle); http_response_code(500); exit;
}
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false || $chunk === '') { break; }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) { break; }
}
fclose($handle);
exit;
