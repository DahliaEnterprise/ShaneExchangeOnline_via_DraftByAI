<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth/bootstrap.php';
require_once dirname(__DIR__) . '/voicemail_config.php';
require_login();

const RADIO_STORAGE_DIR = '/var/lib/shastaexchange/radio/';
const RADIO_MAX_GPS_ACCURACY = 500.0;
const RADIO_PRESENCE_SECONDS = 18;
const RADIO_MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

header('Cache-Control: no-store');
$userId = (int)$_SESSION['user_id'];

function radio_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function radio_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') radio_json(['error' => 'POST required'], 405);
}
function radio_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
        radio_json(['error' => 'Security token expired. Refresh the page.'], 403);
    }
}
function radio_storage(): void {
    if (!is_dir(RADIO_STORAGE_DIR) || !is_writable(RADIO_STORAGE_DIR)) {
        radio_json(['error' => 'Radio storage is not configured. See installation instructions.'], 500);
    }
}
function radio_boundary(): array {
    $path = RADIO_STORAGE_DIR . '/shasta-county.geojson';
    if (!is_file($path)) radio_json(['error' => 'Shasta County boundary is not installed. Run the boundary download script.'], 503);
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json) || empty($json['features'][0]['geometry'])) radio_json(['error' => 'County boundary file is invalid.'], 500);
    return $json['features'][0]['geometry'];
}
function radio_ring_contains(float $x, float $y, array $ring): bool {
    $inside = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float)$ring[$i][0]; $yi = (float)$ring[$i][1];
        $xj = (float)$ring[$j][0]; $yj = (float)$ring[$j][1];
        $intersects = (($yi > $y) !== ($yj > $y)) &&
            ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 0.0000000001) + $xi));
        if ($intersects) $inside = !$inside;
    }
    return $inside;
}
function radio_polygon_contains(float $lon, float $lat, array $rings): bool {
    if (!$rings || !radio_ring_contains($lon, $lat, $rings[0])) return false;
    for ($i = 1; $i < count($rings); $i++) {
        if (radio_ring_contains($lon, $lat, $rings[$i])) return false;
    }
    return true;
}
function radio_point_inside(float $lon, float $lat): bool {
    $geometry = radio_boundary();
    $type = $geometry['type'] ?? '';
    $coords = $geometry['coordinates'] ?? [];
    if ($type === 'Polygon') return radio_polygon_contains($lon, $lat, $coords);
    if ($type === 'MultiPolygon') {
        foreach ($coords as $polygon) if (radio_polygon_contains($lon, $lat, $polygon)) return true;
    }
    return false;
}
function radio_validate_location(): array {
    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($_POST['lon'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
error_log("RADIO GPS POLYGON: " .
    (radio_point_inside((float)$lon, (float)$lat) ? 'INSIDE' : 'OUTSIDE')
);
    if ($lat === false || $lon === false || $accuracy === false ||
        $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ||
        $accuracy < 0 || $accuracy > RADIO_MAX_GPS_ACCURACY) {
        return ['inside' => false, 'reason' => 'A GPS fix with accuracy of 100 meters or better is required.'];
    }
    return ['inside' => radio_point_inside((float)$lon, (float)$lat), 'reason' => ''];
}
function radio_active(PDO $pdo): ?array {
    $q = $pdo->query("SELECT p.user_id, u.email, p.transmitting_since
        FROM radio_presence p JOIN users u ON u.id=p.user_id
        WHERE p.transmitting_since IS NOT NULL AND p.last_seen >= (NOW() - INTERVAL " . RADIO_PRESENCE_SECONDS . " SECOND)
        ORDER BY p.transmitting_since DESC, p.user_id DESC LIMIT 1");
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function radio_clean(PDO $pdo): void {
    $pdo->exec("DELETE FROM radio_signals WHERE created_at < (NOW() - INTERVAL 2 MINUTE)");
    $pdo->exec("DELETE FROM radio_presence WHERE last_seen < (NOW() - INTERVAL " . RADIO_PRESENCE_SECONDS . " SECOND)");
    $q = $pdo->prepare('SELECT id, stored_name FROM radio_recordings WHERE ended_at < (NOW() - INTERVAL 30 MINUTE)');
    $q->execute();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $file) {
        $path = RADIO_STORAGE_DIR . '/recordings/' . basename((string)$file['stored_name']);
        if (is_file($path)) @unlink($path);
    }
    $pdo->exec("DELETE FROM radio_recordings WHERE ended_at < (NOW() - INTERVAL 30 MINUTE)");
}
function radio_presence(PDO $pdo, int $userId): void {
    $q = $pdo->prepare('SELECT user_id, email, transmitting_since, last_seen FROM radio_presence p JOIN users u ON u.id=p.user_id WHERE p.user_id=?');
    $q->execute([$userId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT p.user_id, u.email, p.transmitting_since, p.last_seen
        FROM radio_presence p JOIN users u ON u.id=p.user_id
        WHERE p.last_seen >= (NOW() - INTERVAL " . RADIO_PRESENCE_SECONDS . " SECOND)
        ORDER BY (p.transmitting_since IS NOT NULL) DESC, p.transmitting_since DESC, p.user_id");
    $q->execute();
    $people = $q->fetchAll(PDO::FETCH_ASSOC);
    $active = radio_active($pdo);
    $peers = array_values(array_filter($people, fn($p) => (int)$p['user_id'] !== $userId));
    radio_json([
        'ok' => true,
        'people' => $people,
        'peers' => $peers,
        'active' => $active ? ['user_id'=>(int)$active['user_id'], 'email'=>$active['email']] : null,
        'active_user_id' => $active ? (int)$active['user_id'] : null,
        'server_time' => time()
    ]);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') radio_csrf();
radio_clean($pdo);

if ($action === 'heartbeat') {
    radio_post();
    $lat = (float)($_POST['lat'] ?? 999);
    $lon = (float)($_POST['lon'] ?? 999);
    $accuracy = (float)($_POST['accuracy'] ?? 9999);
    $location = radio_validate_location();
    $transmitting = ($_POST['transmitting'] ?? '0') === '1';
    if ($transmitting && !$location['inside']) {
        $q = $pdo->prepare('UPDATE radio_presence SET transmitting_since=NULL, last_seen=NOW() WHERE user_id=?');
        $q->execute([$userId]);
        radio_json(['ok'=>false, 'inside'=>false, 'error'=>$location['reason'] ?: 'You must be inside Shasta County to transmit.'], 403);
    }
    $q = $pdo->prepare("INSERT INTO radio_presence (user_id, latitude, longitude, accuracy_m, inside_county, transmitting_since, last_seen)
        VALUES (?, ?, ?, ?, ?, IF(?, NOW(6), NULL), NOW())
        ON DUPLICATE KEY UPDATE latitude=VALUES(latitude), longitude=VALUES(longitude),
        accuracy_m=VALUES(accuracy_m), inside_county=VALUES(inside_county),
        transmitting_since=IF(?, IF(transmitting_since IS NULL, NOW(6), transmitting_since), NULL), last_seen=NOW()");
    $q->execute([$userId, $lat, $lon, $accuracy, $location['inside'] ? 1 : 0, $transmitting ? 1 : 0, $transmitting ? 1 : 0]);
    radio_json(['ok'=>true, 'inside'=>$location['inside'], 'reason'=>$location['inside'] ? 'Inside Shasta County.' : 'You can listen, but GPS places you outside Shasta County.']);
}

if ($action === 'poll') {
    radio_post();
    // A member can listen without granting GPS permission. GPS is required only to transmit.
    $q = $pdo->prepare("INSERT INTO radio_presence
        (user_id, latitude, longitude, accuracy_m, inside_county, transmitting_since, last_seen)
        VALUES (?, 0, 0, 0, 0, NULL, NOW())
        ON DUPLICATE KEY UPDATE last_seen=NOW()");
    $q->execute([$userId]);
    radio_presence($pdo, $userId);
}

if ($action === 'signal') {
    radio_post();
    $to = filter_var($_POST['to'] ?? null, FILTER_VALIDATE_INT);
    $type = (string)($_POST['type'] ?? '');
    $payload = (string)($_POST['payload'] ?? '');
    if (!$to || $to === $userId || !in_array($type, ['offer','answer','ice','hangup'], true) || strlen($payload) > 20000) {
        radio_json(['error'=>'Invalid signaling message'], 400);
    }
    $q = $pdo->prepare('SELECT 1 FROM radio_presence WHERE user_id=? AND last_seen >= (NOW() - INTERVAL 20 SECOND)');
    $q->execute([$to]);
    if (!$q->fetchColumn()) radio_json(['error'=>'Radio member is no longer online'], 404);
    $q = $pdo->prepare('INSERT INTO radio_signals (from_user_id,to_user_id,signal_type,payload) VALUES (?,?,?,?)');
    $q->execute([$userId, $to, $type, $payload]);
    radio_json(['ok'=>true]);
}

if ($action === 'signals') {
    radio_post();
    $q = $pdo->prepare('SELECT id, from_user_id, signal_type, payload FROM radio_signals WHERE to_user_id=? ORDER BY id ASC LIMIT 100');
    $q->execute([$userId]);
    $signals = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($signals) {
        $ids = array_map('intval', array_column($signals, 'id'));
        $pdo->exec('DELETE FROM radio_signals WHERE id IN (' . implode(',', $ids) . ')');
    }
    radio_json(['ok'=>true, 'signals'=>$signals]);
}

if ($action === 'archive') {
    radio_post();
    $q = $pdo->query("SELECT r.id, r.user_id, u.email, r.stored_name, r.duration_seconds, r.started_at, r.ended_at
        FROM radio_recordings r JOIN users u ON u.id=r.user_id
        WHERE r.ended_at >= (NOW() - INTERVAL 30 MINUTE)
        ORDER BY r.ended_at DESC LIMIT 200");
    radio_json(['ok'=>true, 'recordings'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'upload') {
    radio_post();
    radio_storage();
    if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) radio_json(['error'=>'Recording upload failed'], 400);
    $file = $_FILES['audio'];
    if ((int)$file['size'] < 1 || (int)$file['size'] > RADIO_MAX_UPLOAD_BYTES) radio_json(['error'=>'Recording is empty or exceeds 25 MB'], 413);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!in_array($mime, ['audio/webm','video/webm','audio/ogg','application/ogg'], true)) radio_json(['error'=>'Unsupported recording format: ' . $mime], 415);
    $duration = max(0.1, min(1800.0, (float)($_POST['duration'] ?? 0)));
    $started = (int)($_POST['started_at'] ?? time());
    $ended = time();
    $stored = bin2hex(random_bytes(24)) . '.webm';
    $directory = RADIO_STORAGE_DIR . '/recordings';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) radio_json(['error'=>'Could not create recording folder'], 500);
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $stored)) radio_json(['error'=>'Could not save recording'], 500);
    $q = $pdo->prepare('INSERT INTO radio_recordings (user_id,stored_name,mime_type,byte_size,duration_seconds,started_at,ended_at) VALUES (?,?,?,?,?,FROM_UNIXTIME(?),NOW())');
    $q->execute([$userId, $stored, $mime, (int)$file['size'], $duration, $started]);
    radio_json(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
}

if ($action === 'audio') {
    // Audio playback requires a logged-in member. GET is used by the HTML audio element.
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) { http_response_code(400); exit; }
    $q = $pdo->prepare('SELECT stored_name,mime_type,ended_at FROM radio_recordings WHERE id=? AND ended_at >= (NOW() - INTERVAL 30 MINUTE)');
    $q->execute([$id]);
    $record = $q->fetch(PDO::FETCH_ASSOC);
    if (!$record) { http_response_code(404); exit('Recording not found or expired.'); }
    $path = RADIO_STORAGE_DIR . '/recordings/' . basename((string)$record['stored_name']);
    if (!is_file($path)) { http_response_code(404); exit('Recording file missing.'); }
    header('Content-Type: ' . $record['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="radio-recording.webm"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

radio_json(['error'=>'Unknown radio action'], 400);
