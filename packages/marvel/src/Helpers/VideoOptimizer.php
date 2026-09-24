<?php

namespace Marvel\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ProductVideo;

class VideoOptimizer
{
    /**
     * Оптимизировать видео: создать превью, постер и thumbnail
     */
    public static function optimizeVideo(ProductVideo $video, $videoPath = null)
    {
        $generatedKeys = [];
        $recordUpdated = false;

        try {
            // Если путь не передан, пытаемся получить из S3
            if (!$videoPath) {
                // Проверяем, есть ли файл в S3
                $sourceKey = $video->getRawOriginal('url');
                if ($sourceKey && Storage::disk('s3')->exists($sourceKey)) {
                    // Скачиваем временно для обработки
                    $tempPath = storage_path('app/temp/' . basename($sourceKey));
                    $dir = dirname($tempPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($tempPath, Storage::disk('s3')->get($sourceKey));
                    $videoPath = $tempPath;
                } else {
                    Log::warning('VideoOptimizer: Video file not found in S3', ['url' => $sourceKey]);
                    return false;
                }
            }

            if (!file_exists($videoPath)) {
                Log::warning('VideoOptimizer: Video file not found', ['path' => $videoPath]);
                return false;
            }

            // Проверяем, есть ли FFmpeg
            if (!class_exists('FFMpeg\FFMpeg')) {
                Log::warning('VideoOptimizer: FFmpeg not available, skipping video processing');
                return false;
            }

            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries'  => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
                'timeout'          => 120,
                'ffmpeg.threads'   => 4,
            ]);

            $ffprobe = \FFMpeg\FFProbe::create([
                'ffprobe.binaries' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
            ]);

            $videoFile = $ffmpeg->open($videoPath);
            
            // Получаем метаданные
            $duration = $ffprobe->format($videoPath)->get('duration');
            $streams = $ffprobe->streams($videoPath)->videos()->first();
            $width = $streams->get('width');
            $height = $streams->get('height');
            $fileSize = filesize($videoPath);
            $mimeType = mime_content_type($videoPath);

            $videoId = $video->id;

            // Создаём web-совместимую полную версию: MP4/H.264/AAC + faststart.
            // При любой ошибке оригинал остаётся рабочим fallback.
            $originalKey = $video->getRawOriginal('url');
            $optimizedFileName = "products/videos/optimized/{$videoId}.mp4";
            $optimizedPath = storage_path("app/temp/optimized_{$videoId}.mp4");
            $optimizedDir = dirname($optimizedPath);
            if (!is_dir($optimizedDir)) {
                mkdir($optimizedDir, 0755, true);
            }
            $optimizedKey = null;

            try {
                $webVideo = $ffmpeg->open($videoPath);
                $maxDimension = max((int) $width, (int) $height);
                if ($maxDimension > 1280) {
                    $scale = 1280 / $maxDimension;
                    $targetWidth = max(2, (int) floor(((int) $width * $scale) / 2) * 2);
                    $targetHeight = max(2, (int) floor(((int) $height * $scale) / 2) * 2);
                    $webVideo->filters()->resize(
                        new \FFMpeg\Coordinate\Dimension($targetWidth, $targetHeight),
                        \FFMpeg\Filters\Video\ResizeFilter::RESIZEMODE_INSET,
                        true
                    );
                }

                $webFormat = (new \FFMpeg\Format\Video\X264('aac', 'libx264'))
                    ->setKiloBitrate(1800)
                    ->setAudioKiloBitrate(128)
                    ->setPasses(1)
                    ->setAdditionalParameters(['-movflags', '+faststart', '-pix_fmt', 'yuv420p']);
                $webVideo->save($webFormat, $optimizedPath);

                if (file_exists($optimizedPath)) {
                    $stored = Storage::disk('s3')->put(
                        $optimizedFileName,
                        file_get_contents($optimizedPath),
                        [
                            'visibility' => 'public',
                            'CacheControl' => 'public, max-age=86400',
                            'ContentType' => 'video/mp4',
                        ]
                    );
                    if ($stored) {
                        $optimizedKey = $optimizedFileName;
                        $generatedKeys[] = $optimizedFileName;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('VideoOptimizer: full web conversion failed; using original', [
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if (file_exists($optimizedPath)) {
                    @unlink($optimizedPath);
                }
            }

            // 1. Создаем 3-секундное превью
            $previewFileName = "products/videos/preview/{$videoId}.mp4";
            $previewKey = null;
            $previewPath = storage_path("app/temp/preview_{$videoId}.mp4");
            $previewDir = dirname($previewPath);
            if (!is_dir($previewDir)) {
                mkdir($previewDir, 0755, true);
            }
            
            $previewDuration = min(3, $duration);
            $videoFile
                ->filters()
                ->clip(\FFMpeg\Coordinate\TimeCode::fromSeconds(0), \FFMpeg\Coordinate\TimeCode::fromSeconds($previewDuration));
            
            $previewFormat = (new \FFMpeg\Format\Video\X264('aac', 'libx264'))
                ->setPasses(1)
                ->setAdditionalParameters(['-movflags', '+faststart', '-an', '-pix_fmt', 'yuv420p']);
            $videoFile->save($previewFormat, $previewPath);
            
            // Загружаем превью в S3
            if (file_exists($previewPath)) {
                $stored = Storage::disk('s3')->put($previewFileName, file_get_contents($previewPath), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => 'video/mp4',
                ]);
                if ($stored) {
                    $previewKey = $previewFileName;
                    $generatedKeys[] = $previewFileName;
                }
                unlink($previewPath);
            }
            
            // 2. Создаем постер (WebP) из первого кадра
            $posterFileName = "products/videos/poster/{$videoId}.webp";
            $posterKey = null;
            $posterPath = storage_path("app/temp/poster_{$videoId}.webp");
            $posterDir = dirname($posterPath);
            if (!is_dir($posterDir)) {
                mkdir($posterDir, 0755, true);
            }
            
            $frame = $videoFile->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(0));
            $frame->save($posterPath);
            
            // Загружаем постер в S3
            if (file_exists($posterPath)) {
                $stored = Storage::disk('s3')->put($posterFileName, file_get_contents($posterPath), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => 'image/webp',
                ]);
                if ($stored) {
                    $posterKey = $posterFileName;
                    $generatedKeys[] = $posterFileName;
                }
                unlink($posterPath);
            }
            
            // 3. Создаем thumbnail (JPEG) для списков
            $thumbnailFileName = "products/videos/thumbnail/{$videoId}.jpg";
            $thumbnailKey = null;
            $thumbnailPath = storage_path("app/temp/thumbnail_{$videoId}.jpg");
            $thumbnailDir = dirname($thumbnailPath);
            if (!is_dir($thumbnailDir)) {
                mkdir($thumbnailDir, 0755, true);
            }
            
            $frame = $videoFile->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(min(1, $duration / 2)));
            $frame->save($thumbnailPath);
            
            // Загружаем thumbnail в S3
            if (file_exists($thumbnailPath)) {
                $stored = Storage::disk('s3')->put($thumbnailFileName, file_get_contents($thumbnailPath), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => 'image/jpeg',
                ]);
                if ($stored) {
                    $thumbnailKey = $thumbnailFileName;
                    $generatedKeys[] = $thumbnailFileName;
                }
                unlink($thumbnailPath);
            }
            
