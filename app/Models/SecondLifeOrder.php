<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecondLifeOrder extends Model
{
    protected $table = 'second_life_orders';

    protected $fillable = [
        'buyer_id',
        'seller_id',
        'product_id',
        'payment_profile_id',
        'price',
        'platform_fee',
        'payment_method',
        'payment_status',
        'order_status',
        'receiver_name',
        'phone',
        'bank_name',
        'company_name',
        'inn',
        'sbp_qr_url',
        'buyer_payment_comment',
        'buyer_payment_screenshot',
        'buyer_marked_paid_at',
        'seller_confirmed_at',
        'completed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'buyer_marked_paid_at' => 'datetime',
        'seller_confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}