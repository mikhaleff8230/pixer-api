<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Models\SellerAgreement;
use App\Models\SellerBalanceTransaction;
use App\Models\SellerRating;
use Illuminate\Http\Request;

class SellerController extends Controller
{
    public function balance(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'seller_balance' => (float)($user->seller_balance ?? 0),
            'seller_debt' => (float)($user->seller_debt ?? 0),
            'seller_status' => $user->seller_status ?? 'inactive',
        ]);
    }

    public function balanceTransactions(Request $request)
    {
        return response()->json(
            SellerBalanceTransaction::where('seller_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->paginate((int)$request->get('limit', 20))
        );
    }

    public function rating(Request $request)
    {
        $rating = SellerRating::firstOrCreate(
            ['seller_id' => $request->user()->id],
            ['completed_orders' => 0, 'cancelled_orders' => 0, 'disputes_count' => 0, 'rating_score' => 0, 'verification_score' => 0]
        );

        return response()->json($rating);
    }

    public function acceptAgreement(Request $request)
    {
        $data = $request->validate([
            'agreement_type' => ['required', 'string', 'in:tax_responsibility,direct_payment,product_origin,platform_rules'],
        ]);

        $agreement = SellerAgreement::create([
            'seller_id' => $request->user()->id,
            'agreement_type' => $data['agreement_type'],
            'accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => (string)$request->userAgent(),
        ]);

        if ($data['agreement_type'] === 'tax_responsibility') {
            $request->user()->forceFill([
                'seller_agreed_tax_responsibility' => true,
                'seller_agreed_at' => now(),
            ])->save();
        }

        return response()->json($agreement, 201);
    }
}
