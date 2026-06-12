<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'receiver_name',
        'phone',
        'bank_name',
        'inn',
        'company_name',
        'sbp_qr_url',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
