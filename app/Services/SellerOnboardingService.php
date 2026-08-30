<?php

namespace App\Services;

use App\Models\SellerOnboarding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\ShopRepository;
use Marvel\Enums\Permission;

class SellerOnboardingService
{
    public function eligible(User $user): bool
    {
        return $user->hasPermissionTo(Permission::STORE_OWNER) && !$user->hasPermissionTo(Permission::SUPER_ADMIN);
    }

    public function ensure(User $user, array $attribution = [], bool $registered = false): ?SellerOnboarding
    {
        if (!$this->eligible($user)) return null;

        return DB::transaction(function () use ($user, $attribution, $registered) {
            // Serialize all attempts for this seller, including repeated login and double submits.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $state = SellerOnboarding::where('user_id', $user->id)->first();
            if ($state) return $state;

            // Never filter by active status: a manually created/disabled shop must be reused.
            $shop = Shop::where('owner_id', $user->id)->orderBy('id')->first();
            $existingShop = (bool) $shop;
            if (!$shop) {
                $name = trim($user->name ?: Str::before($user->email, '@'));
                $request = new Request(['name' => $name ?: 'Мой магазин', 'is_active' => true]);
                $request->setUserResolver(fn () => $user);
                $shop = app(ShopRepository::class)->storeShop($request);
            }
            // Legacy sellers with products already use the cabinet, without synthetic conversions.
            $hasProducts = Product::whereIn('shop_id', Shop::where('owner_id', $user->id)->select('id'))->exists();
            $state = SellerOnboarding::create([
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'status' => $hasProducts ? 'completed' : 'in_progress',
                'step' => $hasProducts ? 'success' : ($existingShop ? 'product' : 'shop'),
                'started_at' => now(),
                'shop_completed_at' => $existingShop ? now() : null,
                'completed_at' => $hasProducts ? now() : null,
                'product_request_key' => (string) Str::uuid(),
                'attribution' => $this->attribution($attribution),
            ]);
            if ($registered) $this->event($state, 'seller_registration_completed');
            if (!$hasProducts) $this->event($state, 'seller_onboarding_started');
            return $state;
        }, 3);
    }

    public function attribution(array $input): array
    {
        return collect($input)->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid'])
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(fn ($value) => mb_substr($value, 0, 500))->all();
    }

    public function event(SellerOnboarding $state, string $event, array $extra = []): void
    {
        DB::table('seller_onboarding_events')->insertOrIgnore([
            'user_id' => $state->user_id,
            'event' => $event,
            'payload' => json_encode(array_merge($state->attribution ?? [], [
                'seller_id' => $state->user_id, 'shop_id' => $state->shop_id,
                'product_id' => $state->first_product_id,
            ], $extra), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    public function recordProduct(Product $product, ?User $actor): void
    {
        DB::transaction(function () use ($product, $actor) {
            $state = SellerOnboarding::where('shop_id', $product->shop_id)->lockForUpdate()->first();
            if (!$state || $state->completed_at) return;
            // Admin/import products must not count as seller activation.
            if (!$state->first_product_id && $actor && (int) $actor->id === (int) $state->user_id
                && !$actor->hasPermissionTo(Permission::SUPER_ADMIN)
                && $actor->currentAccessToken()?->name !== 'admin-impersonation') {
                $state->update(['first_product_id' => $product->id, 'step' => 'product']);
                $this->event($state, 'seller_first_product_created');
            }
            if ((int) $state->first_product_id !== (int) $product->id) return;
            if ($product->status === 'publish' && $product->is_active && $product->shop?->is_active) {
                $state->update(['status' => 'completed', 'step' => 'success', 'completed_at' => now(), 'product_draft' => null]);
                $this->event($state, 'seller_first_product_published');
                $this->event($state, 'seller_onboarding_completed');
            }
        }, 3);
    }

    public function present(SellerOnboarding $state): array
    {
        $shop = Shop::findOrFail($state->shop_id);
        $product = $state->first_product_id ? Product::find($state->first_product_id) : null;
        $urls = app(PublicStoreUrl::class);
        return [
            'status' => $state->status, 'step' => $state->step,
            'started_at' => $state->started_at, 'completed_at' => $state->completed_at,
            'shop_completed_at' => $state->shop_completed_at,
            'product_request_key' => $state->product_request_key,
            'draft' => $state->product_draft ?? [], 'draft_version' => $state->draft_version,
            'shop' => ['id' => $shop->id, 'name' => $shop->name, 'slug' => $shop->slug,
                'is_active' => (bool) $shop->is_active, 'url' => $urls->to('/shops/' . rawurlencode($shop->slug))],
            'product' => $product ? [
                'id' => $product->id, 'name' => $product->name, 'slug' => $product->slug,
                'price' => $product->price, 'image' => $product->image,
                'status' => $product->status, 'moderation_status' => $product->moderation_status,
                'visible' => $product->status === 'publish' && $product->is_active && $shop->is_active,
                'url' => $urls->productUrl($product->slug, $product->id),
            ] : null,
        ];
    }
}
