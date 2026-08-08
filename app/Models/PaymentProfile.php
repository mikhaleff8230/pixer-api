<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentProfile extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'type',
        'receiver_name',
        'phone',
        'bank_name',
        'bank_code',
        'uploaded_qr_path',
        'payment_link',
        'inn',
        'company_name',
        'sbp_qr_url',
        'is_default',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
    public function user() { return $this->belongsTo(\Marvel\Database\Models\User::class); }
}
