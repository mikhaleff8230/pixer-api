<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SellerYandexIntensityAudit extends Model
{
    protected $fillable=['seller_id','old_level','new_level','calculated_modifier','source'];
    protected $casts=['old_level'=>'decimal:2','new_level'=>'decimal:2'];
}
