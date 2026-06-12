<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerTaxStatus extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'requires_receipt',
        'can_resell_goods',
        'is_business',
    ];

    protected $casts = [
        'requires_receipt' => 'boolean',
        'can_resell_goods' => 'boolean',
        'is_business' => 'boolean',
    ];
}
