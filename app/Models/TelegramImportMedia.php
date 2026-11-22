<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramImportMedia extends Model
{
    protected $guarded = [];
    protected $casts = ['storage_data' => 'array'];
    public function post() { return $this->belongsTo(TelegramImportPost::class, 'post_id'); }
}
