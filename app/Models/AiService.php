<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiService extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'service_type', 'provider', 'model',
        'cost', 'currency', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function jobs()
    {
        return $this->hasMany(AiServiceJob::class);
    }
}