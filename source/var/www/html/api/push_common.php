<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/voicemail_config.php';

function push_require_library(): void {
    $autoload = '/var/www-private/shasta_exchange/vendor/autoload.php';
    if (!is_file($autoload)) {
        json_response(['error' => 'Web Push library is not installed. Run Composer install in /var/www-private/shasta_exchange.'], 500);
    }
    require_once $autoload;

    $config = '/var/www-private/shasta_exchange/push_config.php';
    if (!is_file($config)) {
        json_response(['error' => 'Push configuration is missing. Install push_config.php in the private configuration directory.'], 500);
    }
    require_once $config;

    if (
        !defined('SHASTA_VAPID_PUBLIC_KEY') ||
        !defined('SHASTA_VAPID_PRIVATE_KEY') ||
        !defined('SHASTA_VAPID_SUBJECT') ||
        SHASTA_VAPID_PUBLIC_KEY === 'PUT_PUBLIC_KEY_HERE' ||
        SHASTA_VAPID_PRIVATE_KEY === 'PUT_PRIVATE_KEY_HERE'
    ) {
        json_response(['error' => 'VAPID keys are not configured.'], 500);
    }
}

function push_send_to_user(PDO $pdo, int $userId, int $conversationId, int $messageId, string $senderEmail): void {
    // Insert a state row if this is the user's first notification.
    $insert = $pdo->prepare('INSERT IGNORE INTO push_notification_state (user_id) VALUES (?)');
    $insert->execute([$userId]);

    // Lock this user's state so simultaneous incoming messages cannot
    // create multiple pending notifications.
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT pending_message_id FROM push_notification_state WHERE user_id = ? FOR UPDATE');
        $lock->execute([$userId]);
        $pending = $lock->fetchColumn();

        if ($pending !== false && $pending !== null) {
            $pdo->commit();
            return;
        }

        $subQ = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $subQ->execute([$userId]);
        $subscriptions = $subQ->fetchAll();

        if (!$subscriptions) {
            $pdo->commit();
            return;
        }

        $set = $pdo->prepare(
            'UPDATE push_notification_state
             SET pending_message_id = ?, pending_conversation_id = ?, pending_since = NOW()
             WHERE user_id = ?'
        );
        $set->execute([$messageId, $conversationId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Shasta push state error: ' . $e->getMessage());
        return;
    }

    try {
        push_require_library();

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => SHASTA_VAPID_SUBJECT,
                'publicKey' => SHASTA_VAPID_PUBLIC_KEY,
                'privateKey' => SHASTA_VAPID_PRIVATE_KEY,
            ],
        ]);

        $payload = json_encode([
            'title' => 'New Shasta Exchange message',
            'body' => 'New voice message from ' . $senderEmail,
            'url' => '/conversation.php?id=' . $conversationId,
            'conversationId' => $conversationId,
            'messageId' => $messageId,
        ], JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $sub) {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth'],
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        $sent = false;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent = true;
            } else {
                error_log('Shasta push delivery failed: ' . $report->getReason());
                if ($report->isSubscriptionExpired()) {
                    $endpoint = $report->getRequest()->getUri()->__toString();
                    $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
                    $delete->execute([hash('sha256', $endpoint)]);
                }
            }
        }

        // If no push endpoint accepted the notification, release the slot.
        if (!$sent) {
            $clear = $pdo->prepare(
                'UPDATE push_notification_state
                 SET pending_message_id = NULL, pending_conversation_id = NULL, pending_since = NULL
                 WHERE user_id = ? AND pending_message_id = ?'
            );
            $clear->execute([$userId, $messageId]);
        }
    } catch (Throwable $e) {
        error_log('Shasta push send error: ' . $e->getMessage());
        $clear = $pdo->prepare(
            'UPDATE push_notification_state
             SET pending_message_id = NULL, pending_conversation_id = NULL, pending_since = NULL
             WHERE user_id = ? AND pending_message_id = ?'
        );
        $clear->execute([$userId, $messageId]);
    }
}
