<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerAgreement extends Model
{
    protected $fillable = [
        'seller_id',
        'agreement_type',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
