<?php

namespace Marvel\Listeners;

use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Marvel\Events\MessageSent;

class SendMessageWebPush implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(MessageSent $event, WebPushService $webPush): void
    {
        // MessageRepository uses "shop" when the customer writes to the seller.
        if ($event->type !== 'shop') {
            return;
        }

        $shop = $event->conversation->shop;
        if (!$shop || !$shop->owner_id) {
            return;
        }

        $sender = $event->message->user?->name ?: 'Покупатель';
        $body = trim(strip_tags((string) $event->message->body));
        if ($body === '') {
            $body = 'Отправлено вложение';
        }

        $webPush->sendToUser((int) $shop->owner_id, [
            'title' => 'Новое сообщение в SANCAN',
            'body' => $sender . ': ' . mb_strimwidth($body, 0, 140, '…'),
            'url' => '/shop-message/' . $event->conversation->id,
            'tag' => 'conversation-' . $event->conversation->id,
        ]);
    }
}
