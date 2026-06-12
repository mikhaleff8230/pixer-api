<?php


namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Availability;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\GeoPoint;
use Marvel\Database\Models\Resource;
use Marvel\Database\Models\Tag;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\ProductKey;
use Marvel\Database\Models\Course;
use Marvel\Database\Models\Lesson;
use Marvel\Database\Models\Variation;
use Illuminate\Support\Str;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Marvel\Enums\Permission;
use Marvel\Events\ProductReviewApproved;
use Marvel\Events\ProductReviewRejected;
use Marvel\Events\ProductCreated;
use Marvel\Events\ProductUnderReview;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductRepository extends BaseRepository
{

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',
        'shop_id',
        'status',
        'is_rental',
        'product_type',
        'type.slug',
        'dropoff_locations.slug' => 'in',
        'pickup_locations.slug' => 'in',
        'persons.slug' => 'in',
        'deposits.slug' => 'in',
        'features.slug' => 'in',
        'categories.slug' => 'in',
        'tags.slug' => 'in',
        'author.slug',
        'manufacturer.slug' => 'in',
        'min_price' => 'between',
        'max_price' => 'between',
        'price' => 'between',
        'language',
        'metas.key',
        'metas.value',
        'internal_article' => 'like', // Поиск по внутреннему артикулу
        'sku' => 'like', // Поиск по артикулу (для обратной совместимости)

    ];

    protected $dataArray = [
        'name',
        'slug',
        'price',
        'sale_price',
        'max_price',
        'min_price',
        'type_id',
        'author_id',
        'language',
        'manufacturer_id',
        'product_type',
        'quantity',
        'unit',
        'is_digital',
        'digital_product_type',
        'file_url',
        'prompt_text',
        'external_url',
        'account_data',
        'subscription_data',
        'subscription_days',
        'billing_access_type',
        'duration_days',
        'key_data',
        'is_external',
        'external_product_url',
        'external_product_button_text',
        'description',
        'sku',
        'internal_article', // Внутренний артикул (генерируется автоматически)
        'preview_url',
        'image',
        'gallery',
        'video',
        'status',
        'height',
        'length',
        'width',
        'weight',
        'in_stock',
        'is_taxable',
        'shop_id',
        'address',
        'region_id',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Product::class;
    }

    /**
     * Создаёт/обновляет geo_points и привязку товара по lat/lng из запроса.
     * Ключи lat/lng отсутствуют — гео не трогаем (сохраняем точку на карте).
     * Сброс точки только при clear_geo=true и пустых lat/lng.
     */
    protected function syncProductGeoPoint(Product $product, $request): void
    {
        $all = $request->all();
        if (!array_key_exists('lat', $all) || !array_key_exists('lng', $all)) {
            return;
        }

        $lat = $all['lat'];
        $lng = $all['lng'];

        $latEmpty = $lat === null || $lat === '';
        $lngEmpty = $lng === null || $lng === '';

        if ($latEmpty && $lngEmpty) {
            if ($request->boolean('clear_geo') && $product->geo_point_id) {
                $product->geo_point_id = null;
                $product->save();
            }
            return;
        }

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return;
        }

        $latF = (float) $lat;
        $lngF = (float) $lng;

        if ($product->geo_point_id) {
            $gp = GeoPoint::find($product->geo_point_id);
            if ($gp) {
                $gp->update(['lat' => $latF, 'lng' => $lngF]);
                return;
            }
        }

        $gp = GeoPoint::create(['lat' => $latF, 'lng' => $lngF]);
        $product->geo_point_id = $gp->id;
        $product->save();
    }

    /**
     * Синхронизация пула лицензионных ключей (только неиспользованные перезаписываются; выданные сохраняются).
     */
    protected function syncDigitalLicenseKeysFromRequest(Product $product, $request): void
    {
        if (!$request->has('digital_license_keys')) {
            return;
        }

        $type = $request->input('digital_product_type', $product->digital_product_type ?? 'file');

        if ($type !== 'key') {
            ProductKey::where('product_id', $product->id)->whereNull('used_by')->delete();

            return;
        }

        $raw = $request->input('digital_license_keys');
        $lines = is_array($raw) ? $raw : preg_split("/\r\n|\n|\r/", (string) $raw);
        $keys = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $keys[] = $line;
            }
        }
        $keys = array_values(array_unique($keys));

        ProductKey::where('product_id', $product->id)->whereNull('used_by')->delete();

        foreach ($keys as $k) {
            ProductKey::create([
                'product_id' => $product->id,
                'key' => $k,
            ]);
        }
    }

    /**
     * Курс и уроки для цифрового товара с типом subscription (доступ по подписке на product).
     */
    protected function syncProductCourse(Product $product, $request): void
    {
        $type = $request->input('digital_product_type', $product->digital_product_type ?? 'file');

        if ($type !== 'subscription') {
            $existing = Course::query()->where('required_product_id', $product->id)->get();
            foreach ($existing as $c) {
                $c->lessons()->delete();
                $c->delete();
            }

            return;
        }

        if (!$request->has('course')) {
            return;
        }

        $payload = $request->input('course');
        if (!is_array($payload)) {
            return;
        }

        $course = Course::firstOrNew(['required_product_id' => $product->id]);
        $title = isset($payload['title']) && trim((string) $payload['title']) !== ''
            ? trim((string) $payload['title'])
            : (string) $product->name;
        $course->title = $title;
        $course->description = isset($payload['description']) ? (string) $payload['description'] : null;
        $course->required_product_id = $product->id;
        $course->save();

        Course::query()
            ->where('required_product_id', $product->id)
            ->where('id', '!=', $course->id)
            ->get()
            ->each(function (Course $extra) {
                $extra->lessons()->delete();
                $extra->delete();
            });

        $lessonsInput = $payload['lessons'] ?? [];
        if (!is_array($lessonsInput)) {
            $lessonsInput = [];
        }

        $keptIds = [];
        foreach (array_values($lessonsInput) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $lessonId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $lesson = null;
            if ($lessonId) {
                $lesson = Lesson::query()
                    ->where('course_id', $course->id)
                    ->where('id', $lessonId)
                    ->first();
            }
            if (!$lesson) {
                $lesson = new Lesson();
            }

            $lesson->course_id = $course->id;
            $rawTitle = isset($row['title']) ? trim((string) $row['title']) : '';
            $lesson->title = $rawTitle !== '' ? $rawTitle : ('Урок ' . ($index + 1));

            $ct = $row['content_type'] ?? 'video';
            $lesson->content_type = is_string($ct) && strlen($ct) <= 32 ? $ct : 'video';

            $lesson->content_url = isset($row['content_url']) && $row['content_url'] !== ''
                ? (string) $row['content_url']
                : null;
            $lesson->content_body = isset($row['content_body']) && $row['content_body'] !== ''
                ? (string) $row['content_body']
                : null;

            $pos = $row['position'] ?? $index;
            $lesson->position = is_numeric($pos) ? (int) $pos : $index;

            $drip = $row['drip_days'] ?? 0;
            $lesson->drip_days = is_numeric($drip) ? max(0, (int) $drip) : 0;

            $lesson->save();
            $keptIds[] = $lesson->id;
        }

        if ($keptIds === []) {
            Lesson::query()->where('course_id', $course->id)->delete();
        } else {
            Lesson::query()
                ->where('course_id', $course->id)
                ->whereNotIn('id', $keptIds)
                ->delete();
        }
    }

    /**
     * storeProduct
     *
     * @param  mixed $request
     * @param  mixed $setting
     * @return void
     */
    public function storeProduct($request, $setting)
    {
        try {
            // --- ПРОВЕРКА ЛИМИТА на количество товаров у селлера ---
            $currentUser = $request->user();
            $sellerId = $currentUser ? $currentUser->id : null;
            if ($sellerId) {
                $countProducts = \App\Models\Product::where('shop_id', $request->input('shop_id'))->count();
                $hasPro = false;
                if (class_exists('App\\Models\\ProSubscription')) {
                    $proSubscription = \App\Models\ProSubscription::getActive($sellerId);
                    $hasPro = $proSubscription && $proSubscription->isActive();
                }
                if (!$hasPro && $countProducts >= 60) {
                    throw new \Exception('Достигнут лимит 60 товаров для не-PRO аккаунта. Оформите PRO или удалите существующий товар.');
                }
            }
            // -----------------------------------------------
            // FormData уже обработан в ProductCreateRequest::prepareForValidation
            // Расширенное логирование для отладки
            Log::info('=== ProductRepository::storeProduct - START ===');
            Log::info('ProductRepository::storeProduct - request data', [
                'product_type' => $request->input('product_type'),
                'has_product_type' => $request->has('product_type'),
                'name' => $request->input('name'),
                'shop_id' => $request->input('shop_id'),
                'type_id' => $request->input('type_id'),
                'has_variations' => $request->has('variations'),
                'has_variation_options' => $request->has('variation_options'),
                'variations' => $request->input('variations'),
                'variation_options' => $request->input('variation_options'),
                'all_keys' => array_keys($request->all()),
            ]);
            
            $data = $request->only($this->dataArray);
            
            // Используем единый сервис для генерации slug и кода
            $slugText = isset($request['slug']) && $request['slug'] ? $request['slug'] : $request['name'];
            $slugData = \Marvel\Services\ProductSlugService::generateForNewProduct(
                $request['name'],
                $slugText
            );
            
            $data['slug'] = $slugData['slug'];
            $data['slug_numeric_code'] = $slugData['slug_numeric_code'];

            if ($setting->options["isProductReview"]) {
                if ($request->status == ProductStatus::DRAFT) {
                    $data['status'] = ProductStatus::DRAFT;
                } elseif ($request->status == ProductStatus::UNDER_REVIEW) {
                    $data['status'] = ProductStatus::UNDER_REVIEW;
                } else {
                    throw new HttpException(406, 'The selected status is invalid.');
                }
            }

            if ($request->product_type == ProductType::SIMPLE) {
                $data['max_price'] = $data['price'];
                $data['min_price'] = $data['price'];
            }
            
            // ВАЖНО: internal_article генерируется ТОЛЬКО автоматически, игнорируем любые значения из запроса
            // Удаляем internal_article из данных, если он был передан (не должен приниматься из API)
            unset($data['internal_article']);
            
            // Генерируем внутренний артикул автоматически
            $generatedArticle = \Marvel\Services\ArticleGeneratorService::generateProductArticle();
            $data['internal_article'] = $generatedArticle;
            
            // Логируем для отладки
            Log::info('ProductRepository::storeProduct - Generated article', [
                'internal_article' => $generatedArticle,
                'data_keys' => array_keys($data),
                'has_internal_article' => isset($data['internal_article']),
            ]);
            
            $product = $this->create($data);
            
            // Проверяем, что артикул и slug_numeric_code сохранились
            $product->refresh();
            Log::info('ProductRepository::storeProduct - Product created', [
                'product_id' => $product->id,
                'internal_article' => $product->internal_article,
                'internal_article_set' => !empty($product->internal_article),
                'slug' => $product->slug,
                'slug_numeric_code' => $product->slug_numeric_code,
                'slug_numeric_code_set' => !empty($product->slug_numeric_code),
            ]);
            
            // ВАЖНО: Если slug_numeric_code не сохранился - генерируем и сохраняем
            if (empty($product->slug_numeric_code)) {
                Log::warning('ProductRepository::storeProduct - slug_numeric_code not saved, generating', [
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                ]);
                
                $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                    $product,
                    $product->name
                );
                
                $product->slug = $slugData['slug'];
                $product->slug_numeric_code = $slugData['slug_numeric_code'];
                $product->save();
                
                Log::info('ProductRepository::storeProduct - slug_numeric_code generated and saved', [
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'slug_numeric_code' => $product->slug_numeric_code,
                ]);
            }
            
            // Логика взимания оплаты ЗА ПУБЛИКАЦИЮ полностью отключена. Платежи за публикацию товара более не применяются. Только бизнес-логика создания товара, как черновика или опубликованного, без списания с баланса.
            Log::info('ProductRepository::storeProduct - Товар создан (оплата публикации отключена по новой бизнес-логике)', [
                'product_id' => $product->id,
                'status' => $product->status
            ]);
            
            // Если артикул не сохранился, устанавливаем его вручную
            if (empty($product->internal_article)) {
                Log::warning('ProductRepository::storeProduct - Article not saved, setting manually', [
                    'product_id' => $product->id,
                    'generated_article' => $generatedArticle,
                ]);
                $product->internal_article = $generatedArticle;
                $product->save();
            }

            // Если slug пустой или числовой, генерируем его с кодом через единый сервис
            if (empty($product->slug) || is_numeric($product->slug)) {
                $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                    $product,
                    $product->name
                );
                
                $product->slug = $slugData['slug'];
                $product->slug_numeric_code = $slugData['slug_numeric_code'];
                $product->save();
            } elseif (empty($product->slug_numeric_code) && !empty($product->slug)) {
                // Если код не был сохранен, но slug содержит код - извлекаем и сохраняем
                $slugParsed = Product::parseSlugId($product->slug);
                if (isset($slugParsed['code']) && preg_match('/^\d{12}$/', $slugParsed['code'])) {
                    $product->slug_numeric_code = $slugParsed['code'];
                    // Убираем код из slug, оставляем только базовую часть
                    $product->slug = preg_replace('/-\d{12}$/', '', $product->slug);
                    $product->save();
                } else {
                    // Если slug не содержит 12-значный код, генерируем новый
                    $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                        $product,
                        $product->name
                    );
                    
                    $product->slug = $slugData['slug'];
                    $product->slug_numeric_code = $slugData['slug_numeric_code'];
                    $product->save();
                }
            }

            if (isset($request['metas'])) {
                foreach ($request['metas'] as $value) {
                    $metas[$value['key']] = $value['value'];
                    $product->setMeta($metas);
                }
            }

            // Обработка категории: теперь одна категория вместо массива
            try {
                if (isset($request['category_id'])) {
                    // Преобразуем category_id в число (если передан объект, извлекаем id)
                    $categoryId = is_array($request['category_id']) ? ($request['category_id']['id'] ?? $request['category_id'][0] ?? null) : $request['category_id'];
                    $categoryId = is_numeric($categoryId) ? (int)$categoryId : null;
                    
                    if ($categoryId) {
                        $product->categories()->attach([$categoryId]);
                    }
                } elseif (isset($request['categories'])) {
                    // Для обратной совместимости: если передан массив categories
                    $categoryIds = is_array($request['categories']) ? $request['categories'] : [$request['categories']];
                    // Преобразуем все ID в числа
                    $categoryIds = array_filter(array_map(function($catId) {
                        if (is_array($catId)) {
                            return isset($catId['id']) && is_numeric($catId['id']) ? (int)$catId['id'] : null;
                        }
                        return is_numeric($catId) ? (int)$catId : null;
                    }, $categoryIds));
                    
                    if (!empty($categoryIds)) {
                        $product->categories()->attach($categoryIds);
                    }
                }
            } catch (\Exception $e) {
                \Log::error('ProductRepository::storeProduct - Error processing category', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Не прерываем создание товара из-за ошибки категории
            }
            
            // Обработка значений атрибутов товара
            try {
                if (isset($request['attribute_values']) && is_array($request['attribute_values'])) {
                    $attributeValuesData = [];
                    foreach ($request['attribute_values'] as $attributeId => $value) {
                        // Преобразуем attributeId в число
                        $attrId = is_numeric($attributeId) ? (int)$attributeId : null;
                        if (!$attrId) {
                            continue; // Пропускаем невалидные ID
                        }
                        
                        // Пропускаем пустые значения
                        if (empty($value) && $value !== '0' && $value !== 0) {
                            continue;
                        }
                        
                        // Преобразуем значение в строку, если это массив (для multiselect)
                        $finalValue = is_array($value) ? implode(',', $value) : (string)$value;
                        
                        // Если значение - это объект (из SelectInput), извлекаем value
                        if (is_array($value) && isset($value['value'])) {
                            $finalValue = is_array($value['value']) ? implode(',', $value['value']) : (string)$value['value'];
                            $attributeValueId = isset($value['attribute_value_id']) && is_numeric($value['attribute_value_id']) ? (int)$value['attribute_value_id'] : null;
                        } else {
                            $attributeValueId = null;
                        }
                        
                        $attributeValuesData[$attrId] = [
                            'value' => $finalValue,
                            'attribute_value_id' => $attributeValueId,
                        ];
                    }
                    
                    // Используем sync для обновления значений атрибутов
                    if (!empty($attributeValuesData)) {
                        $product->attributes()->sync($attributeValuesData);
                    }
                }
            } catch (\Exception $e) {
                \Log::error('ProductRepository::storeProduct - Error processing attributes', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Не прерываем создание товара из-за ошибки атрибутов
            }
            if (isset($request['dropoff_locations'])) {
                $product->dropoff_locations()->attach($request['dropoff_locations']);
            }
            if (isset($request['pickup_locations'])) {
                $product->pickup_locations()->attach($request['pickup_locations']);
            }
            if (isset($request['persons'])) {
                $product->persons()->attach($request['persons']);
            }
            if (isset($request['features'])) {
                $product->features()->attach($request['features']);
            }
            if (isset($request['deposits'])) {
                $product->deposits()->attach($request['deposits']);
            }
            if (isset($request['tags'])) {
                $tagIds = $this->processTags($request['tags'], $product->type_id ?? 1, $request['language'] ?? DEFAULT_LANGUAGE);
                if (!empty($tagIds)) {
                    $product->tags()->attach($tagIds);
                }
            }
            if (isset($request['variations'])) {
                // variations - это массив ID attribute_value для вариативных товаров
                // Используем sync() для правильной синхронизации связей
                $variationIds = is_array($request['variations']) 
                    ? array_filter(array_map('intval', $request['variations']))
                    : [];
                $product->variations()->sync($variationIds);
            }
            if (isset($request['variation_options']) && isset($request['variation_options']['upsert'])) {
                $upsertOptions = $request['variation_options']['upsert'];
                Log::info('ProductRepository::storeProduct - Processing variation_options', [
                    'count' => is_array($upsertOptions) ? count($upsertOptions) : 0,
                    'upsert' => $upsertOptions,
                ]);
                
                if (is_array($upsertOptions) && count($upsertOptions) > 0) {
                    foreach ($upsertOptions as $variation_option) {
                        // Обрабатываем options - должны быть массивом объектов {name, value}
                        if (isset($variation_option['options'])) {
                            if (is_string($variation_option['options'])) {
                                $variation_option['options'] = json_decode($variation_option['options'], true);
                            }
                            // Убеждаемся, что options - это массив
                            if (!is_array($variation_option['options'])) {
                                $variation_option['options'] = [];
                            }
                        } else {
                            $variation_option['options'] = [];
                        }
                        
                        // Формируем title из options, если не указан
                        if (!isset($variation_option['title']) || empty($variation_option['title'])) {
                            if (is_array($variation_option['options']) && count($variation_option['options']) > 0) {
                                $variation_option['title'] = implode('/', array_map(function($opt) {
                                    return $opt['value'] ?? '';
                                }, $variation_option['options']));
                            } else {
                                $variation_option['title'] = 'Variant';
                            }
                        }
                        
                        // Преобразуем числовые значения в правильные типы
                        if (isset($variation_option['price'])) {
                            $variation_option['price'] = (string)$variation_option['price'];
                        } else {
                            $variation_option['price'] = '0';
                        }
                        if (isset($variation_option['sale_price']) && $variation_option['sale_price'] !== null && $variation_option['sale_price'] !== '') {
                            $variation_option['sale_price'] = (string)$variation_option['sale_price'];
                        } else {
                            $variation_option['sale_price'] = null;
                        }
                        if (isset($variation_option['quantity'])) {
                            $variation_option['quantity'] = (int)$variation_option['quantity'];
                        } else {
                            $variation_option['quantity'] = 0;
                        }
                        
                        // Убеждаемся, что sku установлен
                        if (!isset($variation_option['sku']) || empty($variation_option['sku'])) {
                            $variation_option['sku'] = $product->slug . '-' . uniqid();
                        }
                        
                        // Убеждаемся, что is_disable установлен
                        if (!isset($variation_option['is_disable'])) {
                            $variation_option['is_disable'] = false;
                        }
                        if (!isset($variation_option['is_digital'])) {
                            $variation_option['is_digital'] = false;
                        }
                        
                        // Обрабатываем is_digital и digital_file
                    if (isset($variation_option['is_digital']) && $variation_option['is_digital']) {
                            $file = $variation_option['digital_file'] ?? null;
                        unset($variation_option['digital_file']);
                        } else {
                            $file = null;
                        }
                        
                        Log::info('ProductRepository::storeProduct - Creating variation_option', [
                            'variation_option' => $variation_option,
                        ]);
                        
                        try {
                    $new_variation_option = $product->variation_options()->create($variation_option);
                            
                            if ($file && isset($file['attachment_id']) && isset($file['url'])) {
                        $new_variation_option->digital_file()->create($file);
                            }
                            
                            Log::info('ProductRepository::storeProduct - Variation option created successfully', [
                                'id' => $new_variation_option->id,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('ProductRepository::storeProduct - Error creating variation_option', [
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                                'variation_option' => $variation_option,
                            ]);
                            throw $e;
                        }
                    }
                }
            }
            if (isset($request['is_digital']) && $request['is_digital'] && !empty($request['digital_file'])) {

                $digitalFileArray['attachment_id'] = $request['digital_file']['attachment_id'];
                $digitalFileArray['url'] = $request['digital_file']['url'];
                
                $product->digital_file()->create($digitalFileArray);
            }

               // Обработка загрузки видео
               // Логируем информацию о запросе
               Log::info('ProductRepository::storeProduct - проверка видео', [
                   'hasFile_video' => $request->hasFile('video'),
                   'has_video' => $request->has('video'),
                   'all_files' => array_keys($request->allFiles()),
                   'all_input_keys' => array_keys($request->all()),
                   'content_type' => $request->header('Content-Type'),
                   'request_method' => $request->method(),
               ]);
               
               if ($request->hasFile('video')) {
                   $file = $request->file('video');
                Log::info('ProductRepository::storeProduct - сохраняем видео', [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
                
                // Проверяем размер файла (40MB максимум)
                $maxSize = 40 * 1024 * 1024; // 40MB
                if ($file->getSize() > $maxSize) {
                    throw new \Exception('Video file size exceeds maximum allowed size of 40MB');
                }
                
                $key = 'products/videos/' . uniqid('', true) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => $file->getMimeType() ?: 'video/mp4',
                ]);
                
                $videoRecord = \Marvel\Database\Models\ProductVideo::create([
                    'product_id' => $product->id,
                    'url' => $key,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
                
                Log::info('ProductRepository::storeProduct - видео сохранено в БД', [
                    'video_id' => $videoRecord->id,
                    'product_id' => $product->id,
                    'video_url' => $videoRecord->url,
                    's3_key' => $key,
                    'video_exists_in_db' => \Marvel\Database\Models\ProductVideo::where('id', $videoRecord->id)->exists(),
                ]);
                
                // Оптимизируем видео в фоне (можно через очередь)
                try {
                    \Marvel\Helpers\VideoOptimizer::optimizeVideo($videoRecord, $file->getRealPath());
                } catch (\Exception $e) {
                    Log::error('ProductRepository::storeProduct - ошибка оптимизации видео', [
                        'video_id' => $videoRecord->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                // Если установлена галочка "Сделать обложкой", используем превью как первое изображение
                if ($request->has('video_as_cover') && $request->input('video_as_cover')) {
                    // Сохраняем флаг, что нужно использовать превью
                    $product->setMeta('video_as_cover', true);
                    $product->setMeta('cover_video_id', $videoRecord->id);
                } else {
                    // Если галочка не установлена, удаляем флаг
                    $product->removeMeta('video_as_cover');
                    $product->removeMeta('cover_video_id');
                }
            } elseif ($request->has('video_as_cover')) {
                // Если видео не загружается, но есть флаг, обрабатываем его
                if ($request->input('video_as_cover')) {
                    // Находим последнее видео и устанавливаем флаг
                    $lastVideo = $product->videos()->latest()->first();
                    if ($lastVideo) {
                        $product->setMeta('video_as_cover', true);
                        $product->setMeta('cover_video_id', $lastVideo->id);
                    }
                } else {
                    // Если галочка снята, удаляем флаг
                    $product->removeMeta('video_as_cover');
                    $product->removeMeta('cover_video_id');
                }
            }

            $product->save();

            $this->syncProductGeoPoint($product->fresh(), $request);
            
            // Загружаем videos после сохранения
            try {
                if (class_exists(\Marvel\Database\Models\ProductVideo::class)) {
                    $product->load('videos');
                    Log::info('ProductRepository::storeProduct - videos загружены', [
                        'product_id' => $product->id,
                        'videos_count' => $product->videos ? $product->videos->count() : 0,
                        'videos_in_db' => \Marvel\Database\Models\ProductVideo::where('product_id', $product->id)->count(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('ProductRepository::storeProduct - не удалось загрузить videos', [
                    'error' => $e->getMessage(),
                    'product_id' => $product->id,
                ]);
            }
            
            // Загружаем связи для финальной проверки
            $product->load('variations', 'variation_options');
            // Для админки в ответе после create нужен URL цифрового файла,
            // иначе фронт не может корректно отобразить уже прикрепленный файл.
            try {
                $product->load('digital_file');
                if ($product->digital_file) {
                    $product->digital_file->makeVisible(['url']);
                }
            } catch (\Exception $e) {
                Log::warning('ProductRepository::storeProduct - failed to load digital_file', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
            Log::info('ProductRepository::storeProduct - Product created successfully', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_type' => $product->product_type,
                'variations_count' => $product->variations->count(),
                'variation_options_count' => $product->variation_options->count(),
            ]);

            $this->syncDigitalLicenseKeysFromRequest($product->fresh(), $request);
            $this->syncProductCourse($product->fresh(), $request);
            
            // Отправляем событие о создании товара
            event(new ProductCreated($product));
            
            Log::info('=== ProductRepository::storeProduct - END (SUCCESS) ===');
            return $product;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function checkProductForPublish($request, $product)
    {
        // ВАЖНО: Если статус не передан в запросе, возвращаем текущий статус товара
        // Это предотвращает сброс статуса при простом обновлении (например, редактировании slug)
        if (!$request->has('status') || $request->status === null) {
            return $product->status;
        }
        
        $status = '';
        $isSuperAdmin = $request->user()->hasPermissionTo(Permission::SUPER_ADMIN);
        
        if ($product->shop['owner']['id'] == $request->user()->id) {
            // ВЛАДЕЛЕЦ ТОВАРА - может установить любой статус
            if ($request->status == ProductStatus::PUBLISH) {
                $status = ProductStatus::PUBLISH;
            } elseif ($request->status == ProductStatus::DRAFT) {
                $status = ProductStatus::DRAFT;
            } elseif ($request->status == ProductStatus::UNDER_REVIEW) {
                $status = ProductStatus::UNDER_REVIEW;
                event(new ProductUnderReview($product));
            } elseif ($request->status == ProductStatus::UNPUBLISH) {
                $status = ProductStatus::UNPUBLISH;
            } else {
                // Если статус не распознан, сохраняем текущий
                $status = $product->status;
            }
        } elseif ($isSuperAdmin) {
            // СУПЕР-АДМИН
            if ($request->status == ProductStatus::APPROVED) {
                $status = ProductStatus::PUBLISH;
                event(new ProductReviewApproved($product));
            } elseif ($request->status == ProductStatus::REJECTED) {
                $status = ProductStatus::REJECTED;
                event(new ProductReviewRejected($product));
            } elseif ($request->status == ProductStatus::PUBLISH) {
                return ProductStatus::PUBLISH;
            } elseif ($request->status == ProductStatus::UNPUBLISH) {
                $status = ProductStatus::UNPUBLISH;
            } else {
                // Если статус не распознан, сохраняем текущий
                $status = $product->status;
            }
        } else {
            // Если пользователь не владелец и не супер-админ, сохраняем текущий статус
            $status = $product->status;
        }
        
        return $status;
    }

    /**
     * updateProduct
     *
     * @param  $request
     * @param  $id
     * @param  $setting
     * @return void
     */
    public function updateProduct($request, $id, $setting)
    {
        Log::info('=== ProductRepository::updateProduct - START ===');
        // FormData уже обработан в ProductUpdateRequest::prepareForValidation
        // Расширенное логирование для отладки
        Log::info('ProductRepository::updateProduct - request data', [
                'id' => $id,
                'product_type' => $request->input('product_type'),
                'has_product_type' => $request->has('product_type'),
                'name' => $request->input('name'),
                'shop_id' => $request->input('shop_id'),
                'type_id' => $request->input('type_id'),
                'has_variations' => $request->has('variations'),
                'has_variation_options' => $request->has('variation_options'),
                'variations' => $request->input('variations'),
                'variation_options' => $request->input('variation_options'),
                'all_keys' => array_keys($request->all()),
                'all_data' => $request->all(),
                'content_type' => $request->header('Content-Type'),
                'is_json' => $request->isJson(),
                'is_form_data' => $request->hasFile('video') || str_contains($request->header('Content-Type', ''), 'multipart/form-data'),
            ]);
            
            $product = $this->findOrFail($id);

            if (is_array($request['metas'])) {
                foreach ($request['metas'] as $key => $value) {
                    $metas[$value['key']] = $value['value'];
                    $product->setMeta($metas);
                }
            }

            // Обработка категории: теперь одна категория вместо массива
            try {
                if (isset($request['category_id'])) {
                    // Преобразуем category_id в число (если передан объект, извлекаем id)
                    $categoryId = is_array($request['category_id']) ? ($request['category_id']['id'] ?? $request['category_id'][0] ?? null) : $request['category_id'];
                    $categoryId = is_numeric($categoryId) ? (int)$categoryId : null;
                    
                    if ($categoryId) {
                        $product->categories()->sync([$categoryId]);
                    } else {
                        // Если category_id пустой или невалидный, удаляем все категории
                        $product->categories()->detach();
                    }
                } elseif (isset($request['categories'])) {
                // Для обратной совместимости: если передан массив categories
                $categoryIds = is_array($request['categories']) ? $request['categories'] : [$request['categories']];
                // Преобразуем все ID в числа
                $categoryIds = array_filter(array_map(function($catId) {
                    if (is_array($catId)) {
                        return isset($catId['id']) && is_numeric($catId['id']) ? (int)$catId['id'] : null;
                    }
                    return is_numeric($catId) ? (int)$catId : null;
                }, $categoryIds));
                
                // Используем только первую категорию (теперь товар может иметь только одну категорию)
                if (!empty($categoryIds)) {
                    $firstCategoryId = reset($categoryIds);
                    $product->categories()->sync([$firstCategoryId]);
                } else {
                    $product->categories()->detach();
                }
            }
            } catch (\Exception $e) {
                \Log::error('ProductRepository::updateProduct - Error processing category', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Не прерываем обновление товара из-за ошибки категории
            }
            
            // Обработка значений атрибутов товара
            try {
                if (isset($request['attribute_values']) && is_array($request['attribute_values'])) {
                    $attributeValuesData = [];
                    foreach ($request['attribute_values'] as $attributeId => $value) {
                        // Преобразуем attributeId в число
                        $attrId = is_numeric($attributeId) ? (int)$attributeId : null;
                        if (!$attrId) {
                            continue; // Пропускаем невалидные ID
                        }
                        
                        // Пропускаем пустые значения
                        if (empty($value) && $value !== '0' && $value !== 0) {
                            continue;
                        }
                        
                        // Преобразуем значение в строку, если это массив (для multiselect)
                        $finalValue = is_array($value) ? implode(',', $value) : (string)$value;
                        
                        // Если значение - это объект (из SelectInput), извлекаем value
                        if (is_array($value) && isset($value['value'])) {
                            $finalValue = is_array($value['value']) ? implode(',', $value['value']) : (string)$value['value'];
                            $attributeValueId = isset($value['attribute_value_id']) && is_numeric($value['attribute_value_id']) ? (int)$value['attribute_value_id'] : null;
                        } else {
                            $attributeValueId = null;
                        }
                        
                        $attributeValuesData[$attrId] = [
                            'value' => $finalValue,
                            'attribute_value_id' => $attributeValueId,
                        ];
                    }
                    
                    // Используем sync для обновления значений атрибутов
                    if (!empty($attributeValuesData)) {
                        $product->attributes()->sync($attributeValuesData);
                    } else {
                        // Если все значения пустые, удаляем все связи с атрибутами
                        $product->attributes()->detach();
                    }
                }
            } catch (\Exception $e) {
                \Log::error('ProductRepository::updateProduct - Error processing attributes', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Не прерываем обновление товара из-за ошибки атрибутов
            }
            if (isset($request['tags'])) {
                \Log::info('ProductRepository::updateProduct - Processing tags', [
                    'tags' => $request['tags'],
                    'type_id' => $product->type_id ?? 1,
                    'language' => $request['language'] ?? DEFAULT_LANGUAGE,
                ]);
                
                $tagIds = $this->processTags($request['tags'], $product->type_id ?? 1, $request['language'] ?? DEFAULT_LANGUAGE);
                
                \Log::info('ProductRepository::updateProduct - Processed tag IDs', [
                    'tag_ids' => $tagIds,
                ]);
                
                if (!empty($tagIds)) {
                    $product->tags()->sync($tagIds);
                } else {
                    // Если тегов нет, отвязываем все теги
                    $product->tags()->sync([]);
                }
            }
            if (isset($request['dropoff_locations'])) {
                $product->dropoff_locations()->sync($request['dropoff_locations']);
            }
            if (isset($request['pickup_locations'])) {
                $product->pickup_locations()->sync($request['pickup_locations']);
            }
            if (isset($request['variations'])) {
                // variations - это массив ID attribute_value для вариативных товаров
                // Используем sync() для правильной синхронизации связей
                $variationIds = is_array($request['variations']) 
                    ? array_filter(array_map('intval', $request['variations']))
                    : [];
                Log::info('ProductRepository::updateProduct - Syncing variations', [
                    'variation_ids' => $variationIds,
                    'count' => count($variationIds),
                ]);
                $product->variations()->sync($variationIds);
                Log::info('ProductRepository::updateProduct - Variations synced successfully');
            } else {
                Log::warning('ProductRepository::updateProduct - variations not found in request', [
                    'has_variations_key' => $request->has('variations'),
                    'product_type' => $request->input('product_type'),
                    'current_product_type' => $product->product_type,
                ]);
            }
            if (isset($request['persons'])) {
                $product->persons()->sync($request['persons']);
            }
            if (isset($request['features'])) {
                $product->features()->sync($request['features']);
            }
            if (isset($request['deposits'])) {
                $product->deposits()->sync($request['deposits']);
            }
            if (isset($request['digital_file'])) {
                $file = $request['digital_file'];
                if (isset($file['id'])) {
                    $product->digital_file()->where('id', $file['id'])->update($file);
                } else {
                    $product->digital_file()->create($file);
                }
            }
            if (isset($request['variation_options'])) {
                Log::info('ProductRepository::updateProduct - variation_options found', [
                    'has_upsert' => isset($request['variation_options']['upsert']),
                    'has_delete' => isset($request['variation_options']['delete']),
                    'variation_options_type' => gettype($request['variation_options']),
                    'variation_options' => $request['variation_options'],
                ]);
                if (isset($request['variation_options']['upsert'])) {
                    $upsertOptions = $request['variation_options']['upsert'];
                    Log::info('ProductRepository::updateProduct - Processing variation_options', [
                        'count' => is_array($upsertOptions) ? count($upsertOptions) : 0,
                        'upsert' => $upsertOptions,
                    ]);
                    
                    if (is_array($upsertOptions) && count($upsertOptions) > 0) {
                        foreach ($upsertOptions as $key => $variation) {
                            // Обрабатываем options - должны быть массивом объектов {name, value}
                            if (isset($variation['options'])) {
                                if (is_string($variation['options'])) {
                                    $variation['options'] = json_decode($variation['options'], true);
                                }
                                // Убеждаемся, что options - это массив
                                if (!is_array($variation['options'])) {
                                    $variation['options'] = [];
                                }
                            } else {
                                $variation['options'] = [];
                            }
                            
                            // Преобразуем числовые значения в правильные типы
                            if (isset($variation['price'])) {
                                $variation['price'] = (string)$variation['price'];
                            }
                            if (isset($variation['sale_price']) && $variation['sale_price'] !== null && $variation['sale_price'] !== '') {
                                $variation['sale_price'] = (string)$variation['sale_price'];
                            } else {
                                $variation['sale_price'] = null;
                            }
                            if (isset($variation['quantity'])) {
                                $variation['quantity'] = (int)$variation['quantity'];
                            }
                            
                            // Убеждаемся, что title установлен
                            if (!isset($variation['title']) || empty($variation['title'])) {
                                // Генерируем title из options
                                if (is_array($variation['options']) && count($variation['options']) > 0) {
                                    $variation['title'] = implode('/', array_map(function($opt) {
                                        return $opt['value'] ?? '';
                                    }, $variation['options']));
                                } else {
                                    $variation['title'] = 'Variant ' . ($key + 1);
                                }
                            }
                            
                            // Обрабатываем is_digital и digital_file
                        if (isset($variation['is_digital']) && $variation['is_digital']) {
                                $file = $variation['digital_file'] ?? null;
                            unset($variation['digital_file']);
                            } else {
                                $file = null;
                            }
                            
                            Log::info('ProductRepository::updateProduct - Processing variation', [
                                'id' => $variation['id'] ?? 'new',
                                'variation' => $variation,
                            ]);

                            if (isset($variation['is_digital']) && $variation['is_digital'] && $file) {
                                if (isset($variation['id']) && $variation['id']) {
                                $product->variation_options()->where('id', $variation['id'])->update($variation);
                                try {
                                    $updated_variation = Variation::findOrFail($variation['id']);
                                } catch (Exception $e) {
                                    throw new ModelNotFoundException(NOT_FOUND);
                                }
                                if (TRANSLATION_ENABLED) {
                                    Variation::where('sku', $updated_variation->sku)->where('id', '=', $updated_variation->id)->update([
                                        'price' => $updated_variation->price,
                                        'sale_price' => $updated_variation->sale_price,
                                        'quantity' => $updated_variation->quantity,
                                    ]);
                                }
                                if (isset($file['id'])) {
                                    $updated_variation->digital_file()->where('id', $file['id'])->update($file);
                                } else {
                                    $updated_variation->digital_file()->create($file);
                                }
                            } else {
                                $new_variation = $product->variation_options()->create($variation);
                                    if ($file && isset($file['attachment_id']) && isset($file['url'])) {
                                $new_variation->digital_file()->create($file);
                                    }
                            }
                        } else {
                                if (isset($variation['id']) && $variation['id']) {
                                $product->variation_options()->where('id', $variation['id'])->update($variation);
                            } else {
                                $product->variation_options()->create($variation);
                                }
                            }
                        }
                    }
                }
                if (isset($request['variation_options']['delete'])) {
                    foreach ($request['variation_options']['delete'] as $key => $id) {
                        try {
                            $product->variation_options()->where('id', $id)->delete();
                        } catch (Exception $e) {
                            //
                        }
                    }
                }
            } else {
                Log::warning('ProductRepository::updateProduct - variation_options not found in request', [
                    'has_variation_options_key' => $request->has('variation_options'),
                    'product_type' => $request->input('product_type'),
                    'current_product_type' => $product->product_type,
                ]);
            }
            $data = $request->only($this->dataArray);
            $data['sale_price'] = isset($request['sale_price']) ? $request['sale_price'] : null;
            
            // ВАЖНО: type_id должен быть обязательным для всех товаров
            // Если type_id не передан в запросе, но товар уже имеет type_id - сохраняем существующий
            // Если type_id не передан и товар не имеет type_id - это ошибка (но не прерываем обновление, чтобы не сломать существующие товары)
            if (!isset($data['type_id']) || empty($data['type_id'])) {
                if ($product->type_id) {
                    // Сохраняем существующий type_id
                    $data['type_id'] = $product->type_id;
                    Log::info('ProductRepository::updateProduct - Preserving existing type_id', [
                        'type_id' => $product->type_id,
                    ]);
                } else {
                    // Товар не имеет type_id - это проблема, но не прерываем обновление
                    Log::warning('ProductRepository::updateProduct - Product has no type_id!', [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'request_type_id' => $request->input('type_id'),
                    ]);
                }
            } else {
                // type_id передан в запросе - используем его
                Log::info('ProductRepository::updateProduct - Using type_id from request', [
                    'type_id' => $data['type_id'],
                ]);
            }
            
            // Логируем информацию о type_id
            Log::info('ProductRepository::updateProduct - type_id check', [
                'request_type_id' => $request->input('type_id'),
                'data_type_id' => $data['type_id'] ?? 'NOT_SET',
                'current_type_id' => $product->type_id,
                'final_type_id' => $data['type_id'],
            ]);

            // Отладочная информация для обновления товара
            \Log::info('Product update - Request data:', [
                'product_id' => $id,
                'request_image' => $request['image'] ?? 'not_set',
                'request_gallery' => $request['gallery'] ?? 'not_set',
                'data_image' => $data['image'] ?? 'not_set',
                'data_gallery' => $data['gallery'] ?? 'not_set',
                'current_product_image' => $product->image ?? 'not_set',
                'current_product_gallery' => $product->gallery ?? 'not_set'
            ]);

            // НЕ удаляем поля image и gallery при обновлении статуса
            // Они должны сохраняться всегда, если не переданы явно для замены
            if (!isset($request['image']) || $request['image'] === null) {
                unset($data['image']);
                \Log::info('Image field removed from update data - keeping existing');
            } else {
                \Log::info('Image field will be updated', ['new_image' => $request['image']]);
            }
            
            if (!isset($request['gallery']) || $request['gallery'] === null) {
                unset($data['gallery']);
                \Log::info('Gallery field removed from update data - keeping existing');
            } else {
                \Log::info('Gallery field will be updated', ['new_gallery' => $request['gallery']]);
            }

            // ВАЖНО: checkProductForPublish вызывается ТОЛЬКО если статус явно передан в запросе
            // Если статус не передан - сохраняем текущий статус товара
            if ($setting->options["isProductReview"]) {
                // Проверяем, передан ли статус в запросе
                if ($request->has('status') && $request->status !== null) {
                    $data['status'] = $this->checkProductForPublish($request, $product);
                } else {
                    // Статус не передан - сохраняем текущий статус
                    // НЕ вызываем checkProductForPublish, чтобы не сбросить статус
                    unset($data['status']);
                }
            }

            // ВАЖНО: Проверяем product_type из запроса, если не передан - берем из существующего товара
            $requestProductType = $request->input('product_type');
            $finalProductType = $requestProductType ?? $product->product_type;
            
            Log::info('ProductRepository::updateProduct - Product type check', [
                'request_product_type' => $requestProductType,
                'current_product_type' => $product->product_type,
                'final_product_type' => $finalProductType,
                'is_variable' => $finalProductType == ProductType::VARIABLE,
                'is_simple' => $finalProductType == ProductType::SIMPLE,
            ]);
            
            if ($finalProductType == ProductType::VARIABLE) {
                $data['price'] = NULL;
                $data['sale_price'] = NULL;
                $data['sku'] = NULL;
                // ВАЖНО: Устанавливаем product_type в данных для обновления
                $data['product_type'] = ProductType::VARIABLE;
            }
            if ($finalProductType == ProductType::SIMPLE) {
                // Проверяем наличие price в $data, если нет - берем из существующего товара или используем 0
                $price = $data['price'] ?? $product->price ?? 0;
                $data['max_price'] = $price;
                $data['min_price'] = $price;
                // Убеждаемся что price тоже установлен
                if (!isset($data['price'])) {
                    $data['price'] = $price;
                }
            }

            // ВАЖНО: Обработка обновления slug с использованием единого сервиса
            // Сначала проверяем и мигрируем старые товары (если код в slug, а не в slug_numeric_code)
            if (empty($product->slug_numeric_code) && !empty($product->slug)) {
                // Проверяем, содержит ли slug 12-значный код
                if (preg_match('/^(.+)-(\d{12})$/', $product->slug, $matches)) {
                    $baseSlug = $matches[1];
                    $code = $matches[2];
                    // Мигрируем: сохраняем код в slug_numeric_code, убираем из slug
                    $product->slug = $baseSlug;
                    $product->slug_numeric_code = $code;
                    $product->save();
                    
                    Log::info('ProductRepository::updateProduct - Migrated old slug format', [
                        'product_id' => $product->id,
                        'old_slug' => $matches[0],
                        'new_slug' => $baseSlug,
                        'slug_numeric_code' => $code,
                    ]);
                } elseif (preg_match('/^(.+)-(\d{1,4})$/', $product->slug, $matches)) {
                    // ВАЖНО: Если slug содержит короткий код (1-4 цифры) - это старый формат с ID
                    // Убираем его и генерируем новый 12-значный код
                    $baseSlug = $matches[1];
                    $oldCode = $matches[2];
                    
                    // Генерируем новый 12-значный код
                    $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                        $product,
                        $product->name
                    );
                    
                    $product->slug = $slugData['slug'];
                    $product->slug_numeric_code = $slugData['slug_numeric_code'];
                    $product->save();
                    
                    Log::info('ProductRepository::updateProduct - Migrated old short code format', [
                        'product_id' => $product->id,
                        'old_slug' => $matches[0],
                        'old_code' => $oldCode,
                        'new_slug' => $slugData['slug'],
                        'slug_numeric_code' => $slugData['slug_numeric_code'],
                    ]);
                }
            }
            
            // Проверяем, нужно ли обновлять slug
            $shouldUpdateSlug = false;
            $newSlugText = null;
            
            // Сравниваем базовый slug (без кода) для правильного определения изменений
            $currentBaseSlug = $product->slug;
            // Убираем код из текущего slug, если он есть (для старых товаров)
            $currentBaseSlug = preg_replace('/-\d{12}$/', '', $currentBaseSlug);
            
            if (!empty($request->slug) && $request->slug != $currentBaseSlug) {
                // Пользователь вручную редактирует slug
                $shouldUpdateSlug = true;
                $newSlugText = $request->slug;
            } elseif ((empty($request->slug) || $request->slug == '') && isset($data['name']) && $data['name'] != $product->name) {
                // Slug не передан или пустой, но название изменилось
                $shouldUpdateSlug = true;
                $newSlugText = $data['name'];
            } elseif (empty($product->slug_numeric_code)) {
                // Если у товара нет кода - генерируем его (для старых товаров)
                $shouldUpdateSlug = true;
                $newSlugText = $product->slug ?: $product->name;
            }
            
            if ($shouldUpdateSlug) {
                if (!empty($request->slug) && $request->slug != $currentBaseSlug) {
                    // Пользователь вручную редактирует slug
                    $slugData = \Marvel\Services\ProductSlugService::updateSlugForProduct(
                        $product,
                        $request->slug
                    );
                } else {
                    // Генерируем slug из названия или существующего slug с сохранением кода
                    $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                        $product,
                        $newSlugText
                    );
                }
                
                $data['slug'] = $slugData['slug'];
                $data['slug_numeric_code'] = $slugData['slug_numeric_code'];
                
                Log::info('ProductRepository::updateProduct - Slug updated', [
                    'product_id' => $product->id,
                    'old_slug' => $product->slug,
                    'new_slug' => $slugData['slug'],
                    'slug_numeric_code' => $slugData['slug_numeric_code'],
                    'full_slug' => "{$slugData['slug']}-{$slugData['slug_numeric_code']}",
                ]);

                if (TRANSLATION_ENABLED) {
                    $fullSlug = $slugData['slug'] . '-' . $slugData['slug_numeric_code'];
                    $this->where('slug', $product->slug)->where('id', '!=', $product->id)->update([
                        'slug' => $fullSlug
                    ]);
                }
            }

            // Обработка загрузки видео при обновлении
            // Логируем информацию о запросе
            Log::info('ProductRepository::updateProduct - проверка видео', [
                'product_id' => $product->id,
                'hasFile_video' => $request->hasFile('video'),
                'has_video' => $request->has('video'),
                'all_files' => array_keys($request->allFiles()),
                'all_input_keys' => array_keys($request->all()),
                'content_type' => $request->header('Content-Type'),
                'request_method' => $request->method(),
            ]);
            
            if ($request->hasFile('video')) {
                try {
                    // Удаляем старые видео
                    $product->videos()->delete();
                    
                    $file = $request->file('video');
                    Log::info('ProductRepository::updateProduct - сохраняем видео', [
                        'file_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'product_id' => $product->id,
                    ]);
                    
                    // Проверяем размер файла (40MB максимум)
                    $maxSize = 40 * 1024 * 1024; // 40MB
                    if ($file->getSize() > $maxSize) {
                        throw new \Exception('Video file size exceeds maximum allowed size of 40MB');
                    }
                    
                    $key = 'products/videos/' . uniqid('', true) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                    
                    // Загружаем в S3
                    try {
                        $fileContents = file_get_contents($file->getRealPath());
                        $fileSize = strlen($fileContents);
                        
                        Log::info('ProductRepository::updateProduct - загружаем видео в S3', [
                            's3_key' => $key,
                            'file_size' => $fileSize,
                            'file_path' => $file->getRealPath(),
                            'file_exists' => file_exists($file->getRealPath()),
                        ]);
                        
                        $result = Storage::disk('s3')->put($key, $fileContents, [
                            'visibility' => 'public',
                            'CacheControl' => 'public, max-age=86400',
                            'ContentType' => $file->getMimeType() ?: 'video/mp4',
                        ]);
                        
                        // Проверяем, что файл действительно загружен
                        $existsInS3 = Storage::disk('s3')->exists($key);
                        
                        Log::info('ProductRepository::updateProduct - видео загружено в S3', [
                            's3_key' => $key,
                            'upload_result' => $result,
                            'exists_in_s3' => $existsInS3,
                            's3_url' => Storage::disk('s3')->url($key),
                        ]);
                    } catch (\Exception $e) {
                        Log::error('ProductRepository::updateProduct - ошибка загрузки видео в S3', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        throw new \Exception('Failed to upload video to S3: ' . $e->getMessage());
                    }
                    
                    // Создаем запись в БД
                    try {
                        $videoRecord = \Marvel\Database\Models\ProductVideo::create([
                            'product_id' => $product->id,
                            'url' => $key,
                            'file_size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                        ]);
                        
                        Log::info('ProductRepository::updateProduct - видео сохранено в БД', [
                            'video_id' => $videoRecord->id,
                            'product_id' => $product->id,
                            'video_url' => $videoRecord->url,
                            's3_key' => $key,
                            'video_exists_in_db' => \Marvel\Database\Models\ProductVideo::where('id', $videoRecord->id)->exists(),
                            'product_videos_count' => \Marvel\Database\Models\ProductVideo::where('product_id', $product->id)->count(),
                        ]);
                    } catch (\Exception $e) {
                        Log::error('ProductRepository::updateProduct - ошибка создания записи видео в БД', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        // Пытаемся удалить файл из S3, если запись в БД не создалась
                        try {
                            Storage::disk('s3')->delete($key);
                        } catch (\Exception $deleteException) {
                            Log::error('ProductRepository::updateProduct - ошибка удаления файла из S3', [
                                'error' => $deleteException->getMessage(),
                            ]);
                        }
                        throw new \Exception('Failed to create video record in database: ' . $e->getMessage());
                    }
                    
                    // Обрабатываем флаг video_as_cover (правильно обрабатываем строку '1' как boolean)
                    $videoAsCover = false;
                    if ($request->has('video_as_cover')) {
                        $videoAsCoverValue = $request->input('video_as_cover');
                        // Обрабатываем строку '1', 'true', boolean true и т.д.
                        $videoAsCover = in_array($videoAsCoverValue, ['1', 'true', true, 1], true);
                    }
                    
                    // Устанавливаем мета-данные ДО оптимизации
                    if ($videoAsCover) {
                        $product->setMeta('video_as_cover', true);
                        $product->setMeta('cover_video_id', $videoRecord->id);
                    } else {
                        $product->removeMeta('video_as_cover');
                        $product->removeMeta('cover_video_id');
                    }
                    
                    // Оптимизируем видео (может занять время, но не должно блокировать сохранение)
                    try {
                        $optimizationResult = \Marvel\Helpers\VideoOptimizer::optimizeVideo($videoRecord, $file->getRealPath());
                        
                        // Если установлена галочка "Сделать обложкой" и оптимизация прошла успешно
                        if ($videoAsCover && $optimizationResult) {
                            // Обновляем превью видео после оптимизации
                            $videoRecord->refresh();
                            if ($videoRecord->poster_url) {
                                // Используем постер видео как первое изображение
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
                                    'thumbnail' => $videoRecord->poster_url,
                                    'original' => $videoRecord->poster_url,
                                    'id' => null,
                                ];
                                
                                // Если есть существующее изображение, добавляем его в gallery
                                if ($firstImage) {
                                    $newGallery = array_merge([$newImage], [$firstImage], $currentGallery);
                                } else {
                                    $newGallery = array_merge([$newImage], $currentGallery);
                                }
                                
                                $data['image'] = $newImage;
                                $data['gallery'] = $newGallery;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('ProductRepository::updateProduct - ошибка оптимизации видео', [
                            'video_id' => $videoRecord->id ?? 'unknown',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        // Не прерываем обновление товара из-за ошибки оптимизации видео
                    }
                } catch (\Exception $e) {
                    Log::error('ProductRepository::updateProduct - критическая ошибка при загрузке видео', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'product_id' => $product->id,
                    ]);
                    throw $e; // Пробрасываем исключение дальше
                }
            } elseif ($request->has('existing_video')) {
                // Сохраняем существующее видео
                $product->videos()->delete();
                \Marvel\Database\Models\ProductVideo::create([
                    'product_id' => $product->id,
                    'url' => $request->input('existing_video')
                ]);
            }
            
            // Обрабатываем флаг video_as_cover (только если видео НЕ загружается)
            // Если видео загружается, флаг уже обработан выше
            if (!$request->hasFile('video') && $request->has('video_as_cover')) {
                $videoAsCoverValue = $request->input('video_as_cover');
                $videoAsCover = in_array($videoAsCoverValue, ['1', 'true', true, 1], true);
                
                if ($videoAsCover) {
                    // Если галочка установлена, находим последнее видео и устанавливаем флаг
                    $lastVideo = $product->videos()->latest()->first();
                    if ($lastVideo) {
                        $product->setMeta('video_as_cover', true);
                        $product->setMeta('cover_video_id', $lastVideo->id);
                    }
                } else {
                    // Если галочка снята, удаляем флаг
                    $product->removeMeta('video_as_cover');
                    $product->removeMeta('cover_video_id');
                }
            }
            
            // Защита: internal_article нельзя изменить после создания
            // ВАЖНО: internal_article генерируется ТОЛЬКО автоматически при создании
            // Удаляем internal_article из данных обновления (не принимается из API)
            unset($data['internal_article']);
            
            // Если артикул еще не установлен (для старых записей), генерируем его
            if (empty($product->internal_article)) {
                $data['internal_article'] = \Marvel\Services\ArticleGeneratorService::generateProductArticle();
            }

            // Сохраняем старый статус для проверки изменения
            $oldStatus = $product->status;
            $newStatus = $data['status'] ?? $oldStatus;
            
            // Логируем данные перед обновлением
            Log::info('ProductRepository::updateProduct - Data before update', [
                'product_id' => $product->id,
                'data_keys' => array_keys($data),
                'has_slug' => isset($data['slug']),
                'has_slug_numeric_code' => isset($data['slug_numeric_code']),
                'slug' => $data['slug'] ?? null,
                'slug_numeric_code' => $data['slug_numeric_code'] ?? null,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'status_changed' => $oldStatus !== $newStatus,
                'has_status_in_data' => isset($data['status']),
            ]);
            
            $product->update($data);

            $this->syncProductGeoPoint($product->fresh(), $request);
            
            // Проверяем, что slug_numeric_code сохранился
            $product->refresh();
            Log::info('ProductRepository::updateProduct - Product updated', [
                'product_id' => $product->id,
                'slug' => $product->slug,
                'slug_numeric_code' => $product->slug_numeric_code,
                'full_slug' => $product->full_slug,
                'slug_numeric_code_set' => !empty($product->slug_numeric_code),
                'status_after_update' => $product->status,
                'old_status' => $oldStatus,
                'status_changed' => $oldStatus !== $product->status,
            ]);
            
            // ВАЖНО: Если slug_numeric_code не сохранился - генерируем и сохраняем
            if (empty($product->slug_numeric_code)) {
                Log::warning('ProductRepository::updateProduct - slug_numeric_code not saved, generating', [
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                ]);
                
                $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                    $product,
                    $product->name
                );
                
                $product->slug = $slugData['slug'];
                $product->slug_numeric_code = $slugData['slug_numeric_code'];
                $product->save();
                
                Log::info('ProductRepository::updateProduct - slug_numeric_code generated and saved', [
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'slug_numeric_code' => $product->slug_numeric_code,
                ]);
            }
            
        // Загружаем videos после обновления перед возвращением
        try {
            if (class_exists(\Marvel\Database\Models\ProductVideo::class)) {
                $product->load('videos');
                Log::info('ProductRepository::updateProduct - videos загружены после update', [
                    'product_id' => $product->id,
                    'videos_count' => $product->videos ? $product->videos->count() : 0,
                    'videos_in_db' => \Marvel\Database\Models\ProductVideo::where('product_id', $product->id)->count(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('ProductRepository::updateProduct - не удалось загрузить videos', [
                'error' => $e->getMessage(),
                'product_id' => $product->id,
            ]);
        }
        // Для админки в ответе после update нужен URL цифрового файла.
        try {
            $product->load('digital_file');
            if ($product->digital_file) {
                $product->digital_file->makeVisible(['url']);
            }
        } catch (\Exception $e) {
            Log::warning('ProductRepository::updateProduct - failed to load digital_file', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->syncDigitalLicenseKeysFromRequest($product->fresh(), $request);
        $this->syncProductCourse($product->fresh(), $request);

        return $product;
    }

    /*
     * Duplicate legacy tail left from a previous merge. Keep it inert so the
     * repository can autoload; the active updateProduct implementation ends
     * above.
     *
    // <-- Конец метода updateProduct

            $product->load('videos');
            Log::info('ProductRepository::updateProduct - videos загружены после update', [
                'product_id' => $product->id,
                'videos_count' => $product->videos ? $product->videos->count() : 0,
                'videos_in_db' => \Marvel\Database\Models\ProductVideo::where('product_id', $product->id)->count(),
            ]);
        }
            
            // ВАЖНО: Удаляем вариации только если товар действительно стал простым
            // И только если он был вариативным до этого
            if ($finalProductType === ProductType::SIMPLE && $product->product_type === ProductType::VARIABLE) {
                Log::info('ProductRepository::updateProduct - Converting from variable to simple, deleting variations');
                $product->variations()->delete();
                $product->variation_options()->delete();
            }
            $product->save();

            // Отладочная информация после обновления
            \Log::info('Product updated successfully:', [
                'product_id' => $id,
                'final_image' => $product->fresh()->image ?? 'not_set',
                'final_gallery' => $product->fresh()->gallery ?? 'not_set',
                'updated_fields' => array_keys($data)
            ]);

            if (TRANSLATION_ENABLED) {
                $this->where('sku', $product->sku)->where('id', '=',  $product->id)->update([
                    'price' => $product->price,
                    'sale_price' => $product->sale_price,
                    'max_price' => $product->max_price,
                    'min_price' => $product->min_price,
                    'unit' => $product->unit,
                    'quantity' => $product->quantity,
                ]);
            }
            
            // Обновляем videos перед возвратом (на случай если они были изменены)
            try {
                if (class_exists(\Marvel\Database\Models\ProductVideo::class)) {
                    $product->load('videos');
                }
            } catch (\Exception $e) {
                Log::warning('ProductRepository::updateProduct - не удалось загрузить videos перед возвратом', [
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Логируем информацию о видео для отладки
            Log::info('ProductRepository::updateProduct - возвращаем товар с videos', [
                'product_id' => $product->id,
                'videos_count' => $product->videos ? $product->videos->count() : 0,
                'has_videos_relation' => $product->relationLoaded('videos'),
                'videos_in_db_count' => \Marvel\Database\Models\ProductVideo::where('product_id', $product->id)->count(),
            ]);
            
            // Преобразуем в массив для проверки
            try {
                $productArray = $product->toArray();
                Log::info('ProductRepository::updateProduct - product toArray videos', [
                    'product_id' => $product->id,
                    'has_videos_in_array' => isset($productArray['videos']),
                    'videos_array_count' => isset($productArray['videos']) && is_array($productArray['videos']) ? count($productArray['videos']) : 0,
                ]);
            } catch (\Exception $e) {
                // Игнорируем ошибки преобразования
            }
            
            // Загружаем связи для финальной проверки
            // ВАЖНО: Загружаем type, чтобы фронтенд получил полную информацию
            $product->load('variations', 'variation_options', 'type', 'shop', 'categories');
            Log::info('ProductRepository::updateProduct - Product updated successfully', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_type' => $product->product_type,
                'type_id' => $product->type_id,
                'type_loaded' => $product->relationLoaded('type'),
                'type_name' => $product->type ? $product->type->name : 'NOT_LOADED',
                'variations_count' => $product->variations->count(),
                'variation_options_count' => $product->variation_options->count(),
            ]);
            
            Log::info('=== ProductRepository::updateProduct - END (SUCCESS) ===');
            return $product;
    }

    */

    public function getBestSellingProducts($request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $range = !empty($request->range) && $request->range !== 'undefined'  ? $request->range : '';
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (ModelNotFoundException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }

        $products_query = Product::leftJoin('order_product', 'order_product.product_id', 'products.id')
            ->leftJoin('orders', 'order_product.order_id', '=', 'orders.id')
            ->with(['type', 'shop'])
            ->selectRaw('products.*, sum(order_product.order_quantity) total_sales')
            ->where('orders.parent_id', null)
            ->where('orders.order_status', 'order-completed')
            ->where('orders.language', $language)
            ->groupBy('order_product.product_id')
            ->orderBy('total_sales', 'desc');

        if (isset($request->shop_id)) {
            $products_query = $products_query->where('shop_id', "=", $request->shop_id);
        }
        if ($range) {
            $products_query = $products_query->whereDate('created_at', '>', Carbon::now()->subDays($range));
        }
        if ($type_id) {
            $products_query = $products_query->where('type_id', '=', $type_id);
        }
        return $products_query->take($limit)->get();
    }

    public function fetchRelated($slug, $limit = 10, $language = DEFAULT_LANGUAGE)
    {
        try {
            $product    = $this->findOneByFieldOrFail('slug', $slug);
            $categories = $product->categories->pluck('id');

            return $this->where('language', $language)->whereHas('categories', function ($query) use ($categories) {
                $query->whereIn('categories.id', $categories);
            })->with('type')->limit($limit)->get();
        } catch (Exception $e) {
            return [];
        }
    }

    public function getUnavailableProducts($from, $to)
    {
        $_blockedDates = Availability::whereDate('from', '<=', $from)
            ->whereDate('to', '>=', $to)
            ->get()->groupBy('product_id');

        $unavailableProducts = [];

        foreach ($_blockedDates as $productId =>  $date) {
            if (!$this->isProductAvailableAt($from, $to, $productId, $date)) {
                $unavailableProducts[] = $productId;
            }
        }
        return $unavailableProducts;
    }

    public function isProductAvailableAt($from, $to, $productId, $_blockedDates, $requestedQuantity = 1)
    {
        $quantity = 0;
        try {
            $product = Product::findOrFail($productId);
        } catch (\Throwable $th) {
            throw $th;
        }

        foreach ($_blockedDates as $singleDate) {
            $period = Period::make($singleDate['from'], $singleDate['to'], Precision::DAY, Boundaries::EXCLUDE_END);
            $range = Period::make($from, $to, Precision::DAY, Boundaries::EXCLUDE_END);
            if ($period->overlapsWith($range)) {
                $quantity += $singleDate->order_quantity;
            }
        }
        return $product->quantity - $quantity > $requestedQuantity;
    }


    public function fetchBlockedDatesForAProductInRange($from, $to, $productId)
    {
        return  Availability::where('product_id', $productId)->whereDate('from', '>=', $from)->whereDate('to', '<=', $to)->get();
    }

    public function fetchBlockedDatesForAVariationInRange($from, $to, $variation_id)
    {
        return  Availability::where('bookable_id', $variation_id)->where('bookable_type', 'Marvel\Database\Models\Variation')->whereDate('from', '>=', $from)->whereDate('to', '<=', $to)->get();
    }

    public function isVariationAvailableAt($from, $to, $variationId, $_blockedDates, $requestedQuantity)
    {
        $quantity = 0;
        try {
            $variation = Variation::findOrFail($variationId);
        } catch (\Throwable $th) {
            throw $th;
        }

        foreach ($_blockedDates as $singleDate) {
            $period = Period::make($singleDate['from'], $singleDate['to'], Precision::DAY, Boundaries::EXCLUDE_END);
            $range = Period::make($from, $to, Precision::DAY, Boundaries::EXCLUDE_END);
            if ($period->overlapsWith($range)) {
                $quantity += $singleDate->order_quantity;
            }
        }
        return $variation->quantity - $quantity >= $requestedQuantity;
    }


    public function calculatePrice($bookedDay, $product_id, $variation_id, $quantity, $persons, $dropoff_location_id, $pickup_location_id, $deposits, $features)
    {
        $price = 0;
        $person_price = 0;
        $deposit_price = 0;
        $feature_price = 0;
        $dropoff_location_price = 0;
        $pickup_location_price = 0;

        if ($variation_id) {
            $variation_price = $this->calculateVariationPrice($variation_id);
            $price += $variation_price * $bookedDay * $quantity;
        } else {
            $product_price = $this->calculateProductPrice($product_id);
            $price += $product_price * $bookedDay * $quantity;
        }
        if ($dropoff_location_id) {
            $dropoff_location_price = $this->calculateLocationPrice($dropoff_location_id);
        }
        if ($pickup_location_id) {
            $pickup_location_price = $this->calculateLocationPrice($pickup_location_id);
        }
        if ($features) {
            $feature_price = $this->calculateResourcePrice($features);
        }
        if ($persons) {
            $person_price = $this->calculateResourcePrice($persons);
        }
        if ($deposits) {
            $deposit_price = $this->calculateResourcePrice($deposits);
        }

        return [
            'totalPrice' => $price + $person_price + $deposit_price + $feature_price + $dropoff_location_price, $pickup_location_price,
            'personPrice' => $person_price,
            'depositPrice' => $deposit_price,
            'featurePrice' => $feature_price,
            'dropoffLocationPrice' => $dropoff_location_price,
            'pickupLocationPrice' => $pickup_location_price
        ];
    }

    public function calculateProductPrice($product_id)
    {
        try {
            $product = Product::findOrFail($product_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $product->sale_price ? $product->sale_price : $product->price;
    }

    public function calculateVariationPrice($variation_id)
    {
        try {
            $variation = Variation::findOrFail($variation_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $variation->sale_price ? $variation->sale_price : $variation->price;
    }

    public function calculateLocationPrice($location_id)
    {
        try {
            $location = Resource::findOrFail($location_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $location->price;
    }

    public function calculateResourcePrice($resources)
    {
        $price = 0;
        foreach ($resources as $resource_id) {
            try {
                $resource = Resource::findOrFail($resource_id);
            } catch (\Throwable $th) {
                throw $th;
            }
            if ($resource->price) {
                $price += $resource->price;
            }
        }
        return $price;
    }

    public function customSlugify($text, string $divider = '-')
    {
        $slug      = preg_replace('~[^\pL\d]+~u', $divider, $text);
        $slugCount = Product::where('slug', $slug)->orWhere('slug', 'like',  $slug . '%')->count();

        if (empty($slugCount)) {
            return $slug;
        }

        return $slug . $divider . $slugCount;
    }

    /**
     * Обрабатывает теги: создает новые теги если их нет, возвращает массив ID существующих тегов
     * 
     * @param array $tags Массив тегов (могут быть ID или объекты с name)
     * @param int $typeId ID типа товара
     * @param string $language Язык тега
     * @return array Массив ID тегов
     */
    protected function processTags($tags, $typeId = 1, $language = 'ru')
    {
        if (empty($tags) || !is_array($tags)) {
            Log::info('ProductRepository::processTags - Empty or invalid tags array', [
                'tags' => $tags,
            ]);
            return [];
        }

        $tagIds = [];

        foreach ($tags as $index => $tag) {
            Log::info('ProductRepository::processTags - Processing tag', [
                'index' => $index,
                'tag' => $tag,
                'tag_type' => gettype($tag),
            ]);
            
            // Если тег - это ID (число или строка с числом)
            if (is_numeric($tag)) {
                // Проверяем, существует ли тег с таким ID
                $existingTag = Tag::find($tag);
                if ($existingTag) {
                    $tagIds[] = $existingTag->id;
                    Log::info('ProductRepository::processTags - Found existing tag by ID', [
                        'tag_id' => $existingTag->id,
                        'tag_name' => $existingTag->name,
                    ]);
                } else {
                    Log::warning('ProductRepository::processTags - Tag ID not found', [
                        'tag_id' => $tag,
                    ]);
                }
                continue;
            }

            // Если тег - это объект или массив с полем name
            $tagName = null;
            if (is_array($tag) && isset($tag['name'])) {
                $tagName = trim($tag['name']);
            } elseif (is_object($tag) && isset($tag->name)) {
                $tagName = trim($tag->name);
            } elseif (is_string($tag)) {
                // Если это просто строка - используем как имя тега
                $tagName = trim($tag);
            }

            if (empty($tagName)) {
                Log::warning('ProductRepository::processTags - Empty tag name', [
                    'tag' => $tag,
                ]);
                continue;
            }

            // Пытаемся найти существующий тег по имени и языку
            $existingTag = Tag::where('name', $tagName)
                ->where('language', $language)
                ->first();

            if ($existingTag) {
                // Тег существует, используем его ID
                $tagIds[] = $existingTag->id;
                Log::info('ProductRepository::processTags - Found existing tag by name', [
                    'tag_id' => $existingTag->id,
                    'tag_name' => $tagName,
                    'language' => $language,
                ]);
            } else {
                // Тег не существует, создаем новый
                try {
                    $slug = Str::slug($tagName);
                    
                    // Проверяем уникальность slug для данного языка
                    $slugCount = Tag::where('slug', $slug)
                        ->where('language', $language)
                        ->count();
                    
                    if ($slugCount > 0) {
                        $slug = $slug . '-' . ($slugCount + 1);
                    }

                    $newTag = Tag::create([
                        'name' => $tagName,
                        'slug' => $slug,
                        'language' => $language,
                        'type_id' => $typeId,
                    ]);

                    $tagIds[] = $newTag->id;

                    Log::info('ProductRepository::processTags - Created new tag', [
                        'tag_id' => $newTag->id,
                        'tag_name' => $tagName,
                        'tag_slug' => $slug,
                        'language' => $language,
                        'type_id' => $typeId,
                    ]);
                } catch (\Exception $e) {
                    Log::error('ProductRepository::processTags - Error creating tag', [
                        'tag_name' => $tagName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Продолжаем обработку остальных тегов даже если один не удалось создать
                }
            }
        }

        // Убираем дубликаты и возвращаем массив ID
        $uniqueTagIds = array_unique($tagIds);
        Log::info('ProductRepository::processTags - Final tag IDs', [
            'tag_ids' => $uniqueTagIds,
            'count' => count($uniqueTagIds),
        ]);
        
        return $uniqueTagIds;
    }
}
