<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SellerAdBillingEntry extends Model {
 protected $fillable=['seller_id','seller_yandex_ad_group_id','period_from','period_to','yandex_cost_delta','markup_percent','seller_charge','balance_transaction_id','status','error_message'];
 protected $casts=['period_from'=>'datetime','period_to'=>'datetime','yandex_cost_delta'=>'decimal:2','markup_percent'=>'decimal:2','seller_charge'=>'decimal:2'];
}
