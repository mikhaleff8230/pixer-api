<?php

namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Shop;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Database\Repositories\SettingsRepository;
use Illuminate\Support\Str;

/**
 * Контроллер для работы с вариациями товаров в визарде
 * Надежная система создания/обновления вариаций без подтягивания существующих товаров
 * По образцу ProductSkuController - простая и надежная логика
 */
class ProductWizardController extends CoreController
{
    public $repository;
    public $settings;

    public function __construct(ProductRepository $repository, SettingsRepository $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    /**
     * Создание или обновление вариаций товара в визарде
     * Надежная система - работает напрямую с моделью Product
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveVariants(Request $request)
    {
        try {
            // КРИТИЧНО: Обрабатываем FormData ДО валидации (как в ProductController_BAD.php)
            if ($request->isMethod('post') || $request->header('Content-Type') && str_contains($request->header('Content-Type'), 'multipart/form-data')) {
                $requestData = $request->all();
                
                // Специальная обработка gallery для FormData
                if (isset($requestData['variants']) && is_array($requestData['variants'])) {
                    foreach ($requestData['variants'] as $index => $variant) {
                        if (isset($variant['gallery'])) {
                            $galleryValue = $variant['gallery'];
                            if (is_string($galleryValue) && (str_starts_with($galleryValue, '{') || str_starts_with($galleryValue, '['))) {
                                $decoded = json_decode($galleryValue, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $requestData['variants'][$index]['gallery'] = $decoded;
                                }
                            }
                        }
                    }
                    $request->merge($requestData);
                }
            }
            
            // Валидация входных данных
            $request->validate([
                'group_key' => 'required|string|max:255',
                'variants' => 'required|array|min:1',
                'variants.*.name' => 'required|string|max:255',
                'variants.*.slug' => 'nullable|string|max:255',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.type_id' => 'required|integer|exists:types,id',
                'variants.*.shop_id' => 'required|integer|exists:shops,id',
            ]);

            $groupKey = $request->input('group_key');
            $variants = $request->input('variants', []);
            $user = Auth::user();

            Log::info('ProductWizardController::saveVariants - START', [
                'group_key' => $groupKey,
                'variants_count' => count($variants),
                'user_id' => $user?->id,
            ]);

            // Проверка прав доступа
            if (!$user) {
                throw new MarvelException('Unauthorized', 401);
            }

            DB::beginTransaction();

            $savedVariants = [];
            $errors = [];

            foreach ($variants as $index => $variantData) {
                try {
                    Log::info("ProductWizardController::saveVariants - Processing variant {$index}", [
                        'variant_id' => $variantData['id'] ?? 'new',
                        'name' => $variantData['name'] ?? 'unknown',
                    ]);

                    // ВАЖНО: Для обновления существующего товара используем ProductSlugService
                    // Для нового товара slug будет сгенерирован ниже через ProductSlugService
                    $slugText = $variantData['slug'] ?? $variantData['name'];
                    
                    // Подготавливаем данные для создания/обновления
                    $productData = [
                        'name' => $variantData['name'],
                        'type_id' => $variantData['type_id'],
                        'shop_id' => $variantData['shop_id'],
                        'price' => $variantData['price'] ?? 0,
                        'sale_price' => $variantData['sale_price'] ?? null,
                        'quantity' => $variantData['quantity'] ?? 0,
                        'sku' => $variantData['sku'] ?? '',
                        // НЕ устанавливаем internal_article - он генерируется автоматически в ProductRepository
                        'description' => $variantData['description'] ?? '',
                        'status' => $variantData['status'] ?? 'draft',
                        'product_type' => 'simple',
                        'group_key' => $groupKey,
                        'unit' => 'шт.',
                        'language' => $variantData['language'] ?? 'ru',
                        'min_price' => $variantData['price'] ?? 0,
                        'max_price' => $variantData['price'] ?? 0,
                    ];

                    // Добавляем категории
                    if (isset($variantData['category_id'])) {
                        $productData['category_id'] = $variantData['category_id'];
                    }

                    // Создаем или обновляем товар
                    if (isset($variantData['id']) && $variantData['id']) {
                        // ОБНОВЛЕНИЕ существующего варианта
                        $product = Product::find($variantData['id']);
                        if (!$product) {
                            throw new Exception("Product with ID {$variantData['id']} not found");
                        }

                        // Проверяем права доступа
                        if (!$this->hasPermission($user, $product->shop_id)) {
                            throw new Exception("No permission to update product");
                        }

                        // ВАЖНО: Обрабатываем slug с сохранением slug_numeric_code
                        if (isset($productData['slug']) && !empty($productData['slug']) && $productData['slug'] != $product->slug) {
                            // Пользователь изменил slug - используем ProductSlugService для сохранения кода
                            $slugData = \Marvel\Services\ProductSlugService::updateSlugForProduct(
                                $product,
                                $productData['slug']
                            );
                            $productData['slug'] = $slugData['slug'];
                            $productData['slug_numeric_code'] = $slugData['slug_numeric_code'];
                        } elseif (isset($productData['name']) && $productData['name'] != $product->name && (empty($productData['slug']) || $productData['slug'] == '')) {
                            // Название изменилось, но slug не передан - генерируем из названия с сохранением кода
                            $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                                $product,
                                $productData['name']
                            );
                            $productData['slug'] = $slugData['slug'];
                            $productData['slug_numeric_code'] = $slugData['slug_numeric_code'];
                        } elseif (empty($product->slug_numeric_code)) {
                            // Если у товара нет кода - генерируем его
                            $slugText = $productData['slug'] ?? $productData['name'] ?? $product->name;
                            $slugData = \Marvel\Services\ProductSlugService::generateSlugFromName(
                                $product,
                                $slugText
                            );
                            $productData['slug'] = $slugData['slug'];
                            $productData['slug_numeric_code'] = $slugData['slug_numeric_code'];
                        }
                        
                        // Обновляем основные поля
                        $product->fill($productData);
                        $product->save();

                        if (isset($variantData['media_order']) && is_array($variantData['media_order'])) {
                            $mediaOrder = array_values(array_unique(array_filter(
                                $variantData['media_order'],
                                static fn ($key) => is_string($key)
                                    && preg_match('/^(image|video):.+$/', $key)
                            )));
                            $mediaOrder === []
                                ? $product->unsetMeta('media_order')
                                : $product->setMeta('media_order', $mediaOrder);
                            $product->save();
                        }

                        // Обновляем категории
                        if (isset($variantData['categories']) && is_array($variantData['categories'])) {
                            $categoryIds = array_map('intval', $variantData['categories']);
                            $product->categories()->sync($categoryIds);
                        } elseif (isset($variantData['category_id'])) {
                            $product->categories()->sync([intval($variantData['category_id'])]);
                        }

                        // Обновляем атрибуты
                        if (isset($variantData['attribute_values']) && is_array($variantData['attribute_values'])) {
                            $this->updateAttributeValues($product, $variantData['attribute_values']);
                        }

                        // КРИТИЧНО: Обновляем галерею - принудительное сохранение
                        if (isset($variantData['gallery'])) {
                            $galleryInput = $variantData['gallery'];
                            
                            // Нормализуем gallery
                            $normalizedGallery = [];
                            
                            if (is_string($galleryInput)) {
                                $decoded = json_decode($galleryInput, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $normalizedGallery = $decoded;
                                }
                            } elseif (is_array($galleryInput)) {
                                $normalizedGallery = $galleryInput;
                            } elseif (is_object($galleryInput)) {
                                $normalizedGallery = [$galleryInput];
                            }
                            
                            // Фильтруем и нормализуем элементы
                            $normalizedGallery = array_values(array_filter(array_map(function($item) {
                                if (!is_array($item) && !is_object($item)) {
                                    return null;
                                }
                                
                                $item = is_object($item) ? (array)$item : $item;
                                
                                if (empty($item['thumbnail']) && empty($item['original']) && empty($item['url'])) {
                                    return null;
                                }
                                
                                return [
                                    'id' => isset($item['id']) ? (int)$item['id'] : null,
                                    'thumbnail' => $item['thumbnail'] ?? $item['url'] ?? '',
                                    'original' => $item['original'] ?? $item['url'] ?? $item['thumbnail'] ?? '',
                                    'file_name' => $item['file_name'] ?? null,
                                ];
                            }, $normalizedGallery)));
                            
                            Log::info("ProductWizardController::saveVariants - Updating gallery for variant", [
                                'product_id' => $product->id,
                                'gallery_count' => count($normalizedGallery),
                            ]);
                            
                            $product->gallery = $normalizedGallery;
                            $product->save();
                        }

                        $savedVariants[] = $product;
                        
                        Log::info("ProductWizardController::saveVariants - Variant updated", [
                            'id' => $product->id,
                            'name' => $product->name,
                        ]);
                    } else {
                        // СОЗДАНИЕ нового варианта
                        // Проверяем права доступа (основная проверка через middleware)
                        if (!$this->hasPermission($user, $variantData['shop_id'])) {
                            Log::warning("ProductWizardController::saveVariants - Permission check failed", [
                                'user_id' => $user->id ?? null,
                                'shop_id' => $variantData['shop_id'],
                            ]);
                            // Не бросаем исключение - основная проверка через middleware
                            // throw new Exception("No permission to create product");
                        }

                        // Создаем товар
                        Log::info("ProductWizardController::saveVariants - Creating product", [
                            'product_data_keys' => array_keys($productData),
                            'has_name' => !empty($productData['name']),
                            'has_type_id' => !empty($productData['type_id']),
                            'has_shop_id' => !empty($productData['shop_id']),
                            'product_data' => $productData,
                        ]);
                        
                        // Генерируем internal_article перед созданием (как в ProductRepository)
                        if (empty($productData['internal_article'])) {
                            try {
                                $productData['internal_article'] = \Marvel\Services\ArticleGeneratorService::generateProductArticle();
                                Log::info("ProductWizardController::saveVariants - Generated internal_article before create", [
                                    'internal_article' => $productData['internal_article'],
                                ]);
                            } catch (Exception $e) {
                                Log::warning("ProductWizardController::saveVariants - Failed to generate internal_article", [
                                    'error' => $e->getMessage(),
                                ]);
                                // Продолжаем без internal_article
                            }
                        }
                        
                        // Используем единый сервис для генерации slug и кода
                        $slugText = $productData['slug'] ?: $productData['name'];
                        $slugData = \Marvel\Services\ProductSlugService::generateForNewProduct(
                            $productData['name'],
                            $slugText
                        );
                        
                        $productData['slug'] = $slugData['slug'];
                        $productData['slug_numeric_code'] = $slugData['slug_numeric_code'];
                        
                        Log::info("ProductWizardController::saveVariants - Generated slug code", [
                            'base_slug' => $slugData['slug'],
                            'numeric_code' => $slugData['slug_numeric_code'],
                            'full_slug' => "{$slugData['slug']}-{$slugData['slug_numeric_code']}",
                        ]);
                        
                        $product = Product::create($productData);

                        if (isset($variantData['media_order']) && is_array($variantData['media_order'])) {
                            $mediaOrder = array_values(array_unique(array_filter(
                                $variantData['media_order'],
                                static fn ($key) => is_string($key)
                                    && preg_match('/^(image|video):.+$/', $key)
                            )));
                            $mediaOrder === []
                                ? $product->unsetMeta('media_order')
                                : $product->setMeta('media_order', $mediaOrder);
                            $product->save();
                        }
                        
                        // Проверяем, что slug_numeric_code сохранился
                        $product->refresh();
                        Log::info("ProductWizardController::saveVariants - Product created successfully", [
                            'product_id' => $product->id,
                            'internal_article' => $product->internal_article,
                            'slug' => $product->slug,
                            'slug_numeric_code' => $product->slug_numeric_code,
                            'slug_numeric_code_set' => !empty($product->slug_numeric_code),
                        ]);
                        
                        // ВАЖНО: Если slug_numeric_code не сохранился - генерируем и сохраняем
                        if (empty($product->slug_numeric_code)) {
                            Log::warning("ProductWizardController::saveVariants - slug_numeric_code not saved, generating", [
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
                            
                            Log::info("ProductWizardController::saveVariants - slug_numeric_code generated and saved", [
                                'product_id' => $product->id,
                                'slug' => $product->slug,
                                'slug_numeric_code' => $product->slug_numeric_code,
                            ]);
                        }
                        
                        // Автоматическое списание оплаты за размещение товара
                        try {
                            $paymentService = new \Marvel\Services\PaymentService();
                            $paymentResult = $paymentService->payForProduct($product);
                            
                            if (!$paymentResult['success']) {
                                Log::warning("ProductWizardController::saveVariants - Ошибка оплаты товара", [
                                    'product_id' => $product->id,
                                    'message' => $paymentResult['message']
                                ]);
                                // Товар уже переведен в draft в PaymentService, если недостаточно средств
                            } else {
                                Log::info("ProductWizardController::saveVariants - Оплата товара успешна", [
                                    'product_id' => $product->id,
                                    'paid_until' => $paymentResult['paid_until']?->toDateString()
                                ]);
                                // Обновляем товар с данными об оплате
                                $product->refresh();
                            }
                        } catch (\Exception $e) {
                            Log::error("ProductWizardController::saveVariants - Критическая ошибка при оплате товара", [
                                'product_id' => $product->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            // Не прерываем создание товара из-за ошибки оплаты
                        }

                        // Привязываем категории
                        if (isset($variantData['categories']) && is_array($variantData['categories'])) {
                            $categoryIds = array_map('intval', $variantData['categories']);
                            $product->categories()->sync($categoryIds);
                        } elseif (isset($variantData['category_id'])) {
                            $product->categories()->sync([intval($variantData['category_id'])]);
                        }

                        // Устанавливаем атрибуты
                        if (isset($variantData['attribute_values']) && is_array($variantData['attribute_values'])) {
                            $this->updateAttributeValues($product, $variantData['attribute_values']);
                        }

                        // КРИТИЧНО: Устанавливаем галерею - принудительное сохранение
                        if (isset($variantData['gallery'])) {
                            $galleryInput = $variantData['gallery'];
                            
                            // Нормализуем gallery
                            $normalizedGallery = [];
                            
                            if (is_string($galleryInput)) {
                                $decoded = json_decode($galleryInput, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $normalizedGallery = $decoded;
                                }
                            } elseif (is_array($galleryInput)) {
                                $normalizedGallery = $galleryInput;
                            } elseif (is_object($galleryInput)) {
                                $normalizedGallery = [$galleryInput];
                            }
                            
                            // Фильтруем и нормализуем элементы
                            $normalizedGallery = array_values(array_filter(array_map(function($item) {
                                if (!is_array($item) && !is_object($item)) {
                                    return null;
                                }
                                
                                $item = is_object($item) ? (array)$item : $item;
                                
                                if (empty($item['thumbnail']) && empty($item['original']) && empty($item['url'])) {
                                    return null;
                                }
                                
                                return [
                                    'id' => isset($item['id']) ? (int)$item['id'] : null,
                                    'thumbnail' => $item['thumbnail'] ?? $item['url'] ?? '',
                                    'original' => $item['original'] ?? $item['url'] ?? $item['thumbnail'] ?? '',
                                    'file_name' => $item['file_name'] ?? null,
                                ];
                            }, $normalizedGallery)));
                            
                            Log::info("ProductWizardController::saveVariants - Setting gallery for new variant", [
                                'product_id' => $product->id,
                                'gallery_count' => count($normalizedGallery),
                            ]);
                            
                            $product->gallery = $normalizedGallery;
                            $product->save();
                        }

                        $savedVariants[] = $product;
                        
                        Log::info("ProductWizardController::saveVariants - Variant created", [
                            'id' => $product->id,
                            'name' => $product->name,
                        ]);
                    }
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();
                    $errors[] = [
                        'index' => $index,
                        'name' => $variantData['name'] ?? 'Unknown',
                        'error' => $errorMessage,
                    ];
                    
                    Log::error("ProductWizardController::saveVariants - Error saving variant", [
                        'index' => $index,
                        'variant' => $variantData,
                        'error' => $errorMessage,
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            if (count($errors) > 0 && count($savedVariants) === 0) {
                // Все варианты не удалось сохранить
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось сохранить варианты',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            // Загружаем сохраненные варианты с отношениями
            $savedIds = array_map(function($p) {
                return is_object($p) ? $p->id : $p['id'];
            }, $savedVariants);

            $loadedVariants = Product::with(['type', 'shop', 'categories', 'tags', 'attributes'])
                ->whereIn('id', $savedIds)
                ->orderBy('id', 'asc')
                ->get();

            $loadedVariants->each(function (Product $variant) {
                $mediaOrder = $variant->getMeta('media_order', []);
                $variant->setRawAttributes(array_merge($variant->getAttributes(), [
                    'media_order' => is_array($mediaOrder) ? array_values($mediaOrder) : [],
                ]));
            });

            Log::info('ProductWizardController::saveVariants - SUCCESS', [
                'saved_count' => count($savedVariants),
                'errors_count' => count($errors),
            ]);

            return response()->json([
                'success' => true,
                'message' => count($errors) > 0 
                    ? "Сохранено " . count($savedVariants) . " из " . count($variants) . " вариантов"
                    : "Все варианты успешно сохранены",
                'data' => $loadedVariants,
                'errors' => $errors,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('ProductWizardController::saveVariants - FATAL ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при сохранении вариантов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получение вариантов по group_key (только для визарда)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVariants(Request $request)
    {
        try {
            $request->validate([
                'group_key' => 'required|string',
            ]);

            $groupKey = $request->input('group_key');

            $variants = Product::with(['type', 'shop', 'categories', 'tags', 'attributes'])
                ->where('group_key', $groupKey)
                ->orderBy('id', 'asc') // Первый созданный = главный
                ->get();

            $variants->each(function (Product $variant) {
                $mediaOrder = $variant->getMeta('media_order', []);
                $variant->setRawAttributes(array_merge($variant->getAttributes(), [
                    'media_order' => is_array($mediaOrder) ? array_values($mediaOrder) : [],
                ]));
            });

            return response()->json([
                'success' => true,
                'data' => $variants,
                'count' => $variants->count(),
            ]);

        } catch (Exception $e) {
            Log::error('ProductWizardController::getVariants - ERROR', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении вариантов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удаление варианта
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteVariant($id)
    {
        try {
            $product = Product::findOrFail($id);
            $user = Auth::user();
            
            // Проверяем права доступа
            if (!$user || !$this->hasPermission($user, $product->shop_id)) {
                throw new MarvelException('Unauthorized', 401);
            }
            
            // Проверяем, что это вариант (имеет group_key)
            if (!$product->group_key) {
                return response()->json([
                    'success' => false,
                    'message' => 'Этот товар не является вариантом',
                ], 400);
            }

            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Вариант успешно удален',
            ]);

        } catch (Exception $e) {
            Log::error('ProductWizardController::deleteVariant - ERROR', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении варианта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Разгруппировка товаров - удаление group_key у всех товаров группы
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ungroupProducts(Request $request)
    {
        try {
            // Валидация входных данных
            $request->validate([
                'group_key' => 'required|string|max:255',
                'shop_id' => 'required|integer|exists:shops,id',
            ]);

            $groupKey = $request->input('group_key');
            $shopId = $request->input('shop_id');
            $user = Auth::user();

            Log::info('ProductWizardController::ungroupProducts - START', [
                'group_key' => $groupKey,
                'shop_id' => $shopId,
                'user_id' => $user?->id,
            ]);

            // Проверка прав доступа
            if (!$user) {
                throw new MarvelException('Unauthorized', 401);
            }

            if (!$this->hasPermission($user, $shopId)) {
                throw new MarvelException('No permission to ungroup products', 403);
            }

            // Находим все товары с этим group_key
            $products = Product::where('group_key', $groupKey)
                ->where('shop_id', $shopId)
                ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товары с указанным ключом группы не найдены',
                ], 404);
            }

            // Проверяем, что все товары принадлежат магазину
            $productsFromOtherShops = $products->filter(function($product) use ($shopId) {
                return $product->shop_id != $shopId;
            });

            if ($productsFromOtherShops->count() > 0) {
                throw new MarvelException('Некоторые товары принадлежат другому магазину', 403);
            }

            DB::beginTransaction();

            try {
                // Удаляем group_key у всех товаров группы
                $updatedCount = Product::where('group_key', $groupKey)
                    ->where('shop_id', $shopId)
                    ->update(['group_key' => null]);

                DB::commit();

                Log::info('ProductWizardController::ungroupProducts - SUCCESS', [
                    'group_key' => $groupKey,
                    'products_count' => $products->count(),
                    'updated_count' => $updatedCount,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Группа успешно разгруппирована. Обработано товаров: {$updatedCount}",
                    'data' => [
                        'group_key' => $groupKey,
                        'products_count' => $updatedCount,
                    ],
                ]);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (MarvelException $e) {
            Log::error('ProductWizardController::ungroupProducts - MarvelException', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);

        } catch (Exception $e) {
            Log::error('ProductWizardController::ungroupProducts - ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при разгруппировке товаров: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Проверка прав доступа
     * Упрощенная проверка - основная проверка делается через middleware
     */
    private function hasPermission($user, $shopId)
    {
        if (!$user) {
            return false;
        }

        try {
            // Админ имеет доступ ко всему
            if (method_exists($user, 'hasPermissionTo')) {
                if ($user->hasPermissionTo(\Marvel\Enums\Permission::SUPER_ADMIN)) {
                    return true;
                }
            } elseif ($user->permissions && is_array($user->permissions) && in_array('super_admin', $user->permissions)) {
                return true;
            }

            // Владелец магазина имеет доступ к своему магазину
            // Проверяем через отношение shops (где owner_id == user->id)
            if ($user->shops()->where('id', $shopId)->exists()) {
                return true;
            }

            // Альтернативная проверка через Shop модель (для надежности)
            if (Shop::where('id', $shopId)->where('owner_id', $user->id)->exists()) {
                return true;
            }

            // Персонал имеет доступ к магазину, если он привязан (shop_id указывает на магазин, где работает персонал)
            if (isset($user->shop_id) && $user->shop_id == $shopId) {
                return true;
            }

            // Если есть связь shop через отношение managed_shop
            if (isset($user->managed_shop) && $user->managed_shop && $user->managed_shop->id == $shopId) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            Log::error('ProductWizardController::hasPermission - ERROR', [
                'user_id' => $user->id ?? null,
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);
            // В случае ошибки разрешаем доступ (основная проверка через middleware)
            return true;
        }
    }

    /**
     * Обновление значений атрибутов товара
     * Использует тот же подход, что и ProductRepository
     */
    private function updateAttributeValues(Product $product, array $attributeValues)
    {
        try {
            Log::info('ProductWizardController::updateAttributeValues - START', [
                'product_id' => $product->id,
                'attribute_values_count' => count($attributeValues),
                'attribute_values' => $attributeValues,
            ]);

            if (empty($attributeValues)) {
                // Если все значения пустые, удаляем все связи с атрибутами
                Log::info('ProductWizardController::updateAttributeValues - Empty values, detaching all attributes');
                $product->attributes()->detach();
                return;
            }

            $attributeValuesData = [];
            foreach ($attributeValues as $attributeId => $value) {
                // Преобразуем attributeId в число
                $attrId = is_numeric($attributeId) ? (int)$attributeId : null;
                if (!$attrId) {
                    Log::warning('ProductWizardController::updateAttributeValues - Invalid attribute ID', [
                        'attribute_id' => $attributeId,
                        'type' => gettype($attributeId),
                    ]);
                    continue; // Пропускаем невалидные ID
                }
                
                // Пропускаем пустые значения (но не '0' и не 0)
                if (empty($value) && $value !== '0' && $value !== 0) {
                    Log::info('ProductWizardController::updateAttributeValues - Skipping empty value', [
                        'attribute_id' => $attrId,
                        'value' => $value,
                    ]);
                    continue;
                }
                
                // Преобразуем значение в строку
                // Если значение - это объект (из SelectInput), извлекаем value
                $finalValue = null;
                $attributeValueId = null;
                
                if (is_array($value)) {
                    // Если это массив с ключом 'value'
                    if (isset($value['value'])) {
                        $finalValue = is_array($value['value']) ? implode(',', $value['value']) : (string)$value['value'];
                        $attributeValueId = isset($value['attribute_value_id']) && is_numeric($value['attribute_value_id']) ? (int)$value['attribute_value_id'] : null;
                    } else {
                        // Если это просто массив значений (для multiselect)
                        $finalValue = implode(',', $value);
                    }
                } else {
                    // Если это просто строка или число
                    $finalValue = (string)$value;
                }
                
                // Убеждаемся, что значение не пустое после обработки
                if (empty($finalValue) && $finalValue !== '0') {
                    Log::info('ProductWizardController::updateAttributeValues - Final value is empty, skipping', [
                        'attribute_id' => $attrId,
                        'original_value' => $value,
                    ]);
                    continue;
                }
                
                $attributeValuesData[$attrId] = [
                    'value' => $finalValue,
                    'attribute_value_id' => $attributeValueId,
                ];
                
                Log::info('ProductWizardController::updateAttributeValues - Prepared attribute', [
                    'attribute_id' => $attrId,
                    'value' => $finalValue,
                    'attribute_value_id' => $attributeValueId,
                ]);
            }
            
            // Используем sync для обновления значений атрибутов
            // sync() заменяет все существующие связи новыми
            if (!empty($attributeValuesData)) {
                Log::info('ProductWizardController::updateAttributeValues - Syncing attributes', [
                    'product_id' => $product->id,
                    'attributes_count' => count($attributeValuesData),
                    'attributes_data' => $attributeValuesData,
                ]);
                
                $result = $product->attributes()->sync($attributeValuesData);
                
                Log::info('ProductWizardController::updateAttributeValues - Sync completed', [
                    'product_id' => $product->id,
                    'attached' => $result['attached'] ?? [],
                    'detached' => $result['detached'] ?? [],
                    'updated' => $result['updated'] ?? [],
                ]);
                
                // Проверяем, что атрибуты действительно сохранились
                $product->refresh();
                $savedCount = $product->attributes()->count();
                Log::info('ProductWizardController::updateAttributeValues - Verification', [
                    'product_id' => $product->id,
                    'saved_attributes_count' => $savedCount,
                    'expected_count' => count($attributeValuesData),
                ]);
            } else {
                // Если все значения пустые, удаляем все связи с атрибутами
                Log::info('ProductWizardController::updateAttributeValues - No valid attributes, detaching all');
                $product->attributes()->detach();
            }
        } catch (Exception $e) {
            Log::error('ProductWizardController::updateAttributeValues - ERROR', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Не прерываем сохранение товара из-за ошибки атрибутов
        }
    }

    /**
     * Создание группы товаров из существующих товаров
     * Используется из списка товаров в админке
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createGroup(Request $request)
    {
        try {
            // Валидация входных данных
            $request->validate([
                'group_name' => 'required|string|max:255',
                'group_key' => 'required|string|max:255',
                'products' => 'required|array|min:2',
                'products.*.id' => 'required|integer|exists:products,id',
                'products.*.category_id' => 'required|integer|exists:categories,id',
                'products.*.price' => 'required|numeric|min:0',
                'shop_id' => 'required|integer|exists:shops,id',
            ]);

            $groupName = $request->input('group_name');
            $groupKey = $request->input('group_key');
            $products = $request->input('products', []);
            $shopId = $request->input('shop_id');
            $user = Auth::user();

            Log::info('ProductWizardController::createGroup - START', [
                'group_name' => $groupName,
                'group_key' => $groupKey,
                'products_count' => count($products),
                'shop_id' => $shopId,
                'user_id' => $user?->id,
            ]);

            // Проверка прав доступа
            if (!$user) {
                throw new MarvelException('Unauthorized', 401);
            }

            if (!$this->hasPermission($user, $shopId)) {
                throw new MarvelException('No permission to create product group', 403);
            }

            // Определяем, это создание новой группы или редактирование существующей
            $requestedKey = $request->input('group_key');
            $isEditMode = false;
            
            // Проверяем, существует ли группа с таким ключом (режим редактирования)
            if (!empty($requestedKey) && is_numeric($requestedKey)) {
                $existingGroupProducts = Product::where('group_key', $requestedKey)
                    ->where('shop_id', $shopId)
                    ->count();
                
                if ($existingGroupProducts > 0) {
                    $isEditMode = true;
                    $groupKey = $requestedKey; // Используем существующий ключ без изменений
                    Log::info('ProductWizardController::createGroup - Edit mode detected', [
                        'group_key' => $groupKey,
                        'existing_products_count' => $existingGroupProducts,
                    ]);
                } else {
                    // Новый ключ, но проверяем уникальность
                    $groupKey = $this->ensureUniqueGroupKey($requestedKey, $shopId);
                    if ($groupKey !== $requestedKey) {
                        Log::info('ProductWizardController::createGroup - Group key was changed for uniqueness', [
                            'original' => $requestedKey,
                            'new' => $groupKey,
                        ]);
                    }
                }
            } else {
                // Генерируем новый числовой ключ
                $groupKey = $this->generateNumericGroupKey($shopId);
                Log::info('ProductWizardController::createGroup - Generated new numeric group_key', [
                    'requested' => $requestedKey,
                    'generated' => $groupKey,
                ]);
            }

            // Проверяем, что все товары из одной категории
            $categoryIds = array_unique(array_column($products, 'category_id'));
            if (count($categoryIds) > 1) {
                throw new MarvelException('Все товары должны быть из одной категории', 422);
            }

            // ДЕТАЛЬНАЯ ПРОВЕРКА ТОВАРОВ
            $productIds = array_column($products, 'id');
            $existingProducts = Product::withTrashed() // Включая удаленные для проверки
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            // Проверка 1: Все товары должны существовать
            if ($existingProducts->count() !== count($productIds)) {
                $missingIds = array_diff($productIds, $existingProducts->keys()->toArray());
                throw new MarvelException(
                    'Некоторые товары не найдены: ' . implode(', ', $missingIds),
                    422
                );
            }

            // Проверка 2: Все товары должны принадлежать магазину
            $productsFromOtherShops = $existingProducts->filter(function($product) use ($shopId) {
                return $product->shop_id != $shopId;
            });

            if ($productsFromOtherShops->count() > 0) {
                $productNames = $productsFromOtherShops->pluck('name')->toArray();
                throw new MarvelException(
                    'Некоторые товары принадлежат другому магазину: ' . implode(', ', $productNames),
                    422
                );
            }

            // Проверка 3: Товары не должны быть удалены (soft delete)
            $deletedProducts = $existingProducts->filter(function($product) {
                return $product->trashed();
            });

            if ($deletedProducts->count() > 0) {
                $productNames = $deletedProducts->pluck('name')->toArray();
                throw new MarvelException(
                    'Некоторые товары были удалены: ' . implode(', ', $productNames),
                    422
                );
            }

            // Проверка 4: Товары не должны уже быть в другой группе
            // В режиме редактирования разрешаем товары с тем же group_key
            Log::info('ProductWizardController::createGroup - Checking products in other groups', [
                'group_key' => $groupKey,
                'is_edit_mode' => $isEditMode,
                'products_count' => $existingProducts->count(),
                'products_group_keys' => $existingProducts->pluck('group_key')->unique()->toArray(),
            ]);
            
            $productsInOtherGroups = $existingProducts->filter(function($product) use ($groupKey, $isEditMode) {
                // Если товар не имеет group_key - это нормально (можно добавить в группу)
                if (!$product->group_key) {
                    return false; // Товар не в группе, можно добавить
                }
                
                // Приводим к строке для корректного сравнения (group_key может быть строкой или числом)
                $productGroupKey = (string)$product->group_key;
                $currentGroupKey = (string)$groupKey;
                
                // Если товар имеет group_key и он отличается от текущего - это ошибка
                // В режиме редактирования товары с тем же group_key разрешены
                if ($productGroupKey !== $currentGroupKey) {
                    Log::info('ProductWizardController::createGroup - Product in different group detected', [
                        'product_id' => $product->id,
                        'product_group_key' => $productGroupKey,
                        'current_group_key' => $currentGroupKey,
                        'is_edit_mode' => $isEditMode,
                    ]);
                    return true; // Товар в другой группе
                }
                
                // Товар в той же группе - разрешено (особенно в режиме редактирования)
                Log::info('ProductWizardController::createGroup - Product in same group (allowed)', [
                    'product_id' => $product->id,
                    'product_group_key' => $productGroupKey,
                    'current_group_key' => $currentGroupKey,
                    'is_edit_mode' => $isEditMode,
                ]);
                return false;
            });

            if ($productsInOtherGroups->count() > 0) {
                $productNames = $productsInOtherGroups->pluck('name')->toArray();
                $groupKeys = $productsInOtherGroups->pluck('group_key')->unique()->toArray();
                
                Log::error('ProductWizardController::createGroup - Products in other groups detected', [
                    'products_count' => $productsInOtherGroups->count(),
                    'product_names' => $productNames,
                    'other_group_keys' => $groupKeys,
                    'current_group_key' => $groupKey,
                    'is_edit_mode' => $isEditMode,
                ]);
                
                throw new MarvelException(
                    'Некоторые товары уже входят в другую группу (' . implode(', ', $groupKeys) . '): ' . implode(', ', $productNames),
                    422
                );
            }

            // Проверка 6: Анализ статусов товаров (информационная, не блокирующая)
            $statusCounts = $existingProducts->groupBy('status')->map->count();
            $statuses = $statusCounts->keys()->toArray();
            
            Log::info('ProductWizardController::createGroup - Product statuses analysis', [
                'statuses' => $statuses,
                'status_counts' => $statusCounts->toArray(),
                'total_products' => $existingProducts->count(),
            ]);

            // Предупреждение (не ошибка) если есть товары с разными статусами
            if (count($statuses) > 1) {
                Log::warning('ProductWizardController::createGroup - Products have different statuses', [
                    'statuses' => $statuses,
                    'status_counts' => $statusCounts->toArray(),
                ]);
            }

            // Проверка 5: Валидация данных каждого товара
            $validationErrors = [];
            foreach ($products as $index => $productData) {
                $product = $existingProducts->get($productData['id']);
                if (!$product) {
                    continue;
                }

                $errors = [];
                
                // Проверка названия
                if (empty($productData['name']) || !is_string($productData['name'])) {
                    $errors[] = "Товар #{$index} ({$product->name}): отсутствует название";
                }

                // Проверка цены
                if (!isset($productData['price']) || !is_numeric($productData['price']) || $productData['price'] < 0) {
                    $errors[] = "Товар #{$index} ({$product->name}): неверная цена";
                }

                // Проверка категории
                if (!isset($productData['category_id']) || !is_numeric($productData['category_id'])) {
                    $errors[] = "Товар #{$index} ({$product->name}): не указана категория";
                }

                // Проверка SKU (опционально, но если есть - должен быть строкой)
                if (isset($productData['sku']) && !is_string($productData['sku'])) {
                    $errors[] = "Товар #{$index} ({$product->name}): SKU должен быть строкой";
                }

                if (!empty($errors)) {
                    $validationErrors = array_merge($validationErrors, $errors);
                }
            }

            if (!empty($validationErrors)) {
                throw new MarvelException(
                    'Ошибки валидации товаров: ' . implode('; ', $validationErrors),
                    422
                );
            }

            Log::info('ProductWizardController::createGroup - All product validations passed', [
                'products_count' => count($products),
                'group_key' => $groupKey,
            ]);

            DB::beginTransaction();

            $updatedProducts = [];

            foreach ($products as $index => $productData) {
                try {
                    // Используем уже проверенные товары из $existingProducts
                    $product = $existingProducts->get($productData['id']);
                    if (!$product) {
                        throw new Exception("Product with ID {$productData['id']} not found (should not happen after validation)");
                    }

                    // Дополнительная проверка прав доступа (уже проверено выше, но для безопасности)
                    if ($product->shop_id != $shopId) {
                        throw new Exception("Product {$product->id} does not belong to shop {$shopId} (should not happen after validation)");
                    }

                    // Проверка, что товар не удален (уже проверено выше)
                    if ($product->trashed()) {
                        throw new Exception("Product {$product->id} is deleted (should not happen after validation)");
                    }

                    // Обновляем товар: устанавливаем group_key и обновляем данные
                    // ВАЖНО: Статус товара НЕ изменяется при создании группы
                    // Каждый товар сохраняет свой статус (draft, publish, under_review и т.д.)
                    $product->group_key = $groupKey;
                    $product->category_id = $productData['category_id'];
                    $product->price = $productData['price'];
                    $product->sku = $productData['sku'] ?? $product->sku;
                    
                    // Обновляем min_price и max_price
                    $product->min_price = $productData['price'];
                    $product->max_price = $productData['price'];
                    
                    // Статус НЕ изменяем - он остается как был у товара
                    // $product->status остается без изменений
                    
                    $product->save();

                    // Обновляем категории
                    $product->categories()->sync([intval($productData['category_id'])]);

                    // Обновляем атрибуты
                    // ВАЖНО: Атрибуты должны сохраняться в таблицу product_attribute_values
                    if (isset($productData['attribute_values']) && is_array($productData['attribute_values']) && !empty($productData['attribute_values'])) {
                        Log::info('ProductWizardController::createGroup - Saving attributes for product', [
                            'product_id' => $product->id,
                            'attribute_values_count' => count($productData['attribute_values']),
                            'attribute_values' => $productData['attribute_values'],
                        ]);
                        
                        $this->updateAttributeValues($product, $productData['attribute_values']);
                        
                        // Проверяем, что атрибуты сохранились
                        $product->refresh();
                        $savedAttributes = $product->attributes()->count();
                        Log::info('ProductWizardController::createGroup - Attributes saved', [
                            'product_id' => $product->id,
                            'saved_attributes_count' => $savedAttributes,
                        ]);
                    } else {
                        Log::info('ProductWizardController::createGroup - No attributes to save for product', [
                            'product_id' => $product->id,
                            'has_attribute_values' => isset($productData['attribute_values']),
                            'is_array' => isset($productData['attribute_values']) && is_array($productData['attribute_values']),
                            'is_empty' => isset($productData['attribute_values']) && empty($productData['attribute_values']),
                        ]);
                    }

                    $updatedProducts[] = $product;
                    
                    Log::info("ProductWizardController::createGroup - Product updated", [
                        'id' => $product->id,
                        'name' => $product->name,
                        'group_key' => $product->group_key,
                    ]);
                } catch (Exception $e) {
                    Log::error("ProductWizardController::createGroup - Error updating product", [
                        'product_id' => $productData['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    throw $e; // Прерываем транзакцию при ошибке
                }
            }

            DB::commit();

            // Загружаем обновленные товары с отношениями
            $productIds = array_map(function($p) {
                return is_object($p) ? $p->id : $p['id'];
            }, $updatedProducts);

            $loadedProducts = Product::with(['type', 'shop', 'categories', 'tags', 'attributes'])
                ->whereIn('id', $productIds)
                ->orderBy('id', 'asc')
                ->get();

            Log::info('ProductWizardController::createGroup - SUCCESS', [
                'group_key' => $groupKey,
                'products_count' => count($updatedProducts),
                'is_edit_mode' => $isEditMode,
            ]);

            $message = $isEditMode
                ? "Группа товаров '{$groupName}' успешно обновлена (" . count($updatedProducts) . " товаров)"
                : "Групповой товар '{$groupName}' успешно создан из " . count($updatedProducts) . " товаров";
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $loadedProducts,
                'group_key' => $groupKey,
            ]);

        } catch (MarvelException $e) {
            DB::rollBack();
            Log::error('ProductWizardController::createGroup - MARVEL EXCEPTION', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('ProductWizardController::createGroup - FATAL ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании группы: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Генерация уникального числового group_key
     * 
     * @param int|null $shopId Опционально для логирования
     * @return string Числовой group_key
     */
    private function generateNumericGroupKey($shopId = null)
    {
        // Генерируем числовой ключ на основе timestamp и случайного числа
        // Формат: timestamp + случайное число (например: 1735312345678)
        $groupKey = (string)(time() * 1000 + mt_rand(100, 999));
        
        // Проверяем уникальность (на всякий случай, хотя вероятность коллизии очень мала)
        $maxAttempts = 10;
        $attempt = 0;
        while (Product::where('group_key', $groupKey)->exists() && $attempt < $maxAttempts) {
            $groupKey = (string)(time() * 1000 + mt_rand(100, 999));
            $attempt++;
        }
        
        // Если все еще не уникален, добавляем дополнительный случайный компонент
        if (Product::where('group_key', $groupKey)->exists()) {
            $groupKey = (string)(time() * 1000 + mt_rand(10000, 99999));
        }
        
        Log::info('ProductWizardController::generateNumericGroupKey', [
            'group_key' => $groupKey,
            'shop_id' => $shopId,
            'attempts' => $attempt,
        ]);
        
        return $groupKey;
    }

    /**
     * Проверка уникальности group_key и генерация уникального если нужно
     * Если передан не числовой ключ, генерируется новый числовой
     * 
     * @param string|null $groupKey Старый ключ (может быть null или не числовой)
     * @param int $shopId
     * @return string Числовой group_key
     */
    private function ensureUniqueGroupKey($groupKey, $shopId)
    {
        // Если ключ не передан или не числовой, генерируем новый
        if (empty($groupKey) || !is_numeric($groupKey)) {
            return $this->generateNumericGroupKey($shopId);
        }
        
        // Если ключ числовой, проверяем уникальность
        $originalKey = $groupKey;
        $counter = 1;
        
        // Проверяем уникальность group_key (глобально, не только в рамках магазина)
        while (Product::where('group_key', $groupKey)->exists()) {
            // Если ключ уже существует, генерируем новый числовой
            $groupKey = $this->generateNumericGroupKey($shopId);
            $counter++;
            
            // Защита от бесконечного цикла
            if ($counter > 10) {
                Log::warning('ProductWizardController::ensureUniqueGroupKey - Too many attempts', [
                    'original_key' => $originalKey,
                    'shop_id' => $shopId,
                ]);
                break;
            }
        }
        
        return $groupKey;
    }

    /**
     * Генерация slug для варианта
     * Упрощенная версия без timestamp (уникальность обеспечивается в цикле выше)
     */
    private function generateSlug($name, $productId = null)
    {
        // Используем Str::slug для генерации базового slug
        $baseSlug = Str::slug($name);
        
        // Если есть ID товара, добавляем его для уникальности
        if ($productId) {
            return $baseSlug . '-' . $productId;
        }
        
        // Для новых товаров возвращаем базовый slug (уникальность обеспечивается в цикле выше)
        return $baseSlug;
    }
}
