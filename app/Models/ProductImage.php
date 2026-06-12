<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id', 'url', 'thumbnail_url', 'is_ai_generated', 'original_image_id',
        'ai_service_job_id', 'image_role', 'meta',
    ];

    protected $casts = [
        'is_ai_generated' => 'boolean',
        'meta' => 'array',
    ];
}