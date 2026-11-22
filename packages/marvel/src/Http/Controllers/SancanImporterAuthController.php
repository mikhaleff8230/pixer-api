<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Marvel\Database\Models\Shop;
use Marvel\Enums\Permission;

class SancanImporterAuthController extends CoreController
{
    private const PAIRING_TTL_MINUTES = 10;
    private const TOKEN_TTL_MINUTES = 30;

    public function requestPairing(Request $request): JsonResponse
    {
        $deviceCode = Str::random(64);
        Cache::put($this->cacheKey($deviceCode), ['status' => 'pending'], now()->addMinutes(self::PAIRING_TTL_MINUTES));

        $adminUrl = rtrim((string) (config('shop.admin_url') ?: env('ADMIN_URL', 'https://seller.sancan.ru')), '/');

        return response()->json([
            'device_code' => $deviceCode,
            'verification_uri' => $adminUrl.'/extension/connect?device_code='.urlencode($deviceCode),
            'expires_in' => self::PAIRING_TTL_MINUTES * 60,
            'interval' => 2,
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate(['device_code' => ['required', 'string', 'size:64']]);
        $key = $this->cacheKey($validated['device_code']);
        $pairing = Cache::get($key);

        if (!$pairing) {
            return response()->json(['status' => 'expired'], 410);
        }
        if (($pairing['status'] ?? null) !== 'approved') {
            return response()->json(['status' => 'pending'], 202);
        }

        Cache::forget($key);

        return response()->json($pairing);
    }

    public function shops(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            $shops = Shop::query()->with('owner:id,name,email')->orderBy('name')->limit(1000)->get();
        } elseif ($user->hasPermissionTo(Permission::STORE_OWNER)) {
            $shops = $user->shops()->with('owner:id,name,email')->orderBy('name')->get();
        } elseif ($user->hasPermissionTo(Permission::STAFF) && $user->shop_id) {
            $shops = Shop::query()->with('owner:id,name,email')->whereKey($user->shop_id)->get();
        } else {
            abort(403, 'Нет доступных магазинов.');
        }

        return response()->json(['shops' => $shops->map(fn (Shop $shop) => $this->shopPayload($shop))->values()]);
    }

    public function approve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'size:64'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
        ]);
        $key = $this->cacheKey($validated['device_code']);
        $pairing = Cache::get($key);
        abort_unless($pairing, 410, 'Код подключения истёк. Начните подключение заново из расширения.');
        abort_unless(($pairing['status'] ?? null) === 'pending', 409, 'Это подключение уже было подтверждено.');

        $user = $request->user('api');
        $shop = Shop::with('owner:id,name,email')->findOrFail($validated['shop_id']);
        abort_unless($this->canAccessShop($user, $shop), 403, 'Нет прав на выбранный магазин.');

        $newToken = $user->createToken(
            'sancan-importer:shop:'.$shop->id,
            ['ozon:import', 'wb:import', 'shop:'.$shop->id],
            now()->addMinutes(self::TOKEN_TTL_MINUTES)
        );

        Cache::put($key, [
            'status' => 'approved',
            'token' => $newToken->plainTextToken,
            'expires_in' => self::TOKEN_TTL_MINUTES * 60,
            'shop' => $this->shopPayload($shop),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], now()->addMinutes(self::PAIRING_TTL_MINUTES));

        return response()->json(['message' => 'Расширение SANCAN Importer подключено.', 'shop' => $this->shopPayload($shop)]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = $request->user('api')?->currentAccessToken();
        if ($token && Str::startsWith((string) $token->name, 'sancan-importer:')) {
            $token->delete();
        }

        return response()->json(['message' => 'Подключение отозвано.']);
    }

    public static function userCanAccessShop($user, Shop $shop): bool
    {
        if (!$user) return false;
        if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) return true;
        if ($user->hasPermissionTo(Permission::STORE_OWNER) && (int) $shop->owner_id === (int) $user->id) return true;
        return $user->hasPermissionTo(Permission::STAFF) && (int) $user->shop_id === (int) $shop->id;
    }

    private function canAccessShop($user, Shop $shop): bool
    {
        return self::userCanAccessShop($user, $shop);
    }

    private function shopPayload(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'owner' => $shop->owner ? ['id' => $shop->owner->id, 'name' => $shop->owner->name, 'email' => $shop->owner->email] : null,
        ];
    }

    private function cacheKey(string $deviceCode): string
    {
        return 'sancan-importer:pairing:'.hash('sha256', $deviceCode);
    }
}
