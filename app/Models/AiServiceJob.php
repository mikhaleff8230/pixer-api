<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiServiceJob extends Model
{
    protected $fillable = [
        'seller_id', 'product_id', 'product_image_id', 'ai_service_id', 'status',
        'provider', 'model', 'input_payload', 'output_payload', 'input_image_url',
        'output_image_url', 'cost', 'currency', 'balance_transaction_id',
        'error_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'cost' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(AiService::class, 'ai_service_id');
    }
}