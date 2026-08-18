<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductPromotionStatDaily extends Model { protected $table='product_promotion_stats_daily'; protected $fillable=['product_id','seller_id','date','views','yandex_clicks']; protected $casts=['date'=>'date']; }
