<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\ProductVideo;
use Marvel\Helpers\VideoOptimizer;
use RuntimeException;
use Throwable;

class OptimizeProductVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;
    public int $backoff = 10;
    public bool $failOnTimeout = true;

    public function __construct(
        public int $videoId,
        public array $previousVideoIds = []
    ) {
    }

    public function handle(): void
    {
        $video = ProductVideo::find($this->videoId);
        if (!$video) {
            return;
        }

        $video->update([
            'processing_status' => 'processing',
            'processing_error' => null,
        ]);

        if (!VideoOptimizer::optimizeVideo($video)) {
            throw new RuntimeException('Не удалось проверить или обработать видео.');
        }

        $video->refresh();
        $video->update([
            'processing_status' => 'ready',
            'processing_error' => null,
        ]);

        ProductVideo::query()
            ->whereIn('id', $this->previousVideoIds)
            ->where('product_id', $video->product_id)
            ->get()
            ->each
            ->delete();

        $this->invalidateCatalogCache();

        Log::info('OptimizeProductVideo: video is ready', [
            'video_id' => $video->id,
            'product_id' => $video->product_id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $video = ProductVideo::find($this->videoId);
        if (!$video) {
            return;
        }

        $video->update([
            'processing_status' => 'failed',
            'processing_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);

        $fallback = ProductVideo::query()
            ->whereIn('id', $this->previousVideoIds)
            ->where('product_id', $video->product_id)
            ->latest('id')
            ->first();

        if ($fallback && $video->product) {
            $video->product->setMeta('video_as_cover', true);
            $video->product->setMeta('cover_video_id', $fallback->id);
        }

        $this->invalidateCatalogCache();

        Log::error('OptimizeProductVideo: processing failed', [
            'video_id' => $video->id,
            'product_id' => $video->product_id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function invalidateCatalogCache(): void
    {
        try {
            $key = 'products_catalog_version';
            if (!Cache::has($key)) {
                Cache::forever($key, 1);
            }
            Cache::increment($key);
        } catch (Throwable $exception) {
            Log::warning('OptimizeProductVideo: cache invalidation failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}