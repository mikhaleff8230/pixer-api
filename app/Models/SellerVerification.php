<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerVerification extends Model
{
    protected $fillable = [
        'seller_id',
        'verification_type',
        'status',
        'document_url',
        'face_scan_url',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];
}
