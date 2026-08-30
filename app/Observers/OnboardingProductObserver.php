<?php

namespace App\Observers;

use App\Models\SellerOnboarding;
use App\Services\ProductPublicationService;
use App\Services\SellerOnboardingService;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;

class OnboardingProductObserver
{
    public function updating(Product $product): void
    {
        // Existing products retain their original publication behavior.
        if ($product->getRawOriginal('moderation_status') === null || !request()->user()) return;
        if (!$product->isDirty('status') && !request()->filled('status')) return;
        $original = new Product;
        $original->setRawAttributes($product->getRawOriginal(), true);
        $request = new Request(['status' => request()->input('status', $product->status), 'shop_id' => $product->shop_id]);
        $request->setUserResolver(fn () => request()->user());
        $product->forceFill(app(ProductPublicationService::class)->attributes($request, $original));
    }

    public function saved(Product $product): void
    {
        if (SellerOnboarding::where('shop_id', $product->shop_id)->whereNull('completed_at')->exists()) {
            app(SellerOnboardingService::class)->recordProduct($product, request()->user());
        }
    }
}
