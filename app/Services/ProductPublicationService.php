<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Enums\Permission;

/** Publication policy for onboarding-created products; legacy products are unchanged. */
class ProductPublicationService
{
    public function attributes(Request $request, ?Product $product = null): array
    {
        $actor = $request->user();
        $admin = $actor && $actor->hasPermissionTo(Permission::SUPER_ADMIN);
        $requested = $request->input('status', $product?->status ?? 'draft');
        if ($product && !$request->filled('status')) {
            return [];
        }

        if ($admin) {
            $status = $requested === 'approved' ? 'publish' : $requested;
            return [
                'status' => $status,
                'moderation_status' => match ($requested) {
                    'approved', 'publish' => 'approved',
                    'rejected' => 'rejected',
                    'unpublish' => 'hidden',
                    'under_review' => 'pending',
                    default => $product?->moderation_status ?? 'not_required',
                },
                'is_published' => $status === 'publish',
            ];
        }

        if (in_array($requested, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Решение о проверке принимает администратор.']);
        }
        if (in_array($requested, ['draft', 'unpublish'], true)) {
            return ['status' => $requested, 'is_published' => false];
        }

        $shop = Shop::findOrFail($product?->shop_id ?? $request->input('shop_id'));
        $seller = $shop->owner;
        if (!$shop->is_active || !$seller?->is_active || in_array($seller->seller_status, ['blocked', 'limited'], true)) {
            throw ValidationException::withMessages(['status' => 'Публикация временно недоступна. Обратитесь в поддержку.']);
        }
        if ($product && (in_array($product->moderation_status, ['rejected', 'hidden'], true) || in_array($product->status, ['rejected'], true))) {
            // Editing cannot undo an administrator's decision.
            return ['status' => 'under_review', 'moderation_status' => $product->moderation_status ?: 'rejected', 'is_published' => false];
        }
        $policy = config('seller_onboarding.publication_policy', 'post_moderation');
        $reviewFirst = $policy === 'all' || ($policy === 'new_sellers' && !Product::where('shop_id', $shop->id)
            ->where('moderation_status', 'approved')->exists());
        if (!in_array($policy, ['off', 'post_moderation', 'new_sellers', 'all'], true)) {
            $reviewFirst = true; // Fail closed on configuration errors.
        }
        return [
            'status' => $reviewFirst ? 'under_review' : 'publish',
            'moderation_status' => $policy === 'off' ? 'not_required' : 'pending',
            'is_published' => !$reviewFirst,
        ];
    }
}
