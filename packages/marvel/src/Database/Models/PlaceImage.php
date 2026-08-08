<?php

namespace Marvel\Database\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PlaceImage extends Model
{
    protected $fillable = [
        'place_id',
        'url',
        'thumbnail_url',
        'width',
        'height',
        'file_size',
        'mime_type',
    ];

    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Получить полный URL изображения
     */
    public function getImageUrlAttribute()
    {
        return $this->buildFullUrl($this->url);
    }

    /**
     * Получить полный URL thumbnail
     */
    public function getThumbnailUrlAttribute($value)
    {
        if ($value) {
            return $this->buildFullUrl($value);
        }
        
        // Fallback к оригинальному изображению если thumbnail нет
        return $this->buildFullUrl($this->url);
    }

    /**
     * Построить полный URL
     */
    private function buildFullUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        $url = MediaUrl::publicUrl($path);
        if ($url) {
            return $url;
        }

        // Fallback: локальный API storage
        if (str_starts_with($path, '/storage/')) {
            return 'https://api.sancan.ru' . $path;
        }

        return 'https://api.sancan.ru/storage/' . ltrim($path, '/');
    }
} 