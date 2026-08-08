<?php

namespace Marvel\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ProductVideo;
use Marvel\Database\Models\Product;

class VideoOptimizer
{
    /**
     * Оптимизировать видео: создать превью, постер и thumbnail
     */
    public static function optimizeVideo(ProductVideo $video, $videoPath = null)
    {
        try {
            // Если путь не передан, пытаемся получить из S3
            if (!$videoPath) {
                // Проверяем, есть ли файл в S3
                if (Storage::disk('s3')->exists($video->url)) {
                    // Скачиваем временно для обработки
                    $tempPath = storage_path('app/temp/' . basename($video->url));
                    $dir = dirname($tempPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($tempPath, Storage::disk('s3')->get($video->url));
                    $videoPath = $tempPath;
                } else {
                    Log::warning('VideoOptimizer: Video file not found in S3', ['url' => $video->url]);
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

            // 1. Создаем 3-секундное превью
            $previewFileName = "products/videos/preview/{$videoId}.mp4";
            $previewPath = storage_path('app/temp/' . basename($previewFileName));
            $previewDir = dirname($previewPath);
            if (!is_dir($previewDir)) {
                mkdir($previewDir, 0755, true);
            }
            
            $previewDuration = min(3, $duration);
            $videoFile
                ->filters()
                ->clip(\FFMpeg\Coordinate\TimeCode::fromSeconds(0), \FFMpeg\Coordinate\TimeCode::fromSeconds($previewDuration));
            
            $videoFile->save(new \FFMpeg\Format\Video\X264(), $previewPath);
            
            // Загружаем превью в S3
            if (file_exists($previewPath)) {
                Storage::disk('s3')->put($previewFileName, file_get_contents($previewPath), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => 'video/mp4',
                ]);
                unlink($previewPath);
            }
            
            // 2. Создаем постер (WebP) из первого кадра
            $posterFileName = "products/videos/poster/{$videoId}.webp";
            $posterPath = storage_path('app/temp/' . basename($posterFileName));
            $posterDir = dirname($posterPath);
            if (!is_dir($posterDir)) {
                mkdir($posterDir, 0755, true);
            }
            
            $frame = $videoFile->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(0));
            $frame->save($posterPath);
            
            // Загружаем постер в S3
            if (file_exists($posterPath)) {
                Storage::disk('s3')->put($posterFileName, file_get_contents($posterPath), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => 'image/webp',
                ]);
                unlink($posterPath);
            }
            
            // 3. Создаем thumbnail (JPEG) для списков
            $thumbnailFileName = "products/videos/thumbnail/{$videoId}.jpg";
            $thumbnailPath = storage_path('app/temp/' . basename($thumbnailFileName));
            $thumbnailDir = dirname($thumbnailPath);
            if (!is_dir($thumbnailDir)) {
                mkdir($thumbnailDir, 0755, true);
            }
            
            $frame = $videoFile->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(min(1, $duration / 2)));
            $frame->save($thumbnailPath);
            
            // Загружаем thumbnail в S3
            if (file_exists($thumbnailPath)) {
                Storage::disk('s3')->put($thumbnailFileName, file_get_contents($thumbnailPath), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => 'image/jpeg',
                ]);
                unlink($thumbnailPath);
            }
            
            // Удаляем временный файл
            if ($videoPath && str_contains($videoPath, 'app/temp/')) {
                @unlink($videoPath);
            }
            
            // Обновляем запись в базе
            $video->update([
                'preview_url' => $previewFileName,
                'poster_url' => $posterFileName,
                'thumbnail_url' => $thumbnailFileName,
                'duration' => round($duration, 2),
                'width' => $width,
                'height' => $height,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
            ]);
            
            // Обновляем запись после сохранения, чтобы accessor'ы работали правильно
            $video->refresh();

            // Если установлен флаг "Сделать обложкой", обновляем image товара
            $product = $video->product;
            if ($product && $product->getMeta('video_as_cover') && $product->getMeta('cover_video_id') == $video->id) {
                // Используем постер видео как первое изображение
                // Используем прямой доступ к атрибуту, так как accessor может вернуть null
                $posterUrl = $video->attributes['poster_url'] ?? $video->poster_url;
                
                if ($posterUrl) {
                    // Строим полный URL для постера
                    $fullPosterUrl = self::buildFullUrl($posterUrl);
                    
                    $currentImage = $product->image;
                    $currentGallery = $product->gallery ?? [];
                    
                    // Если image - массив, берем первый элемент
                    if (is_array($currentImage)) {
                        $firstImage = $currentImage[0] ?? null;
                    } else {
                        $firstImage = $currentImage;
                    }
                    
                    // Создаем новую структуру изображений с постером видео первым
                    $newImage = [
                        'thumbnail' => $fullPosterUrl,
                        'original' => $fullPosterUrl,
                        'id' => null,
                    ];
                    
                    // Если есть существующее изображение, добавляем его в gallery
                    if ($firstImage) {
                        $newGallery = array_merge([$newImage], [$firstImage], $currentGallery);
                    } else {
                        $newGallery = array_merge([$newImage], $currentGallery);
                    }
                    
                    $product->update([
                        'image' => $newImage,
                        'gallery' => $newGallery,
                    ]);
                }
            }

            Log::info('VideoOptimizer: Video optimized successfully', [
                'video_id' => $video->id,
                'product_id' => $video->product_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('VideoOptimizer: Error optimizing video', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Удаляем временный файл при ошибке
            if (isset($videoPath) && $videoPath && str_contains($videoPath, 'app/temp/')) {
                @unlink($videoPath);
            }
            
            return false;
        }
    }
    
    /**
     * Построить полный URL (копия из ProductVideo)
     */
    private static function buildFullUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        $url = \App\Support\MediaUrl::publicUrl($path);
        if ($url) {
            return $url;
        }

        if (str_starts_with($path, '/storage/')) {
            return 'https://api.sancan.ru' . $path;
        }

        return 'https://api.sancan.ru/storage/' . ltrim($path, '/');
    }
}

