<?php

namespace Marvel\Services;

use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\Tag;
use Marvel\Database\Models\Attribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image; // for thumbnails
use Illuminate\Support\Str;
use SimpleXMLElement;

class XmlImportService
{
    protected $importStats = [
        'total' => 0,
        'imported' => 0,
        'updated' => 0,
        'errors' => 0,
        'errors_list' => [],
        'dry_run' => false,
    ];

    protected $defaultValues = [
        // НЕ используем shop_id здесь! Он ДОЛЖЕН приходить из options
        'status' => 'publish',
        'unit' => '1',
        'in_stock' => true,
        'is_taxable' => false,
        'is_digital' => false,
        'is_external' => false,
        'quantity' => 100
    ];

    protected $customFieldMapping = [];
    protected $attributeMapping = [];
    protected $logs = [];
    protected $progressCallback = null;

    public function importFromXml($xmlContent, $options = [])
    {
        try {
            // Очищаем статистику
            $this->resetStats();
            $this->importStats['dry_run'] = (bool)($options['dry_run'] ?? false);

            // Парсим XML
            $xml = new SimpleXMLElement($xmlContent);
            
            // Определяем тип XML (Yandex.Market, 1C, или универсальный)
            $xmlType = $this->detectXmlType($xml);
            
            // Настраиваем маппинг полей в зависимости от типа XML
            $fieldMapping = $this->getFieldMapping($xmlType);
            
            // Обрабатываем товары
            $this->processProducts($xml, $fieldMapping, $options);
            
            $this->importStats['logs_count'] = count($this->logs);
            return $this->importStats;
            
        } catch (\Exception $e) {
            Log::error('XML Import Error: ' . $e->getMessage());
            $this->importStats['errors']++;
            $this->importStats['errors_list'][] = 'General error: ' . $e->getMessage();
            
            return $this->importStats;
        }
    }

    protected function detectXmlType($xml)
    {
        // Проверяем различные типы XML
        if (isset($xml->shop) && isset($xml->shop->offers)) {
            return 'yandex_market';
        } elseif (isset($xml->КоммерческаяИнформация)) {
            return '1c';
        } elseif (isset($xml->products) || isset($xml->items)) {
            return 'universal';
        }
        
        return 'unknown';
    }

    protected function getFieldMapping($xmlType)
    {
        // Если установлен кастомный маппинг, используем его
        if (!empty($this->customFieldMapping)) {
            return $this->customFieldMapping;
        }

        $mappings = [
            'yandex_market' => [
                'name' => 'name',
                'description' => 'description',
                'price' => 'price',
                'currency' => 'currencyId',
                'category' => 'categoryId',
                'image' => 'picture',
                'available' => 'available',
                'vendor' => 'vendor',
                'model' => 'model',
                'sku' => 'id',
                'url' => 'url',
                'weight' => 'weight',
                'dimensions' => 'dimensions',
                'status' => 'status'
            ],
            '1c' => [
                'name' => 'Наименование',
                'description' => 'Описание',
                'price' => 'Цена',
                'currency' => 'Валюта',
                'category' => 'Группы',
                'image' => 'Картинка',
                'available' => 'Количество',
                'vendor' => 'Производитель',
                'model' => 'Модель',
                'sku' => 'Ид',
                'url' => 'Ссылка',
                'weight' => 'Вес',
                'dimensions' => 'Размеры',
                'status' => 'Статус'
            ],
            'universal' => [
                'name' => 'name',
                'description' => 'description',
                'price' => 'price',
                'currency' => 'currency',
                'category' => 'category',
                'image' => 'image',
                'available' => 'available',
                'vendor' => 'vendor',
                'model' => 'model',
                'sku' => 'sku',
                'url' => 'url',
                'weight' => 'weight',
                'dimensions' => 'dimensions',
                'status' => 'status'
            ]
        ];

        return $mappings[$xmlType] ?? $mappings['universal'];
    }

    /**
     * Импорт из CSV контента
     */
    public function importFromCsv(string $csvContent, array $options = [], array $fieldMapping = [])
    {
        try {
            $this->resetStats();
            $this->importStats['dry_run'] = (bool)($options['dry_run'] ?? false);

            $rows = $this->parseCsv($csvContent);
            if (empty($rows)) {
                return $this->importStats;
            }

            // Если нет явного маппинга, попытаемся вывести его из заголовков CSV
            $headers = array_keys($rows[0]);
            $resolvedMapping = !empty($fieldMapping) ? $fieldMapping : $this->inferMappingFromHeaders($headers);

            foreach ($rows as $rowIndex => $row) {
                try {
                    $this->importStats['total']++;
                    $productData = $this->mapCsvRowToProduct($row, $resolvedMapping);
                    // Санитизируем/генерируем SKU при необходимости
                    $rawSku = $productData['sku'] ?? '';
                    $sku = is_string($rawSku) ? trim($rawSku) : trim((string)$rawSku);
                    if ($sku === '' || strtolower($sku) === 'null' || strtolower($sku) === 'undefined') {
                        $generated = 'sku-' . ($rowIndex + 1) . '-' . substr(md5(json_encode($row)), 0, 6);
                        $productData['sku'] = $generated;
                        $this->addLog('warning', 'SKU_MISSING', 'SKU отсутствует, сгенерирован временный SKU и статус будет draft', [
                            'row' => $rowIndex + 1,
                            'sku' => $generated,
                        ]);
                        // Помечаем как draft
                        $options = array_merge($options, ['status' => 'draft']);
                    }
                    $result = $this->processProduct($productData, $options);
                    if ($result === 'imported') {
                        $this->importStats['imported']++;
                    } elseif ($result === 'updated') {
                        $this->importStats['updated']++;
                    }
                } catch (\Exception $e) {
                    $this->importStats['errors']++;
                    $this->importStats['errors_list'][] = 'CSV row error: ' . $e->getMessage();
                    Log::error('CSV product import error: ' . $e->getMessage(), $row);

                    // Безопасно извлекаем SKU для логов, даже если маппинг его не содержит
                    $loggedSku = null;
                    if (isset($resolvedMapping['sku'])) {
                        $skuColumn = $resolvedMapping['sku'];
                        if (is_string($skuColumn) && array_key_exists($skuColumn, $row)) {
                            $loggedSku = $row[$skuColumn];
                        }
                    }

                    $this->addLog('error', 'CSV_ROW_ERROR', $e->getMessage(), [
                        'row' => $rowIndex + 1,
                        'sku' => $loggedSku,
                    ]);
                }
            }

            $this->importStats['logs_count'] = count($this->logs);
            return $this->importStats;
        } catch (\Exception $e) {
            Log::error('CSV Import Error: ' . $e->getMessage());
            $this->importStats['errors']++;
            $this->importStats['errors_list'][] = 'General CSV error: ' . $e->getMessage();
            return $this->importStats;
        }
    }

