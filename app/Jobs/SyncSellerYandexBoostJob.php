<?php

namespace App\Jobs;

use App\Models\SellerBalance;
use App\Models\SellerYandexAdGroup;
use App\Models\YandexDirectErrorLog;
use App\Models\YandexDirectSetting;
use App\Services\YandexDirect\YandexDirectService;
use App\Services\DecimalMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;

class SyncSellerYandexBoostJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 4;
    public array $backoff = [30, 120, 600];
    public int $uniqueFor = 60;
    public function __construct(public int $sellerId, public bool $allowApiErrorRecovery = true) {}
    public function uniqueId(): string { return 'yandex-boost-' . $this->sellerId; }

    public function handle(): void
    {
        Cache::lock($this->uniqueId(), 120)->block(5, function () {
            $settings = YandexDirectSetting::current();
            if (!$settings->enabled) return;
            $seller = User::withoutGlobalScopes()->find($this->sellerId);
            $ids = Product::query()->whereHas('shop',fn($q)=>$q->where('owner_id',$this->sellerId))->where('boost_enabled',true)->where('status','publish')->where('is_active',true)->where(function($q){$q->where('in_stock','>',0)->orWhere('quantity','>',0);})->orderBy('id')->pluck('id')->map(fn($id)=>(int)$id)->all();
            Product::whereHas('shop',fn($q)=>$q->where('owner_id',$this->sellerId))->where('boost_enabled',false)->where('boost_status','stopping')->update(['boost_status'=>'off','boost_last_error'=>null]);
            Product::whereHas('shop',fn($q)=>$q->where('owner_id',$this->sellerId))->where('boost_enabled',true)->when($ids,fn($q)=>$q->whereNotIn('id',$ids))->update(['boost_status'=>'off']);
            $group = SellerYandexAdGroup::where(['seller_id'=>$this->sellerId,'campaign_id'=>$settings->campaign_id,'slot'=>1])->first();
            if (!$group && !$ids) return;
            $group ??= SellerYandexAdGroup::create(['seller_id'=>$this->sellerId,'campaign_id'=>$settings->campaign_id,'slot'=>1,'feed_id'=>$settings->feed_id,'status'=>'pending','bid_level'=>$settings->default_bid_level]);
            $balance = SellerBalance::getOrCreate($this->sellerId);
            $direct = new YandexDirectService($settings);

            try {
                if($settings->bid_ceiling_sync_status!=='synced'||(float)$settings->applied_bid_ceiling!==(float)$settings->desired_bid_ceiling){if($group->shopping_ad_id)$direct->pauseShoppingAd((int)$group->shopping_ad_id);$group->update(['status'=>'paused','pause_reason'=>'strategy_not_synced','last_error'=>'Потолок ЕПК не подтверждён Direct API.']);return;}
                if ($group->pause_reason === 'admin' || ($group->pause_reason === 'api_error' && !$this->allowApiErrorRecovery)) return;
                if (!$seller || !$seller->is_active) { if($group->shopping_ad_id)$direct->pauseShoppingAd((int)$group->shopping_ad_id); $group->update(['status'=>'paused','pause_reason'=>'seller_blocked','last_sync_at'=>now()]); return; }
                if (!$ids) { if($group->shopping_ad_id)$direct->pauseShoppingAd((int)$group->shopping_ad_id); $group->update(['status'=>'paused','pause_reason'=>'no_boost_products','boost_filter_hash'=>null,'last_sync_at'=>now(),'last_error'=>null]); return; }
                if (DecimalMoney::cents((string)$balance->balance)<DecimalMoney::cents((string)$settings->balance_reserve)) { if($group->shopping_ad_id)$direct->pauseShoppingAd((int)$group->shopping_ad_id); $group->update(['status'=>'paused','pause_reason'=>'insufficient_balance','last_sync_at'=>now()]); return; }
                if (!$group->ad_group_id) $group->ad_group_id=$direct->createSellerAdGroup($this->sellerId);
                if(!$group->bid_level)$group->bid_level=$settings->default_bid_level;
                if (!$group->shopping_ad_id) $group->shopping_ad_id=$direct->createShoppingAd((int)$group->ad_group_id,$ids);
                $hash=hash('sha256',implode(',',$ids));
                if($group->boost_filter_hash!==$hash)$direct->updateShoppingAdProducts((int)$group->shopping_ad_id,$ids);
                $direct->submitShoppingAdForModerationIfDraft((int)$group->shopping_ad_id);
                if($group->status!=='active')$direct->resumeShoppingAd((int)$group->shopping_ad_id);
                $group->fill(['feed_id'=>$settings->feed_id,'status'=>'active','pause_reason'=>null,'boost_filter_hash'=>$hash,'last_sync_at'=>now(),'last_error'=>null])->save();
                UpdateSellerYandexBidModifierJob::dispatch($this->sellerId)->delay(now()->addSeconds(3));
                Product::whereIn('id',$ids)->update(['boost_status'=>'on','boost_last_error'=>null]);
            } catch (\Throwable $e) {
                $group->update(['status'=>'error','pause_reason'=>'api_error','last_error'=>$e->getMessage(),'last_sync_at'=>now()]);
                Product::whereIn('id',$ids)->update(['boost_status'=>'error','boost_last_error'=>$e->getMessage()]);
                YandexDirectErrorLog::create(['seller_id'=>$this->sellerId,'operation'=>'sync_seller_boost','error_code'=>(string)$e->getCode(),'error_message'=>$e->getMessage(),'context'=>['group_id'=>$group->id,'product_count'=>count($ids)]]);
                throw $e;
            }
        });
    }
}
