<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSellerYandexBoostJob;
use App\Models\ProductPromotionStatDaily;
use App\Models\ProductPromotionVisit;
use App\Models\SellerAdBillingEntry;
use App\Models\SellerAdStatDaily;
use App\Models\SellerBalance;
use App\Models\YandexDirectSetting;
use App\Models\SellerYandexAdGroup;
use App\Models\SellerYandexIntensityAudit;
use App\Jobs\UpdateSellerYandexBidModifierJob;
use Carbon\Carbon;
use App\Services\DecimalMoney;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;

class YandexBoostController extends Controller
{
    public function toggle(Request $request, Product $product)
    {
        $data=$request->validate(['enabled'=>'required|boolean']); $user=$request->user();
        abort_unless((int)$product->shop?->owner_id===(int)$user->id||$user->hasPermissionTo('super_admin'),403,'Можно продвигать только собственный товар.');
        abort_unless($product->status === 'publish' && (bool) $product->is_active, 422, 'Продвигать можно только опубликованный активный товар.');
        $settings=YandexDirectSetting::current(); abort_unless($settings->enabled,422,'Продвижение временно недоступно.');
        if($data['enabled']){ $balance=SellerBalance::getOrCreate($product->shop->owner_id); abort_if(DecimalMoney::cents((string)$balance->balance)<=DecimalMoney::cents((string)$settings->balance_reserve),422,'Недостаточно средств для продвижения.'); }
        $product->forceFill(['boost_enabled'=>$data['enabled'],'boost_status'=>$data['enabled']?'starting':'stopping','boost_started_at'=>$data['enabled']?now():$product->boost_started_at,'boost_stopped_at'=>$data['enabled']?null:now(),'boost_last_error'=>null])->save();
        SyncSellerYandexBoostJob::dispatchAfterResponse((int)$product->shop->owner_id);
        return ['success'=>true,'boost_enabled'=>(bool)$product->boost_enabled,'boost_status'=>$product->boost_status];
    }