    /**
     * Извлечь превью из CSV
     */
    public function extractCsvPreview(string $csvContent): array
    {
        $rows = $this->parseCsv($csvContent);
        return [
            'items' => $rows,
            'total' => count($rows),
        ];
    }

    private function parseCsv(string $csvContent): array
    {
        return CsvImportReader::parse($csvContent);
    }

    private function inferMappingFromHeaders(array $headers): array
    {
        $map = [];
        $candidates = [
            'name' => ['name', 'title', 'product_name'],
            'description' => ['description', 'desc', 'details'],
            'price' => ['price', 'cost', 'amount'],
            'sku' => ['sku', 'id', 'article', 'code'],
            'category' => ['category', 'categoryId', 'group'],
            'image' => ['image', 'picture', 'photo', 'image_url'],
            'gallery' => ['gallery', 'images'],
            'tags' => ['tags'],
            'available' => ['available', 'quantity', 'stock'],
            'vendor' => ['vendor', 'brand', 'manufacturer'],
            'model' => ['model'],
            'weight' => ['weight', 'mass'],
            'dimensions' => ['dimensions', 'size'],
            'url' => ['url', 'link', 'href'],
        ];
        foreach ($candidates as $dbField => $keys) {
            foreach ($keys as $key) {
                if (in_array($key, $headers, true)) {
                    $map[$dbField] = $key;
                    break;
                }
            }
        }
        return $map;
    }

    private function mapCsvRowToProduct(array $row, array $mapping): array
    {
        $product = [];
        foreach ($mapping as $dbField => $src) {
            if (array_key_exists($src, $row)) {
                $product[$dbField] = $row[$src];
            }
        }
        return $this->normalizeCsvProduct($product, $row);
    }

    private function normalizeCsvProduct(array $product, array $row): array
    {
        // В WooCommerce поле «Описание» обычно содержит полное описание,
        // а description — короткий анонс.
        if (!empty($row['Описание'])) {
            $product['description'] = $row['Описание'];
        }

        $product['gallery'] = $product['gallery'] ?? $row['images'] ?? $row['Images'] ?? null;
        return CsvImportReader::promoteFirstGalleryImage($product);
    }

    /**
     * Получить стандартный маппинг для указанного типа XML
     */
    public function getDefaultFieldMapping($xmlType = 'yandex_market')
    {
        $mappings = [
            'yandex_market' => [
                'name' => 'name',
                'description' => 'description',
                'price' => 'price',
                'currency' => 'currencyId',
                'category' => 'categoryId',
                'image' => 'picture',
                'available' => 'available',
                'vendor' => 'vendor',
                'model' => 'model',
                'sku' => 'id',
                'url' => 'url',
                'weight' => 'weight',
                'dimensions' => 'dimensions',
                'status' => 'status'
            ],
            '1c' => [
                'name' => 'Наименование',
                'description' => 'Описание',
                'price' => 'Цена',
                'currency' => 'Валюта',
                'category' => 'Группы',
                'image' => 'Картинка',
                'available' => 'Количество',
                'vendor' => 'Производитель',
                'model' => 'Модель',
                'sku' => 'Ид',
                'url' => 'Ссылка',
                'weight' => 'Вес',
                'dimensions' => 'Размеры',
                'status' => 'Статус'
            ],
            'universal' => [
                'name' => 'name',
                'description' => 'description',
                'price' => 'price',
                'currency' => 'currency',
                'category' => 'category',
                'image' => 'image',
                'available' => 'available',
                'vendor' => 'vendor',
                'model' => 'model',
                'sku' => 'sku',
                'url' => 'url',
                'weight' => 'weight',
                'dimensions' => 'dimensions',
                'status' => 'status'
            ]
        ];

        return $mappings[$xmlType] ?? $mappings['universal'];
    }

    /**
     * Устанавливает маппинг характеристик: param name => имя атрибута в системе
     *
     * Пример:
     * [
     *   "Гарантийный срок" => "Гарантийный срок",
     *   "Материал" => "Материал полотна",
     * ]
     */
    public function setAttributeMapping(array $mapping): void
    {
        $normalized = [];
        foreach ($mapping as $paramName => $attributeName) {
            if (is_array($attributeName) && isset($attributeName['attribute_name'], $attributeName['param_name'])) {
                // Поддержка формата [{ attribute_name, param_name }, ...] на всякий случай
                $param = trim((string)$attributeName['param_name']);
                $attr = trim((string)$attributeName['attribute_name']);
            } else {
                $param = trim((string)$paramName);
                $attr = trim((string)$attributeName);
            }
            if ($param === '' || $attr === '') {
                continue;
            }
            $normalized[$param] = $attr;
        }
        $this->attributeMapping = $normalized;
    }

