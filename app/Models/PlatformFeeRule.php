<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformFeeRule extends Model
{
    protected $fillable = [
        'seller_tax_status_id',
        'product_origin_type_id',
        'fee_type',
        'fee_value',
        'min_fee',
        'max_fee',
        'is_active',
    ];

    protected $casts = [
        'fee_value' => 'decimal:2',
        'min_fee' => 'decimal:2',
        'max_fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
