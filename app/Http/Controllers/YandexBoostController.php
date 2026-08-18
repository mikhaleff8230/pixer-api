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
        $seller=$request->user();$balance=SellerBalance::getOrCreate($seller->id);
        $products=Product::whereHas('shop',fn($q)=>$q->where('owner_id',$seller->id))->latest()->paginate($request->integer('limit',20));
        $productIds=$products->getCollection()->pluck('id');$productStats=ProductPromotionStatDaily::whereIn('product_id',$productIds)->selectRaw('product_id, SUM(views) as views, SUM(yandex_clicks) as yandex_clicks')->groupBy('product_id')->get()->keyBy('product_id');
        $products->getCollection()->transform(function($product)use($productStats){$product->promotion_stats=$productStats->get($product->id)?:['views'=>0,'yandex_clicks'=>0];return $product;});
        $stats=SellerAdStatDaily::where('seller_id',$seller->id);
        return ['balance'=>$balance->balance,'active_products'=>Product::whereHas('shop',fn($q)=>$q->where('owner_id',$seller->id))->where('boost_enabled',true)->count(),'spent'=>SellerAdBillingEntry::where('seller_id',$seller->id)->where('status','charged')->sum('seller_charge'),'impressions'=>(clone $stats)->sum('impressions'),'clicks'=>(clone $stats)->sum('clicks'),'products'=>$products];
    }

    public function track(Request $request)
    {
        $data=$request->validate(['product_id'=>'required|integer|exists:products,id','yclid'=>'nullable|string|max:255']);$product=Product::with('shop')->findOrFail($data['product_id']);
        $daily=ProductPromotionStatDaily::firstOrCreate(['product_id'=>$product->id,'date'=>today()->toDateString()],['seller_id'=>$product->shop->owner_id,'views'=>0,'yandex_clicks'=>0]);$daily->increment('views');
        $created=false;if(!empty($data['yclid'])){$visit=ProductPromotionVisit::firstOrCreate(['yclid'=>$data['yclid']],['product_id'=>$product->id,'seller_id'=>$product->shop->owner_id,'source'=>'yandex','type'=>'paid_click','ip_hash'=>hash('sha256',(string)$request->ip())]);$created=$visit->wasRecentlyCreated;if($created)$daily->increment('yandex_clicks');}
        return response()->json(['success'=>true],$created?201:200);
    }
}