    protected function processProducts($xml, $fieldMapping, $options)
    {
        $products = $this->extractProducts($xml, $fieldMapping);
        $this->importStats['total'] = count($products);
        
        foreach ($products as $index => $productData) {
            try {
                $this->importStats['total']++;
                
                // Обрабатываем товар
                $result = $this->processProduct($productData, $options);
                
                if ($result === 'imported') {
                    $this->importStats['imported']++;
                } elseif ($result === 'updated') {
                    $this->importStats['updated']++;
                }
                
            } catch (\Exception $e) {
                $this->importStats['errors']++;
                $sku = isset($productData['sku']) ? $productData['sku'] : 'unknown';
                $this->importStats['errors_list'][] = 'Product ' . $sku . ': ' . $e->getMessage();
                Log::error('Product import error: ' . $e->getMessage(), $productData);
                $this->addLog('error', 'PRODUCT_ERROR', $e->getMessage(), [
                    'sku' => $sku,
                    'index' => $index,
                ]);
            }

            // Отправляем прогресс наружу, если задан колбек
            if (is_callable($this->progressCallback)) {
                try {
                    call_user_func($this->progressCallback, $this->importStats);
                } catch (\Throwable $t) {
                    // не прерываем импорт из-за ошибок обратного вызова
                }
            }
        }
    }

    protected function extractProducts($xml, $fieldMapping)
    {
        $products = [];
        
        if (isset($xml->shop->offers->offer)) {
            // Yandex.Market формат
            foreach ($xml->shop->offers->offer as $offer) {
                $products[] = $this->extractYandexProduct($offer, $fieldMapping);
            }
        } elseif (isset($xml->КоммерческаяИнформация->Товары->Товар)) {
            // 1C формат
            foreach ($xml->КоммерческаяИнформация->Товары->Товар as $товар) {
                $products[] = $this->extract1cProduct($товар, $fieldMapping);
            }
        } elseif (isset($xml->products->product) || isset($xml->items->item)) {
            // Универсальный формат
            $productNodes = $xml->products->product ?? $xml->items->item;
            foreach ($productNodes as $product) {
                $products[] = $this->extractUniversalProduct($product, $fieldMapping);
            }
        }
        
        return $products;
    }

    protected function extractYandexProduct($offer, $fieldMapping)
    {
        $product = [];
        
        foreach ($fieldMapping as $dbField => $xmlField) {
            if (isset($offer->{$xmlField})) {
                $product[$dbField] = (string)$offer->{$xmlField};
            }
        }

        // Специальная обработка картинок Yandex.Market:
        // <picture> может встречаться несколько раз, формируем из них галерею.
        if (isset($offer->picture)) {
            $pictures = [];
            foreach ($offer->picture as $picNode) {
                $url = trim((string)$picNode);
                if ($url !== '') {
                    $pictures[] = $url;
                }
            }

            if (!empty($pictures)) {
                // Первое изображение используем как основное
                $product['image'] = $pictures[0];
                // В галерею складываем все изображения
                $product['gallery'] = $pictures;
            }
        }

        // Дополнительная обработка для Yandex.Market
        if (isset($offer->param)) {
            $product['attributes'] = [];
            foreach ($offer->param as $param) {
                $paramName = (string)$param['name'];
                $paramValue = (string)$param;

                // Сохраняем все исходные характеристики как есть
                $product['attributes'][] = [
                    'name' => $paramName,
                    'value' => $paramValue,
                ];

                // Если настроен маппинг характеристик, пробрасываем значения в attribute_values
                if (!empty($this->attributeMapping) && isset($this->attributeMapping[$paramName])) {
                    $targetAttributeName = $this->attributeMapping[$paramName];
                    if (!empty($targetAttributeName)) {
                        if (!isset($product['attribute_values']) || !is_array($product['attribute_values'])) {
                            $product['attribute_values'] = [];
                        }
                        // Ключом может быть имя или slug атрибута, дальше resolve делается в updateProductAttributeValues
                        $product['attribute_values'][$targetAttributeName] = $paramValue;
                    }
                }
            }
        }
        
        // Обработка доступности товара
        if (isset($offer['available'])) {
            $product['available'] = (string)$offer['available'] === 'true';
        }
        
        // Обработка веса (если есть)
        if (isset($offer->weight)) {
            $product['weight'] = (float)$offer->weight;
        }
        
        // Обработка размеров (если есть)
        if (isset($offer->dimensions)) {
            $product['dimensions'] = (string)$offer->dimensions;
        }
        
        return $product;
    }

    protected function extract1cProduct($товар, $fieldMapping)
    {
        $product = [];
        
        foreach ($fieldMapping as $dbField => $xmlField) {
            if (isset($товар->{$xmlField})) {
                $product[$dbField] = (string)$товар->{$xmlField};
            }
        }
        
        return $product;
    }

    protected function extractUniversalProduct($product, $fieldMapping)
    {
        $productData = [];
        
        foreach ($fieldMapping as $dbField => $xmlField) {
            if (isset($product->{$xmlField})) {
                $productData[$dbField] = (string)$product->{$xmlField};
            }
        }
        
        return $productData;
    }

