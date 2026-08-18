<?php

namespace App\Jobs;

use App\Models\SellerYandexAdGroup;
use App\Models\YandexDirectErrorLog;
use App\Services\YandexDirect\YandexDirectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Product;

class PauseAllYandexBoostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries=3; public array $backoff=[30,120,600];
    public function handle(YandexDirectService $direct):void
    {
        SellerYandexAdGroup::whereNotNull('ad_group_id')->where('status','!=','paused')->orderBy('id')->chunkById(100,function($groups)use($direct){foreach($groups as $group){try{$direct->pauseSellerAdGroup((int)$group->ad_group_id);$group->update(['status'=>'paused','pause_reason'=>'admin','last_sync_at'=>now()]);}catch(\Throwable $e){YandexDirectErrorLog::create(['seller_id'=>$group->seller_id,'operation'=>'pause_integration','error_code'=>(string)$e->getCode(),'error_message'=>$e->getMessage(),'context'=>['group_id'=>$group->id]]);}}});
        Product::where('boost_enabled',true)->update(['boost_status'=>'off']);
    }
}
