<?php

namespace Marvel\Database\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Traits\Excludable;
use Kodeine\Metable\Metable;
use Marvel\Exceptions\MarvelException;
use Marvel\Traits\TranslationTrait;
use Marvel\Database\Models\Region;
use Marvel\Database\Models\GeoPoint;

class Product extends Model
{
    use Sluggable, SoftDeletes, Excludable, Metable, TranslationTrait;

    public $guarded = [];

    protected $table = 'products';
    protected $metaTable = 'products_meta'; //optional.
    public $hideMeta = true;


    protected $casts = [
        'image' => 'array',
        'gallery' => 'array',
        'video' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'account_data' => 'array',
        'subscription_data' => 'array',
        'key_data' => 'array',
        'reserved_at' => 'datetime',
        'reserved_until' => 'datetime',
    ];

    protected $appends = [
        'ratings',
        'total_reviews',
        'total_downloads',
        'rating_count',
        'my_review',
        'in_wishlist',
        'blocked_dates',
        'translated_languages',
        'canonical_url',
        'full_slug', // Полный slug с кодом
        'group_key', // Включаем group_key в JSON ответ
        'attribute_values', // Включаем attribute_values в JSON ответ
    ];

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name'
            ]
        ];
    }


    public function scopeWithUniqueSlugConstraints(Builder $query, Model $model): Builder
    {
        return $query->where('language', $model->language);
    }

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getBlockedDatesAttribute()
    {
        return $this->getBlockedDates();
    }

    function getBlockedDates()
    {
        $_blockedDates = $this->fetchBlockedDatesForAProduct();
        $_flatBlockedDates = [];
        foreach ($_blockedDates as $date) {
            $from = Carbon::parse($date->from);
            $to = Carbon::parse($date->to);
            $dateRange = CarbonPeriod::create($from, $to);
            $_blockedDates = $dateRange->toArray();
            unset($_blockedDates[count($_blockedDates) - 1]);
            $_flatBlockedDates =  array_unique(array_merge($_flatBlockedDates, $_blockedDates));
        }
        return $_flatBlockedDates;
    }

    /**
     * Get group_key attribute (accessor for $appends)
     * @return string|null
     */
    public function getGroupKeyAttribute()
    {
        // Проверяем напрямую из attributes (сырые данные из БД)
        $value = $this->attributes['group_key'] ?? null;
        
        // Логируем для отладки (только если значение есть, чтобы не засорять логи)
        if ($value && $this->id) {
            \Log::info('Product::getGroupKeyAttribute', [
                'product_id' => $this->id,
                'group_key' => $value,
                'has_in_attributes' => isset($this->attributes['group_key']),
            ]);
        }
        
        return $value;
    }

    /**
     * Get attribute_values attribute (accessor for $appends)
     * Возвращает простой формат ключ-значение для фронтенда
     * @return array
     */
    public function getAttributeValuesAttribute()
    {
        try {
            $attributeValues = [];
            
            // Загружаем атрибуты через relation с pivot данными
            $attributes = $this->attributes()->withPivot('value', 'attribute_value_id')->get();
            
            foreach ($attributes as $attribute) {
                $attrId = (string)$attribute->id;
                
                // Получаем значение из pivot - используем прямой доступ
                $value = null;
                if ($attribute->pivot) {
                    // Прямой доступ к свойству pivot
                    $pivot = $attribute->pivot;
                    if (isset($pivot->value)) {
                        $value = $pivot->value;
                    } elseif (property_exists($pivot, 'value') && $pivot->value !== null) {
                        $value = $pivot->value;
                    }
                }
                
                // Преобразуем значение в строку, если оно не пустое
                if ($value !== null && $value !== '' && $value !== 'NaN' && strtolower($value) !== 'nan') {
                    $attributeValues[$attrId] = (string)$value;
                }
            }
            
            // Логируем для отладки (только если пустой результат при наличии атрибутов)
            if (empty($attributeValues) && $this->id && $attributes->count() > 0) {
                \Log::info('Product::getAttributeValuesAttribute - Empty result but attributes exist', [
                    'product_id' => $this->id,
                    'attributes_count' => $attributes->count(),
                    'sample_attribute' => $attributes->first() ? [
                        'id' => $attributes->first()->id,
                        'has_pivot' => isset($attributes->first()->pivot),
                        'pivot_keys' => $attributes->first()->pivot ? array_keys((array)$attributes->first()->pivot) : [],
                    ] : null,
                ]);
            }
            
            return $attributeValues;
        } catch (\Exception $e) {
            \Log::warning('Error getting attribute values in accessor: ' . $e->getMessage(), [
                'product_id' => isset($this->id) ? $this->id : 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    public function fetchBlockedDatesForAProduct()
    {
        return  Availability::where('product_id', $this->id)->where('bookable_type', 'Marvel\Database\Models\Product')->whereDate('to', '>=', Carbon::now())->get();
    }


    public function getTotalDownloadsAttribute()
    {
        if ($this->is_digital && $this->digital_file) {
            return DownloadToken::where('digital_file_id', $this->digital_file->id)->where('downloaded', 1)->count();
        }
    }

    /**
     * @return BelongsTo
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * @return BelongsTo
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * @return BelongsTo
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * @return BelongsTo
     */
    public function shipping(): BelongsTo
    {
        return $this->belongsTo(Shipping::class, 'shipping_class_id');
    }

    /**
     * @return BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    /**
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag');
    }

    /**
     * @return HasMany
     */
    public function videos(): HasMany
    {
        // Используем полное имя класса для избежания проблем с автозагрузкой
        return $this->hasMany(\Marvel\Database\Models\ProductVideo::class);
    }

    /**
     * @return HasMany
     */
    public function variation_options(): HasMany
    {
        return $this->hasMany(Variation::class, 'product_id');
    }

    /**
     * @return belongsToMany
     */
    public function orders(): belongsToMany
    {
        return $this->belongsToMany(Order::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany
     */
    public function variations(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'attribute_product');
    }

    /**
     * @return BelongsToMany
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attribute_values')
                    ->withPivot('value', 'attribute_value_id')
                    ->withTimestamps();
    }

    /**
     * Get attribute value for specific attribute by attribute ID
     * NOTE: Method name intentionally avoids overriding Eloquent's getAttributeValue($key)
     */
    public function getAttributeValueById($attributeId)
    {
        try {
            return $this->attributes()->wherePivot('attribute_id', $attributeId)->first()?->pivot?->value;
        } catch (\Exception $e) {
            \Log::warning('Error getting attribute value: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set attribute value for specific attribute
     */
    public function setAttributeValue($attributeId, $value, $attributeValueId = null)
    {
        try {
            // Убеждаемся, что товар сохранен в базе данных
            if (!$this->id) {
                $this->save();
            }
            
            return $this->attributes()->syncWithoutDetaching([
                $attributeId => [
                    'value' => $value,
                    'attribute_value_id' => $attributeValueId
                ]
            ]);
        } catch (\Exception $e) {
            \Log::warning('Error setting attribute value: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all attribute values as key-value array
     */
    public function getAttributeValuesArray()
    {
        try {
            // Возвращаем массив где ключ - это attribute_id (строка), а значение - объект с value
            return $this->attributes()->get()->mapWithKeys(function ($attribute) {
                // Используем attribute_id как ключ для фронтенда (приводим к строке для совместимости)
                $attributeId = (string)$attribute->id;
                
                // Получаем значение из pivot, обрабатываем NULL и пустые значения
                $pivotValue = $attribute->pivot->value ?? null;
                
                // Если значение NULL, пустая строка или "NaN" - возвращаем null
                if ($pivotValue === null || $pivotValue === '' || $pivotValue === 'NaN' || strtolower($pivotValue) === 'nan') {
                    $pivotValue = null;
                }
                
                // Если значение - число, но хранится как строка - преобразуем
                if ($pivotValue !== null && is_numeric($pivotValue)) {
                    // Для числовых атрибутов возвращаем число, для остальных - строку
                    $pivotValue = ($attribute->type === 'number') ? (float)$pivotValue : (string)$pivotValue;
                }
                
                return [$attributeId => [
                    'value' => $pivotValue,
                    'attribute_value_id' => $attribute->pivot->attribute_value_id ?? null,
                ]];
            })->toArray();
        } catch (\Exception $e) {
            \Log::warning('Error getting attribute values array: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'product_id');
    }

    /**
     * @return HasMany
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'product_id');
    }

    /**
     * @return HasMany
     */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'product_id');
    }

    public function getRatingsAttribute()
    {
        return round($this->reviews()->avg('rating'), 2);
    }

    public function getTotalReviewsAttribute()
    {
        return $this->reviews()->count();
    }

    public function getRatingCountAttribute()
    {
        return $this->reviews()->orderBy('rating', 'DESC')->groupBy('rating')->select('rating', DB::raw('count(*) as total'))->get();
    }

    public function getMyReviewAttribute()
    {
        if (auth()->user() && !empty($this->reviews()->where('user_id', auth()->user()->id)->first())) {
            return $this->reviews()->where('user_id', auth()->user()->id)->get();
        }
        return null;
    }

    public function getInWishlistAttribute()
    {
        if (auth()->user() && !empty($this->wishlists()->where('user_id', auth()->user()->id)->first())) {
            return true;
        }
        return false;
    }

    public function digital_file()
    {
        return $this->morphOne(DigitalFile::class, 'fileable');
    }

    public function productKeys(): HasMany
    {
        return $this->hasMany(ProductKey::class);
    }

    public function productSubscriptions(): HasMany
    {
        return $this->hasMany(ProductSubscription::class);
    }

    /**
     * Курсы, для доступа к которым требуется подписка на этот товар.
     */
    public function entitlementCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'required_product_id');
    }

    public function availabilities()
    {
        return $this->morphMany(Availability::class, 'bookable');
    }


    /**
     * @return BelongsToMany
     */
    public function dropoff_locations(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'dropoff_location_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function pickup_locations(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'pickup_location_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function deposits(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'deposit_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'person_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'feature_product', 'product_id', 'resource_id');
    }

    public function places()
    {
        return $this->belongsToMany(Place::class, 'place_product');
    }

    /**
     * Регион (город), к которому привязан продукт.
     * Согласно ТЗ — всегда city-level.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Географическая точка продукта (для радиусного поиска)
     */
    public function geoPoint(): BelongsTo
    {
        return $this->belongsTo(GeoPoint::class);
    }

    /**
     * Дополнительные регионы покрытия (районы, соседи и т.д.)
     */
    public function additionalRegions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'product_region_relations');
    }

    /**
     * Scope: только активные и опубликованные товары
     */
    public function scopePublished($query)
    {
        return $query->where('is_active', true)
                     ->where('is_published', true);
    }

    /**
     * Scope: товары в конкретном регионе + соседях
     */
    public function scopeInRegionOrNeighbors($query, $regionId)
    {
        return $query->published()
            ->where(function ($q) use ($regionId) {
                $q->where('region_id', $regionId)
                  ->orWhereIn('region_id', function ($sub) use ($regionId) {
                      $sub->select('neighbor_region_id')
                          ->from('region_neighbors')
                          ->where('region_id', $regionId);
                  });
            });
    }


    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // Очищаем изображения при удалении товара
        static::deleting(function ($product) {
            $product->deleteProductImages();
        });

        // Отслеживаем изменение slug ПЕРЕД сохранением
        static::saving(function ($product) {
            // Если модель уже существует (не новая) и slug изменился
            if ($product->exists && $product->isDirty('slug')) {
                $originalSlug = $product->getOriginal('slug');
                // Сохраняем только если оригинальный slug существует и не пустой
                if (!empty($originalSlug)) {
                    $product->_old_slug_for_history = $originalSlug;
                }
            }
        });

        // Сохраняем историю ПОСЛЕ успешного сохранения
        static::saved(function ($product) {
            // Проверяем, был ли сохранен старый slug
            if (isset($product->_old_slug_for_history) && !empty($product->_old_slug_for_history)) {
                $oldSlug = $product->_old_slug_for_history;
                
                // Создаем запись в истории только если slug действительно изменился
                if ($oldSlug !== $product->slug) {
                    ProductSlugHistory::create([
                        'product_id' => $product->id,
                        'old_slug' => $oldSlug,
                        'language' => $product->language ?? 'ru',
                        'changed_at' => now(),
                    ]);

                    Log::info('Product slug changed', [
                        'product_id' => $product->id,
                        'old_slug' => $oldSlug,
                        'new_slug' => $product->slug,
                    ]);
                }

                // Очищаем временное свойство
                unset($product->_old_slug_for_history);
            }
        });
    }

    /**
     * Удаляет все изображения товара из S3
     */
    public function deleteProductImages()
    {
        try {
            // Удаляем основное изображение
            if (!empty($this->image)) {
                $imageData = is_string($this->image) ? json_decode($this->image, true) : $this->image;
                
                if (is_array($imageData) && isset($imageData['original'])) {
                    $this->deleteImageFromS3($imageData['original']);
                }
                
                if (is_array($imageData) && isset($imageData['thumbnail'])) {
                    $this->deleteImageFromS3($imageData['thumbnail']);
                }
            }

            // Удаляем галерею
            if (!empty($this->gallery)) {
                $galleryData = is_string($this->gallery) ? json_decode($this->gallery, true) : $this->gallery;
                
                if (is_array($galleryData)) {
                    foreach ($galleryData as $image) {
                        if (is_array($image)) {
                            if (isset($image['original'])) {
                                $this->deleteImageFromS3($image['original']);
                            }
                            if (isset($image['thumbnail'])) {
                                $this->deleteImageFromS3($image['thumbnail']);
                            }
                        }
                    }
                }
            }

            // Удаляем видео
            if (!empty($this->video)) {
                $videoData = is_string($this->video) ? json_decode($this->video, true) : $this->video;
                
                if (is_array($videoData) && isset($videoData['url'])) {
                    $this->deleteImageFromS3($videoData['url']);
                }
            }

            Log::info('Product images deleted successfully', [
                'product_id' => $this->id,
                'product_name' => $this->name
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete product images', [
                'product_id' => $this->id,
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
            // Извлекаем ключ из URL S3
            if (strpos($url, 's3.twcstorage.ru') !== false) {
                $parsedUrl = parse_url($url);
                $key = ltrim($parsedUrl['path'], '/');
                
                if (Storage::disk('s3')->exists($key)) {
                    Storage::disk('s3')->delete($key);
                    Log::info('Image deleted from S3', ['key' => $key]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete image from S3', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Связь с историей slug
     */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(ProductSlugHistory::class, 'product_id');
    }

    /**
     * Получить URL элемента в формате /element/{slug}-{id}
     * Это правильный формат согласно логике контроллера
     */
    public function getUrlAttribute(): string
    {
        if (!$this->slug || !$this->id) {
            return "/element/{$this->slug}";
        }
        return "/element/{$this->slug}-{$this->id}";
    }

    /**
     * Получить полный URL элемента в формате /element/{slug}-{id}
     */
    public function getFullUrlAttribute(): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        if (!$this->slug || !$this->id) {
            return "{$baseUrl}/element/{$this->slug}";
        }
        return "{$baseUrl}/element/{$this->slug}-{$this->id}";
    }

    /**
     * Получить полный slug с кодом (если код есть в БД)
     * ВАЖНО: Проверяем, не содержит ли slug уже код, чтобы не дублировать
     */
    public function getFullSlugAttribute(): string
    {
        // Если slug уже содержит 12-значный код в конце - возвращаем как есть
        if (preg_match('/-\d{12}$/', $this->slug)) {
            return $this->slug;
        }
        
        // Если есть slug_numeric_code в БД - добавляем его
        if (!empty($this->slug_numeric_code)) {
            return $this->slug . '-' . $this->slug_numeric_code;
        }
        
        // Если slug содержит старый формат кода (не 12 цифр) - возвращаем как есть
        return $this->slug;
    }

    /**
     * Получить canonical URL элемента
     * Товары не имеют языковых версий, поэтому canonical URL всегда без языкового префикса
     */
    public function getCanonicalUrlAttribute(): string
    {
        $baseUrl = rtrim(config('app.url', 'https://sancan.ru'), '/');
        $fullSlug = $this->full_slug;
        return "{$baseUrl}/element/{$fullSlug}";
    }

    /**
     * Найти товар по slug или старому slug
     */
    public static function findBySlugOrHistory(string $slug, string $language = 'ru'): ?Product
    {
        // Сначала ищем по текущему slug
        $product = self::where('slug', $slug)
            ->where('language', $language)
            ->first();

        if ($product) {
            return $product;
        }

        // Если не найдено, ищем в истории
        return ProductSlugHistory::findProductByOldSlug($slug, $language);
    }

    /**
     * Парсинг slug и code из строки формата "{slug}-{code}"
     * Поддерживает:
     * 1. Новый формат: {slug}-{12-значный числовой код} (например: kartina-120x90-123456789012)
     * 2. Старый формат со старыми кодами: {slug}-{буквы+цифры} (например: kartina-SE5)
     * 3. Совсем старый формат: {slug}-{ID до 10 цифр} (например: kartina-752)
     * 4. Без кода: {slug} (для обратной совместимости)
     */
    public static function parseSlugId(string $slugId): array
    {
        // НОВЫЙ формат: последний сегмент - ровно 12 цифр (уникальный код товара)
        // Примеры: kartina-120x90-123456789012, abstrakciya-sinij-ton-987654321098
        if (preg_match('/^(.+)-(\d{12})$/', $slugId, $matches)) {
            return [
                'slug' => $slugId, // Полный slug для поиска в БД (включая код)
                'id' => null,
                'code' => $matches[2], // 12-значный код
            ];
        }
        
        // СТАРЫЙ формат с буквенно-цифровым кодом: последний сегмент содержит буквы
        // Примеры: kartina-abstrakciya-SE5, product-XA2B4C
        if (preg_match('/^(.+)-([A-Z0-9]{3,})$/', $slugId, $matches)) {
            $code = $matches[2];
            // Если содержит хотя бы одну букву - это старый буквенный код
            if (preg_match('/[A-Z]/', $code)) {
                return [
                    'slug' => $slugId, // Полный slug для поиска в БД
                    'id' => null,
                    'code' => $code,
                ];
            }
        }
        
        // СОВСЕМ СТАРЫЙ формат: последний сегмент - ID (1-10 цифр, но НЕ 12)
        // Примеры: kartina-752, product-12345
        // НО НЕ: размеры (120, 90), годы (2024, 2025)
        if (preg_match('/^(.+)-(\d{1,10})$/', $slugId, $matches)) {
            $possibleSlug = $matches[1];
            $possibleId = (int)$matches[2];
            
            // Игнорируем размеры (обычно 2-3 цифры от 10 до 999)
            // И годы (4 цифры от 1900 до 2099)
            if (($possibleId >= 10 && $possibleId <= 999) || 
                ($possibleId >= 1900 && $possibleId <= 2099)) {
                // Возможно, это размер или год - не парсим как ID
                return ['slug' => $slugId, 'id' => null];
            }
            
            // Старый ID-формат
            return [
                'slug' => $possibleSlug,
                'id' => $possibleId,
                'old_format' => true,
            ];
        }

        // Без кода/ID - просто slug
        return ['slug' => $slugId, 'id' => null];
    }
}