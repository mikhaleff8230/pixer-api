<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerAdBillingEntry;
use App\Models\SellerAdStatDaily;
use App\Models\SellerYandexAdGroup;
use App\Models\YandexDirectErrorLog;
use App\Models\YandexDirectSetting;
use App\Services\YandexDirect\YandexDirectService;
use App\Jobs\SyncSellerYandexBoostJob;
use App\Jobs\PauseAllYandexBoostJob;
use App\Jobs\UpdateSellerYandexBidModifierJob;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;

class YandexDirectController extends Controller
{
    public function show(): array { return ['settings' => YandexDirectSetting::current()->safeArray(), 'monitor' => $this->monitorData(), 'errors' => YandexDirectErrorLog::latest()->limit(20)->get()]; }

    public function update(Request $request)
    {
        $data = $request->validate(['enabled'=>'required|boolean','oauth_token'=>'nullable|string|min:20','client_login'=>'nullable|string|max:255','campaign_id'=>'required|integer|min:1','feed_id'=>'required|integer|min:1','markup_percent'=>'required|numeric|min:0|max:500','balance_reserve'=>'required|numeric|min:0','sync_interval_minutes'=>'required|integer|min:5|max:1440','campaign_bid_ceiling'=>'required|numeric|min:1','default_bid_level'=>'required|numeric|min:1','allowed_bid_levels'=>'required|array|min:1','allowed_bid_levels.*'=>'numeric|min:1']);
        $levels=array_values(array_unique(array_map('floatval',$data['allowed_bid_levels'])));sort($levels);abort_if(max($levels)>(float)$data['campaign_bid_ceiling'],422,'Разрешённый уровень не может превышать максимум кампании.');abort_unless(in_array((float)$data['default_bid_level'],$levels,true),422,'Уровень по умолчанию должен быть разрешён.');$data['allowed_bid_levels']=$levels;
        $settings = YandexDirectSetting::current();
        $wasEnabled = $settings->enabled;
        if (empty($data['oauth_token'])) unset($data['oauth_token']);
        $settings->fill($data);
        if ($settings->enabled) (new YandexDirectService($settings))->testConnection();
        $settings->last_error = null;
        $ceilingChanged=$settings->isDirty('campaign_bid_ceiling');$levelsChanged=$settings->isDirty('allowed_bid_levels');$settings->save();
        if($ceilingChanged||$levelsChanged){SellerYandexAdGroup::whereNotNull('ad_group_id')->each(function($group)use($settings,$levels){if(!in_array((float)$group->bid_level,$levels,true)){$nearest=collect($levels)->sortBy(fn($v)=>abs($v-(float)$group->bid_level))->first();$group->update(['bid_level'=>$nearest]);}UpdateSellerYandexBidModifierJob::dispatch($group->seller_id)->delay(now()->addSeconds(3));});}
        if($wasEnabled&&!$settings->enabled)PauseAllYandexBoostJob::dispatchAfterResponse();
        if(!$wasEnabled&&$settings->enabled)Product::where('boost_enabled',true)->with('shop:id,owner_id')->get()->pluck('shop.owner_id')->filter()->unique()->each(fn($sellerId)=>SyncSellerYandexBoostJob::dispatchAfterResponse((int)$sellerId));
        return ['message' => 'Настройки Яндекс Директа сохранены.', 'settings' => $settings->fresh()->safeArray()];
    }

    public function test(Request $request)
    {
        $settings = YandexDirectSetting::current();
        $candidate=$request->validate(['oauth_token'=>'nullable|string','client_login'=>'nullable|string|max:255','campaign_id'=>'nullable|integer|min:1','feed_id'=>'nullable|integer|min:1']);
        if(empty($candidate['oauth_token']))unset($candidate['oauth_token']);
        $settings->fill($candidate);
        try {
            $result = (new YandexDirectService($settings))->testConnection();
            YandexDirectSetting::current()->update(['last_connection_check_at' => now(), 'last_error' => null]);
            return ['success' => true] + $result;
        } catch (\Throwable $e) {
            YandexDirectSetting::current()->update(['last_connection_check_at' => now(), 'last_error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function errors(Request $request) { return YandexDirectErrorLog::latest()->paginate($request->integer('limit', 30)); }

    public function syncStrategy()
    {
        $settings=YandexDirectSetting::current();
        try{$result=(new YandexDirectService($settings))->syncCampaignBidCeiling();$settings->update(['strategy_sync_status'=>'applied','strategy_synced_at'=>now(),'last_error'=>null]);SellerYandexAdGroup::whereNotNull('ad_group_id')->each(fn($group)=>UpdateSellerYandexBidModifierJob::dispatch($group->seller_id)->delay(now()->addSeconds(3)));return ['success'=>true,'strategy'=>$result];}
        catch(\Throwable $e){$settings->update(['strategy_sync_status'=>'error','last_error'=>$e->getMessage()]);return response()->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    public function groupAction(Request $request, SellerYandexAdGroup $group)
    {
        $data=$request->validate(['action'=>'required|in:pause,resume']);
        if($data['action']==='pause'){if($group->shopping_ad_id)(new YandexDirectService)->pauseShoppingAd((int)$group->shopping_ad_id);$group->update(['status'=>'paused','pause_reason'=>'admin']);}
        else{$group->update(['status'=>'pending','pause_reason'=>null]);SyncSellerYandexBoostJob::dispatch($group->seller_id);}
        return ['success'=>true,'group'=>$group->fresh()];
    }

    private function monitorData(): array
    {
        return ['active_seller_groups'=>SellerYandexAdGroup::where('status','active')->count(),'active_boost_products'=>Product::where('boost_enabled',true)->where('status','publish')->count(),'yandex_cost_today'=>SellerAdStatDaily::whereDate('date',today())->sum('yandex_cost'),'seller_charged_today'=>SellerAdBillingEntry::whereDate('created_at',today())->where('status','charged')->sum('seller_charge'),'intensity_distribution'=>SellerYandexAdGroup::selectRaw('bid_level,COUNT(*) as sellers')->groupBy('bid_level')->orderBy('bid_level')->get(),'last_sync_at'=>YandexDirectSetting::current()->last_sync_at];
    }
}
