<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class YandexDirectErrorLog extends Model
{
    protected $fillable=['seller_id','product_id','operation','error_code','error_message','context'];
    protected $casts=['context'=>'array'];

    public function scopeActionable($query)
    {
        return $query->where(function($query){
            $query->where('operation','!=','reports_batch')
                ->orWhere('error_message','not like','Отчёт Direct ещё формируется.%');
        });
    }
}
