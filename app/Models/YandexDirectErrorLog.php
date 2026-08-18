<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class YandexDirectErrorLog extends Model { protected $fillable=['seller_id','product_id','operation','error_code','error_message','context']; protected $casts=['context'=>'array']; }
