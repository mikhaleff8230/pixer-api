<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSellerYandexBoostJob;
use App\Models\ProductPromotionStatDaily;
use App\Models\ProductPromotionVisit;
use App\Models\SellerAdBillingEntry;
use App\Models\SellerAdStatDaily;
use App\Models\SellerBalance;
use App\Models\YandexDirectSetting;
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

        $productQuery = Product::query()
            ->whereHas('shop', fn ($query) => $query->where('owner_id', $seller->id))
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%' . $request->string('search')->trim() . '%'));

        $products = (clone $productQuery)
            ->with(['shop:id,name,slug,owner_id', 'type:id,name'])
            ->latest()
            ->paginate(min(max($request->integer('limit', 20), 1), 100));
        $productIds=$products->getCollection()->pluck('id');$productStats=ProductPromotionStatDaily::whereIn('product_id',$productIds)->selectRaw('product_id, SUM(views) as views, SUM(yandex_clicks) as yandex_clicks')->groupBy('product_id')->get()->keyBy('product_id');
        $products->getCollection()->transform(function($product)use($productStats){$product->promotion_stats=$productStats->get($product->id)?:['views'=>0,'yandex_clicks'=>0];return $product;});
        $stats=SellerAdStatDaily::where('seller_id',$seller->id);
        return ['balance'=>$balance->balance,'active_products'=>(clone $productQuery)->where('boost_enabled',true)->count(),'spent'=>SellerAdBillingEntry::where('seller_id',$seller->id)->where('status','charged')->sum('seller_charge'),'impressions'=>(clone $stats)->sum('impressions'),'clicks'=>(clone $stats)->sum('clicks'),'shops'=>$shops,'selected_shop_id'=>$shopId,'products'=>$products];
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
            ->get();
        abort_unless($products->count() === count($data['product_ids']), 403, 'Можно изменять только собственные товары.');

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
