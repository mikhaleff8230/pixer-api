<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductPromotionVisit extends Model { protected $fillable=['product_id','seller_id','source','type','yclid','ip_hash']; }
