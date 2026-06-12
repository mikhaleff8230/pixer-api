<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Models\PaymentProfile;
use App\Models\SecondLifeOrder;
use App\Services\SellerBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'payment_method' => ['sometimes', 'string', 'in:direct_sbp,cash,external_delivery,safe_deal'],
        ]);

        $buyer = $request->user();
        $product = Product::findOrFail($data['product_id']);
        $sellerId = $this->resolveSellerId($product);

        if (!$sellerId) {
            return response()->json(['message' => 'У товара не найден продавец.'], 422);
        }

        $paymentProfile = PaymentProfile::where('user_id', $sellerId)
            ->active()
            ->default()
            ->first();

        if (!$paymentProfile) {
            return response()->json(['message' => 'У продавца нет активного платежного профиля.'], 422);
        }

        $order = DB::transaction(function () use ($buyer, $sellerId, $product, $paymentProfile, $data) {
            $price = $product->sale_price ?? $product->price ?? $product->min_price ?? 0;

            $order = SecondLifeOrder::create([
                'buyer_id' => $buyer->id,
                'seller_id' => $sellerId,
                'product_id' => $product->id,
                'payment_profile_id' => $paymentProfile->id,
                'price' => $price,
                'platform_fee' => 0,
                'payment_method' => $data['payment_method'] ?? 'direct_sbp',
                'payment_status' => 'waiting_payment',
                'order_status' => 'reserved',
                'receiver_name' => $paymentProfile->receiver_name,
                'phone' => $paymentProfile->phone,
                'bank_name' => $paymentProfile->bank_name,
                'company_name' => $paymentProfile->company_name,
                'inn' => $paymentProfile->inn,
                'sbp_qr_url' => $paymentProfile->sbp_qr_url,
            ]);

            $product->forceFill(['reserved_at' => now()])->save();

            return $order;
        });

        return response()->json([
            'order' => $order,
            'payment_notice' => [
                'Вы переводите деньги напрямую продавцу.',
                'SANCAN не принимает оплату за товар.',
                'Платформа не является стороной платежа.',
            ],
        ], 201);
    }

    public function markPaid(Request $request, int $id)
    {
        $order = SecondLifeOrder::where('buyer_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'buyer_payment_comment' => ['nullable', 'string'],
            'buyer_payment_screenshot' => ['nullable', 'string'],
        ]);

        $order->update([
            'payment_status' => 'buyer_marked_paid',
            'buyer_payment_comment' => $data['buyer_payment_comment'] ?? $order->buyer_payment_comment,
            'buyer_payment_screenshot' => $data['buyer_payment_screenshot'] ?? $order->buyer_payment_screenshot,
            'buyer_marked_paid_at' => now(),
        ]);

        return response()->json($order->fresh());
    }

    public function confirmPayment(Request $request, int $id, SellerBalanceService $balanceService)
    {
        $order = SecondLifeOrder::where('seller_id', $request->user()->id)->findOrFail($id);

        $order->update([
            'payment_status' => 'seller_confirmed_paid',
            'order_status' => 'paid',
            'seller_confirmed_at' => now(),
        ]);

        return response()->json($order->fresh());
    }

    public function complete(Request $request, int $id)
    {
        $order = SecondLifeOrder::where(function ($query) use ($request) {
                $query->where('buyer_id', $request->user()->id)
                    ->orWhere('seller_id', $request->user()->id);
            })
            ->findOrFail($id);

        $order->update([
            'order_status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json($order->fresh());
    }

    private function resolveSellerId(Product $product): ?int
    {
        if (!empty($product->seller_id)) {
            return (int)$product->seller_id;
        }

        if (!empty($product->user_id)) {
            return (int)$product->user_id;
        }

        if (!empty($product->shop_id)) {
            $shop = Shop::find($product->shop_id);
            if ($shop && !empty($shop->owner_id)) {
                return (int)$shop->owner_id;
            }
            if ($shop && !empty($shop->user_id)) {
                return (int)$shop->user_id;
            }
        }

        return null;
    }
}
