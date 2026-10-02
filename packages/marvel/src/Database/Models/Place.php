<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Cviebrock\EloquentSluggable\Sluggable;

class Place extends Model
{
    use Sluggable;

    protected $fillable = [
        'user_id',
        'community_id',
        'title',
        'slug',
        'description',
        'location',
        'alt_text',
        'allow_comments',
        'language',
        'source_url',
    ];

    protected $casts = [
        'allow_comments' => 'boolean',
    ];

    protected $appends = ['url'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function images()
    {
        return $this->hasMany(PlaceImage::class);
    }

    public function videos()
    {
        return $this->hasMany(PlaceVideo::class);
    }

    public function hashtags()
    {
        return $this->belongsToMany(Hashtag::class, 'place_hashtag');
    }

    public function likes()
    {
        return $this->hasMany(PlaceLike::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'place_product');
    }

    public function followers()
    {
        return $this->hasMany(PlaceFollow::class);
    }

    public function wishlists()
    {
        return $this->hasMany(PlaceWishlist::class);
    }

    public function comments()
    {
        return $this->hasMany(PlaceComment::class)->whereNull('parent_id');
    }

    public function slugHistory()
    {
        return $this->hasMany(PlaceSlugHistory::class);
    }

    public function isLikedBy($userId)
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }

    public function isFollowedBy($userId)
    {
        return $this->followers()->where('user_id', $userId)->exists();
    }

    public function isFavoritedBy($userId)
    {
        return $this->wishlists()->where('user_id', $userId)->exists();
    }

    /**
     * Sluggable configuration
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'title',
                'onUpdate' => true,
                'unique' => true,
            ]
        ];
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // Сохраняем старый slug в историю при изменении
        static::updating(function ($place) {
            if ($place->isDirty('slug') && $place->getOriginal('slug')) {
                PlaceSlugHistory::create([
                    'place_id' => $place->id,
                    'old_slug' => $place->getOriginal('slug'),
                    'language' => $place->language ?? 'ru',
                    'changed_at' => now(),
                ]);
            }
        });

        static::created(function ($place) {
            if ($place->community_id) {
                Community::whereKey($place->community_id)->update([
                    'places_count' => self::where('community_id', $place->community_id)->count(),
                ]);
            }
        });

        static::updated(function ($place) {
            if ($place->wasChanged('community_id')) {
                foreach (array_filter([$place->getOriginal('community_id'), $place->community_id]) as $communityId) {
                    Community::whereKey($communityId)->update([
                        'places_count' => self::where('community_id', $communityId)->count(),
                    ]);
                }
            }
        });

        // Очищаем изображения и видео при удалении места
        static::deleting(function ($place) {
            $place->deletePlaceMedia();
        });

        static::deleted(function ($place) {
            if ($place->community_id) {
                Community::whereKey($place->community_id)->update([
                    'places_count' => self::where('community_id', $place->community_id)->count(),
                ]);
            }
        });
    }

    /**
     * Генерация SEO-URL: /places/{slug}-{id}
     */
    public function getUrlAttribute(): string
    {
        return "/places/{$this->slug}-{$this->id}";
    }

    /**
     * Парсинг slug и ID из строки формата "{slug}-{id}"
     */
    public static function parseSlugId(string $slugId): ?array
    {
        // Ищем последнее вхождение дефиса перед цифрами
        if (preg_match('/^(.+)-(\d+)$/', $slugId, $matches)) {
            return [
                'slug' => $matches[1],
                'id' => (int) $matches[2],
            ];
        }
        return null;
    }

    /**
     * Поиск Place по slug-id или по истории slug
     * Возвращает массив: ['place' => Place, 'redirect' => bool]
     */
    public static function findBySlugOrHistory(string $slug, int $id): ?array
    {
        $place = self::find($id);
        
        if (!$place) {
            return null;
        }

        // Если slug совпадает с текущим - редирект не нужен
        if ($place->slug === $slug) {
            return ['place' => $place, 'redirect' => false];
        }

        // Проверяем историю slug
        $historyExists = PlaceSlugHistory::where('place_id', $id)
            ->where('old_slug', $slug)
            ->exists();

        if ($historyExists) {
            return ['place' => $place, 'redirect' => true];
        }

        // Slug не совпадает и не найден в истории - все равно редиректим на текущий
        return ['place' => $place, 'redirect' => true];
    }

    /**
     * Удаляет все медиа-файлы места из S3
     */
    public function deletePlaceMedia()
    {
        try {
            // Удаляем изображения
            foreach ($this->images as $image) {
                $this->deleteImageFromS3($image->url);
            }

            // Удаляем видео
            foreach ($this->videos as $video) {
                $this->deleteImageFromS3($video->url);
            }

            Log::info('Place media deleted successfully', [
                'place_id' => $this->id,
                'place_title' => $this->title
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete place media', [
                'place_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Удаляет изображение из S3 по URL
     */
    private function deleteImageFromS3($url)
    {
        try {
            if (empty($url)) {
                return;
            }

            // Определяем ключ для удаления
            $key = $url;

            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $parsedUrl = parse_url($url);
                $key = isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : null;
            }

            if (!$key) {
                return;
            }

            $key = ltrim($key, '/');

            if (Storage::disk('s3')->exists($key)) {
                Storage::disk('s3')->delete($key);
                Log::info('Place media deleted from S3', ['key' => $key]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete place media from S3', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
    }
}
