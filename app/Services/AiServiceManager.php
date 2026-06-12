<?php

namespace App\Services;

use App\Jobs\ProcessAiServiceJob;
use App\Models\AiService;
use App\Models\AiServiceJob;
use App\Models\ProductImage;
use App\Services\Ai\GeminiService;
use App\Services\Ai\OpenAiService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;

class AiServiceManager
{
    public function __construct(
        private SellerBalanceService $balanceService,
        private OpenAiService $openAiService,
        private GeminiService $geminiService
    ) {
    }

    public function getAvailableServices(User $seller, ?Product $product = null)
    {
        $balance = $this->balanceService->getCurrentBalance($seller);

        return AiService::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (AiService $service) use ($balance) {
                return array_merge($service->toArray(), [
                    'seller_balance' => $balance,
                    'can_charge' => $balance >= (float)$service->cost,
                ]);
            });
    }

    public function createJob(User $seller, AiService $service, array $payload): AiServiceJob
    {
        return AiServiceJob::create([
            'seller_id' => $seller->id,
            'product_id' => $payload['product_id'] ?? null,
            'product_image_id' => $payload['product_image_id'] ?? null,
            'ai_service_id' => $service->id,
            'status' => 'waiting_confirmation',
            'provider' => $service->provider,
            'model' => $service->model,
            'input_payload' => $payload,
            'input_image_url' => $payload['input_image_url'] ?? null,
            'cost' => $service->cost,
            'currency' => $service->currency,
        ]);
    }

    public function confirmAndCharge(AiServiceJob $job): AiServiceJob
    {
        return DB::transaction(function () use ($job) {
            $job->refresh();

            if (!in_array($job->status, ['waiting_confirmation', 'draft'], true)) {
                throw new \RuntimeException('AI-задача уже подтверждена или не может быть запущена.');
            }

            $seller = User::findOrFail($job->seller_id);
            $transaction = $this->balanceService->chargeAiService($seller, $job);

            $job->update([
                'status' => 'processing',
                'balance_transaction_id' => $transaction->id,
                'started_at' => now(),
            ]);

            ProcessAiServiceJob::dispatch($job->id);

            return $job->fresh();
        });
    }

    public function processJob(AiServiceJob $job): AiServiceJob
    {
        $job->load('service');
        $service = $job->service;
        $product = $job->product_id ? Product::find($job->product_id) : null;
        $payload = array_merge($job->input_payload ?? [], [
            'model' => $service->model,
            'service_code' => $service->code,
            'input_image_url' => $job->input_image_url ?: ($job->input_payload['input_image_url'] ?? null),
        ]);
        $imageUrl = $job->input_image_url ?: ($payload['input_image_url'] ?? '');

        $provider = $service->provider === 'gemini' ? $this->geminiService : $this->openAiService;

        $result = match ($service->code) {
            'photo_enhance' => $provider->enhancePhoto($imageUrl, $payload),
            'photo_white_background' => $provider->whiteBackground($imageUrl, $payload),
            'photo_marketplace_crop' => method_exists($provider, 'marketplaceCrop')
                ? $provider->marketplaceCrop($imageUrl, $payload)
                : ['message' => 'Marketplace crop provider is not implemented.'],
            'generate_description' => $product
                ? $provider->generateDescription($product, $payload)
                : ['error' => 'Product is required for description generation.'],
            'photo_analyze' => $provider->analyzePhoto($imageUrl, $payload),
            'estimate_price' => $product
                ? $provider->estimatePrice($product, $payload)
                : ['error' => 'Product is required for price estimation.'],
            default => ['error' => 'Unknown AI service code.'],
        };

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        $outputImageUrl = $result['output_image_url'] ?? null;

        $job->update([
            'status' => 'completed',
            'output_payload' => $result,
            'output_image_url' => $outputImageUrl,
            'completed_at' => now(),
            'error_message' => null,
        ]);

        if ($job->product_id && $outputImageUrl) {
            ProductImage::create([
                'product_id' => $job->product_id,
                'url' => $outputImageUrl,
                'is_ai_generated' => true,
                'original_image_id' => $job->product_image_id,
                'ai_service_job_id' => $job->id,
                'image_role' => 'ai_result',
            ]);
        }

        return $job->fresh();
    }

    public function refundJob(AiServiceJob $job, string $reason): AiServiceJob
    {
        $seller = User::findOrFail($job->seller_id);
        $transaction = $this->balanceService->refundAiService($seller, $job, $reason);

        $job->update([
            'status' => 'refunded',
            'error_message' => $reason,
            'balance_transaction_id' => $job->balance_transaction_id ?: $transaction->id,
            'completed_at' => now(),
        ]);

        return $job->fresh();
    }

    public function applyJobResult(AiServiceJob $job): array
    {
        if ($job->status !== 'completed') {
            throw new \RuntimeException('Применить можно только завершённую AI-задачу.');
        }

        $outputUrl = $job->output_image_url;
        if (!$outputUrl) {
            throw new \RuntimeException('У задачи нет изображения для применения.');
        }

        $galleryItem = [
            'thumbnail' => $outputUrl,
            'original' => $outputUrl,
            'url' => $outputUrl,
        ];

        ProductImage::where('ai_service_job_id', $job->id)
            ->where('image_role', 'ai_result')
            ->get()
            ->each(function (ProductImage $image) {
                $image->update([
                    'image_role' => 'applied',
                    'meta' => array_merge($image->meta ?? [], ['applied_at' => now()->toIso8601String()]),
                ]);
            });

        if ($job->product_id) {
            $product = Product::find($job->product_id);
            if ($product) {
                $gallery = is_array($product->gallery) ? $product->gallery : [];
                $alreadyExists = collect($gallery)->contains(function ($item) use ($outputUrl) {
                    $item = is_array($item) ? $item : (array) $item;

                    return ($item['original'] ?? $item['url'] ?? $item['thumbnail'] ?? '') === $outputUrl;
                });

                if (!$alreadyExists) {
                    $gallery[] = $galleryItem;
                    $product->gallery = array_values($gallery);
                    $product->save();
                }
            }
        }

        $outputPayload = $job->output_payload ?? [];
        $outputPayload['applied'] = true;
        $outputPayload['applied_at'] = now()->toIso8601String();
        $job->update(['output_payload' => $outputPayload]);

        return [
            'job_id' => $job->id,
            'gallery_item' => $galleryItem,
            'message' => 'AI-фото добавлено в галерею. Оригинал не изменён.',
        ];
    }

    public function rejectJobResult(AiServiceJob $job): AiServiceJob
    {
        if ($job->status !== 'completed') {
            throw new \RuntimeException('Отклонить можно только завершённую AI-задачу.');
        }

        ProductImage::where('ai_service_job_id', $job->id)
            ->whereIn('image_role', ['ai_result', 'applied'])
            ->get()
            ->each(function (ProductImage $image) {
                $image->update([
                    'image_role' => 'rejected',
                    'meta' => array_merge($image->meta ?? [], ['rejected_at' => now()->toIso8601String()]),
                ]);
            });

        $outputPayload = $job->output_payload ?? [];
        $outputPayload['rejected'] = true;
        $outputPayload['rejected_at'] = now()->toIso8601String();
        $job->update(['output_payload' => $outputPayload]);

        return $job->fresh();
    }
}
