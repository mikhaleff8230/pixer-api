<?php

namespace Marvel\Database\Models;

use App\Services\PublicStoreUrl;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kodeine\Metable\Metable;
use Marvel\Traits\TranslationTrait;
use Illuminate\Support\Facades\Log;

class ProductSku extends Model
{
    use Sluggable, SoftDeletes, Metable, TranslationTrait;

    protected $table = 'product_skus';
    protected $metaTable = 'product_skus_meta';
    public $hideMeta = true;

    protected $guarded = [];

    protected $casts = [
        'image' => 'array',
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
                'source' => ['group.title', 'title'],
                'onUpdate' => true,
                'separator' => '-',
            ]
        ];
    }

    /**
     * Генерация slug из options, если title пустой
     */
    protected function generateSlugFromOptions()
    {
        if (empty($this->title) && $this->group) {
            $options = $this->options;
            if (is_array($options) && !empty($options)) {
                $values = array_map(function($opt) {
                    return isset($opt['value']) ? \Illuminate\Support\Str::slug($opt['value']) : '';
                }, $options);
                $values = array_filter($values);
                
                if (!empty($values)) {
                    return $this->group->slug . '-' . implode('-', $values);
                }
            }
        }
        return null;
    }

    public function scopeWithUniqueSlugConstraints($query, $model)
    {
        return $query->where('language', $model->language);
    }

    /**
     * Связь с группой товара
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /**
     * Связь со свойствами (атрибутами) через pivot таблицу
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_sku_property_values', 'sku_id', 'property_id')
            ->withPivot('property_value_id')
            ->withTimestamps();
    }

    /**
     * Связь со значениями свойств (атрибутов)
     */
    public function propertyValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_sku_property_values', 'sku_id', 'property_value_id')
            ->withPivot('property_id')
            ->withTimestamps();
    }

    /**
     * Цифровой файл (если is_digital = true)
     */
    public function digitalFile(): HasOne
    {
        return $this->morphOne(DigitalFile::class, 'fileable');
    }

    /**
     * Получить все свойства SKU в формате для фронтенда
     * Возвращает массив вида: [{"name": "Размер", "value": "S"}, ...]
     */
    public function getOptionsAttribute()
    {
        $options = [];
        
        // Группируем значения по атрибутам
        $propertyValues = $this->propertyValues()->with('attribute')->get();
        
        foreach ($propertyValues as $propertyValue) {
            $attribute = $propertyValue->attribute;
            if ($attribute) {
                $options[] = [
                    'name' => $attribute->name,
                    'value' => $propertyValue->value,
                    'attribute_id' => $attribute->id,
                    'attribute_value_id' => $propertyValue->id,
                ];
            }
        }
        
        return $options;
    }

    /**
     * Установить свойства SKU из массива options
     * Принимает массив вида: [{"name": "Размер", "value": "S"}, ...]
     * или [{"attribute_id": 1, "attribute_value_id": 10}, ...]
     */
    public function setOptions(array $options)
    {
        $syncData = [];
        
        foreach ($options as $option) {
            // Если передан attribute_id и attribute_value_id
            if (isset($option['attribute_id']) && isset($option['attribute_value_id'])) {
                $syncData[$option['attribute_value_id']] = [
                    'property_id' => $option['attribute_id'],
                ];
            }
            // Если передан name и value - ищем по названиям
            elseif (isset($option['name']) && isset($option['value'])) {
                $attribute = Attribute::where('name', $option['name'])->first();
                if ($attribute) {
                    $attributeValue = AttributeValue::where('attribute_id', $attribute->id)
                        ->where('value', $option['value'])
                        ->first();
                    
                    if ($attributeValue) {
                        $syncData[$attributeValue->id] = [
                            'property_id' => $attribute->id,
                        ];
                    }
                }
            }
        }
        
        $this->propertyValues()->sync($syncData);
    }

    /**
     * Проверка наличия товара на складе
     */
    public function isInStock(): bool
    {
        return $this->is_active && $this->quantity > 0;
    }

    /**
     * Получить актуальную цену (с учетом скидки)
     */
    public function getActualPriceAttribute()
    {
        return $this->old_price && $this->old_price > $this->price 
            ? $this->price 
            : ($this->old_price ?? $this->price);
    }

    /**
     * Связь с историей slug
     */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(ProductSkuSlugHistory::class, 'product_sku_id');
    }

    /**
     * Получить URL SKU в формате /element/{group-slug}/{sku-slug}-{sku-id}
     */
    public function getUrlAttribute(): string
    {
        $group = $this->group;
        return "/element/{$group->slug}/{$this->slug}-{$this->id}";
    }

    /**
     * Получить полный URL SKU
     */
    public function getFullUrlAttribute(): string
    {
        $group = $this->group;
        return app(PublicStoreUrl::class)->to("/element/{$group->slug}/{$this->slug}-{$this->id}");
    }

    /**
     * Boot method для отслеживания изменений slug
     */
    protected static function boot()
    {
        parent::boot();

        // Отслеживаем изменение slug в САМОМ НАЧАЛЕ цикла сохранения
        static::saving(function ($sku) {
            // Если модель существует (не новая запись)
            if ($sku->exists) {
                $originalSlug = $sku->getOriginal('slug');
                $newSlug = $sku->slug;
                
                // Если slug был изменен явно (не пустой оригинал и новый отличается)
                if (!empty($originalSlug) && $originalSlug !== $newSlug && !empty($newSlug)) {
                    // Сохраняем старый slug для записи в историю
                    $sku->_old_slug_for_history = $originalSlug;
                    
                    Log::debug('ProductSku slug change detected in saving', [
                        'sku_id' => $sku->id,
                        'old' => $originalSlug,
                        'new' => $newSlug,
                    ]);
                }
            }
        });

        // Сохраняем историю ПОСЛЕ успешного сохранения
        static::saved(function ($sku) {
            // Проверяем, был ли отмечен старый slug для записи в историю
            if (isset($sku->_old_slug_for_history)) {
                $oldSlug = $sku->_old_slug_for_history;
                $currentSlug = $sku->slug;
                
                // Еще раз проверяем, что slug действительно изменился
                if ($oldSlug !== $currentSlug) {
                    try {
                        ProductSkuSlugHistory::create([
                            'product_sku_id' => $sku->id,
                            'old_slug' => $oldSlug,
                            'language' => $sku->language ?? 'ru',
                            'changed_at' => now(),
                        ]);

                        Log::info('ProductSku slug history saved', [
                            'sku_id' => $sku->id,
                            'old_slug' => $oldSlug,
                            'new_slug' => $currentSlug,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to save ProductSku slug history', [
                            'sku_id' => $sku->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Очищаем временное свойство
                unset($sku->_old_slug_for_history);
            }
        });
    }

    /**
     * Найти SKU по slug или старому slug
     */
    public static function findBySlugOrHistory(string $slug, string $language = 'ru'): ?ProductSku
    {
        // Сначала ищем по текущему slug
        $sku = self::where('slug', $slug)
            ->where('language', $language)
            ->first();

        if ($sku) {
            return $sku;
        }

        // Если не найдено, ищем в истории
        return ProductSkuSlugHistory::findSkuByOldSlug($slug, $language);
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
            ProductSkuSlugHistory::create([
                'product_sku_id' => $this->id,
                'old_slug' => $oldSlug,
                'language' => $this->language ?? 'ru',
                'changed_at' => now(),
            ]);

            Log::info('ProductSku slug changed via changeSlug()', [
                'sku_id' => $this->id,
                'old_slug' => $oldSlug,
                'new_slug' => $newSlug,
            ]);
        }

        // Обновляем slug
        $this->slug = $newSlug;
        return $this->save();
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
}
