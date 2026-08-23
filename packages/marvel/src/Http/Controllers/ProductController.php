<?php

namespace Marvel\Http\Controllers;

use Exception;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Marvel\Database\Models\Type;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductKey;
use Marvel\Database\Models\Course;
use Marvel\Database\Models\Wishlist;
use Marvel\Database\Models\Variation;
use Marvel\Exceptions\MarvelException;
use Illuminate\Database\Eloquent\Collection;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Database\Repositories\SettingsRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Database\Models\Settings;
use Marvel\Exceptions\MarvelNotFoundException;
use \OpenAI;
use Marvel\Services\LocationService;
use Marvel\Database\Models\Region;


class ProductController extends CoreController
{
    public $repository;

    public $settings;

    public function __construct(ProductRepository $repository, SettingsRepository $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    /**
     * Новый метод — товары с учетом геолокации пользователя (аналогично Avito).
     * Поддерживает фильтрацию по региону, соседям и радиусу.
     */
    public function geoFeed(Request $request)
    {
        $locationService = app(LocationService::class);
        $user = $request->user();
        
        $filters = $request->only(['category_id', 'price_min', 'price_max', 'per_page', 'lat', 'lng', 'radius']);
        
        if (!empty($filters['lat']) && !empty($filters['lng'])) {
            // Поиск по радиусу
            $products = $locationService->findProductsByRadius(
                (float)$filters['lat'], 
                (float)$filters['lng'], 
                (int)($filters['radius'] ?? 50000)
            );
        } else {
            // Поиск по региону пользователя
            $products = $locationService->getProductsForUser($user, $filters);
        }

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'user_region' => $locationService->getUserRegion($user)->name ?? 'Не определен',
            ]
        ]);
    }

    // === МЕТОДЫ ДЛЯ CANONICAL URL (из ProductControllerREL_NEW.php - РАБОЧАЯ ВЕРСИЯ) ===
    protected function generateCanonicalUrl($product, Request $request): string
    {
        $baseUrl = rtrim(config('app.url', 'https://sancan.ru'), '/');
        
        // Получаем slug и id
        if (is_array($product)) {
            $slug = $product['slug'] ?? '';
            $id = $product['id'] ?? '';
        } else {
            $slug = $product->slug ?? '';
            $id = $product->id ?? '';
        }
        
        // Логируем для отладки
        \Log::info('ProductController::generateCanonicalUrl', [
            'slug' => $slug,
            'id' => $id,
            'has_slug' => !empty($slug),
            'has_id' => !empty($id),
            'base_url' => $baseUrl,
        ]);
        
        if (!$id || !$slug) {
            \Log::warning('ProductController::generateCanonicalUrl - missing slug or id', [
                'slug' => $slug,
                'id' => $id,
            ]);
            return $baseUrl;
        }
        
        // Канонический URL ВСЕГДА без языкового префикса: /element/{slug}-{id}
        // Товары не имеют языковых версий
        $canonical = "{$baseUrl}/element/{$slug}-{$id}";
        
        \Log::info('ProductController::generateCanonicalUrl result', [
            'canonical' => $canonical,
        ]);
        
        return $canonical;
    }

    protected function generateHreflangTags($product, Request $request): array
    {
        $baseUrl = rtrim(config('app.url', 'https://sancan.ru'), '/');
        
        // Получаем slug и id
        if (is_array($product)) {
            $slug = $product['slug'] ?? '';
            $id = $product['id'] ?? '';
        } else {
            $slug = $product->slug ?? '';
            $id = $product->id ?? '';
        }
        
        if (!$id || !$slug) {
            return [];
        }
        
        // Товары не имеют языковых версий, поэтому все языки указывают на один URL без префикса
        $canonicalUrl = "{$baseUrl}/element/{$slug}-{$id}";
        
        $languages = ['ru', 'en'];
        $tags = [];
        
        // Все языки указывают на один и тот же URL (без языкового префикса)
        foreach ($languages as $lang) {
            $tags[] = [
                'hreflang' => $lang,
                'href' => $canonicalUrl
            ];
        }
        
        // Добавляем x-default
        $tags[] = [
            'hreflang' => 'x-default',
            'href' => $canonicalUrl
        ];
        
        return $tags;
    }

    // === ИСПРАВЛЕННЫЙ МЕТОД ДЛЯ ГАРАНТИИ IMAGES (из ProductControllerREL_NEW.php - РАБОЧАЯ ВЕРСИЯ) ===
    // ВАЖНО: Этот метод используется ТОЛЬКО для фронтенда, НЕ для админки!
    // КРИТИЧНО: НЕ трогаем gallery, только создаем images на основе gallery!
    protected function ensureProductImages(array $productArray): array
    {
        // Инициализируем images как пустой массив (но НЕ трогаем gallery!)
        $productArray['images'] = [];
        
        // Сохраняем оригинальную gallery ДО любых изменений
        $originalGallery = $productArray['gallery'] ?? null;
        
        // 1. Пробуем получить из поля gallery
        if (!empty($originalGallery)) {
            $gallery = $originalGallery;
            
            // Если gallery - строка, декодируем её
            if (is_string($gallery)) {
                $decoded = json_decode($gallery, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $gallery = $decoded;
                } else {
                    $gallery = [];
                }
            }
            
            // Копируем gallery в images (но НЕ перезаписываем gallery!)
            if (is_array($gallery) && !empty($gallery)) {
                $productArray['images'] = $gallery;
            }
        }
        
        // 2. Если images пустой, пробуем использовать основное изображение
        if (empty($productArray['images']) && !empty($productArray['image'])) {
            $mainImage = $productArray['image'];
            
            if (is_string($mainImage)) {
                $decoded = json_decode($mainImage, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $mainImage = $decoded;
                }
            }
            
            if (is_array($mainImage) && !empty($mainImage)) {
                // Нормализуем структуру изображения
                $normalizedImage = [
                    'id' => $mainImage['id'] ?? 0,
                    'url' => $mainImage['url'] ?? $mainImage['thumbnail'] ?? $mainImage['original'] ?? '',
                    'thumbnail' => $mainImage['thumbnail'] ?? $mainImage['url'] ?? $mainImage['original'] ?? '',
                    'original' => $mainImage['original'] ?? $mainImage['url'] ?? $mainImage['thumbnail'] ?? '',
                ];
                
                if ($normalizedImage['url']) {
                    $productArray['images'] = [$normalizedImage];
                    // Если gallery была пустой, заполняем её
                    if (empty($originalGallery)) {
                        $productArray['gallery'] = [$normalizedImage];
                    }
                }
            }
        }
        
        // 3. Если все еще пусто - добавляем заглушку ТОЛЬКО для images
        if (empty($productArray['images'])) {
            $placeholder = [
                'id' => 0,
                'thumbnail' => '/images/no-image.jpg',
                'original' => '/images/no-image.jpg',
                'url' => '/images/no-image.jpg',
            ];
            $productArray['images'] = [$placeholder];
            // Если gallery была пустой, добавляем заглушку и туда
            if (empty($originalGallery)) {
                $productArray['gallery'] = [$placeholder];
            }
        }
        
        // КРИТИЧНО: ВСЕГДА сохраняем оригинальную gallery (как в ProductController_OLD.php)
        // НЕ перезаписываем gallery, только если она была пустой изначально
        if ($originalGallery !== null) {
            // Если gallery была массивом - сохраняем как есть
            if (is_array($originalGallery)) {
                $productArray['gallery'] = $originalGallery;
            }
            // Если gallery была строкой - декодируем и сохраняем
            elseif (is_string($originalGallery)) {
                $decoded = json_decode($originalGallery, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $productArray['gallery'] = $decoded;
                } else {
                    $productArray['gallery'] = $originalGallery; // Сохраняем как строку, если не удалось декодировать
                }
            } else {
                // Сохраняем как есть, если это другой тип
                $productArray['gallery'] = $originalGallery;
            }
        }
        
        // 4. Гарантируем правильную структуру каждого изображения
        foreach ($productArray['images'] as &$image) {
            if (is_array($image)) {
                $image = [
                    'id' => $image['id'] ?? 0,
                    'url' => $image['url'] ?? $image['thumbnail'] ?? $image['original'] ?? '',
                    'thumbnail' => $image['thumbnail'] ?? $image['url'] ?? $image['original'] ?? '',
                    'original' => $image['original'] ?? $image['url'] ?? $image['thumbnail'] ?? '',
                ];
            }
        }
        
        unset($image);
        
        // Добавляем SEO-URL в формате /element/{full_slug} (с 12-значным кодом)
        // Используем full_slug если есть, иначе fallback на slug-id
        if (isset($productArray['slug'])) {
            if (!empty($productArray['slug_numeric_code'])) {
                // Новый формат с 12-значным кодом
                $fullSlug = "{$productArray['slug']}-{$productArray['slug_numeric_code']}";
                $productArray['url'] = "/element/{$fullSlug}";
            } elseif (isset($productArray['id'])) {
                // Старый формат с ID (для обратной совместимости)
                $productArray['url'] = "/element/{$productArray['slug']}-{$productArray['id']}";
            } else {
                $productArray['url'] = "/element/{$productArray['slug']}";
            }
        }
        
        // Добавляем canonical URL для каждого продукта
        // Товары не имеют языковых версий, поэтому canonical URL всегда без языкового префикса
        if (isset($productArray['slug'])) {
            $baseUrl = rtrim(config('app.url', 'https://sancan.ru'), '/');
            if (!empty($productArray['slug_numeric_code'])) {
                // Новый формат с 12-значным кодом
                $fullSlug = "{$productArray['slug']}-{$productArray['slug_numeric_code']}";
                $productArray['canonical_url'] = "{$baseUrl}/element/{$fullSlug}";
            } elseif (isset($productArray['id'])) {
                // Старый формат с ID (для обратной совместимости)
                $productArray['canonical_url'] = "{$baseUrl}/element/{$productArray['slug']}-{$productArray['id']}";
            } else {
                $productArray['canonical_url'] = "{$baseUrl}/element/{$productArray['slug']}";
            }
        }
        
        return $productArray;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Product[]
     */
    public function index(Request $request)
    {
        // Карта: отдельный ответ с lat/lng (не путать с пагинацией каталога).
        if ($request->filled('bbox')) {
            return $this->productsMapByBbox($request);
        }

        $limit = $request->limit ?   $request->limit : 15;
        $language = $request->language ?: DEFAULT_LANGUAGE;
        
        // Обрабатываем категории для включения дочерних
        $categorySlugs = $this->processCategoriesWithChildren($request->categories, $language);
        
        // Создаем новый запрос с обработанными категориями
        $modifiedRequest = clone $request;
        $modifiedRequest->merge(['categories' => $categorySlugs]);
        
        // Убеждаемся, что атрибуты загружаются, если они запрошены
        $with = $request->input('with', '');
        if (strpos($with, 'attributes') === false && strpos($with, 'attributes') === false) {
            // Если attributes не указаны в with, но нужны для attribute_values, добавляем их
            // Но только если запрашиваются товары группы (group_key)
            if ($request->has('group_key')) {
                $with = $with ? $with . ';attributes' : 'attributes';
                $modifiedRequest->merge(['with' => $with]);
            }
        }
        
        // Убеждаемся, что атрибуты загружаются через with
        $withRelations = explode(';', $modifiedRequest->input('with', ''));
        if (!in_array('attributes', $withRelations) && $modifiedRequest->has('group_key')) {
            $withRelations[] = 'attributes';
            $modifiedRequest->merge(['with' => implode(';', array_filter($withRelations))]);
        }
        
        $result = $this->fetchProducts($modifiedRequest)->paginate($limit);
        
        // Логируем результат для отладки
        if ($modifiedRequest->has('group_key') && $result && method_exists($result, 'getCollection')) {
            $collection = $result->getCollection();
            \Log::info('ProductController::index - group_key result', [
                'group_key' => $modifiedRequest->input('group_key'),
                'products_count' => $collection->count(),
                'first_product_id' => $collection->first()?->id ?? null,
                'first_product_group_key' => $collection->first()?->group_key ?? null,
                'first_product_has_attributes' => $collection->first()?->relationLoaded('attributes') ?? false,
            ]);
        }
        
        // attribute_values теперь добавляются автоматически через accessor в модели Product
        // Но убеждаемся, что атрибуты загружены для всех товаров в коллекции
        if ($result && method_exists($result, 'getCollection')) {
            $collection = $result->getCollection();
            $needsAttributes = false;
            
            // Проверяем, нужно ли загружать атрибуты
            foreach ($collection as $product) {
                if (!$product->relationLoaded('attributes')) {
                    $needsAttributes = true;
                    break;
                }
            }
            
            // Если нужно, загружаем атрибуты для всех товаров
            if ($needsAttributes) {
                \Log::info('ProductController::index - loading attributes for products', [
                    'products_count' => $collection->count(),
                ]);
                $collection->load('attributes');
            }
        }
        
        return $result;
    }

    /**
     * Display a listing of the resource with caching for dynamic rendering.
     *
     * @param Request $request
     * @return Collection|Product[]
     */
    public function dynamicProducts(Request $request)
    {
        $limit = $request->limit ?: 20;
        $language = $request->language ?: DEFAULT_LANGUAGE;
        
        // Обрабатываем категории для включения дочерних
        $categorySlugs = $this->processCategoriesWithChildren($request->categories, $language);
        
        \Log::info('Dynamic products request:', [
            'original_categories' => $request->categories,
            'processed_categories' => $categorySlugs,
            'language' => $language
        ]);
        
        // Создаем ключ кэша на основе параметров запроса
        $cacheKey = 'products_dynamic_' . md5(serialize([
            'language' => $language,
            'limit' => $limit,
            'page' => $request->page ?: 1,
            'categories' => $categorySlugs,
            'tags' => $request->tags,
            'shop_id' => $request->shop_id,
            'price' => $request->price,
            'name' => $request->name,
            'with' => $request->with,
            'orderBy' => $request->orderBy,
            'sortedBy' => $request->sortedBy,
            'attribute_values' => $request->attribute_values,
        ]));

        $fetchProducts = function () use ($request, $limit, $categorySlugs, $language) {
            
            try {
                // Создаем запрос через модель Product напрямую
                $query = \Marvel\Database\Models\Product::where('language', $language)
                    ->where('status', 'publish');
                
                $this->applyCatalogFiltersToProductQuery($query, $request, $categorySlugs);
                
                $withRelations = ['shop', 'type'];
                if ($request->with) {
                    $withRelations = array_merge($withRelations, explode(';', $request->with));
                }
                
                $canLoadVideos = false;
                try {
                    if (class_exists(\Marvel\Database\Models\ProductVideo::class)) {
                        if (Schema::hasTable('product_videos')) {
                            $canLoadVideos = true;
                        }
                    }
                } catch (\Throwable $e) {
                    $canLoadVideos = false;
                }
                
                if ($canLoadVideos && !in_array('videos', $withRelations)) {
                    $withRelations[] = 'videos';
                }
                
                if ($request->orderBy === 'orders_count') {
                    $query->withCount('orders');
                }
                
                if ($request->orderBy && $request->sortedBy) {
                    $orderBy = $request->orderBy;
                    $sortedBy = strtoupper($request->sortedBy) === 'ASC' ? 'asc' : 'desc';
                    $query->orderBy($orderBy, $sortedBy);
                } else {
                    $query->orderBy('updated_at', 'desc');
                }
                
                $result = $query->with($withRelations)->paginate($limit);
                
                if ($canLoadVideos) {
                    $result->getCollection()->transform(function ($product) {
                        $hasVideoAsCover = false;
                        $coverVideo = null;
                        
                        if (!$product->relationLoaded('videos')) {
                            try {
                                $product->load('videos');
                            } catch (\Exception $e) {
                            }
                        }
                        
                        if ($product->videos && $product->videos->count() > 0) {
                            try {
                                $videoAsCover = $product->getMeta('video_as_cover');
                                $coverVideoId = $product->getMeta('cover_video_id');
                                
                                if ($videoAsCover && $coverVideoId) {
                                    $coverVideo = $product->videos->firstWhere('id', $coverVideoId);
                                    if ($coverVideo) {
                                        $hasVideoAsCover = true;
                                    }
                                }
                                
                                if (!$hasVideoAsCover && $videoAsCover) {
                                    $coverVideo = $product->videos->first();
                                    $hasVideoAsCover = true;
                                }
                            } catch (\Exception $e) {
                            }
                        }
                        
                        $product->setAttribute('has_video_as_cover', $hasVideoAsCover);
                        if ($coverVideo) {
                            $product->setAttribute('cover_video', $coverVideo);
                        }
                        
                        return $product;
                    });
                }
                
                return $result;
                
            } catch (\Exception $e) {
                \Log::error('Error fetching products:', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'categories' => $categorySlugs
                ]);
                
                return new \Illuminate\Pagination\LengthAwarePaginator(
                    [],
                    0,
                    $limit,
                    1,
                    ['path' => request()->url()]
                );
            }
        };

        // Каталог должен оставаться доступным даже при временной проблеме
        // файлового/внешнего cache store. Ранее ошибка записи Cache::remember
        // происходила до выполнения callback и превращала запрос категории в 500.
        try {
            return \Cache::remember($cacheKey, 300, $fetchProducts);
        } catch (\Throwable $cacheException) {
            \Log::warning('Dynamic products cache unavailable; using direct query', [
                'error' => $cacheException->getMessage(),
                'categories' => $categorySlugs,
            ]);

            return $fetchProducts();
        }
    }

    /**
     * Process categories to include all child categories recursively
     */
    private function processCategoriesWithChildren($categories, $language)
    {
        if (empty($categories)) {
            return [];
        }

        $categorySlugs = is_array($categories) ? $categories : [$categories];
        $allCategorySlugs = [];

        foreach ($categorySlugs as $categorySlug) {
            if (empty($categorySlug)) {
                continue;
            }

            $allCategorySlugs[] = $categorySlug;

            $category = \Marvel\Database\Models\Category::where('slug', $categorySlug)
                ->where('language', $language)
                ->first();

            if ($category) {
                $childSlugs = $this->getAllChildCategorySlugs($category->id, $language);
                $allCategorySlugs = array_merge($allCategorySlugs, $childSlugs);
            }
        }

        return array_unique(array_filter($allCategorySlugs));
    }

    /**
     * Каталоговые фильтры (как в dynamicProducts): категории, теги, магазин, имя, цена, атрибуты.
     * Префикс products.* — чтобы запрос оставался однозначным при JOIN (карта по bbox).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Marvel\Database\Models\Product>  $query
     */
    private function applyCatalogFiltersToProductQuery($query, Request $request, array $categorySlugs): void
    {
        if (!empty($categorySlugs)) {
            $query->whereHas('categories', function ($q) use ($categorySlugs) {
                $q->whereIn('slug', $categorySlugs);
            });
        }

        if ($request->tags) {
            $query->whereHas('tags', function ($q) use ($request) {
                $q->whereIn('slug', is_array($request->tags) ? $request->tags : [$request->tags]);
            });
        }

        if ($request->shop_id) {
            $query->where('products.shop_id', $request->shop_id);
        }

        if ($request->name) {
            $query->where('products.name', 'like', '%' . $request->name . '%');
        }

        if ($request->price) {
            $priceRange = explode('-', $request->price);
            if (count($priceRange) == 2) {
                $query->whereBetween('products.price', [$priceRange[0], $priceRange[1]]);
            }
        }

        $attributeValuesFilter = $request->attribute_values;
        if (is_string($attributeValuesFilter)) {
            $decoded = json_decode($attributeValuesFilter, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE && $attributeValuesFilter !== 'null') {
                \Log::warning('Failed to decode attribute_values JSON:', [
                    'input' => $attributeValuesFilter,
                    'json_error' => json_last_error_msg(),
                ]);
                $attributeValuesFilter = null;
            } else {
                $attributeValuesFilter = $decoded;
            }
        }

        if ($attributeValuesFilter && is_array($attributeValuesFilter) && !empty($attributeValuesFilter)) {
            foreach ($attributeValuesFilter as $attributeId => $valueIds) {
                $attributeId = is_numeric($attributeId) ? (int) $attributeId : $attributeId;

                if (!empty($valueIds) && is_array($valueIds)) {
                    $firstValue = count($valueIds) === 1 ? $valueIds[0] : null;

                    if ($firstValue && is_string($firstValue) && strpos($firstValue, '-') !== false) {
                        $rangeParts = explode('-', $firstValue, 2);
                        if (count($rangeParts) === 2) {
                            $minValue = trim($rangeParts[0]);
                            $maxValue = trim($rangeParts[1]);

                            if (is_numeric($minValue) && is_numeric($maxValue)) {
                                $minValueFloat = floatval($minValue);
                                $maxValueFloat = floatval($maxValue);

                                $query->whereIn('products.id', function ($subQuery) use ($attributeId, $minValueFloat, $maxValueFloat) {
                                    $subQuery->select('product_id')
                                        ->from('product_attribute_values')
                                        ->where('attribute_id', $attributeId)
                                        ->whereRaw('CAST(value AS DECIMAL(10,2)) >= ?', [$minValueFloat])
                                        ->whereRaw('CAST(value AS DECIMAL(10,2)) <= ?', [$maxValueFloat]);
                                });
                                continue;
                            }
                        }
                    }

                    $numericValueIds = array_map(function ($id) {
                        return is_numeric($id) ? (int) $id : $id;
                    }, $valueIds);

                    $attributeValues = \Marvel\Database\Models\AttributeValue::whereIn('id', $numericValueIds)
                        ->pluck('value', 'id')
                        ->toArray();

                    if (!empty($attributeValues)) {
                        $valuesArray = array_values($attributeValues);
                        $query->whereIn('products.id', function ($subQuery) use ($attributeId, $valuesArray) {
                            $subQuery->select('product_id')
                                ->from('product_attribute_values')
                                ->where('attribute_id', $attributeId)
                                ->whereIn('value', $valuesArray);
                        });
                    } else {
                        $query->whereIn('products.id', function ($subQuery) use ($attributeId, $valueIds) {
                            $subQuery->select('product_id')
                                ->from('product_attribute_values')
                                ->where('attribute_id', $attributeId)
                                ->whereIn('value', $valueIds);
                        });
                    }
                }
            }
        }
    }

    /**
     * Get all child category slugs recursively
     */
    private function getAllChildCategorySlugs($parentId, $language)
    {
        $childSlugs = [];
        
        $children = \Marvel\Database\Models\Category::where('parent', $parentId)
            ->where('language', $language)
            ->get(['id', 'slug', 'name']);

        foreach ($children as $child) {
            if (!empty($child->slug)) {
                $childSlugs[] = $child->slug;
                $grandChildren = $this->getAllChildCategorySlugs($child->id, $language);
                $childSlugs = array_merge($childSlugs, $grandChildren);
            }
        }

        return $childSlugs;
    }

    public function fetchProducts(Request $request)
    {
        $unavailableProducts = [];
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
        if (isset($request->date_range)) {
            $dateRange = explode('//', $request->date_range);
            $unavailableProducts = $this->repository->getUnavailableProducts($dateRange[0], $dateRange[1]);
        }
        if (in_array('variation_options.digital_files', explode(';', $request->with)) || in_array('digital_files', explode(';', $request->with))) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        
        // Логируем запрос для отладки
        if ($request->has('group_key')) {
            \Log::info('ProductController::fetchProducts - group_key request', [
                'group_key' => $request->input('group_key'),
                'with' => $request->input('with'),
                'status' => $request->input('status'),
                'language' => $language,
            ]);
        }
        
        $this->repository->pushCriteria(app(\Prettus\Repository\Criteria\RequestCriteria::class));
        
        $query = $this->repository->where('language', $language)->whereNotIn('id', $unavailableProducts);
        
        // Явно добавляем фильтр по group_key, если он указан
        // RequestCriteria должен обработать это через fieldSearchable, но на всякий случай добавляем явно
        if ($request->has('group_key') && $request->input('group_key')) {
            $groupKey = $request->input('group_key');
            \Log::info('ProductController::fetchProducts - applying group_key filter', [
                'group_key' => $groupKey,
            ]);
            $query = $query->where('group_key', $groupKey);
        }
        
        return $query;
    }

    /**
     * Store a newly created resource in storage by rest.
     */
    public function store(ProductCreateRequest $request)
    {
        return $this->ProductStore($request);
    }

    /**
     * Store a newly created resource in storage by GQL.
     */
    public function ProductStore(Request $request)
    {
        try {
            $setting = $this->settings->first();
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->storeProduct($request, $setting);
            } else {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     * Поддерживает формат: /element/{slug}-{id} или /element/{slug} (старый формат)
     */
    public function show(Request $request, $slugId)
    {
        try {
            // Если сработал products/{product} с product=map вместо статического products/map
            if ($slugId === 'map') {
                return $this->productsMapByBbox($request);
            }

            $language = $request->language ?? DEFAULT_LANGUAGE;
            
            // Логируем для отладки
            \Log::info('ProductController::show', [
                'slugId' => $slugId,
                'language' => $language,
                'has_with' => $request->has('with'),
                'query_params' => $request->query(),
            ]);
            
            // Сначала пробуем найти товар напрямую по slug (как есть в URL)
            // Это работает для:
            // - Новых товаров с 12-значным кодом: kartina-123456789012
            // - Старых товаров с буквенным кодом: kartina-SE5
            // - Древних товаров без кода: kartina-abstrakciya
            // Ищем по полному slug (с кодом) или по базовому slug + slug_numeric_code
            $product = Product::where(function($query) use ($slugId) {
                    // 1. Прямое совпадение slug (для старых товаров, где код в slug)
                    $query->where('slug', $slugId);
                    
                    // 2. Поиск по базовому slug + slug_numeric_code (для новых товаров)
                    // Проверяем, заканчивается ли slugId на 12-значный код
                    if (preg_match('/^(.+)-(\d{12})$/', $slugId, $matches)) {
                        $baseSlug = $matches[1];
                        $code = $matches[2];
                        $query->orWhere(function($q) use ($baseSlug, $code) {
                            $q->where('slug', $baseSlug)
                              ->where('slug_numeric_code', $code);
                        });
                    }
                    
                    // 3. Поиск через CONCAT для совместимости
                    $query->orWhereRaw("CONCAT(COALESCE(slug, ''), '-', COALESCE(slug_numeric_code, '')) = ?", [$slugId]);
                })
                ->where('language', $language)
                ->first();
            
            if ($product) {
                \Log::info('Product found by direct slug match', [
                    'slugId' => $slugId,
                    'product_id' => $product->id,
                    'product_slug' => $product->slug,
                ]);
                
                // Загружаем полные данные товара
                $request->merge(['slug' => $product->slug]);
                return $this->fetchSingleProduct($request);
            }
            
            // Если не найден напрямую, парсим slug для старых форматов
            $parsed = Product::parseSlugId($slugId);
            $slug = $parsed['slug'];
            $id = $parsed['id'] ?? null;
            $code = $parsed['code'] ?? null;
            
            \Log::info('Product not found by direct match, trying to parse', [
                'slugId' => $slugId,
                'parsed_slug' => $slug,
                'parsed_id' => $id,
                'parsed_code' => $code,
            ]);
            
            // Если не найден напрямую, пробуем альтернативные способы:
            
            // 1. Если есть ID из старого формата - ищем по ID
            if ($id) {
                $product = Product::where('language', $language)->find($id);
                if ($product) {
                    \Log::info('Product found by old ID', [
                        'slugId' => $slugId,
                        'old_id' => $id,
                        'product_id' => $product->id,
                        'product_slug' => $product->slug,
                    ]);
                    
                    // Загружаем полные данные товара
                    $request->merge(['slug' => $product->slug]);
                    return $this->fetchSingleProduct($request);
                }
            }
            
            // 2. Пробуем найти по парсированному slug (без кода)
            if ($slug !== $slugId) {
                $product = Product::where('slug', $slug)
                    ->where('language', $language)
                    ->first();
                    
                if ($product) {
                    \Log::info('Product found by parsed slug', [
                        'slugId' => $slugId,
                        'parsed_slug' => $slug,
                        'product_id' => $product->id,
                        'product_slug' => $product->slug,
                    ]);
                    
                    // Загружаем полные данные товара
                    $request->merge(['slug' => $product->slug]);
                    return $this->fetchSingleProduct($request);
                }
            }
            
            // 3. Пробуем через историю slug
            $product = Product::findBySlugOrHistory($slugId, $language);
            if (!$product && $slug !== $slugId) {
                $product = Product::findBySlugOrHistory($slug, $language);
            }
            
            if ($product) {
                \Log::info('Product found via slug history', [
                    'slugId' => $slugId,
                    'product_id' => $product->id,
                    'product_slug' => $product->slug,
                ]);
                
                // Загружаем полные данные товара
                $request->merge(['slug' => $product->slug]);
                return $this->fetchSingleProduct($request);
            }

            // 4. Товар не найден никаким способом
            \Log::warning('Product not found anywhere', [
                'slugId' => $slugId,
                'parsed_slug' => $slug,
                'parsed_id' => $id,
                'parsed_code' => $code,
                'language' => $language,
            ]);
            throw new MarvelNotFoundException();

        } catch (MarvelNotFoundException $e) {
            throw $e;
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (Exception $e) {
            \Log::error('ProductController::show - ERROR', [
                'slugId' => $slugId,
                'error' => $e->getMessage(),
            ]);
            throw new MarvelNotFoundException();
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function fetchSingleProduct(Request $request)
    {
        try {
            $slug = $request->slug;
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $user = $request->user();
            $limit = isset($request->limit) ? $request->limit : 10;
            
            // Находим товар БЕЗ связей
            $product = Product::where('language', $language)
                ->where('slug', $slug)
                ->first();
            
            // Если не нашли по slug, пробуем по id (если slug числовой)
            if (!$product && is_numeric($slug)) {
                $product = Product::where('language', $language)
                    ->where('id', $slug)
                    ->first();
            }
            
            if (!$product) {
                throw new MarvelNotFoundException();
            }
            
            
            // Загружаем связи безопасно
            try {
                $product->load(['type', 'shop', 'categories', 'tags']);
            } catch (\Exception $e) {
                \Log::error('Error loading basic relations', [
                    'product_id' => $product->id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }

            try {
                if (Schema::hasColumn('products', 'geo_point_id')) {
                    $product->load(['geoPoint', 'region']);
                }
            } catch (\Exception $e) {
                \Log::debug('ProductController::fetchSingleProduct - geo/region load skipped', [
                    'product_id' => $product->id ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
            
            try {
                $product->load(['author', 'manufacturer']);
            } catch (\Exception $e) {
            }
            
            $canLoadVideos = false;
            try {
                if (class_exists(\Marvel\Database\Models\ProductVideo::class)) {
                    if (Schema::hasTable('product_videos')) {
                        $canLoadVideos = true;
                    }
                }
            } catch (\Throwable $e) {
                $canLoadVideos = false;
            }
            
            if ($canLoadVideos) {
                try {
                    $product->load('videos');
                } catch (\Exception $e) {
                }
            }
            
            try {
                $product->load('variation_options');
            } catch (\Exception $e) {
            }
            
            try {
                $product->load([
                    'variations' => function($query) {
                        $query->with('attribute');
                    }
                ]);
            } catch (\Exception $e) {
            }
            
            // Загружаем атрибуты с правильной структурой
            try {
                $product->load([
                    'attributes' => function($query) {
                        $query->orderBy('id', 'ASC');
                    }
                ]);
            } catch (\Exception $e) {
                \Log::error('Error loading product attributes in fetchSingleProduct', [
                    'product_id' => $product->id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
            
            if (
                in_array('variation_options.digital_file', explode(';', $request->with ?? '')) || 
                in_array('digital_file', explode(';', $request->with ?? ''))
            ) {
                if (!$this->repository->hasPermission($user, $product->shop_id)) {
                    throw new AuthorizationException(NOT_AUTHORIZED);
                }
                // Для админки загружаем digital_file и явно показываем url.
                // Иначе фронт получает только attachment_id и "теряет" файл в редакторе после reload.
                try {
                    $product->load('digital_file');
                    if ($product->digital_file) {
                        $product->digital_file->makeVisible(['url']);
                    }
                } catch (\Exception $e) {
                    \Log::warning('ProductController::fetchSingleProduct - failed to load digital_file', [
                        'product_id' => $product->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            if ($canLoadVideos) {
                try {
                    if (!$product->relationLoaded('videos')) {
                        $product->load('videos');
                    }
                    
                    if ($product->videos && $product->videos->count() > 0) {
                        $videoAsCover = $product->getMeta('video_as_cover');
                        $coverVideoId = $product->getMeta('cover_video_id');
                        
                        if ($videoAsCover && $coverVideoId) {
                            $coverVideo = $product->videos->firstWhere('id', $coverVideoId);
                            if ($coverVideo) {
                                $product->setAttribute('has_video_as_cover', true);
                                $product->setAttribute('cover_video', $coverVideo);
                            }
                        } else if ($videoAsCover) {
                            $coverVideo = $product->videos->first();
                            if ($coverVideo) {
                                $product->setAttribute('has_video_as_cover', true);
                                $product->setAttribute('cover_video', $coverVideo);
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            
            // ВАЖНО: Убеждаемся, что attributes загружены для accessor attribute_values
            if (!$product->relationLoaded('attributes')) {
                $product->load([
                    'attributes' => function($query) {
                        $query->orderBy('id', 'ASC');
                    }
                ]);
            }
            
            // Преобразуем в массив безопасно
            try {
                $productArray = $product->toArray();
                
                // Логируем для отладки group_key и attribute_values
                if (isset($productArray['group_key']) || isset($productArray['attribute_values'])) {
                    \Log::info('ProductController::fetchSingleProduct - group_key and attribute_values', [
                        'product_id' => $product->id,
                        'has_group_key' => isset($productArray['group_key']),
                        'group_key' => $productArray['group_key'] ?? null,
                        'has_attribute_values' => isset($productArray['attribute_values']),
                        'attribute_values' => $productArray['attribute_values'] ?? null,
                        'attributes_loaded' => $product->relationLoaded('attributes'),
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('Error converting product to array', [
                    'product_id' => $product->id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                
                try {
                    $productArray = json_decode(json_encode($product), true);
                } catch (\Exception $e2) {
                    \Log::error('Error converting product to array (JSON fallback)', [
                        'product_id' => $product->id ?? 'unknown',
                        'error' => $e2->getMessage()
                    ]);
                    $productArray = [
                        'id' => $product->id ?? null,
                        'name' => $product->name ?? '',
                        'slug' => $product->slug ?? '',
                        'description' => $product->description ?? '',
                        'price' => $product->price ?? 0,
                        'sale_price' => $product->sale_price ?? null,
                        'image' => $product->image ?? null,
                        'gallery' => $product->gallery ?? null,
                        'status' => $product->status ?? 'draft',
                        'language' => $product->language ?? $language,
                        'group_key' => $product->group_key ?? null, // ВАЖНО: включаем group_key
                    ];
                }
            }
            
            // Добавляем значения атрибутов в массив для фронтенда (простой формат ключ-значение)
            try {
                $attributeValues = [];
                if ($product->relationLoaded('attributes') && $product->attributes) {
                    foreach ($product->attributes as $attribute) {
                        $attrId = (string)$attribute->id;
                        $value = $attribute->pivot->value ?? null;
                        
                        // Преобразуем значение в строку, если оно не пустое
                        if ($value !== null && $value !== '' && $value !== 'NaN' && strtolower($value) !== 'nan') {
                            $attributeValues[$attrId] = (string)$value;
                        }
                    }
                }
                
                $productArray['attribute_values'] = $attributeValues;
                
                // Логируем для отладки
                \Log::info('ProductController::fetchSingleProduct - Attributes loaded', [
                    'product_id' => $product->id,
                    'attributes_count' => count($productArray['attributes'] ?? []),
                    'attribute_values_count' => count($attributeValues),
                ]);
            } catch (\Exception $e) {
                \Log::error('Error getting attribute values in fetchSingleProduct', [
                    'product_id' => $product->id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                $productArray['attribute_values'] = [];
            }

            if ($request->has('with') && $user && $this->repository->hasPermission($user, $product->shop_id)) {
                try {
                    $productArray['digital_license_keys'] = ProductKey::query()
                        ->where('product_id', $product->id)
                        ->orderBy('id')
                        ->pluck('key')
                        ->implode("\n");
                } catch (\Throwable $e) {
                    $productArray['digital_license_keys'] = '';
                }
            }

            $withParts = array_filter(explode(';', (string) ($request->with ?? '')));
            if (in_array('course', $withParts, true) && $user && $this->repository->hasPermission($user, $product->shop_id)) {
                try {
                    if (Schema::hasTable('courses')) {
                        $courseModel = Course::query()
                            ->where('required_product_id', $product->id)
                            ->with(['lessons' => function ($q) {
                                $q->orderBy('position')->orderBy('id');
                            }])
                            ->first();
                        $productArray['course'] = $courseModel ? $courseModel->toArray() : null;
                    } else {
                        $productArray['course'] = null;
                    }
                } catch (\Throwable $e) {
                    $productArray['course'] = null;
                }
            }
            
            // Загружаем связанные товары
            try {
                $productArray['related_products'] = $this->repository->fetchRelated($slug, $limit, $language);
            } catch (\Exception $e) {
                $productArray['related_products'] = [];
            }


            // Для админки (если есть параметр with) возвращаем просто данные товара БЕЗ обработки
            // КАК В ProductControllerREL_NEW.php - просто возвращаем как есть!
            if ($request->has('with')) {
                // Админка - возвращаем только данные товара как есть (gallery не трогаем!)
                return response()->json($productArray);
            }
            
            // Фронтенд - обрабатываем images и URL (из ProductControllerREL_NEW.php)
            // НО: не трогаем gallery, только создаем images на основе gallery
            $productArray = $this->ensureProductImages($productArray);
            
            // Генерируем canonical и meta данные (из ProductControllerREL_NEW.php)
            $canonicalUrl = $this->generateCanonicalUrl($productArray, $request);
            $hreflangTags = $this->generateHreflangTags($productArray, $request);
            
            // Фронтенд - возвращаем структурированный ответ с meta
            return response()->json([
                'success' => true,
                'data' => $productArray,
                'meta' => [
                    'canonical' => $canonicalUrl,
                    'hreflang' => $hreflangTags,
                    'title' => $productArray['meta_title'] ?? $productArray['name'] ?? '',
                    'description' => $productArray['meta_description'] ?? 
                        (isset($productArray['description']) ? mb_substr(strip_tags($productArray['description']), 0, 160) : ''),
                    'og_image' => !empty($productArray['images'][0]['original']) 
                        ? $productArray['images'][0]['original']
                        : (!empty($productArray['images'][0]['url']) 
                            ? $productArray['images'][0]['url'] 
                            : null),
                    'og_type' => 'product',
                    'product_price' => $productArray['price'] ?? null,
                    'product_currency' => 'RUB',
                ]
            ]);
            
        } catch (MarvelNotFoundException $e) {
            throw $e;
        } catch (Exception $e) {
            \Log::error('Error fetching single product', [
                'slug' => $request->slug ?? 'unknown',
                'language' => $request->language ?? DEFAULT_LANGUAGE,
                'error' => $e->getMessage()
            ]);
            
            // Возвращаем структурированную ошибку
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Product not found',
                'images' => [],
                'gallery' => []
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProductUpdateRequest $request, $id)
    {
        try {
            // Обрабатываем FormData ДО валидации, если это multipart/form-data
            // КАК В ProductControllerREL_NEW.php - ПРОСТАЯ ОБРАБОТКА БЕЗ СПЕЦИАЛЬНОЙ ЛОГИКИ ДЛЯ GALLERY
            if ($request->isMethod('post') || $request->header('Content-Type') && str_contains($request->header('Content-Type'), 'multipart/form-data')) {
                $requestData = $request->all();
                foreach ($requestData as $key => $value) {
                    // product_type должен быть строкой, не парсим его
                    if ($key === 'product_type') {
                        continue;
                    }
                    // Парсим JSON строки из FormData
                    if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $request->merge([$key => $decoded]);
                        }
                    }
                }
            }
            
            $request->id = $id;
            return $this->updateProduct($request);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateProduct(Request $request)
    {
        $setting = $this->settings->first();
        $id = $request->id;
        
        // ВАЖНО: Получаем shop_id из товара, а не из запроса
        // При обновлении товара shop_id может не передаваться в запросе или быть null
        try {
            $product = $this->repository->findOrFail($id);
            // Используем shop_id из товара, если он не передан в запросе
            $shopId = $request->shop_id ?? $product->shop_id;
            
            if (!$shopId) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            
            if ($this->repository->hasPermission($request->user(), $shopId)) {
                return $this->repository->updateProduct($request, $id, $setting);
            } else {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        } catch (MarvelNotFoundException $e) {
            throw new MarvelNotFoundException(NOT_FOUND);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $request->id = $id;
        return $this->destroyProduct($request);
    }

    public function destroyProduct(Request $request)
    {
        try {
            \Log::info('Attempting to delete product', [
                'product_id' => $request->id,
                'user_id' => $request->user() ? $request->user()->id : 'guest'
            ]);
            
            $product = $this->repository->findOrFail($request->id);
            
            \Log::info('Product found', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'shop_id' => $product->shop_id
            ]);
            
            if ($this->repository->hasPermission($request->user(), $product->shop_id)) {
                $product->delete();
                \Log::info('Product deleted successfully', ['product_id' => $product->id]);
                return $product;
            }
            \Log::warning('User not authorized to delete product', [
                'user_id' => $request->user() ? $request->user()->id : 'guest',
                'shop_id' => $product->shop_id
            ]);
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            \Log::error('Error deleting product', [
                'product_id' => $request->id,
                'error' => $e->getMessage()
            ]);
            throw new MarvelException($e->getMessage());
        } catch (\Exception $e) {
            \Log::error('Unexpected error deleting product', [
                'product_id' => $request->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new MarvelException($e->getMessage());
        }
    }

    public function relatedProducts(Request $request)
    {
        $limit = isset($request->limit) ? $request->limit : 10;
        $slug =  $request->slug;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        return $this->repository->fetchRelated($slug, $limit, $language);
    }

    public function exportProducts(Request $request, $shop_id)
    {
        $filename = 'products-for-shop-id-' . $shop_id . '.csv';
        $headers = [
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires'             => '0',
            'Pragma'              => 'public'
        ];

        $list = $this->repository->where('shop_id', $shop_id)->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
            }, 200, $headers);
        }
        
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'slug', 'deleted_at', 'created_at', 'updated_at', 'shipping_class_id', 'author_id', 'manufacturer_id', 'ratings', 'total_reviews', 'total_downloads', 'my_review', 'in_wishlist', 'rating_count', 'translated_languages', 'digital_file',];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['deleted_at']);
                unset($row['shipping_class_id']);
                unset($row['updated_at']);
                unset($row['created_at']);
                unset($row['slug']);
                unset($row['author_id']);
                unset($row['manufacturer_id']);
                unset($row['ratings']);
                unset($row['total_reviews']);
                unset($row['total_downloads']);
                unset($row['my_review']);
                unset($row['in_wishlist']);
                unset($row['rating_count']);
                unset($row['translated_languages']);
                unset($row['digital_file']);
                if (isset($row['is_digital'])) {
                    $row['is_digital'] = '0';
                }
                if (isset($row['image'])) {
                    $row['image'] = json_encode($row['image']);
                }
                if (isset($row['gallery'])) {
                    $row['gallery'] = json_encode($row['gallery']);
                }
                if (isset($row['blocked_dates'])) {
                    $row['blocked_dates'] = json_encode($row['blocked_dates']);
                }
                if (isset($row['video'])) {
                    $row['video'] = json_encode($row['video']);
                }
                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportVariableOptions(Request $request, $shop_id)
    {
        $filename = 'variable-options-' . Str::random(5) . '.csv';
        $headers = [
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires'             => '0',
            'Pragma'              => 'public'
        ];

        $products = $this->repository->where('shop_id', $shop_id)->get();

        $list = Variation::WhereIn('product_id', $products->pluck('id'))->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
            }, 200, $headers);
        }
        
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'created_at', 'updated_at', 'translated_languages'];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['updated_at']);
                unset($row['created_at']);
                unset($row['translated_languages']);
                if (isset($row['options'])) {
                    $row['options'] = json_encode($row['options']);
                }
                if (isset($row['blocked_dates'])) {
                    $row['blocked_dates'] = json_encode($row['blocked_dates']);
                }
                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importProducts(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (count($requestFile)) {
            if (isset($requestFile['csv'])) {
                $uploadedCsv = $requestFile['csv'];
            } else {
                $uploadedCsv = current($requestFile);
            }
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        if (isset($shop_id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'products-' . $shop_id . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $products = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($products as $key => $product) {
                if (!isset($product['type_id'])) {
                    throw new MarvelException("MARVEL_ERROR.WRONG_CSV");
                }
                unset($product['id']);
                $product['shop_id'] = $shop_id;
                $product['image'] = json_decode($product['image'], true);
                $product['gallery'] = json_decode($product['gallery'], true);
                $product['video'] = json_decode($product['video'], true);
                try {
                    $type = Type::findOrFail($product['type_id']);
                    if (isset($type->id)) {
                        Product::firstOrCreate($product);
                    }
                } catch (Exception $e) {
                }
            }
            return true;
        }
    }

    public function importVariationOptions(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (count($requestFile)) {
            if (isset($requestFile['csv'])) {
                $uploadedCsv = $requestFile['csv'];
            } else {
                $uploadedCsv = current($requestFile);
            }
        } else {
            throw new MarvelException(CSV_NOT_FOUND);
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        if (isset($user->id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'variation-options-' . Str::random(5) . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $attributes = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($attributes as $key => $attribute) {
                if (!isset($attribute['title']) || !isset($attribute['price'])) {
                    throw new MarvelException("MARVEL_ERROR.WRONG_CSV");
                }
                unset($attribute['id']);
                $attribute['options'] = json_decode($attribute['options'], true);
                try {
                    $product = Type::findOrFail($attribute['product_id']);
                    if (isset($product->id)) {
                        Variation::firstOrCreate($attribute);
                    }
                } catch (Exception $e) {
                }
            }
            return true;
        }
    }

    public function fetchDigitalFilesForProduct(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $product = $this->repository->with(['digital_file'])->findOrFail($request->parent_id);
            if ($this->repository->hasPermission($user, $product->shop_id)) {
                return $product->digital_file;
            }
        }
    }

    public function fetchDigitalFilesForVariation(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $variation_option = Variation::with(['digital_file', 'product'])->findOrFail($request->parent_id);
            if ($this->repository->hasPermission($user, $variation_option->product->shop_id)) {
                return $variation_option->digital_file;
            }
        }
    }

    public function bestSellingProducts(Request $request)
    {
        return $this->repository->getBestSellingProducts($request);
    }

    public function popularProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $range = !empty($request->range) && $request->range !== 'undefined'  ? $request->range : '';
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (MarvelException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }
        $products_query = $this->repository->withCount('orders')->with(['type', 'shop'])->orderBy('orders_count', 'desc')->where('language', $language);
        if (isset($request->shop_id)) {
            $products_query = $products_query->where('shop_id', "=", $request->shop_id);
        }

        $products_query = $products_query->withCount(['orders' => function ($query) use ($range) {
            if ($range) {
                $query->where('parent_id', null)->where('orders.created_at', '>', Carbon::now()->subDays($range + 2));
            }
        }]);
        if ($type_id) {
            $products_query = $products_query->where('type_id', '=', $type_id);
        }
        return $products_query->orderBy('orders_count', 'desc')->take($limit)->get();
    }

    public function calculateRentalPrice(Request $request)
    {
        $isAvailable = true;
        $product_id = $request->product_id;
        try {
            $product = Product::findOrFail($product_id);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
        if (!$product->is_rental) {
            throw new MarvelException(NOT_A_RENTAL_PRODUCT);
        }
        $variation_id = $request->variation_id;
        $quantity = $request->quantity;
        $persons = $request->persons;
        $dropoff_location_id = $request->dropoff_location_id;
        $pickup_location_id = $request->pickup_location_id;
        $deposits = $request->deposits;
        $features = $request->features;
        $from = $request->from;
        $to = $request->to;
        if ($variation_id) {
            $blockedDates = $this->repository->fetchBlockedDatesForAVariationInRange($from, $to, $variation_id);
            $isAvailable = $this->repository->isVariationAvailableAt($from, $to, $variation_id, $blockedDates, $quantity);
            if (!$isAvailable) {
                throw new marvelException(NOT_AVAILABLE_FOR_BOOKING);
            }
        } else {
            $blockedDates = $this->repository->fetchBlockedDatesForAProductInRange($from, $to, $product_id);
            $isAvailable = $this->repository->isProductAvailableAt($from, $to, $product_id, $blockedDates, $quantity);
            if (!$isAvailable) {
                throw new marvelException(NOT_AVAILABLE_FOR_BOOKING);
            }
        }

        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        $bookedDay = $from->diffInDays($to);

        return $this->repository->calculatePrice($bookedDay, $product_id, $variation_id, $quantity, $persons, $dropoff_location_id, $pickup_location_id, $deposits, $features);
    }

    public function myWishlists(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        return $this->fetchWishlists($request)->paginate($limit);
    }

    public function fetchWishlists(Request $request)
    {
        $user = $request->user();
        $wishlist = Wishlist::where('user_id', $user->id)->pluck('product_id');
        return $this->repository->whereIn('id', $wishlist);
    }

    public function searchProducts(Request $request)
    {
        $query = $request->get('q', '');
        $limit = $request->get('limit', 20);
        $language = $request->language ?: DEFAULT_LANGUAGE;
        
        if (empty($query)) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $cacheKey = 'products_search_' . md5($query . $limit . $language);
        
        return \Cache::remember($cacheKey, 180, function () use ($query, $limit, $language) {
            $products = $this->repository
                ->where('language', $language)
                ->where('status', 'publish')
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('slug', 'like', "%{$query}%")
                      ->orWhere('description', 'like', "%{$query}%");
                })
                ->with(['shop', 'type'])
                ->paginate($limit);
                
            return response()->json($products);
        });
    }

    public function testCategoryChildren(Request $request)
    {
        $language = $request->language ?: DEFAULT_LANGUAGE;
        $categorySlug = $request->category_slug;
        
        if (empty($categorySlug)) {
            $categories = \Marvel\Database\Models\Category::where('language', $language)
                ->where('status', 'publish')
                ->orderBy('parent', 'asc')
                ->orderBy('sort_order', 'asc')
                ->get(['id', 'name', 'slug', 'parent']);
            
            $rootCategories = $categories->where('parent', null);
            $childCategories = $categories->where('parent', '!=', null);
            
            $hierarchy = [];
            foreach ($rootCategories as $root) {
                $rootData = [
                    'id' => $root->id,
                    'name' => $root->name,
                    'slug' => $root->slug,
                    'parent' => $root->parent,
                    'children' => []
                ];
                
                $children = $childCategories->where('parent', $root->id);
                foreach ($children as $child) {
                    $childData = [
                        'id' => $child->id,
                        'name' => $child->name,
                        'slug' => $child->slug,
                        'parent' => $child->parent,
                        'children' => []
                    ];
                    
                    $grandchildren = $childCategories->where('parent', $child->id);
                    foreach ($grandchildren as $grandchild) {
                        $childData['children'][] = [
                            'id' => $grandchild->id,
                            'name' => $grandchild->name,
                            'slug' => $grandchild->slug,
                            'parent' => $grandchild->parent
                        ];
                    }
                    
                    $rootData['children'][] = $childData;
                }
                
                $hierarchy[] = $rootData;
            }
            
            return response()->json([
                'message' => 'Все категории с иерархией',
                'total_categories' => $categories->count(),
                'root_categories' => $rootCategories->count(),
                'child_categories' => $childCategories->count(),
                'hierarchy' => $hierarchy,
                'usage' => 'Добавьте ?category_slug=SLUG для тестирования конкретной категории'
            ]);
        }
        
        $category = \Marvel\Database\Models\Category::where('slug', $categorySlug)
            ->where('language', $language)
            ->first();
            
        if (!$category) {
            return response()->json(['error' => 'Category not found'], 404);
        }
        
        $childSlugs = $this->getAllChildCategorySlugs($category->id, $language);
        $allSlugs = array_merge([$categorySlug], $childSlugs);
        
        return response()->json([
            'parent_category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'parent' => $category->parent
            ],
            'child_slugs' => $childSlugs,
            'all_slugs' => $allSlugs,
            'total_children' => count($childSlugs)
        ]);
    }

    public function getFilters(Request $request)
    {
        $language = $request->language ?: DEFAULT_LANGUAGE;
        
        $cacheKey = 'products_filters_' . $language;
        
        return \Cache::remember($cacheKey, 600, function () use ($language) {
            try {
                $categories = \DB::table('categories')
                    ->where('language', $language)
                    ->select('id', 'name', 'slug')
                    ->orderBy('name')
                    ->get();

                $types = \DB::table('types')
                    ->where('language', $language)
                    ->select('id', 'name', 'slug')
                    ->orderBy('name')
                    ->get();

                $tags = collect([]);
                if (\Schema::hasTable('tags')) {
                    $tags = \DB::table('tags')
                        ->where('language', $language)
                        ->select('id', 'name', 'slug')
                        ->orderBy('name')
                        ->get();
                }

                $priceRanges = \DB::table('products')
                    ->where('language', $language)
                    ->where('status', 'publish')
                    ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
                    ->first();

                return response()->json([
                    'categories' => $categories,
                    'types' => $types,
                    'tags' => $tags,
                    'price_ranges' => $priceRanges,
                ]);
            } catch (\Exception $e) {
                \Log::error('Error in getFilters: ' . $e->getMessage());
                return response()->json([
                    'categories' => [],
                    'types' => [],
                    'tags' => [],
                    'price_ranges' => null,
                    'error' => $e->getMessage()
                ], 500);
            }
        });
    }

    /**
     * Список товаров для карты (Yandex): ?bbox=minLat,minLng,maxLat,maxLng
     * Маршрут: GET /products/map (и опционально GET /products?bbox=...).
     */
    public function productsMapByBbox(Request $request): JsonResponse
    {
        try {
            $bbox = $request->query('bbox');
            $regionId = $request->query('region_id');
            $limit = min(max((int) $request->query('limit', 100), 1), 200);

            if (!$request->filled('bbox') && !$request->filled('region_id')) {
                return response()->json([
                    'message' => 'Укажите query-параметр bbox (minLat,minLng,maxLat,maxLng) или region_id.',
                ], 422);
            }

            $query = Product::query()
                ->published()
                ->select('products.*');

            $language = $request->input('language') ?: DEFAULT_LANGUAGE;
            $categorySlugs = $this->processCategoriesWithChildren($request->categories, $language);
            $query->where('products.language', $language);
            $this->applyCatalogFiltersToProductQuery($query, $request, $categorySlugs);

            if ($request->filled('bbox')) {
                $query->with(['region']);
                $parts = explode(',', (string) $bbox);
                if (count($parts) !== 4) {
                    return response()->json([
                        'message' => 'bbox должен содержать 4 числа через запятую: minLat,minLng,maxLat,maxLng',
                    ], 422);
                }

                [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);
                if ($minLat > $maxLat) {
                    [$minLat, $maxLat] = [$maxLat, $minLat];
                }
                if ($minLng > $maxLng) {
                    [$minLng, $maxLng] = [$maxLng, $minLng];
                }

                $latDiff = $maxLat - $minLat;
                $lngDiff = $maxLng - $minLng;
                if ($latDiff > 10 || $lngDiff > 10) {
                    $minLat = $maxLat - 5;
                    $minLng = $maxLng - 5;
                }

                if (! Schema::hasColumn('products', 'geo_point_id')) {
                    return response()->json([
                        'message' => 'Колонка products.geo_point_id отсутствует. Выполните миграции.',
                    ], 503);
                }

                if (! Schema::hasTable('geo_points')) {
                    return response()->json([
                        'message' => 'Таблица geo_points не найдена. Выполните миграции.',
                    ], 503);
                }

                $query->join('geo_points as g', 'g.id', '=', 'products.geo_point_id');

                $driver = Schema::getConnection()->getDriverName();
                $hasLatLng = Schema::hasColumn('geo_points', 'lat')
                    && Schema::hasColumn('geo_points', 'lng');
                $hasLocation = Schema::hasColumn('geo_points', 'location');

                if ($hasLatLng) {
                    $query->whereBetween('g.lat', [$minLat, $maxLat])
                        ->whereBetween('g.lng', [$minLng, $maxLng])
                        ->addSelect(DB::raw('g.lat as map_lat'))
                        ->addSelect(DB::raw('g.lng as map_lng'));
                } elseif ($hasLocation && $driver === 'pgsql') {
                    $query->whereRaw(
                        'ST_Intersects(g.location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                        [$minLng, $minLat, $maxLng, $maxLat]
                    )->addSelect(
                        DB::raw('ST_Y(g.location::geometry) as map_lat'),
                        DB::raw('ST_X(g.location::geometry) as map_lng')
                    );
                } else {
                    return response()->json([
                        'message' => 'Таблица geo_points: нужны колонки lat/lng (MySQL) или location (PostgreSQL + PostGIS).',
                    ], 503);
                }
            } else {
                $query->where('region_id', (int) $regionId)->with(['region', 'geoPoint']);
            }

            // Явная сортировка по таблице products (после join не полагаться на неоднозначный latest)
            $products = $query->orderByDesc('products.id')->limit($limit)->get();

            return response()->json([
                'data' => $products->map(function ($product) {
                    $title = isset($product->getAttributes()['name'])
                        ? (string) $product->getAttributes()['name']
                        : (string) ($product->name ?? '');

                    $lat = $product->getAttribute('map_lat');
                    $lng = $product->getAttribute('map_lng');
                    if ($lat === null && $product->relationLoaded('geoPoint')) {
                        $lat = $product->geoPoint?->lat;
                        $lng = $product->geoPoint?->lng;
                    }

                    // Битый JSON в products.image ломает cast и даёт 500 при обращении к $product->image
                    $imageUrl = null;
                    try {
                        $img = $product->image;
                        if ($img) {
                            $imageUrl = is_array($img) ? ($img['original'] ?? null) : $img;
                        }
                    } catch (\Throwable $ignore) {
                        $imageUrl = null;
                    }

                    return [
                        'id' => $product->id,
                        'title' => $title,
                        'price' => $product->price,
                        'slug' => $product->slug,
                        'address' => $product->address,
                        'region' => $product->region?->name,
                        'lat' => $lat !== null && $lat !== '' ? (float) $lat : null,
                        'lng' => $lng !== null && $lng !== '' ? (float) $lng : null,
                        'image' => $imageUrl,
                    ];
                })->values(),
                'meta' => [
                    'total' => $products->count(),
                    'bbox' => $request->filled('bbox') ? $bbox : null,
                    'region_id' => $request->filled('region_id') ? (int) $regionId : null,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::error('productsMapByBbox', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Server Error',
            ], 500);
        }
    }
}
