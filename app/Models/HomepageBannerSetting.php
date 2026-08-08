<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageBannerSetting extends Model
{
    protected $fillable = ['autoplay', 'interval_ms'];
    protected $casts = ['autoplay' => 'boolean', 'interval_ms' => 'integer'];
}
