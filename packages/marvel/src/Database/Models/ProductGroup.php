<?php

namespace Marvel\Database\Models;

use App\Services\PublicStoreUrl;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kodeine\Metable\Metable;
use Marvel\Traits\TranslationTrait;
use Illuminate\Support\Facades\Log;

class ProductGroup extends Model
{
    use Sluggable, SoftDeletes, Metable, TranslationTrait;

    protected $table = 'product_groups';
    protected $metaTable = 'product_groups_meta';
    public $hideMeta = true;

    protected $guarded = [];

    protected $casts = [
        'main_image' => 'array',
        'gallery' => 'array',
        'video' => 'array',
        'meta' => 'array',
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
                'source' => 'title'
            ]
        ];
    }

    public function scopeWithUniqueSlugConstraints($query, $model)
    {
        return $query->where('language', $model->language);
    }

    /**
     * Связь с категорией
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Связь с типом товара
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    /**
     * Связь с магазином
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * Связь с производителем/автором (полиморфная)
     */
    public function brand()
    {
        if ($this->brand_type === 'manufacturer') {
            return $this->belongsTo(Manufacturer::class, 'brand_id');
        } elseif ($this->brand_type === 'author') {
            return $this->belongsTo(Author::class, 'brand_id');
        }
        return null;
    }

    /**
     * Связь с SKU (вариациями)
     */
    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class, 'group_id');
    }

    /**
     * Активные SKU
     */
    public function activeSkus(): HasMany
    {
        return $this->hasMany(ProductSku::class, 'group_id')->where('is_active', true);
    }

    /**
     * Связь с категориями (many-to-many для обратной совместимости)
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product_group', 'group_id', 'category_id');
    }

    /**
     * Связь с тегами
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_group_tag', 'group_id', 'tag_id');
    }

    /**
     * Минимальная цена среди всех SKU
     */
    public function getMinPriceAttribute()
    {
        return $this->activeSkus()->min('price') ?? 0;
    }

    /**
     * Максимальная цена среди всех SKU
     */
    public function getMaxPriceAttribute()
    {
        return $this->activeSkus()->max('price') ?? 0;
    }

    /**
     * Общее количество всех SKU
     */
    public function getTotalQuantityAttribute()
    {
        return $this->activeSkus()->sum('quantity') ?? 0;
    }

    /**
     * Количество всех SKU
     */
    public function getSkusCountAttribute()
    {
        return $this->skus()->count();
    }

    /**
     * Связь с историей slug
     */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(ProductGroupSlugHistory::class, 'product_group_id');
    }

    /**
     * Получить URL элемента в формате /element/{slug}-{id}
     */
    public function getUrlAttribute(): string
    {
        return "/element/{$this->slug}-{$this->id}";
    }

    /**
     * Получить полный URL элемента
     */
    public function getFullUrlAttribute(): string
    {
        return app(PublicStoreUrl::class)->productUrl($this->slug, $this->id);
    }

    /**
     * Boot method для отслеживания изменений slug
     */
    protected static function boot()
    {
        parent::boot();

        // Отслеживаем изменение slug ПЕРЕД сохранением
        static::saving(function ($group) {
            // Если модель уже существует (не новая) и slug изменился
            if ($group->exists && $group->isDirty('slug')) {
                $originalSlug = $group->getOriginal('slug');
                // Сохраняем только если оригинальный slug существует и не пустой
                if (!empty($originalSlug)) {
                    $group->_old_slug_for_history = $originalSlug;
                }
            }
        });

        // Сохраняем историю ПОСЛЕ успешного сохранения
        static::saved(function ($group) {
            // Проверяем, был ли сохранен старый slug
            if (isset($group->_old_slug_for_history) && !empty($group->_old_slug_for_history)) {
                $oldSlug = $group->_old_slug_for_history;
                
                // Создаем запись в истории только если slug действительно изменился
                if ($oldSlug !== $group->slug) {
                    ProductGroupSlugHistory::create([
                        'product_group_id' => $group->id,
                        'old_slug' => $oldSlug,
                        'language' => $group->language ?? 'ru',
                        'changed_at' => now(),
                    ]);

                    Log::info('ProductGroup slug changed', [
                        'group_id' => $group->id,
                        'old_slug' => $oldSlug,
                        'new_slug' => $group->slug,
                    ]);
                }

                // Очищаем временное свойство
                unset($group->_old_slug_for_history);
            }
        });
    }

    /**
     * Найти группу по slug или старому slug
     */
    public static function findBySlugOrHistory(string $slug, string $language = 'ru'): ?ProductGroup
    {
        // Сначала ищем по текущему slug
        $group = self::where('slug', $slug)
            ->where('language', $language)
            ->first();

        if ($group) {
            return $group;
        }

        // Если не найдено, ищем в истории
        return ProductGroupSlugHistory::findGroupByOldSlug($slug, $language);
    }

    /**
     * Парсинг slug и id из строки формата "{slug}-{id}"
     */
    public static function parseSlugId(string $slugId): array
    {
        // Находим последний дефис, после которого идут только цифры
        if (preg_match('/^(.+)-(\d+)$/', $slugId, $matches)) {
            return [
                'slug' => $matches[1],
                'id' => (int)$matches[2],
            ];
        }

        return ['slug' => $slugId, 'id' => null];
    }

    /**
     * Изменить slug с сохранением истории
     */
    public function changeSlug(string $newSlug): bool
    {
        $oldSlug = $this->slug;
        
        // Если slug не изменился, ничего не делаем
        if ($oldSlug === $newSlug) {
            return true;
        }

        // Сохраняем старый slug в истории
        if (!empty($oldSlug)) {
            ProductGroupSlugHistory::create([
                'product_group_id' => $this->id,
                'old_slug' => $oldSlug,
                'language' => $this->language ?? 'ru',
                'changed_at' => now(),
            ]);

            Log::info('ProductGroup slug changed via changeSlug()', [
                'group_id' => $this->id,
                'old_slug' => $oldSlug,
                'new_slug' => $newSlug,
            ]);
        }

        // Обновляем slug
        $this->slug = $newSlug;
        return $this->save();
    }
}