    public function processProduct($productData, $options)
    {
        // Проверяем обязательные поля (минимальный набор для нашего API)
        $missing = [];
        // name, sku обязательны; price может отсутствовать (уйдёт в draft)
        // Нормализуем значения
        if (isset($productData['name'])) $productData['name'] = trim((string)$productData['name']);
        if (isset($productData['sku'])) $productData['sku'] = trim((string)$productData['sku']);
        foreach (['name', 'sku'] as $field) {
            if (!isset($productData[$field]) || trim((string)$productData[$field]) === '' || $productData[$field] === null) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            // Если не хватает только SKU — генерируем временный, переводим в draft и продолжаем
            $onlySkuMissing = count($missing) === 1 && in_array('sku', $missing, true);
            if ($onlySkuMissing) {
                $generated = 'sku-' . substr(md5(json_encode($productData) . microtime(true)), 0, 10);
                $productData['sku'] = $generated;
                $this->addLog('warning', 'SKU_GENERATED', 'SKU отсутствовал и был сгенерирован временно', [
                    'sku' => $generated,
                ]);
                // Принудительно ставим draft при проблеме со SKU
                $options['status'] = 'draft';
            } else {
                throw new \Exception('Missing required fields: ' . implode(', ', array_unique($missing)));
            }
        }

        // Подготавливаем данные для сохранения
        $productFields = $this->prepareProductFields($productData, $options);

        $dryRun = (bool)($options['dry_run'] ?? false);
        
        // Проверяем, существует ли товар
        $existingProduct = Product::where('sku', $productData['sku'])->first();
        
        if ($existingProduct) {
            // Обновляем существующий товар
            if (!$dryRun) {
                \Log::warning('UPDATING EXISTING PRODUCT - SHOP_ID CHECK', [
                    'sku' => $existingProduct->sku,
                    'old_shop_id' => $existingProduct->shop_id,
                    'new_shop_id' => $productFields['shop_id'] ?? 'NOT SET',
                    'productFields' => $productFields
                ]);
                $existingProduct->update($productFields);
                \Log::warning('AFTER UPDATE - SHOP_ID CHECK', [
                    'sku' => $existingProduct->sku,
                    'shop_id_after_update' => $existingProduct->shop_id
                ]);
                $this->addLog('info', 'PRODUCT_UPDATED', 'Товар обновлен', [
                    'id' => (int)$existingProduct->id,
                    'sku' => $existingProduct->sku,
                    'old_shop_id' => $existingProduct->shop_id,
                    'new_shop_id' => $productFields['shop_id'] ?? 'NOT SET',
                ]);
            }
            
            // Обновляем связи
            if (!$dryRun) {
                $this->updateProductRelations($existingProduct, $productData, $options);
            }
            
            return 'updated';
                    } else {
                // Создаем новый товар
                if ($dryRun) {
                    $product = (object)array_merge(['id' => 0], $productFields);
                } else {
                    \Log::info('Creating product with fields:', [
                        'sku' => $productFields['sku'],
                        'name' => $productFields['name'],
                        'image_in_fields' => $productFields['image'] ?? 'not set',
                        'shop_id' => $productFields['shop_id'] ?? 'not set'
                    ]);
                    
                    $product = Product::create($productFields);
                    $this->addLog('info', 'PRODUCT_CREATED', 'Товар создан', [
                        'id' => (int)$product->id,
                        'sku' => $product->sku,
                    ]);
                    
                    // Отладочная информация для изображений
                    \Log::info('Product Import - Image data saved:', [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'shop_id' => $product->shop_id,
                        'image_before_reload' => $product->image,
                        'gallery_before_reload' => $product->gallery,
                        'image_type' => gettype($product->image),
                        'gallery_type' => gettype($product->gallery)
                    ]);
                    
                    // Перезагружаем товар чтобы получить актуальные данные из базы
                    $product->refresh();
                    
                    \Log::info('Product Import - Image data after reload:', [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'image_after_reload' => $product->image,
                        'gallery_after_reload' => $product->gallery
                    ]);
                }
            
            // Создаем связи
            if (!$dryRun) {
                $this->createProductRelations($product, $productData, $options);
            }
            
            return 'imported';
        }
    }

