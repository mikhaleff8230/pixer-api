<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerBalanceTransaction extends Model
{
    protected $fillable = [
        'seller_id',
        'order_id',
        'ai_service_job_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];
}
