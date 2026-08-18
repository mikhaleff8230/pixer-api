<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YandexDirectSetting extends Model
{
    protected $fillable = ['enabled','oauth_token','client_login','campaign_id','feed_id','markup_percent','balance_reserve','sync_interval_minutes','last_connection_check_at','last_sync_at','last_error'];
    protected $hidden = ['oauth_token'];
    protected $casts = ['enabled'=>'boolean','oauth_token'=>'encrypted','markup_percent'=>'decimal:2','balance_reserve'=>'decimal:2','last_connection_check_at'=>'datetime','last_sync_at'=>'datetime'];

    public static function current(): self
    {
        return static::firstOrCreate(['id'=>1], ['enabled'=>false,'markup_percent'=>30,'balance_reserve'=>100,'sync_interval_minutes'=>15]);
    }

    public function safeArray(): array
    {
        return array_merge($this->toArray(), ['oauth_token_configured'=>!empty($this->oauth_token)]);
    }
}
