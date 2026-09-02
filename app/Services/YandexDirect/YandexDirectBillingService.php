<?php

namespace App\Services\YandexDirect;

use App\Jobs\SyncSellerYandexBoostJob;
use App\Models\SellerAdBillingEntry;
use App\Models\SellerBalance;
use App\Models\SellerBalanceTransaction;
use App\Models\SellerYandexAdGroup;
use App\Models\YandexDirectSetting;
use App\Services\DecimalMoney;
use Illuminate\Support\Facades\DB;

class YandexDirectBillingService
{
    public function charge(SellerYandexAdGroup $group, string $newCumulativeCost, \DateTimeInterface $syncedAt): ?SellerAdBillingEntry
    {
        return DB::transaction(function() use($group,$newCumulativeCost,$syncedAt){
            $locked=SellerYandexAdGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $newCents=DecimalMoney::cents($newCumulativeCost);$oldCents=DecimalMoney::cents((string)$locked->last_yandex_cost);$deltaCents=max(0,$newCents-$oldCents);
            if($deltaCents===0){$locked->update(['last_sync_at'=>$syncedAt]);return null;}
            $settings=YandexDirectSetting::current();$periodFrom=$locked->last_sync_at?:$locked->created_at;$periodTo=$syncedAt;
            $entry=SellerAdBillingEntry::firstOrCreate(['seller_yandex_ad_group_id'=>$locked->id,'period_from'=>$periodFrom,'period_to'=>$periodTo],['seller_id'=>$locked->seller_id,'yandex_cost_delta'=>DecimalMoney::decimal($deltaCents),'markup_percent'=>$settings->markup_percent,'seller_charge'=>DecimalMoney::addMarkup(DecimalMoney::decimal($deltaCents),(string)$settings->markup_percent),'status'=>'pending']);
            if($entry->status==='charged')return $entry;
            $balance=SellerBalance::where('seller_id',$locked->seller_id)->lockForUpdate()->first(); if(!$balance)$balance=SellerBalance::create(['seller_id'=>$locked->seller_id,'balance'=>0,'total_deposited'=>0,'total_spent'=>0]);
            $before=DecimalMoney::cents((string)$balance->balance);$charge=DecimalMoney::cents((string)$entry->seller_charge);$after=$before-$charge;
            $balance->update(['balance'=>DecimalMoney::decimal($after),'total_spent'=>DecimalMoney::decimal(DecimalMoney::cents((string)$balance->total_spent)+$charge)]);
            $transaction=SellerBalanceTransaction::create(['seller_id'=>$locked->seller_id,'type'=>'yandex_promotion','amount'=>'-'.DecimalMoney::decimal($charge),'balance_before'=>DecimalMoney::decimal($before),'balance_after'=>DecimalMoney::decimal($after),'description'=>'Продвижение товаров']);
            $entry->update(['balance_transaction_id'=>$transaction->id,'status'=>'charged']);$locked->update(['last_yandex_cost'=>DecimalMoney::decimal($newCents),'last_sync_at'=>$syncedAt]);
            $seller=$locked->seller;$seller?->forceFill(['seller_balance'=>DecimalMoney::decimal($after)])->save();
            if($after<DecimalMoney::cents((string)$settings->balance_reserve))SyncSellerYandexBoostJob::dispatch($locked->seller_id)->afterCommit();
            return $entry;
        });
    }
}
