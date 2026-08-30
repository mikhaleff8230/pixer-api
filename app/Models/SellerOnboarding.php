<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerOnboarding extends Model
{
    protected $guarded = [];

    protected $casts = [
        'product_draft' => 'array',
        'attribution' => 'array',
        'started_at' => 'datetime',
        'shop_completed_at' => 'datetime',
        'completed_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];
}
