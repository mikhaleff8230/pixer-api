<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\OrderCreated;
use Marvel\Notifications\NewOrderReceived;
use Marvel\Notifications\OrderPlacedSuccessfully;
use Marvel\Traits\OrderSmsTrait;
use Marvel\Traits\SmsTrait;

class SendOrderCreationNotification implements ShouldQueue
{
    use SmsTrait, OrderSmsTrait;

    /**
     * Handle the event.
     *
     * @param OrderCreated $event
     * @return void
     */
    public function handle(OrderCreated $event)
    {
        $order = $event->order;
        $customer = $event->order->customer;
        $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_CREATED, $order->language);
        
        // One notification per recipient, strictly respecting emailEvent settings.
        // Vendors receive their child-order notification from OrderReceived.
        if ($customer && $emailReceiver['customer'] && $order->parent_id == null) {
            $customer->notify(new OrderPlacedSuccessfully($event->invoiceData));
        }
        if ($emailReceiver['admin']) {
            $admins = $this->adminList();
            foreach ($admins as $admin) {
                $admin->notify(new NewOrderReceived($order, 'admin'));
            }
        }
        
        // Send SMS
        $this->sendOrderCreationSms($order);
    }
}
