<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SellerYandexAdGroup extends Model {
 protected $fillable=['seller_id','slot','campaign_id','ad_group_id','shopping_ad_id','feed_id','status','pause_reason','boost_filter_hash','last_yandex_cost','last_sync_at','last_error','bid_level','last_applied_bid_modifier','bid_modifier_id'];
 protected $casts=['last_yandex_cost'=>'decimal:2','bid_level'=>'decimal:2','last_sync_at'=>'datetime'];
 public function seller(){return $this->belongsTo(\Marvel\Database\Models\User::class,'seller_id');}
}
