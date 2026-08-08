<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Models\PaymentProfile;
use App\Services\Payments\PaymentProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentProfileController extends Controller
{
    public function __construct(private PaymentProfileService $service) {}
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
        if ($request->hasFile('qr')) $data['uploaded_qr_path'] = $request->file('qr')->store('private/payment-qr');
        $profile = $this->service->createProfile($request->user(), $data);

        return response()->json($profile, 201);
    }

    public function update(Request $request, int $id)
    {
        $profile = PaymentProfile::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $this->validatePayload($request, true);

        if ($request->hasFile('qr')) $data['uploaded_qr_path'] = $request->file('qr')->store('private/payment-qr');
        $profile = $this->service->updateProfile($request->user(), $profile, $data);

        return response()->json($profile);
    }

    public function destroy(Request $request, int $id)
    {
        $profile = PaymentProfile::where('user_id', $request->user()->id)->findOrFail($id);
        $this->service->deleteProfile($request->user(), $profile);

        return response()->json(['deleted' => true]);
    }

    public function show(Request $request, int $id) { return PaymentProfile::where('user_id',$request->user()->id)->findOrFail($id); }
    public function setDefault(Request $request,int $id) { $p=PaymentProfile::where('user_id',$request->user()->id)->findOrFail($id);$this->service->setDefault($request->user(),$p);return $p->fresh(); }
    public function activate(Request $request,int $id) { $p=PaymentProfile::where('user_id',$request->user()->id)->findOrFail($id);$this->service->activate($request->user(),$p);return $p->fresh(); }
    public function deactivate(Request $request,int $id) { $p=PaymentProfile::where('user_id',$request->user()->id)->findOrFail($id);$this->service->deactivate($request->user(),$p);return $p->fresh(); }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => ['sometimes', 'string', 'in:person_sbp'],
            'receiver_name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'regex:/^\\+7\\d{10}$/'],
            'bank_name' => [$required, 'string', 'max:255'],
            'bank_code' => ['nullable','string','max:50'],
            'payment_link' => ['nullable','url','starts_with:https://','max:2048'],
            'qr' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
            'inn' => ['nullable', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'sbp_qr_url' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
