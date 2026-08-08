<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderEvent extends Model { public $timestamps = false; protected $guarded = []; protected $casts = ['payload'=>'array','created_at'=>'datetime']; }