    protected function prepareProductFields($productData, $options)
    {
        // Логируем что приходит в options
        \Log::info('XmlImportService - prepareProductFields options:', [
            'shop_id_in_options' => $options['shop_id'] ?? 'not set',
            'shop_id_type' => isset($options['shop_id']) ? gettype($options['shop_id']) : 'not set',
            'all_options' => $options
        ]);
        
        $fields = array_merge($this->defaultValues, $options);
        
        // Убираем product_type из options, так как используем type_id
        unset($fields['product_type']);
        
        // shop_id ОБЯЗАТЕЛЕН - если не пришел, выбрасываем ошибку
        if (!isset($fields['shop_id']) || empty($fields['shop_id'])) {
            \Log::error('XmlImportService - shop_id is missing!', [
                'sku' => $productData['sku'] ?? 'unknown',
                'options' => $options,
                'fields' => $fields
            ]);
            throw new \Exception('shop_id is required for product import. Please select a shop in import settings.');
        }
        
        // ПРИНУДИТЕЛЬНО проверяем что shop_id это число
        $fields['shop_id'] = (int) $fields['shop_id'];
        if ($fields['shop_id'] <= 0) {
            \Log::error('XmlImportService - Invalid shop_id!', [
                'shop_id' => $fields['shop_id'],
                'sku' => $productData['sku'] ?? 'unknown',
                'options' => $options
            ]);
            throw new \Exception('Invalid shop_id. Please select a valid shop.');
        }
        
        \Log::info('XmlImportService - shop_id in fields:', [
            'shop_id' => $fields['shop_id'],
            'sku' => $productData['sku'] ?? 'unknown'
        ]);
        
        // Основные поля
        $fields['name'] = $productData['name'];
        $fields['slug'] = Str::slug($productData['name']);
        $fields['sku'] = $productData['sku'];
        // Определяем type_id гибко
        $fields['type_id'] = $this->resolveTypeId($options, $productData);
        // Язык записи: всегда RU независимо от входных параметров
        $fields['language'] = 'ru';
        
        // Описание
        if (!empty($productData['description'])) {
            $fields['description'] = $productData['description'];
        }
        
        // Цена
        if (!empty($productData['price'])) {
            $price = $this->parsePrice($productData['price']);
            $fields['price'] = $price;
            $fields['min_price'] = $price;
            $fields['max_price'] = $price;
        }
        
        // Количество
        if (!empty($productData['available'])) {
            $fields['quantity'] = (int)$productData['available'];
            $fields['in_stock'] = $fields['quantity'] > 0;
        }
        
        // Изображения
        if (!empty($productData['image'])) {
            try {
                \Log::info('Starting image processing:', [
                    'sku' => $productData['sku'] ?? 'unknown',
                    'image_data' => $productData['image'],
                    'image_type' => gettype($productData['image']),
                    'download_images' => $options['download_images'] ?? 'not set'
                ]);
                
                $imageData = $this->processImages($productData['image'], $options);
                
                \Log::info('processImages returned:', [
                    'sku' => $productData['sku'] ?? 'unknown',
                    'imageData' => $imageData,
                    'imageData_type' => gettype($imageData),
                    'is_array' => is_array($imageData),
                    'is_empty' => empty($imageData)
                ]);
                
                // processImages всегда возвращает массив для одиночного изображения
                if (is_array($imageData) && !empty($imageData)) {
                    $fields['image'] = $imageData[0];
                    \Log::info('Image set to fields:', [
                        'sku' => $productData['sku'] ?? 'unknown',
                        'image_field' => $fields['image']
                    ]);
                } else {
                    // Если массив пустой или null, не устанавливаем изображение
                    $fields['image'] = null;
                    \Log::warning('Image data is empty or not array:', [
                        'sku' => $productData['sku'] ?? 'unknown',
                        'imageData' => $imageData
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('Image processing exception:', [
                    'sku' => $productData['sku'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->addLog('warning', 'IMAGE_PROCESSING_ERROR', 'Error processing image: ' . $e->getMessage(), [
                    'sku' => $productData['sku'] ?? 'unknown',
                    'image_data' => $productData['image']
                ]);
            }
        } else {
            \Log::info('No image data in product:', [
                'sku' => $productData['sku'] ?? 'unknown',
                'has_image_key' => isset($productData['image']),
                'image_value' => $productData['image'] ?? 'not set'
            ]);
        }
        
        // Галерея изображений
        if (!empty($productData['gallery'])) {
            $gallery = $productData['gallery'];
            $parts = [];
            
            if (is_string($gallery)) {
                // Пытаемся декодировать как JSON
                $decoded = json_decode($gallery, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $parts = $decoded;
                } else {
                    // Если не JSON, разбиваем по разделителям
                    $parts = preg_split('/[;,|\n]+/', $gallery);
                    $parts = array_values(array_filter(array_map('trim', $parts)));
                }
            } elseif (is_array($gallery)) {
                $parts = $gallery;
            }
            
            if (!empty($parts)) {
                // Скачиваем в сторедж при необходимости
                $stored = [];
                foreach ($parts as $url) {
                    if (empty($url)) continue;
                    [$origUrl, $thumbUrl] = $this->storeRemoteWithThumb((string)$url, $options);
                    $stored[] = [
                        'id' => uniqid('gallery_', true),
                        'original' => $origUrl ?? $url,
                        'thumbnail' => $thumbUrl ?? ($origUrl ?? $url),
                    ];
                }
                $fields['gallery'] = $stored;
                if (count($parts) > 20) {
                    $this->addLog('warning', 'GALLERY_TRUNCATED', 'Gallery contains many items', [
                        'count' => count($parts),
                    ]);
                }
            }
        }

        // Теги
        if (!empty($productData['tags'])) {
            $fields['tags_data'] = $this->processTags($productData['tags']);
        }

        // Дополнительные поля
        if (!empty($productData['vendor'])) {
            $fields['manufacturer_id'] = $this->getOrCreateManufacturer($productData['vendor']);
        }
        
        // Вес товара
        if (!empty($productData['weight'])) {
            $fields['weight'] = (float)$productData['weight'];
        }
        
        // Размеры товара
        if (!empty($productData['dimensions'])) {
            $fields['dimensions'] = $productData['dimensions'];
        }
        
        // Превью/внешняя ссылка из XML: сохраняем как preview_url и НЕ переключаем товар в внешний
        if (!empty($productData['url'])) {
            $fields['preview_url'] = $productData['url'];
            // Никогда не выставляем is_external из импорта URL, чтобы не ломать кнопку "КУПИТЬ"
            // Товар остается обычным (не внешним), но сохраняет ссылку на внешний источник для справки
            // $fields['is_external'] = false; // явно не устанавливаем, используется дефолт из defaultValues
        }

        // Тип товара: если есть атрибуты — variable (используем type_id вместо product_type)
        if (!empty($productData['attributes']) && is_array($productData['attributes']) && count($productData['attributes']) > 0) {
            // Для variable товаров ищем соответствующий type_id
            $variableTypeId = Type::where('slug', 'variable')->value('id');
            if ($variableTypeId) {
                $fields['type_id'] = $variableTypeId;
            }
        }

        // Статус товара: определяем один раз в конце, учитывая все условия
        if (!empty($productData['status'])) {
            $status = strtolower(trim($productData['status']));
            // Маппинг статусов из XML в статусы системы
            $statusMap = [
                'published' => 'publish',
                'publish' => 'publish',
                'draft' => 'draft',
                'under_review' => 'under_review',
                'approved' => 'approved',
                'rejected' => 'rejected',
                'unpublish' => 'unpublish',
                'unpublished' => 'unpublish',
            ];
            $fields['status'] = $statusMap[$status] ?? 'draft';
        }
        
        // Правило статуса: если нет цены или нет категории → всегда draft
        $hasPrice = isset($fields['price']) && $fields['price'] !== null && $fields['price'] !== '';
        $hasCategoryOption = !empty($options['category_id']);
        $hasCategoryData = !empty($productData['category']);
        if (!$hasPrice || (!$hasCategoryOption && !$hasCategoryData)) {
            $fields['status'] = 'draft';
            if (!$hasPrice) {
                $this->addLog('warning', 'MISSING_PRICE', 'Price is missing, set status draft', []);
            }
            if (!$hasCategoryOption && !$hasCategoryData) {
                $this->addLog('warning', 'MISSING_CATEGORY', 'Category is missing, set status draft', []);
            }
        }
        
        return $fields;
    }

    private function resolveTypeId(array $options, array $productData): int
    {
        // 1) Явно из options
        if (isset($options['type_id']) && is_numeric($options['type_id'])) {
            return (int)$options['type_id'];
        }
        // 2) Из категории (options.category_id)
        if (isset($options['category_id']) && is_numeric($options['category_id'])) {
            $cat = Category::find((int)$options['category_id']);
            if ($cat && isset($cat->type_id)) return (int)$cat->type_id;
        }
        // 3) Из productData['type'] как id или slug
        if (!empty($productData['type'])) {
            $val = $productData['type'];
            if (is_numeric($val)) return (int)$val;
            $slug = Str::slug((string)$val);
            $id = Type::where('slug', $slug)->value('id');
            if ($id) return (int)$id;
        }
        // 4) По умолчанию используем тип со slug 'element', если есть
        $elementId = Type::where('slug', 'element')->value('id');
        if ($elementId) return (int)$elementId;
        // 5) Любой доступный тип
        $any = Type::orderBy('id')->value('id');
        if ($any) return (int)$any;
        // 6) Если совсем нет типов — ошибка
        throw new \Exception('No product types found to assign type_id');
    }

    protected function parsePrice($price)
    {
        // Убираем все нечисловые символы, кроме точки и запятой
        $cleanPrice = preg_replace('/[^0-9.,]/', '', $price);
        
        // Заменяем запятую на точку
        $cleanPrice = str_replace(',', '.', $cleanPrice);
        
        return (float)$cleanPrice;
    }

    protected function processImages($imageData, array $options = [])
    {
        $download = (bool)($options['download_images'] ?? true);
        
        // Проверяем, что imageData не пустой
        if (empty($imageData)) {
            return null;
        }
        
        if (is_array($imageData)) {
            $images = [];
            foreach ($imageData as $img) {
                if (empty($img)) continue; // Пропускаем пустые элементы
                
                $attachment = $this->createAttachmentFromUrl((string)$img, $download, $options);
                if ($attachment) {
                    $images[] = $attachment;
                }
            }
            return empty($images) ? null : $images;
        } else {
            $attachment = $this->createAttachmentFromUrl((string)$imageData, $download, $options);
            return $attachment ? [$attachment] : null;
        }
    }

    /**
     * Создает Attachment из URL изображения, сохраняя напрямую в S3
     */
    public function createAttachmentFromUrl(string $imageUrl, bool $download, array $options = [])
    {
        try {
            \Log::info('Creating attachment from URL:', [
                'url' => $imageUrl,
                'download' => $download
            ]);

            // Создаем новый Attachment
            $attachment = new \Marvel\Database\Models\Attachment();
            $attachment->save();

            \Log::info('Attachment created with ID:', ['attachment_id' => $attachment->id]);

            if ($download) {
                // Скачиваем изображение и сохраняем через medialibrary
                $tempFile = $this->downloadImageToTemp($imageUrl);
                if ($tempFile) {
                    \Log::info('Image downloaded to temp file:', ['temp_file' => $tempFile]);
                    $attachment->addMedia($tempFile)
                        ->toMediaCollection();
                    
                    // Получаем URL'ы как в AttachmentController
                    foreach ($attachment->getMedia() as $media) {
                        if (strpos($media->mime_type, 'image/') !== false) {
                            $result = [
                                'thumbnail' => $media->getUrl('thumbnail'),
                                'original' => $media->getUrl(),
                                'id' => $attachment->id
                            ];
                            \Log::info('Attachment created successfully with media:', $result);
                            return $result;
                        } else {
                            $result = [
                                'thumbnail' => '',
                                'original' => $media->getUrl(),
                                'id' => $attachment->id
                            ];
                            \Log::info('Attachment created successfully (non-image):', $result);
                            return $result;
                        }
                    }
                } else {
                    \Log::warning('Failed to download image, using URL as is');
                }
            } else {
                // Если не скачиваем, создаем Attachment с внешней ссылкой
                $result = [
                    'thumbnail' => $imageUrl,
                    'original' => $imageUrl,
                    'id' => $attachment->id
                ];
                \Log::info('Attachment created with external URL:', $result);
                return $result;
            }
        } catch (\Exception $e) {
            \Log::error('Failed to create attachment from URL: ' . $e->getMessage(), [
                'url' => $imageUrl,
                'download' => $download,
                'exception' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Создает thumbnail для изображения
     */
    protected function createThumbnail(string $imagePath, $attachment)
    {
        try {
            // Создаем thumbnail с помощью Intervention Image
            $img = \Intervention\Image\Facades\Image::make($imagePath);
            $img->fit(368, 232, function ($constraint) { 
                $constraint->upsize(); 
            });
            
            // Сохраняем thumbnail во временный файл
            $tempThumbnail = tempnam(sys_get_temp_dir(), 'thumb_');
            $img->save($tempThumbnail, 85, 'webp');
            
            // Добавляем thumbnail как медиа
            $thumbnailMedia = $attachment->addMedia($tempThumbnail)
                ->usingFileName('thumb_' . uniqid() . '.webp')
                ->toMediaCollection('thumbnails');
            
            // Удаляем временный файл
            if (file_exists($tempThumbnail)) {
                unlink($tempThumbnail);
            }
            
            return $thumbnailMedia->getUrl();
        } catch (\Exception $e) {
            \Log::warning('Failed to create thumbnail: ' . $e->getMessage());
            // Возвращаем URL оригинального изображения как fallback
            return null;
        }
    }

    /**
     * Скачивает изображение во временный файл
     */
    protected function downloadImageToTemp(string $url)
    {
        try {
            // Используем временную директорию Laravel вместо системной
            $tempFile = storage_path('app/temp/') . uniqid('xml_import_');
            
            // Создаем директорию если её нет
            if (!is_dir(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0777, true);
            }
            
            // Используем cURL вместо file_get_contents для лучшей обработки ошибок
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($imageData === false || $httpCode !== 200) {
                \Log::warning('Failed to download image', [
                    'url' => $url,
                    'http_code' => $httpCode
                ]);
                return null;
            }
            
            file_put_contents($tempFile, $imageData);
            
            // Определяем расширение файла
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (empty($extension)) {
                // Определяем тип файла по содержимому
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tempFile);
                finfo_close($finfo);
                
                switch ($mimeType) {
                    case 'image/jpeg':
                        $extension = 'jpg';
                        break;
                    case 'image/png':
                        $extension = 'png';
                        break;
                    case 'image/webp':
                        $extension = 'webp';
                        break;
                    default:
                        $extension = 'jpg';
                }
            }
            
            $tempFileWithExt = $tempFile . '.' . $extension;
            rename($tempFile, $tempFileWithExt);
            
            // Проверяем что файл действительно является изображением
            if (!getimagesize($tempFileWithExt)) {
                unlink($tempFileWithExt);
                \Log::warning('Downloaded file is not an image', ['url' => $url]);
                return null;
            }
            
            return new \Illuminate\Http\UploadedFile(
                $tempFileWithExt,
                basename($url),
                mime_content_type($tempFileWithExt),
                null,
                true
            );
        } catch (\Exception $e) {
            \Log::warning('Failed to download image: ' . $e->getMessage(), [
                'url' => $url,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Загрузить удаленное изображение в S3 и вернуть [originalUrl, thumbUrl]
     */
    private function storeRemoteWithThumb(string $url, array $options): array
    {
        try {
            if ($url === '' || stripos($url, 'http') !== 0) return [null, null];
            
            // Скачиваем изображение во временный файл
            $tempFile = $this->downloadImageToTemp($url);
            if (!$tempFile) {
                return [null, null];
            }

            // Создаем Attachment через MediaLibrary
            $attachment = new \Marvel\Database\Models\Attachment();
            $attachment->save();

            // Добавляем медиа через MediaLibrary (будет сохранено в S3)
            $attachment->addMedia($tempFile)
                ->toMediaCollection();
            
            // Получаем URL'ы как в AttachmentController
            foreach ($attachment->getMedia() as $media) {
                if (strpos($media->mime_type, 'image/') !== false) {
                    return [
                        $media->getUrl(),
                        $media->getUrl('thumbnail')
                    ];
                } else {
                    return [
                        $media->getUrl(),
                        ''
                    ];
                }
            }

            return [null, null];
        } catch (\Throwable $e) {
            Log::warning('Image store failed: ' . $e->getMessage());
            return [null, null];
        }
    }

    protected function getOrCreateManufacturer($name)
    {
        // Здесь можно добавить логику создания/получения производителя
        // Пока возвращаем null
        return null;
    }

    protected function updateProductRelations($product, $productData, $options = [])
    {
        // Категории
        $this->attachCategories($product, $productData['category'] ?? null, $options);
        
        // Теги
        if (!empty($productData['tags'])) {
            $this->attachTags($product, $productData['tags']);
        }
        
        // Обновляем атрибуты (старый формат из XML - для обратной совместимости)
        if (!empty($productData['attributes'])) {
            $this->updateProductAttributes($product, $productData['attributes']);
        }
        
        // Обновляем значения атрибутов (новый формат через attribute_values)
        if (!empty($productData['attribute_values']) && is_array($productData['attribute_values'])) {
            $this->updateProductAttributeValues($product, $productData['attribute_values']);
        }
    }

    protected function createProductRelations($product, $productData, $options = [])
    {
        // Категории
        $this->attachCategories($product, $productData['category'] ?? null, $options);
        
        // Теги
        if (!empty($productData['tags'])) {
            $this->attachTags($product, $productData['tags']);
        }
        
        // Создаем связи с атрибутами (старый формат из XML - для обратной совместимости)
        if (!empty($productData['attributes'])) {
            $this->createProductAttributes($product, $productData['attributes']);
        }
        
        // Создаем значения атрибутов (новый формат через attribute_values)
        if (!empty($productData['attribute_values']) && is_array($productData['attribute_values'])) {
            $this->updateProductAttributeValues($product, $productData['attribute_values']);
        }
    }

    protected function attachCategories($product, $categoryData, $options)
    {
        try {
            $categoryIds = [];
            if (!empty($options['category_id'])) {
                $categoryIds[] = (int)$options['category_id'];
            }
            if (!empty($categoryData)) {
                $candidates = is_array($categoryData) ? $categoryData : [$categoryData];
                foreach ($candidates as $cand) {
                    if (is_numeric($cand)) {
                        $cat = Category::find((int)$cand);
                        if ($cat) $categoryIds[] = (int)$cat->id;
                        continue;
                    }
                    $slug = Str::slug((string)$cand);
                    $cat = Category::where('slug', $slug)->first();
                    if (!$cat) {
                        $cat = Category::where('name', (string)$cand)->first();
                    }
                    if ($cat) $categoryIds[] = (int)$cat->id;
                }
            }
            $categoryIds = array_values(array_unique(array_filter($categoryIds)));
            if (!empty($categoryIds) && method_exists($product, 'categories')) {
                // Используем только первую категорию (теперь товар может иметь только одну категорию)
                $firstCategoryId = $categoryIds[0];
                $product->categories()->sync([$firstCategoryId]);
            }
        } catch (\Throwable $e) {
            Log::warning('Category attach warning: ' . $e->getMessage());
        }
    }

    protected function updateProductAttributes($product, $attributes)
    {
        $this->syncAttributes($product, $attributes);
    }

    protected function createProductAttributes($product, $attributes)
    {
        $this->syncAttributes($product, $attributes);
    }

    protected function syncAttributes($product, $attributes)
    {
        try {
            if (empty($attributes) || !method_exists($product, 'attributes')) return;
            $attachIds = [];
            foreach ($attributes as $item) {
                $name = trim((string)($item['name'] ?? ''));
                $value = trim((string)($item['value'] ?? ''));
                if ($name === '' || $value === '') continue;
                $attr = Attribute::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
                $attachIds[] = (int)$attr->id;
            }
            if (!empty($attachIds)) {
                $product->attributes()->syncWithoutDetaching($attachIds);
            }
        } catch (\Throwable $e) {
            Log::warning('Attributes sync warning: ' . $e->getMessage());
        }
    }

    /**
     * Обновляет значения атрибутов товара (новый формат через product_attribute_values)
     * 
     * @param Product $product
     * @param array $attributeValues Массив вида [attributeId => value, ...]
     */
    protected function updateProductAttributeValues($product, $attributeValues)
    {
        try {
            if (empty($attributeValues) || !method_exists($product, 'attributes')) {
                return;
            }

            $attributeValuesData = [];
            foreach ($attributeValues as $attributeId => $value) {
                // Пропускаем пустые значения
                if (empty($value) && $value !== '0' && $value !== 0) {
                    continue;
                }

                // Преобразуем значение в строку, если это массив (для multiselect)
                $finalValue = is_array($value) ? implode(',', $value) : (string)$value;

                // Если значение - это объект, извлекаем value
                if (is_array($value) && isset($value['value'])) {
                    $finalValue = is_array($value['value']) ? implode(',', $value['value']) : (string)$value['value'];
                    $attributeValueId = $value['attribute_value_id'] ?? null;
                } else {
                    $attributeValueId = null;
                }

                // Проверяем, что атрибут существует
                $attrId = is_numeric($attributeId) ? (int)$attributeId : null;
                if (!$attrId) {
                    // Если передан slug или name, пытаемся найти или создать атрибут
                    $nameOrSlug = (string)$attributeId;
                    $slug = Str::slug($nameOrSlug);
                    $attr = Attribute::where('slug', $slug)
                        ->orWhere('name', $nameOrSlug)
                        ->first();
                    if (!$attr) {
                        $attr = Attribute::firstOrCreate(
                            ['slug' => $slug],
                            ['name' => $nameOrSlug]
                        );
                    }
                    $attrId = $attr->id;
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
        } catch (\Throwable $e) {
            Log::warning('Attribute values sync warning: ' . $e->getMessage());
        }
    }

    protected function resetStats()
    {
        $this->importStats = [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'errors_list' => [],
            'dry_run' => false,
        ];
        $this->logs = [];
    }

    public function getImportStats()
    {
        return $this->importStats;
    }

    public function setCustomFieldMapping($mapping)
    {
        $this->customFieldMapping = $mapping;
    }

    public function addLog(string $level, string $code, string $message, array $context = []): void
    {
        $this->logs[] = [
            'ts' => date('c'),
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Установить колбек, который будет вызван после обработки каждого товара.
     */
    public function setProgressCallback($callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Обработка тегов из CSV
     */
    protected function processTags($tagsData)
    {
        $tags = [];
        
        if (is_string($tagsData)) {
            // Пытаемся декодировать как JSON
            $decoded = json_decode($tagsData, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $tags = $decoded;
            } else {
                // Если не JSON, разбиваем по разделителям
                $tags = preg_split('/[;,|\n]+/', $tagsData);
                $tags = array_values(array_filter(array_map('trim', $tags)));
            }
        } elseif (is_array($tagsData)) {
            $tags = $tagsData;
        }
        
        // Очищаем теги от лишних символов
        $cleanedTags = [];
        foreach ($tags as $tag) {
            if (empty($tag)) continue;
            
            // Убираем лишние слэши и кавычки
            $cleanTag = trim($tag, ' "\'');
            $cleanTag = preg_replace('/[\/\\\]+/', ' ', $cleanTag);
            $cleanTag = preg_replace('/\s+/', ' ', $cleanTag);
            $cleanTag = trim($cleanTag);
            
            if (!empty($cleanTag)) {
                $cleanedTags[] = $cleanTag;
            }
        }
        
        return array_unique($cleanedTags);
    }

    /**
     * Прикрепление тегов к товару
     */
    protected function attachTags($product, $tagsData)
    {
        try {
            $tags = $this->processTags($tagsData);
            if (empty($tags)) return;
            
            $tagIds = [];
            foreach ($tags as $tagName) {
                $tag = Tag::firstOrCreate(
                    ['name' => $tagName, 'language' => 'ru'],
                    [
                        'slug' => Str::slug($tagName),
                        'language' => 'ru',
                        'type_id' => $product->type_id ?? 1
                    ]
                );
                $tagIds[] = $tag->id;
            }
            
            if (!empty($tagIds) && method_exists($product, 'tags')) {
                $product->tags()->syncWithoutDetaching($tagIds);
            }
        } catch (\Throwable $e) {
            Log::warning('Tags attach warning: ' . $e->getMessage());
        }
    }
}






