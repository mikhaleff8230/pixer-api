<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Models\PaymentProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentProfileController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            PaymentProfile::where('user_id', $request->user()->id)
                ->orderByDesc('is_default')
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data['user_id'] = $request->user()->id;

        $profile = DB::transaction(function () use ($data, $request) {
            if (!empty($data['is_default'])) {
                PaymentProfile::where('user_id', $request->user()->id)->update(['is_default' => false]);
            }

            $profile = PaymentProfile::create($data);

            if (!PaymentProfile::where('user_id', $request->user()->id)->where('is_default', true)->exists()) {
                $profile->update(['is_default' => true]);
            }

            return $profile->fresh();
        });

        return response()->json($profile, 201);
    }

    public function update(Request $request, int $id)
    {
        $profile = PaymentProfile::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $this->validatePayload($request, true);

        $profile = DB::transaction(function () use ($profile, $data, $request) {
            if (($data['is_default'] ?? false) === true) {
                PaymentProfile::where('user_id', $request->user()->id)->update(['is_default' => false]);
            }

            $profile->update($data);
            return $profile->fresh();
        });

        return response()->json($profile);
    }

    public function destroy(Request $request, int $id)
    {
        $profile = PaymentProfile::where('user_id', $request->user()->id)->findOrFail($id);
        $profile->delete();

        return response()->json(['deleted' => true]);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => [$required, 'string', 'in:person_sbp,self_employed_sbp,ip_sbp,company_sbp'],
            'receiver_name' => [$required, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'sbp_qr_url' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
