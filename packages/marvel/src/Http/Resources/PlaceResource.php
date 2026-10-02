<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class PlaceResource extends JsonResource
{
    public function toArray($request)
    {
        // Оптимизированная структура видео с превью и постерами
        $videos = $this->videos->map(function($vid) {
            return [
                'id' => $vid->id,
                'url' => $vid->video_url, // Оригинальное видео (использует accessor)
                'preview' => $vid->preview_url, // 3-секундное превью (использует accessor)
                'poster' => $vid->poster_url, // Постер из первого кадра (использует accessor)
                'thumbnail' => $vid->thumbnail_url, // Thumbnail для списков (использует accessor)
                'duration' => $vid->duration, // Длительность в секундах
                'formatted_duration' => $vid->formatted_duration, // Читаемый формат
                'width' => $vid->width,
                'height' => $vid->height,
                'file_size' => $vid->file_size,
                'formatted_file_size' => $vid->formatted_file_size,
                'mime_type' => $vid->mime_type,
                'has_optimized_versions' => $vid->hasOptimizedVersions(),
            ];
        });

        // Оптимизированная структура изображений с thumbnail
        $images = $this->images->map(function($img) {
            return [
                'id' => $img->id,
                'url' => $img->image_url, // Оригинальное изображение (использует accessor)
                'thumbnail' => $img->thumbnail_url, // Thumbnail (использует accessor)
                'width' => $img->width,
                'height' => $img->height,
                'file_size' => $img->file_size,
                'mime_type' => $img->mime_type,
            ];
        });

        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'url' => $this->url, // SEO-URL формата /places/{slug}-{id}
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->getUserAvatar(),
            ],
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'alt_text' => $this->alt_text,
            'allow_comments' => (bool) $this->allow_comments,
            'community' => $this->whenLoaded('community', function () {
                return $this->community ? [
                    'id' => $this->community->id,
                    'name' => $this->community->name,
                    'slug' => $this->community->slug,
                    'avatar' => $this->community->avatar,
                    'cover' => $this->community->cover,
                ] : null;
            }),
            'images' => $images,
            'videos' => $videos,
            'hashtags' => $this->whenLoaded('hashtags', function () {
                if (!$this->hashtags || $this->hashtags->isEmpty()) {
                    return [];
                }
                return $this->hashtags->map(function($hashtag) {
                    return [
                        'id' => $hashtag->id,
                        'name' => $hashtag->name ?? '',
                        'slug' => $hashtag->slug ?? null,
                    ];
                })->values()->toArray();
            }, []),
            'products' => $this->whenLoaded('products', function () {
                return $this->products->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'image' => $product->image,
                    ];
                });
            }),
            'likes_count' => $this->likes->count(),
            'favorites_count' => $this->wishlists->count(),
            'created_at' => $this->created_at,
        ];

        // Отладка для первого плейса
        if ($this->id == 1) {
            Log::info('PlaceResource::toArray - оптимизированные данные плейса', [
                'id' => $this->id,
                'title' => $this->title,
                'images_count' => $images->count(),
                'videos_count' => $videos->count(),
                'videos_with_preview' => $videos->where('has_optimized_versions', true)->count(),
                'env_assets_base_url' => env('ASSETS_BASE_URL'),
                'first_image_url' => $images->first() ? $images->first()['url'] : 'N/A',
                'first_video_url' => $videos->first() ? $videos->first()['url'] : 'N/A',
                'raw_first_image' => $this->images->first() ? $this->images->first()->url : 'N/A',
                'raw_first_video' => $this->videos->first() ? $this->videos->first()->url : 'N/A',
            ]);
        }

        return $data;
    }

    private function getUserAvatar()
    {
        try {
            // Проверяем есть ли профиль и аватар
            if ($this->user->profile && $this->user->profile->avatar) {
                $avatar = $this->user->profile->avatar;
                
                // Если аватар это массив с thumbnail
                if (is_array($avatar) && isset($avatar['thumbnail'])) {
                    $avatarUrl = $avatar['thumbnail'];
                } 
                // Если аватар это строка
                elseif (is_string($avatar)) {
                    $avatarUrl = $avatar;
                } 
                // Если аватар это объект с thumbnail
                elseif (is_object($avatar) && isset($avatar->thumbnail)) {
                    $avatarUrl = $avatar->thumbnail;
                } 
                else {
                    $avatarUrl = null;
                }

                // Если есть URL и он начинается с /storage/, добавляем домен
                if ($avatarUrl && str_starts_with($avatarUrl, '/storage/')) {
                    return 'https://api.sancan.ru' . $avatarUrl;
                }

                return $avatarUrl;
            }

            // Если нет аватара, возвращаем null
            return null;
        } catch (\Exception $e) {
            Log::error('PlaceResource::getUserAvatar - ошибка получения аватара', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // Метод buildFullUrl удален - теперь используются accessor'ы из моделей
}
