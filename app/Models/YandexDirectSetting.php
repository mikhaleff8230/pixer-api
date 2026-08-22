<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YandexDirectSetting extends Model
{
    protected $fillable = ['enabled','oauth_token','client_login','campaign_id','feed_id','markup_percent','balance_reserve','sync_interval_minutes','campaign_bid_ceiling','desired_bid_ceiling','applied_bid_ceiling','bid_ceiling_sync_status','default_bid_level','allowed_bid_levels','strategy_sync_status','strategy_synced_at','last_connection_check_at','last_sync_at','last_error'];
    protected $hidden = ['oauth_token'];
    protected $casts = ['enabled'=>'boolean','oauth_token'=>'encrypted','markup_percent'=>'decimal:2','balance_reserve'=>'decimal:2','campaign_bid_ceiling'=>'decimal:2','desired_bid_ceiling'=>'decimal:2','applied_bid_ceiling'=>'decimal:2','default_bid_level'=>'decimal:2','allowed_bid_levels'=>'array','strategy_synced_at'=>'datetime','last_connection_check_at'=>'datetime','last_sync_at'=>'datetime'];

    public static function current(): self
    {
        return static::firstOrCreate(['id'=>1], ['enabled'=>false,'markup_percent'=>30,'balance_reserve'=>100,'sync_interval_minutes'=>15,'campaign_bid_ceiling'=>40,'default_bid_level'=>20,'allowed_bid_levels'=>[5,10,15,20,30,40]]);
    }

    public function safeArray(): array
    {
        return array_merge($this->toArray(), ['oauth_token_configured'=>!empty($this->oauth_token)]);
    }

    public function levels(): array { return array_values(array_map('floatval',$this->allowed_bid_levels?:[5,10,15,20,30,40])); }
    public function modifierFor(float $level): int { return max(1,min(100,(int)round($level/max(0.01,(float)$this->campaign_bid_ceiling)*100))); }
}
