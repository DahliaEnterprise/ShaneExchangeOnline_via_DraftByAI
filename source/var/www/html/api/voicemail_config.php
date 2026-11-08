<?php
declare(strict_types=1);

/*
 * Private message storage. This directory must be outside the document root.
 * Override this constant here if your deployment uses a different path.
 */
const MESSAGE_STORAGE_DIR = '/var/lib/shastaexchange/messages';
const MAX_AUDIO_BYTES = 2 * 1024 * 1024;
const MAX_AUDIO_SECONDS = 60.0;

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'POST required'], 405);
    }
}

function require_csrf(): void {
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || $expected === '' || !hash_equals($expected, $provided)) {
        json_response(['error' => 'Invalid security token'], 403);
    }
}

function require_conversation_member(PDO $pdo, int $conversationId, int $userId): void {
    $q = $pdo->prepare('SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?');
    $q->execute([$conversationId, $userId]);
    if (!$q->fetchColumn()) {
        json_response(['error' => 'Conversation not found'], 404);
    }
}

function ensure_message_storage(): void {
    if (!is_dir(MESSAGE_STORAGE_DIR) || !is_writable(MESSAGE_STORAGE_DIR)) {
        json_response(['error' => 'Message storage is not configured or writable'], 500);
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
