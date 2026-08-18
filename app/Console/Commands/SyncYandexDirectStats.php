<?php

namespace App\Console\Commands;

use App\Models\SellerAdStatDaily;
use App\Models\SellerYandexAdGroup;
use App\Models\YandexDirectErrorLog;
use App\Models\YandexDirectSetting;
use App\Services\YandexDirect\YandexDirectBillingService;
use App\Services\YandexDirect\YandexDirectService;
use Illuminate\Console\Command;

class SyncYandexDirectStats extends Command
{
    protected $signature='yandex-direct:sync-stats'; protected $description='Синхронизировать статистику и списания SANCAN Boost';
    public function handle(YandexDirectService $direct,YandexDirectBillingService $billing):int
    {
        $settings=YandexDirectSetting::current();if(!$settings->enabled){$this->info('Интеграция выключена.');return self::SUCCESS;}
        if($settings->last_sync_at&&$settings->last_sync_at->addMinutes((int)$settings->sync_interval_minutes)->isFuture()){$this->info('Интервал синхронизации ещё не истёк.');return self::SUCCESS;}
        SellerYandexAdGroup::whereNotNull('ad_group_id')->orderBy('id')->chunkById(100,function($groups)use($direct,$billing){
            $from=$groups->min(fn($g)=>$g->created_at)->format('Y-m-d');$to=now()->format('Y-m-d');
            try{$ids=$groups->pluck('ad_group_id')->all();$report=$direct->getGroupStats($ids,$from,$to);$dailyReport=$direct->getGroupStats($ids,$to,$to);}catch(\Throwable $e){YandexDirectErrorLog::create(['operation'=>'reports_batch','error_code'=>(string)$e->getCode(),'error_message'=>$e->getMessage(),'context'=>['groups'=>$groups->pluck('id')->all()]]);$this->error($e->getMessage());return;}
            foreach($groups as $group){try{$row=$report[$group->ad_group_id]??['cost'=>(string)$group->last_yandex_cost];$daily=$dailyReport[$group->ad_group_id]??['impressions'=>0,'clicks'=>0,'cost'=>'0.00'];$billing->charge($group,$row['cost'],now());$sellerCost=\App\Models\SellerAdBillingEntry::where('seller_yandex_ad_group_id',$group->id)->where('status','charged')->whereDate('created_at',today())->sum('seller_charge');SellerAdStatDaily::updateOrCreate(['seller_yandex_ad_group_id'=>$group->id,'date'=>today()->toDateString()],['seller_id'=>$group->seller_id,'impressions'=>$daily['impressions'],'clicks'=>$daily['clicks'],'yandex_cost'=>$daily['cost'],'seller_cost'=>$sellerCost]);}catch(\Throwable $e){YandexDirectErrorLog::create(['seller_id'=>$group->seller_id,'operation'=>'billing_group','error_code'=>(string)$e->getCode(),'error_message'=>$e->getMessage(),'context'=>['group_id'=>$group->id]]);$this->error("Seller {$group->seller_id}: {$e->getMessage()}");}}
        });
        $settings->update(['last_sync_at'=>now(),'last_error'=>null]);return self::SUCCESS;
    }
}
