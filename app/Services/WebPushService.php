<?php

namespace App\Services;

use App\Models\PushSubscription as PushSubscriptionModel;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function isConfigured(): bool
    {
        return filled(config('webpush.subject'))
            && filled(config('webpush.public_key'))
            && filled(config('webpush.private_key'));
    }

    public function sendToUser(int $userId, array $payload): int
    {
        if (!$this->isConfigured()) {
            Log::warning('Web Push skipped: VAPID keys are not configured.');
            return 0;
        }

        $subscriptions = PushSubscriptionModel::where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.subject'),
                'publicKey' => config('webpush.public_key'),
                'privateKey' => config('webpush.private_key'),
            ],
        ]);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subscriptions as $stored) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $stored->endpoint,
                'publicKey' => $stored->public_key,
                'authToken' => $stored->auth_token,
                'contentEncoding' => $stored->content_encoding,
            ]), $json);
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            $stored = $subscriptions->firstWhere('endpoint', $report->getRequest()->getUri()->__toString());
            if ($report->isSuccess()) {
                $sent++;
                $stored?->update(['last_used_at' => now()]);
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $stored?->delete();
            }

            Log::warning('Web Push delivery failed.', [
                'endpoint' => (string) $report->getRequest()->getUri(),
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

}
