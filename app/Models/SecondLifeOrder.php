<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecondLifeOrder extends Model
{
    protected $table = 'second_life_orders';

    protected $fillable = [
        'buyer_id',
        'public_id',
        'seller_id',
        'product_id',
        'payment_profile_id',
        'price',
        'platform_fee',
        'currency',
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
        'reserved_until',
        'seller_confirmed_paid_at',
        'payment_rejected_at',
        'cancelled_at',
        'dispute_reason',
        'admin_comment',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'buyer_marked_paid_at' => 'datetime',
        'seller_confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'reserved_until' => 'datetime',
        'seller_confirmed_paid_at' => 'datetime',
        'payment_rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function paymentDetails() { return $this->hasOne(OrderPaymentDetail::class, 'order_id'); }
    public function confirmations() { return $this->hasMany(PaymentConfirmation::class, 'order_id'); }
    public function events() { return $this->hasMany(OrderEvent::class, 'order_id'); }
    public function product() { return $this->belongsTo(\Marvel\Database\Models\Product::class); }
    public function buyer() { return $this->belongsTo(\Marvel\Database\Models\User::class, 'buyer_id'); }
    public function seller() { return $this->belongsTo(\Marvel\Database\Models\User::class, 'seller_id'); }
}
