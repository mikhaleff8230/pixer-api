<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\User;

class SellerCreateShopReminder extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $createShopUrl;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createShopUrl = rtrim(config('shop.dashboard_url', 'https://seller.sancan.ru'), '/');
    }

    public function build()
    {
        return $this->subject('Создайте свой магазин на SANCAN')
            ->view('emails.seller.create-shop-reminder');
    }
}
