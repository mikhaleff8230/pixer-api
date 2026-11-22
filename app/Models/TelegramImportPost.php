<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramImportPost extends Model
{
    protected $guarded = [];
    protected $casts = [
        'raw_payload' => 'array',
        'normalized_data' => 'array',
        'previous_versions' => 'array',
        'manual_fields' => 'array',
        'original_date' => 'datetime',
        'edited_date' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function channel() { return $this->belongsTo(TelegramImportChannel::class, 'channel_id'); }
    public function media() { return $this->hasMany(TelegramImportMedia::class, 'post_id')->orderBy('sort_order'); }
    public function product() { return $this->belongsTo(\Marvel\Database\Models\Product::class, 'product_id'); }
}
