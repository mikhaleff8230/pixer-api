<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TelegramImportChannel extends Model
{
    use SoftDeletes;

    protected $guarded = [];
    protected $casts = [
        'auto_import_enabled' => 'boolean',
        'content_permission_confirmed_at' => 'datetime',
        'last_publication_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'initial_from' => 'datetime',
        'initial_to' => 'datetime',
        'markup_percent' => 'decimal:2',
        'fixed_markup' => 'decimal:2',
        'minimum_sale_price' => 'decimal:2',
    ];

    public function posts() { return $this->hasMany(TelegramImportPost::class, 'channel_id'); }
}
