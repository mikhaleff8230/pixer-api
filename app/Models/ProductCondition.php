<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCondition extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