    public function dashboard(Request $request)
    {
        $seller = $request->user();
        $balance = SellerBalance::getOrCreate($seller->id);
        $shopId = $request->integer('shop_id') ?: null;
        $shops = Shop::query()
            ->where('owner_id', $seller->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        if ($shopId) {
            abort_unless($shops->contains('id', $shopId), 403, 'Этот магазин не принадлежит продавцу.');
        }

        [$dateFrom,$dateTo,$period]=$this->period($request);
        $sortBy = (string) $request->get('sort_by', '');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $productQuery = Product::query()
            ->whereHas('shop', fn ($query) => $query->where('owner_id', $seller->id))
            ->where('status', 'publish')
            ->where('is_active', true)
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%' . $request->string('search')->trim() . '%'));

        $paidClicksSubquery = ProductPromotionVisit::query()
            ->where('seller_id', $seller->id)
            ->where('source', 'yandex')
            ->where('type', 'paid_click')
            ->whereBetween('created_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()])
            ->selectRaw('product_id, COUNT(*) as paid_clicks')
            ->groupBy('product_id');

        $productsQuery = (clone $productQuery)
            ->leftJoinSub($paidClicksSubquery, 'promotion_paid_clicks', fn ($join) => $join->on('products.id', '=', 'promotion_paid_clicks.product_id'))
            ->select('products.*')
            ->selectRaw('COALESCE(promotion_paid_clicks.paid_clicks, 0) as promotion_paid_visits')
            ->with(['shop:id,name,slug,owner_id', 'type:id,name'])
            ->when($sortBy === 'paid_visits', fn ($query) => $query->orderBy('promotion_paid_visits', $sortOrder))
            ->when($sortBy !== 'paid_visits', fn ($query) => $query->latest('products.created_at'))
            ->orderBy('products.id', 'desc');

        $products = $productsQuery->paginate(min(max($request->integer('limit', 20), 1), 100));
        $products->getCollection()->transform(function ($product) {
            $serializedProduct = $product->toArray();
            $serializedProduct['promotion_stats'] = [
                'yandex_clicks' => (int) $product->promotion_paid_visits,
            ];
            unset($serializedProduct['promotion_paid_visits']);

            return $serializedProduct;
        });
        $stats=SellerAdStatDaily::where('seller_id',$seller->id)->whereBetween('date',[$dateFrom,$dateTo]);
        $settings=YandexDirectSetting::current();$group=SellerYandexAdGroup::where('seller_id',$seller->id)->where('campaign_id',$settings->campaign_id)->first();
        return ['balance'=>$balance->balance,'active_products'=>(clone $productQuery)->where('boost_enabled',true)->count(),'spent'=>SellerAdBillingEntry::where('seller_id',$seller->id)->where('status','charged')->whereBetween('period_to',[$dateFrom->copy()->startOfDay(),$dateTo->copy()->endOfDay()])->sum('seller_charge'),'impressions'=>(clone $stats)->sum('impressions'),'clicks'=>(clone $stats)->sum('clicks'),'period'=>['key'=>$period,'date_from'=>$dateFrom->toDateString(),'date_to'=>$dateTo->toDateString()],'intensity'=>['bid_level'=>(float)($group?->bid_level?:$settings->default_bid_level),'allowed_levels'=>$settings->levels(),'default_level'=>(float)$settings->default_bid_level],'shops'=>$shops,'selected_shop_id'=>$shopId,'products'=>$products];
    }

    public function updateIntensity(Request $request)
    {
        $settings=YandexDirectSetting::current();$level=(float)$request->validate(['bid_level'=>'required|numeric'])['bid_level'];
        abort_unless(in_array($level,$settings->levels(),true),422,'Выберите разрешённый уровень интенсивности.');
        abort_if($level>(float)$settings->campaign_bid_ceiling,422,'Уровень не может превышать максимальную интенсивность кампании.');
        $group=SellerYandexAdGroup::firstOrCreate(['seller_id'=>$request->user()->id,'campaign_id'=>$settings->campaign_id,'slot'=>1],['feed_id'=>$settings->feed_id,'status'=>'pending','bid_level'=>$settings->default_bid_level]);
        $old=(float)$group->bid_level;$modifier=$settings->modifierFor($level);$group->update(['bid_level'=>$level]);
        SellerYandexIntensityAudit::create(['seller_id'=>$request->user()->id,'old_level'=>$old,'new_level'=>$level,'calculated_modifier'=>$modifier,'source'=>'seller']);
        UpdateSellerYandexBidModifierJob::dispatch($request->user()->id)->delay(now()->addSeconds(3));
        return ['success'=>true,'bid_level'=>$level,'modifier'=>$modifier,'message'=>'Интенсивность сохранена и будет применена в течение нескольких секунд.'];
    }

    private function period(Request $request): array
    {
        $key=(string)$request->get('period','today');$today=today();
        return match($key){'yesterday'=>[$today->copy()->subDay(),$today->copy()->subDay(),$key],'7d'=>[$today->copy()->subDays(6),$today,$key],'30d'=>[$today->copy()->subDays(29),$today,$key],'custom'=>[Carbon::parse($request->validate(['date_from'=>'required|date','date_to'=>'required|date|after_or_equal:date_from'])['date_from']),Carbon::parse($request->input('date_to')),$key],default=>[$today,$today,'today']};
    }

    public function bulkToggle(Request $request)
    {
        $data = $request->validate([
            'product_ids' => 'required|array|min:1|max:100',
            'product_ids.*' => 'required|integer|distinct|exists:products,id',
            'enabled' => 'required|boolean',
        ]);
        $seller = $request->user();
        $products = Product::query()
            ->whereIn('id', $data['product_ids'])
            ->whereHas('shop', fn ($query) => $query->where('owner_id', $seller->id))
            ->where('status', 'publish')
            ->where('is_active', true)
            ->get();
        abort_unless($products->count() === count($data['product_ids']), 422, 'Продвигать можно только собственные опубликованные активные товары.');

        $settings = YandexDirectSetting::current();
        abort_unless($settings->enabled, 422, 'Продвижение временно недоступно.');
        if ($data['enabled']) {
            $balance = SellerBalance::getOrCreate($seller->id);
            abort_if(DecimalMoney::cents((string) $balance->balance) <= DecimalMoney::cents((string) $settings->balance_reserve), 422, 'Недостаточно средств для продвижения.');
        }

        $updates = [
            'boost_enabled' => $data['enabled'],
            'boost_status' => $data['enabled'] ? 'starting' : 'stopping',
            'boost_last_error' => null,
        ];
        if ($data['enabled']) {
            $updates['boost_started_at'] = now();
            $updates['boost_stopped_at'] = null;
        } else {
            $updates['boost_stopped_at'] = now();
        }
        Product::whereIn('id', $products->pluck('id'))->update($updates);
        SyncSellerYandexBoostJob::dispatchAfterResponse((int) $seller->id);

        return ['success' => true, 'updated' => $products->count()];
    }

    public function track(Request $request)
    {
        $data=$request->validate(['product_id'=>'required|integer|exists:products,id','yclid'=>'nullable|string|max:255']);$product=Product::with('shop')->findOrFail($data['product_id']);
        $daily=ProductPromotionStatDaily::firstOrCreate(['product_id'=>$product->id,'date'=>today()->toDateString()],['seller_id'=>$product->shop->owner_id,'views'=>0,'yandex_clicks'=>0]);$daily->increment('views');
        $created=false;if(!empty($data['yclid'])){$visit=ProductPromotionVisit::firstOrCreate(['yclid'=>$data['yclid']],['product_id'=>$product->id,'seller_id'=>$product->shop->owner_id,'source'=>'yandex','type'=>'paid_click','ip_hash'=>hash('sha256',(string)$request->ip())]);$created=$visit->wasRecentlyCreated;if($created)$daily->increment('yandex_clicks');}
        return response()->json(['success'=>true],$created?201:200);
    }
}
