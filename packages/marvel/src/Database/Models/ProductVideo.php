<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductVideo extends Model
{
    protected $fillable = [
        'product_id',
        'url',
        'preview_url',
        'poster_url',
        'thumbnail_url',
        'duration',
        'width',
        'height',
        'file_size',
        'mime_type',
    ];

    protected $casts = [
        'duration' => 'decimal:2',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
    ];

    protected $appends = [
        'video_url',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Получить URL для превью видео (3 секунды)
     */
    public function getPreviewUrlAttribute($value)
    {
        // Если значение уже обработано (полный URL), возвращаем как есть
        if ($value && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        
        // Если есть значение в БД, обрабатываем его
        if ($value) {
            return $this->buildFullUrl($value);
        }
        
        // Fallback к оригинальному видео если превью нет
        if ($this->attributes['url'] ?? null) {
            return $this->buildFullUrl($this->attributes['url']);
        }
        
        return null;
    }

    /**
     * Получить URL для постера (первый кадр)
     */
    public function getPosterUrlAttribute($value)
    {
        // Если значение уже обработано (полный URL), возвращаем как есть
        if ($value && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        
        return $value ? $this->buildFullUrl($value) : null;
    }

    /**
     * Получить URL для thumbnail
     */
    public function getThumbnailUrlAttribute($value)
    {
        // Если значение уже обработано (полный URL), возвращаем как есть
        if ($value && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        
        return $value ? $this->buildFullUrl($value) : null;
    }

    /**
     * Получить URL для оригинального видео
     */
    public function getVideoUrlAttribute()
    {
        $url = $this->attributes['url'] ?? $this->url ?? null;
        if (!$url) {
            return null;
        }
        
        // Если уже полный URL, возвращаем как есть
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        
        return $this->buildFullUrl($url);
    }

    /**
     * Проверить, есть ли оптимизированные версии
     */
    public function hasOptimizedVersions()
    {
        return !empty($this->preview_url) && !empty($this->poster_url);
    }

    /**
     * Получить размер файла в читаемом формате
     */
    public function getFormattedFileSizeAttribute()
    {
        if (!$this->file_size) return null;
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * Получить длительность в читаемом формате
     */
    public function getFormattedDurationAttribute()
    {
        if (!$this->duration) return null;
        
        $seconds = (int) $this->duration;
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Построить полный URL
     */
    private function buildFullUrl($path)
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

    /**
     * Удалить файлы при удалении записи
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($video) {
            // Удаляем файлы с диска
            $files = [
                $video->url,
                $video->preview_url,
                $video->poster_url,
                $video->thumbnail_url,
            ];

            foreach ($files as $file) {
                if ($file) {
                    // Удаляем из S3
                    try {
                        if (Storage::disk('s3')->exists($file)) {
                            Storage::disk('s3')->delete($file);
                        }
                    } catch (\Throwable $e) {
                        // Игнорируем ошибки
                    }
                }
            }
        });
    }
}


