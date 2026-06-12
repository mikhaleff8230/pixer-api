<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Services\SellerComplianceService;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;

class ProductPublishController extends Controller
{
    public function __invoke(Request $request, int $id, SellerComplianceService $compliance)
    {
        $product = Product::findOrFail($id);
        $warnings = $compliance->getWarnings($request->user(), $product);

        if (!empty($warnings)) {
            return response()->json([
                'published' => false,
                'warnings' => $warnings,
            ], 422);
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
