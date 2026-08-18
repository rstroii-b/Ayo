<?php

declare(strict_types=1);

namespace Saveurs\Services;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Saveurs\Support\Database;

/** Envoi de notifications push (Web Push / VAPID) — voir push_subscriptions. */
final class PushService
{
    /** @param array{title:string, body:string, url?:string} $payload */
    public function sendToUser(int $userId, array $payload): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll();

        if ($subscriptions === []) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $_ENV['VAPID_SUBJECT'],
                'publicKey' => $_ENV['VAPID_PUBLIC_KEY'],
                'privateKey' => $_ENV['VAPID_PRIVATE_KEY'],
            ],
        ]);

        $byEndpoint = [];
        foreach ($subscriptions as $row) {
            $byEndpoint[$row['endpoint']] = $row['id'];
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['p256dh'],
                    'authToken' => $row['auth'],
                ]),
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        }

        foreach ($webPush->flush() as $report) {
            // Abonnement expiré ou révoqué côté navigateur (410/404) — on le retire, sinon il
            // sera retenté à chaque notification pour rien.
            if (!$report->isSuccess() && isset($byEndpoint[$report->getEndpoint()])) {
                $db->prepare('DELETE FROM push_subscriptions WHERE id = ?')
                    ->execute([$byEndpoint[$report->getEndpoint()]]);
            }
        }
    }
}
