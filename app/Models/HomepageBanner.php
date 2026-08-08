<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageBanner extends Model
{
    protected $fillable = ['kind', 'content', 'sort_order', 'is_active'];
    protected $casts = ['content' => 'array', 'is_active' => 'boolean'];
}
