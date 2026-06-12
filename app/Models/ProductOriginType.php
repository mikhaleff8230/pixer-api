<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOriginType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
    ];
}