            // Удаляем временный файл
            if ($videoPath && str_contains($videoPath, 'app/temp/')) {
                @unlink($videoPath);
            }
            
            // Обновляем запись в базе
            $video->update([
                'url' => $optimizedKey ?: $originalKey,
                'preview_url' => $previewKey,
                'poster_url' => $posterKey,
                'thumbnail_url' => $thumbnailKey,
                'duration' => round($duration, 2),
                'width' => $width,
                'height' => $height,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
            ]);
            $recordUpdated = true;
            
            // Обновляем запись после сохранения, чтобы accessor'ы работали правильно
            $video->refresh();

            if ($optimizedKey && $originalKey && $originalKey !== $optimizedKey) {
                try {
                    Storage::disk('s3')->delete($originalKey);
                } catch (\Throwable $e) {
                    Log::warning('VideoOptimizer: failed to remove original after conversion', [
                        'video_id' => $videoId,
                        'original_key' => $originalKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('VideoOptimizer: Video optimized successfully', [
                'video_id' => $video->id,
                'product_id' => $video->product_id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('VideoOptimizer: Error optimizing video', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Удаляем временный файл при ошибке
            if (isset($videoPath) && $videoPath && str_contains($videoPath, 'app/temp/')) {
                @unlink($videoPath);
            }
            foreach (['optimizedPath', 'previewPath', 'posterPath', 'thumbnailPath'] as $pathVariable) {
                if (isset($$pathVariable) && file_exists($$pathVariable)) {
                    @unlink($$pathVariable);
                }
            }
            if (!$recordUpdated) {
                foreach (array_unique($generatedKeys) as $generatedKey) {
                    try {
                        Storage::disk('s3')->delete($generatedKey);
                    } catch (\Throwable $cleanupError) {
                    }
                }
            }
            
            return false;
        }
    }
}
