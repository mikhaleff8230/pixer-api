<?php

namespace App\Jobs;

use App\Models\TelegramImportPost;
use App\Services\ProductDraftImportService;
use App\Services\TelegramPricingService;
use App\Services\TelegramProductNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Enums\ProductType;

class ProcessTelegramImportPost implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [15, 60, 300];
    public int $uniqueFor = 300;

    public function __construct(public int $postId)
    {
        $this->onConnection('database');
        $this->onQueue('telegram-import');
    }
    public function uniqueId(): string { return 'telegram-post-'.$this->postId; }

    public function handle(TelegramProductNormalizer $normalizer, TelegramPricingService $pricing, ProductDraftImportService $drafts): void
    {
        $post = TelegramImportPost::with(['channel', 'media'])->findOrFail($this->postId);
        if ($post->product_id || in_array($post->status, ['excluded', 'duplicate'], true)) return;

        $post->update(['status' => 'processing', 'error_message' => null]);
        try {
            $normalized = $normalizer->normalize((string) $post->original_text);
            $price = $pricing->calculate($post->channel, $normalized, $post->markup_percent_override !== null ? (float) $post->markup_percent_override : null);
            $normalized['pricing'] = $price;
            $post->normalized_data = $normalized;

            $categoryId = $post->category_id ?: $post->channel->category_id;
            if (!$price || !$categoryId || !$post->media->count() || $normalized['requires_review']) {
                $reasons = [];
                if (!$price) $reasons[] = 'Не определена цена';
                if (!$categoryId) $reasons[] = 'Не задана категория';
                if (!$post->media->count()) $reasons[] = 'Нет фотографий';
                if ($normalized['missing_fields']) $reasons[] = 'Не хватает: '.implode(', ', $normalized['missing_fields']);
                $post->status = 'review';
                $post->error_message = implode('. ', $reasons);
                $post->processed_at = now();
                $post->save();
                return;
            }

            $type = Type::query()->where('slug', $normalized['product_type'] === 'variable' ? 'variable' : 'simple')->first() ?: Type::query()->first();
            if (!$type) throw new \RuntimeException('В SANCAN не найден тип товара.');
            $images = $post->media->pluck('storage_data')->filter()->values()->all();
            $sku = 'TG'.preg_replace('/\D+/', '', (string) $post->channel->telegram_channel_id).'-'.$post->telegram_message_id;

            $product = $drafts->create([
                'name' => $normalized['name'],
                'description' => $normalized['description'],
                'type_id' => $type->id,
                'shop_id' => $post->channel->shop_id,
                'price' => $price['selling_price'],
                'min_price' => $price['selling_price'],
                'max_price' => $price['selling_price'],
                'product_type' => $normalized['product_type'] === 'variable' ? ProductType::VARIABLE : ProductType::SIMPLE,
                'sku' => substr($sku, 0, 100),
                'preview_url' => $post->source_url,
                'image' => $images[0] ?? null,
                'gallery' => $images,
            ], [
                'source_platform' => 'telegram',
                'telegram_channel_id' => $post->channel->telegram_channel_id,
                'telegram_message_id' => $post->telegram_message_id,
                'telegram_grouped_id' => $post->grouped_id,
                'telegram_post_id' => $post->id,
                'supplier_price' => $price['base_price'],
                'supplier_retail_price' => $normalized['retail_price'],
                'pricing_rule' => json_encode($price, JSON_UNESCAPED_UNICODE),
                'source_attributes' => json_encode(['sizes' => $normalized['sizes'], 'colors' => $normalized['colors']], JSON_UNESCAPED_UNICODE),
                'source_original_text' => $post->original_text,
                'content_rights_confirmed' => true,
            ], [$categoryId]);

            $post->update(['product_id' => $product->id, 'status' => 'draft_created', 'processed_at' => now(), 'error_message' => null]);
        } catch (\Throwable $e) {
            $post->update(['status' => 'error', 'error_message' => $e->getMessage(), 'retry_count' => DB::raw('retry_count + 1')]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        TelegramImportPost::whereKey($this->postId)->update(['status' => 'error', 'error_message' => $exception->getMessage()]);
    }
}
