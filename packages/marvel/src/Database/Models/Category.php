<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Marvel\Traits\TranslationTrait;

class Category extends Model
{
    use TranslationTrait, Sluggable;


    protected $table = 'categories';

    public $guarded = [];

    protected $casts = [
        'image' => 'json',
        'menu_icon' => 'json',
        'menu_banner' => 'json',
        'parent' => 'integer',
        'type_id' => 'integer',
        'sort_order' => 'integer',
        'slug' => 'string',
        'name' => 'string',
    ];

    protected $appends = ['parent_id', 'translated_languages'];

    // Scope only active categories
    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getParentIdAttribute()
    {
        if (isset($this->attributes['parent'])) {
            return $this->attributes['parent'];
        }
        return null;
    }

    public function getSlugAttribute($value)
    {
        // Убеждаемся что slug всегда строка и не пустой
        if (empty($value) || !is_string($value)) {
            return 'category-' . $this->id;
        }
        
        // Декодируем unicode escape sequences если они есть
        if (strpos($value, '\\u') !== false) {
            $decoded = json_decode('"' . $value . '"');
            if ($decoded !== null) {
                $value = $decoded;
            }
        }
        
        return $value;
    }

    public function getNameAttribute($value)
    {
        // Декодируем unicode escape sequences в названии если они есть
        if (!empty($value) && is_string($value) && strpos($value, '\\u') !== false) {
            $decoded = json_decode('"' . $value . '"');
            if ($decoded !== null) {
                return $decoded;
            }
        }
        
        return $value;
    }

    public function scopeWithUniqueSlugConstraints(Builder $query, Model $model): Builder
    {
        return $query->where('language', $model->language);
    }

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


    /**
     * @return BelongsTo
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    /**
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product');
    }

    /**
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany('Marvel\Database\Models\Category', 'parent', 'id')
            ->withCount('products')
            ->orderBy('sort_order', 'asc')
            ->orderBy('name');
    }

    /**
     * @return HasMany
     */
    public function subCategories()
    {
        return $this->hasMany('Marvel\Database\Models\Category', 'parent', 'id')
            ->with('subCategories')
            ->withCount('products')
            ->orderBy('sort_order', 'asc')
            ->orderBy('name');
    }

    /**
     * @return HasOne
     */
    public function parent()
    {
        return $this->hasOne('Marvel\Database\Models\Category', 'id', 'parent');
    }

    /**
     * @return BelongsToMany
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute')
                    ->withPivot('is_required', 'sort_order')
                    ->orderByPivot('sort_order')
                    ->withTimestamps();
    }

    /**
     * Get required attributes for this category
     */
    public function requiredAttributes()
    {
        return $this->attributes()->wherePivot('is_required', true);
    }

    /**
     * Get optional attributes for this category
     */
    public function optionalAttributes()
    {
        return $this->attributes()->wherePivot('is_required', false);
    }
}
