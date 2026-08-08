<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaymentConfirmation extends Model { protected $guarded = []; protected $casts = ['submitted_at'=>'datetime','reviewed_at'=>'datetime','amount'=>'decimal:2']; public function order(){return $this->belongsTo(SecondLifeOrder::class,'order_id');} public function buyer(){return $this->belongsTo(\Marvel\Database\Models\User::class,'buyer_id');} }
