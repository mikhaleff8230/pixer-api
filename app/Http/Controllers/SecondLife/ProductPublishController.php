<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Services\SellerComplianceService;
use App\Services\ProductPublicationService;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;

class ProductPublishController extends Controller
{
    public function __invoke(Request $request, int $id, SellerComplianceService $compliance)
    {
        $product = Product::findOrFail($id);
        if ($product->moderation_status !== null) {
            abort_unless($request->user()->hasPermissionTo('super_admin') || (int) $product->shop?->owner_id === (int) $request->user()->id, 403);
        }
        $warnings = $compliance->getWarnings($request->user(), $product);

        if (!empty($warnings)) {
            return response()->json([
                'published' => false,
                'warnings' => $warnings,
            ], 422);
        }

        if ($product->moderation_status !== null) {
            $publish = new Request(['status' => 'publish']);
            $publish->setUserResolver(fn () => $request->user());
            $product->forceFill(app(ProductPublicationService::class)->attributes($publish, $product))->save();
            return response()->json(['published' => $product->status === 'publish' && $product->is_active && $product->shop?->is_active, 'product' => $product->fresh()]);
        }
        $product->forceFill([
            'is_published' => true,
            'is_active' => true,
        ])->save();

        return response()->json([
            'published' => true,
            'product' => $product->fresh(),
        ]);
    }
}
