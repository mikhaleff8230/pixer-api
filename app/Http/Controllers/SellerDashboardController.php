<?php

namespace App\Http\Controllers;

use App\Models\ProductPromotionStatDaily;
use App\Models\SellerAdBillingEntry;
use App\Models\SellerAdStatDaily;
use App\Models\SellerBalance;
use App\Models\SellerBalanceTransaction;
use App\Models\YandexDirectSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Message;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;

class SellerDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'period' => 'nullable|integer|in:7,30,90',
            'shop_id' => 'nullable|integer',
        ]);

        $seller = $request->user();
        $days = (int) ($data['period'] ?? 30);
        $shopId = isset($data['shop_id']) ? (int) $data['shop_id'] : null;

        $shops = Shop::query()
            ->where('owner_id', $seller->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'logo', 'cover_image']);

        if ($shopId) {
            abort_unless($shops->contains('id', $shopId), 403, 'Этот магазин не принадлежит продавцу.');
        }

        return Cache::remember(
            "seller_dashboard:{$seller->id}:" . ($shopId ?: 'all') . ":{$days}",
            now()->addSeconds(30),
            fn () => $this->buildDashboard($seller, $shops, $shopId, $days)
        );
    }

    private function buildDashboard($seller, Collection $shops, ?int $shopId, int $days): array
    {
        $dateTo = today();
        $dateFrom = today()->subDays($days - 1);
        $previousTo = $dateFrom->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);
        $shopIds = $shopId ? collect([$shopId]) : $shops->pluck('id');
        $selectedShop = $shopId ? $shops->firstWhere('id', $shopId) : $shops->first();

        $productScope = Product::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'publish')
            ->where('is_active', true);
        $allProductsScope = Product::query()->whereIn('shop_id', $shopIds);
        $productIds = (clone $productScope)->select('id');

        $currentAd = $this->adSummary($seller->id, $dateFrom, $dateTo);
        $previousAd = $this->adSummary($seller->id, $previousFrom, $previousTo);
        $currentProduct = $this->productSummary($seller->id, $productIds, $dateFrom, $dateTo);
        $previousProduct = $this->productSummary($seller->id, $productIds, $previousFrom, $previousTo);
        $currentMessages = $this->messageQuery($seller->id, $shopIds)
            ->whereBetween('messages.created_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()])
            ->count();
        $previousMessages = $this->messageQuery($seller->id, $shopIds)
            ->whereBetween('messages.created_at', [$previousFrom->copy()->startOfDay(), $previousTo->copy()->endOfDay()])
            ->count();
        $unreadMessages = $this->messageQuery($seller->id, $shopIds)
            ->whereNull('messages.read_at')
            ->count();

        $summary = [
            'impressions' => (int) $currentAd->impressions,
            'clicks' => (int) $currentAd->clicks,
            'product_views' => (int) $currentProduct->views,
            'messages' => (int) $currentMessages,
        ];
        $previous = [
            'impressions' => (int) $previousAd->impressions,
            'clicks' => (int) $previousAd->clicks,
            'product_views' => (int) $previousProduct->views,
            'messages' => (int) $previousMessages,
        ];

        $chart = $this->chart($seller->id, $shopIds, $productIds, $dateFrom, $dateTo);
        $balance = SellerBalance::getOrCreate($seller->id);
        $activeProducts = (clone $productScope)->where('boost_enabled', true)->count();
        // The seller-facing counter represents every product card the seller
        // has actually created, including drafts and temporarily inactive items.
        $totalProducts = (clone $allProductsScope)->count();
        $settings = YandexDirectSetting::current();
        $adSpentTotal = (float) SellerAdBillingEntry::query()
            ->where('seller_id', $seller->id)
            ->where('status', 'charged')
            ->sum('seller_charge');
        $bonusTotal = (float) SellerBalanceTransaction::query()
            ->where('seller_id', $seller->id)
            ->where('amount', '>', 0)
            ->where(function (Builder $query) {
                $query->where('type', 'bonus')
                    ->orWhere('description', 'like', '%бонус%')
                    ->orWhere('description', 'like', '%bonus%');
            })
            ->sum('amount');
        $advertisingStatus = $this->advertisingStatus(
            (float) $balance->balance,
            $activeProducts,
            (bool) $settings->enabled
        );

        $topProducts = $this->topProducts($productScope, $seller->id, $dateFrom, $dateTo);
        $recommendations = $this->recommendations(
            $seller,
            $selectedShop,
            $totalProducts,
            $activeProducts,
            $unreadMessages,
            (float) $balance->balance
        );
        $todayPoint = collect($chart)->firstWhere('date', today()->toDateString()) ?? [
            'impressions' => 0,
            'clicks' => 0,
            'product_views' => 0,
            'messages' => 0,
        ];

        return [
            'period' => [
                'days' => $days,
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'shop' => $selectedShop ? [
                'id' => (int) $selectedShop->id,
                'name' => $selectedShop->name,
                'slug' => $selectedShop->slug,
            ] : null,
            'shops' => $shops->map(fn ($shop) => [
                'id' => (int) $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
            ])->values(),
            'summary' => $summary,
            'growth' => collect($summary)->mapWithKeys(fn ($value, $key) => [
                $key => $this->growth((int) $value, (int) $previous[$key]),
            ]),
            'chart' => $chart,
            'advertising' => [
                'status' => $advertisingStatus,
                'balance' => (float) $balance->balance,
                'total_deposited' => (float) $balance->total_deposited,
                'total_spent' => (float) $balance->total_spent,
                'period_spent' => (float) SellerAdBillingEntry::query()
                    ->where('seller_id', $seller->id)
                    ->where('status', 'charged')
                    ->whereBetween('period_to', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()])
                    ->sum('seller_charge'),
                'bonus_total' => $bonusTotal > 0 ? $bonusTotal : null,
                'bonus_spent' => $bonusTotal > 0 ? min($bonusTotal, $adSpentTotal) : null,
                'active_products' => $activeProducts,
                'available' => (bool) $settings->enabled,
            ],
            'today' => [
                'impressions' => (int) $todayPoint['impressions'],
                'clicks' => (int) $todayPoint['clicks'],
                'product_views' => (int) $todayPoint['product_views'],
                'messages' => (int) $todayPoint['messages'],
            ],
            'products_count' => $totalProducts,
            'unread_messages' => $unreadMessages,
            'products' => $topProducts,
            'recommendations' => $recommendations,
            'onboarding' => [
                'completed' => collect([$shops->isNotEmpty(), $totalProducts > 0, $activeProducts > 0])->filter()->count(),
                'total' => 3,
                'steps' => [
                    ['label' => 'Создать магазин', 'complete' => $shops->isNotEmpty()],
                    ['label' => 'Добавить товар', 'complete' => $totalProducts > 0],
                    ['label' => 'Запустить продвижение', 'complete' => $activeProducts > 0],
                ],
            ],
            'data_scope' => $shopId && $shops->count() > 1 ? [
                'advertising' => 'seller',
                'products_and_messages' => 'shop',
            ] : [
                'advertising' => 'shop',
                'products_and_messages' => 'shop',
            ],
        ];
    }

    private function adSummary(int $sellerId, Carbon $from, Carbon $to): object
    {
        return SellerAdStatDaily::query()
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks')
            ->first();
    }

    private function productSummary(int $sellerId, $productIds, Carbon $from, Carbon $to): object
    {
        return ProductPromotionStatDaily::query()
            ->where('seller_id', $sellerId)
            ->whereIn('product_id', $productIds)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(views), 0) as views, COALESCE(SUM(yandex_clicks), 0) as clicks')
            ->first();
    }

    private function messageQuery(int $sellerId, Collection $shopIds): Builder
    {
        return Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.shop_id', $shopIds)
            ->where('messages.user_id', '!=', $sellerId);
    }

    private function chart(int $sellerId, Collection $shopIds, $productIds, Carbon $from, Carbon $to): array
    {
        $ads = SellerAdStatDaily::query()
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());
        $productViews = ProductPromotionStatDaily::query()
            ->where('seller_id', $sellerId)
            ->whereIn('product_id', $productIds)
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->selectRaw('date, SUM(views) as views')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());
        $messages = $this->messageQuery($sellerId, $shopIds)
            ->whereBetween('messages.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy(DB::raw('DATE(messages.created_at)'))
            ->selectRaw('DATE(messages.created_at) as date, COUNT(*) as total')
            ->pluck('total', 'date');

        $points = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $points[] = [
                'date' => $key,
                'impressions' => (int) ($ads->get($key)?->impressions ?? 0),
                'clicks' => (int) ($ads->get($key)?->clicks ?? 0),
                'product_views' => (int) ($productViews->get($key)?->views ?? 0),
                'messages' => (int) ($messages->get($key) ?? 0),
            ];
        }

        return $points;
    }

    private function topProducts($productScope, int $sellerId, Carbon $from, Carbon $to): array
    {
        $stats = ProductPromotionStatDaily::query()
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('product_id, SUM(views) as views, SUM(yandex_clicks) as clicks')
            ->groupBy('product_id');

        $products = (clone $productScope)
            ->leftJoinSub($stats, 'dashboard_stats', fn ($join) => $join->on('products.id', '=', 'dashboard_stats.product_id'))
            ->select(['products.id', 'products.name', 'products.slug', 'products.image', 'products.shop_id'])
            ->selectRaw('COALESCE(dashboard_stats.views, 0) as dashboard_views')
            ->selectRaw('COALESCE(dashboard_stats.clicks, 0) as dashboard_clicks')
            ->orderByDesc('dashboard_views')
            ->orderByDesc('dashboard_clicks')
            ->orderByDesc('products.created_at')
            ->limit(6)
            ->get();
        $averageCtr = $products->sum('dashboard_views') > 0
            ? ($products->sum('dashboard_clicks') / $products->sum('dashboard_views')) * 100
            : 0;

        return $products->map(function ($product) use ($averageCtr) {
            $views = (int) $product->dashboard_views;
            $clicks = (int) $product->dashboard_clicks;
            $ctr = $views > 0 ? round(($clicks / $views) * 100, 1) : 0;

            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'image' => $product->image,
                'views' => $views,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'messages' => null,
                'status' => $this->productStatus($views, $clicks, $ctr, $averageCtr),
            ];
        })->values()->all();
    }

    private function productStatus(int $views, int $clicks, float $ctr, float $averageCtr): string
    {
        if ($views === 0) {
            return 'no_data';
        }
        if ($clicks > 0 && ($averageCtr === 0.0 || $ctr >= max(1, $averageCtr * 1.15))) {
            return 'good_interest';
        }
        if ($views >= 20 && ($clicks === 0 || $ctr < $averageCtr * 0.7)) {
            return 'improve_photo';
        }

        return 'stable';
    }

    private function recommendations($seller, $shop, int $products, int $activeProducts, int $unread, float $balance): array
    {
        $seller->loadMissing('profile');
        $recommendations = collect();

        if ($unread > 0) {
            $recommendations->push(['key' => 'messages', 'title' => 'Ответьте покупателям', 'description' => "Непрочитанных сообщений: {$unread}", 'priority' => 100]);
        }
        if ($products === 0) {
            $recommendations->push(['key' => 'first_product', 'title' => 'Добавьте первый товар', 'description' => 'После публикации можно запустить продвижение.', 'priority' => 95]);
        } elseif ($products < 4) {
            $remaining = 4 - $products;
            $recommendations->push(['key' => 'products', 'title' => "Добавьте ещё {$remaining} " . $this->plural($remaining, 'товар', 'товара', 'товаров'), 'description' => 'Больше товаров — больше точек входа для покупателей.', 'priority' => 70]);
        }
        if ($products > 0 && $activeProducts === 0) {
            $recommendations->push(['key' => 'promotion', 'title' => 'Запустите продвижение', 'description' => 'Товары начнут получать показы из внешней рекламы.', 'priority' => 90]);
        }
        if ($activeProducts > 0 && $balance < 20) {
            $recommendations->push(['key' => 'balance', 'title' => 'Пополните рекламный баланс', 'description' => 'Продвижение скоро остановится.', 'priority' => 92]);
        }
        if (!$seller->profile || empty($seller->profile->contact)) {
            $recommendations->push(['key' => 'profile', 'title' => 'Заполните профиль', 'description' => 'Добавьте контактные данные продавца.', 'priority' => 50]);
        }
        if ($shop && empty($shop->description)) {
            $recommendations->push(['key' => 'shop_description', 'title' => 'Добавьте описание магазина', 'description' => 'Расскажите покупателям о товарах и подходе.', 'priority' => 45]);
        }

        return $recommendations
            ->sortByDesc('priority')
            ->take(4)
            ->map(fn ($item) => collect($item)->except('priority')->all())
            ->values()
            ->all();
    }

    private function advertisingStatus(float $balance, int $activeProducts, bool $available): string
    {
        if (!$available) {
            return 'unavailable';
        }
        if ($activeProducts === 0) {
            return 'ready';
        }
        if ($balance <= 0) {
            return 'stopped';
        }
        if ($balance < 20) {
            return 'warning';
        }
        if ($balance <= 50) {
            return 'low';
        }

        return 'active';
    }

    private function growth(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function plural(int $number, string $one, string $few, string $many): string
    {
        $mod100 = $number % 100;
        $mod10 = $number % 10;
        if ($mod100 >= 11 && $mod100 <= 19) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }
}
