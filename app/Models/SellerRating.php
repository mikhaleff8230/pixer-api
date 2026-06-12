<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerRating extends Model
{
    protected $fillable = [
        'seller_id',
        'completed_orders',
        'cancelled_orders',
        'disputes_count',
        'rating_score',
        'verification_score',
    ];

    protected $casts = [
        'completed_orders' => 'integer',
        'cancelled_orders' => 'integer',
        'disputes_count' => 'integer',
        'rating_score' => 'decimal:2',
        'verification_score' => 'decimal:2',
    ];
}
