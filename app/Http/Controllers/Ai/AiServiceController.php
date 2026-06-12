<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiService;
use App\Models\AiServiceJob;
use App\Services\AiServiceManager;
use App\Services\SellerBalanceService;
use Illuminate\Http\Request;

class AiServiceController extends Controller
{
    public function services(Request $request, AiServiceManager $manager)
    {
        return response()->json([
            'services' => $manager->getAvailableServices($request->user()),
        ]);
    }

    public function createJob(Request $request, AiServiceManager $manager, SellerBalanceService $balanceService)
    {
        $data = $request->validate([
            'service_code' => ['required', 'string', 'exists:ai_services,code'],
            'product_id' => ['nullable', 'integer'],
            'product_image_id' => ['nullable', 'integer'],
            'input_image_url' => ['nullable', 'string'],
            'context' => ['nullable', 'array'],
        ]);

        $service = AiService::where('code', $data['service_code'])->where('is_active', true)->firstOrFail();
        $payload = array_merge($data['context'] ?? [], $data);
        unset($payload['context']);

        $job = $manager->createJob($request->user(), $service, $payload);

        return response()->json([
            'job_id' => $job->id,
            'status' => $job->status,
            'cost' => (float)$job->cost,
            'currency' => $job->currency,
            'balance' => $balanceService->getCurrentBalance($request->user()),
        ], 201);
    }

    public function showJob(Request $request, int $id)
    {
        $job = AiServiceJob::with('service')
            ->where('seller_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json($job);
    }

    public function confirm(Request $request, int $id, AiServiceManager $manager, SellerBalanceService $balanceService)
    {
        $job = AiServiceJob::with('service')
            ->where('seller_id', $request->user()->id)
            ->findOrFail($id);

        $job = $manager->confirmAndCharge($job);

        return response()->json([
            'job_id' => $job->id,
            'status' => $job->status,
            'charged' => (float)$job->cost,
            'currency' => $job->currency,
            'balance_after' => $balanceService->getCurrentBalance($request->user()),
            'message' => 'Средства списаны, AI-задача запущена.',
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        $job = AiServiceJob::where('seller_id', $request->user()->id)->findOrFail($id);

        if (!in_array($job->status, ['draft', 'waiting_confirmation'], true)) {
            return response()->json([
                'message' => 'Можно отменить только неподтвержденную AI-задачу.',
            ], 422);
        }

        $job->update(['status' => 'cancelled']);

        return response()->json($job->fresh());
    }

    public function apply(Request $request, int $id, AiServiceManager $manager)
    {
        $job = AiServiceJob::with('service')
            ->where('seller_id', $request->user()->id)
            ->findOrFail($id);

        try {
            $result = $manager->applyJobResult($job);

            return response()->json(array_merge(['success' => true], $result));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $id, AiServiceManager $manager)
    {
        $job = AiServiceJob::with('service')
            ->where('seller_id', $request->user()->id)
            ->findOrFail($id);

        try {
            return response()->json([
                'success' => true,
                'data' => $manager->rejectJobResult($job),
                'message' => 'AI-результат отклонён. Оригинальное фото не изменено.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sellerJobs(Request $request)
    {
        $query = AiServiceJob::with('service')
            ->where('seller_id', $request->user()->id);

        if ($request->filled('product_id')) {
            $query->where('product_id', (int)$request->get('product_id'));
        }

        return response()->json(
            $query->orderByDesc('created_at')
                ->paginate((int)$request->get('limit', 20))
        );
    }
}
