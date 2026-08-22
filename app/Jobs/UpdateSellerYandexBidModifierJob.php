<?php
namespace App\Jobs;

use App\Models\SellerYandexAdGroup;
use App\Models\YandexDirectErrorLog;
use App\Models\YandexDirectSetting;
use App\Services\YandexDirect\YandexDirectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateSellerYandexBidModifierJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $uniqueFor=10;
    public function __construct(public int $sellerId) {}
    public function uniqueId(): string { return 'seller-yandex-intensity-'.$this->sellerId; }
    public function handle(): void
    {
        $settings=YandexDirectSetting::current();
        $group=SellerYandexAdGroup::where('seller_id',$this->sellerId)->where('campaign_id',$settings->campaign_id)->first();
        if(!$settings->enabled||$settings->strategy_sync_status!=='applied'||!$group?->ad_group_id)return;
        try {
            $modifier=$settings->modifierFor((float)$group->bid_level);
            $result=(new YandexDirectService($settings))->applyAdGroupAdjustment((int)$group->ad_group_id,$modifier);
            $group->update(['last_applied_bid_modifier'=>$modifier,'bid_modifier_id'=>$result['id']??$group->bid_modifier_id,'last_error'=>null]);
        } catch (\Throwable $e) {
            $group->update(['last_error'=>$e->getMessage()]);
            YandexDirectErrorLog::create(['seller_id'=>$this->sellerId,'operation'=>'update_bid_modifier','error_code'=>(string)$e->getCode(),'error_message'=>$e->getMessage(),'context'=>['group_id'=>$group->id]]);
            throw $e;
        }
    }
}
