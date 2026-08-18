<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SellerAdStatDaily extends Model { protected $fillable=['seller_id','seller_yandex_ad_group_id','date','impressions','clicks','yandex_cost','seller_cost']; protected $casts=['date'=>'date','yandex_cost'=>'decimal:2','seller_cost'=>'decimal:2']; }
