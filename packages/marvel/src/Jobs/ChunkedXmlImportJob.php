<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Marvel\Services\XmlImportService;
use Illuminate\Support\Facades\Storage;

class ChunkedXmlImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath;  // Изменено: путь к файлу вместо контента
    protected string $ext;
    protected array $options;
    protected array $fieldMapping;
    protected string $token;
    protected int $chunkIndex;
    protected int $chunkSize;
    protected int $totalChunks;

    public $timeout = 600; // 10 минут на чанк (для изображений)
    public $tries = 1; // Только 1 попытка (если упало - значит проблема серьёзная)
    public $failOnTimeout = true; // Помечать как failed при timeout

    public function __construct(
        string $filePath,  // Изменено: путь к файлу вместо контента
        string $ext, 
        array $options, 
        array $fieldMapping, 
        string $token,
        int $chunkIndex,
        int $chunkSize,
        int $totalChunks
    ) {
        $this->filePath = $filePath;  // Изменено
        $this->ext = $ext;
        $this->options = $options;
        $this->fieldMapping = $fieldMapping;
        $this->token = $token;
        $this->chunkIndex = $chunkIndex;
        $this->chunkSize = $chunkSize;
        $this->totalChunks = $totalChunks;
    }

    public function handle(): void
    {
        // Увеличиваем лимит памяти для обработки изображений
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', 600); // Увеличено до 10 минут
        
        try {
            Log::info("🚀 Starting chunked import", [
                'token' => $this->token,
                'chunk' => $this->chunkIndex,
                'total_chunks' => $this->totalChunks,
                'chunk_size' => $this->chunkSize,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ]);

            // Обновляем прогресс - начало обработки чанка
            $this->updateProgress('processing', $this->chunkIndex, $this->totalChunks);
            
            Log::info("📋 File check", [
                'file_exists' => file_exists($this->filePath),
                'file_path' => $this->filePath,
                'ext' => $this->ext
            ]);

            $service = new XmlImportService();
            if (!empty($this->fieldMapping)) {
                $service->setCustomFieldMapping($this->fieldMapping);
            }

            Log::info("🔄 Starting chunk processing", [
                'chunk' => $this->chunkIndex,
                'ext' => $this->ext
            ]);

            $result = null;
            if (in_array($this->ext, ['csv'])) {
                $result = $this->processCsvChunk($service);
            } else {
                $result = $this->processXmlChunk($service);
            }
            
            Log::info("✅ Chunk processing completed", [
                'chunk' => $this->chunkIndex,
                'result' => $result
            ]);

            Log::info("💾 Saving chunk result", [
                'chunk' => $this->chunkIndex
            ]);
            
            // Сохраняем результат чанка
            $this->saveChunkResult($result);

            Log::info("📊 Updating overall progress", [
                'chunk' => $this->chunkIndex
            ]);
            
            // Обновляем общий прогресс
            $this->updateOverallProgress($result);

            Log::info("🎉 Chunked import completed successfully", [
                'token' => $this->token,
                'chunk' => $this->chunkIndex,
                'imported' => $result['imported'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors' => $result['errors'] ?? 0
            ]);

        } catch (\Throwable $e) {
            Log::error('ChunkedXmlImportJob failed: ' . $e->getMessage(), [
                'token' => $this->token,
                'chunk' => $this->chunkIndex,
                'error' => $e->getTraceAsString()
            ]);

            $this->updateProgress('error', $this->chunkIndex, $this->totalChunks, $e->getMessage());
            
            // Сохраняем ошибку чанка
            $this->saveChunkResult([
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
                'errors' => 1,
                'errors_list' => ['Chunk failed: ' . $e->getMessage()],
            ]);
        }
    }

    protected function processCsvChunk(XmlImportService $service): array
    {
        // ИСПРАВЛЕНИЕ: Читаем только нужные строки из файла
        $chunkRows = $this->readCsvChunkFromFile();
        
        Log::info('CSV Chunk - Read rows from file:', [
            'token' => $this->token,
            'chunk' => $this->chunkIndex,
            'total_rows' => count($chunkRows),
            'field_mapping' => $this->fieldMapping,
            'first_row_sample' => !empty($chunkRows) ? array_keys($chunkRows[0]) : []
        ]);
        
        if (empty($chunkRows)) {
            return [
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
                'errors' => 0,
                'errors_list' => [],
            ];
        }
        
        $result = [
            'total' => count($chunkRows),
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'errors_list' => [],
        ];

        foreach ($chunkRows as $rowIndex => $row) {
            try {
                Log::info("🔸 CSV Row {$rowIndex} - Start processing", [
                    'chunk' => $this->chunkIndex,
                    'row_index' => $rowIndex,
                    'sku' => $row['sku'] ?? 'unknown'
                ]);
                
                $productData = $this->mapCsvRowToProduct($row, $this->fieldMapping);
                
                Log::info("🔸 CSV Row {$rowIndex} - Mapped data", [
                    'name' => $productData['name'] ?? 'unknown',
                    'sku' => $productData['sku'] ?? 'unknown'
                ]);
                
                Log::info('CSV Chunk - Mapped product data:', [
                    'chunk' => $this->chunkIndex,
                    'row_index' => $rowIndex,
                    'product_data' => $productData
                ]);
                
                // Генерируем name если отсутствует
                if (empty($productData['name']) || trim($productData['name']) === '') {
                    // Пытаемся использовать SKU или описание
                    if (!empty($productData['sku'])) {
                        $productData['name'] = 'Товар ' . $productData['sku'];
                    } elseif (!empty($productData['description'])) {
                        $productData['name'] = substr($productData['description'], 0, 100);
                    } else {
                        $productData['name'] = 'Товар без названия ' . uniqid();
                    }
                    Log::warning('CSV Chunk - Generated product name', [
                        'generated_name' => $productData['name'],
                        'row_index' => $rowIndex
                    ]);
                }
                
                // Генерируем SKU если отсутствует
                $rawSku = $productData['sku'] ?? '';
                $sku = is_string($rawSku) ? trim($rawSku) : trim((string)$rawSku);
                if ($sku === '' || strtolower($sku) === 'null' || strtolower($sku) === 'undefined') {
                    $generated = 'sku-chunk-' . $this->chunkIndex . '-' . ($rowIndex + 1) . '-' . substr(md5(json_encode($row)), 0, 6);
                    $productData['sku'] = $generated;
                    $this->options['status'] = 'draft';
                    Log::warning('CSV Chunk - Generated SKU', [
                        'generated_sku' => $generated,
                        'row_index' => $rowIndex
                    ]);
                }

                Log::info("🔸 CSV Row {$rowIndex} - Processing product", [
                    'sku' => $productData['sku'] ?? 'unknown'
                ]);
                
                $processResult = $this->processProduct($productData, $this->options);
                
                Log::info("🔸 CSV Row {$rowIndex} - Result: {$processResult}", [
                    'sku' => $productData['sku'] ?? 'unknown'
                ]);
                
                if ($processResult === 'imported') {
                    $result['imported']++;
                } elseif ($processResult === 'updated') {
                    $result['updated']++;
                }
            } catch (\Exception $e) {
                $result['errors']++;
                $errorMsg = 'CSV row error: ' . $e->getMessage();
                $result['errors_list'][] = $errorMsg;
                Log::error('CSV chunk product import error', [
                    'error' => $e->getMessage(),
                    'row' => $row,
                    'row_index' => $rowIndex,
                    'chunk' => $this->chunkIndex,
                    'mapped_data' => $productData ?? null
                ]);
            }
        }

        return $result;
    }

    protected function processXmlChunk(XmlImportService $service): array
    {
        // ИСПРАВЛЕНИЕ: Парсим XML из файла и берем только наш чанк
        try {
            if (!file_exists($this->filePath)) {
                throw new \Exception('Import file not found: ' . $this->filePath);
            }
            
            $content = file_get_contents($this->filePath);
            $xml = new \SimpleXMLElement($content);
            $products = $this->extractXmlProducts($xml);
            
            if (empty($products)) {
                return [
                    'total' => 0,
                    'imported' => 0,
                    'updated' => 0,
                    'errors' => 0,
                    'errors_list' => [],
                ];
            }

            // Обрабатываем только наш чанк
            $startIndex = $this->chunkIndex * $this->chunkSize;
            $chunkProducts = array_slice($products, $startIndex, $this->chunkSize);
            
            Log::info('XML Chunk - Extracted products:', [
                'token' => $this->token,
                'chunk' => $this->chunkIndex,
                'start_index' => $startIndex,
                'chunk_size' => $this->chunkSize,
                'total_products_in_file' => count($products),
                'products_in_chunk' => count($chunkProducts)
            ]);
            
            $result = [
                'total' => count($chunkProducts),
                'imported' => 0,
                'updated' => 0,
                'errors' => 0,
                'errors_list' => [],
            ];

            foreach ($chunkProducts as $productIndex => $productData) {
                try {
                    $processResult = $this->processProduct($productData, $this->options);
                    if ($processResult === 'imported') {
                        $result['imported']++;
                    } elseif ($processResult === 'updated') {
                        $result['updated']++;
                    }
                } catch (\Exception $e) {
                    $result['errors']++;
                    $result['errors_list'][] = 'XML product error: ' . $e->getMessage();
                    Log::error('XML chunk product import error: ' . $e->getMessage(), $productData);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('XML chunk processing error: ' . $e->getMessage());
            return [
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
                'errors' => 1,
                'errors_list' => ['XML parsing error: ' . $e->getMessage()],
            ];
        }
    }

    protected function extractXmlProducts(\SimpleXMLElement $xml): array
    {
        $products = [];
        
        // Yandex.Market
        if (isset($xml->shop->offers->offer)) {
            foreach ($xml->shop->offers->offer as $offer) {
                $productData = [];
                foreach ($this->fieldMapping as $dbField => $xmlField) {
                    if (isset($offer->$xmlField)) {
                        $productData[$dbField] = (string)$offer->$xmlField;
                    }
                }
                // Если нет маппинга для основных полей, используем стандартные
                if (empty($productData['name']) && isset($offer->name)) {
                    $productData['name'] = (string)$offer->name;
                }
                if (empty($productData['sku']) && isset($offer->id)) {
                    $productData['sku'] = (string)$offer->id;
                }
                if (empty($productData['price']) && isset($offer->price)) {
                    $productData['price'] = (string)$offer->price;
                }
                $products[] = $productData;
            }
        }
        // 1C
        elseif (isset($xml->КоммерческаяИнформация->Товары->Товар)) {
            foreach ($xml->КоммерческаяИнформация->Товары->Товар as $товар) {
                $productData = [];
                foreach ($this->fieldMapping as $dbField => $xmlField) {
                    if (isset($товар->$xmlField)) {
                        $productData[$dbField] = (string)$товар->$xmlField;
                    }
                }
                if (empty($productData['name']) && isset($товар->Наименование)) {
                    $productData['name'] = (string)$товар->Наименование;
                }
                if (empty($productData['sku']) && isset($товар->Ид)) {
                    $productData['sku'] = (string)$товар->Ид;
                }
                if (empty($productData['price']) && isset($товар->Цена)) {
                    $productData['price'] = (string)$товар->Цена;
                }
                $products[] = $productData;
            }
        }
        // Universal
        else {
            $productNodes = $xml->products->product ?? $xml->items->item ?? [];
            foreach ($productNodes as $product) {
                $productData = [];
                foreach ($this->fieldMapping as $dbField => $xmlField) {
                    if (isset($product->$xmlField)) {
                        $productData[$dbField] = (string)$product->$xmlField;
                    }
                }
                if (empty($productData['name']) && isset($product->name)) {
                    $productData['name'] = (string)$product->name;
                }
                if (empty($productData['sku']) && isset($product->sku)) {
                    $productData['sku'] = (string)$product->sku;
                } elseif (empty($productData['sku']) && isset($product->id)) {
                    $productData['sku'] = (string)$product->id;
                }
                if (empty($productData['price']) && isset($product->price)) {
                    $productData['price'] = (string)$product->price;
                }
                $products[] = $productData;
            }
        }
        
        return $products;
    }

    /**
     * Читает только нужный чанк строк из CSV файла
     */
    protected function readCsvChunkFromFile(): array
    {
        if (!file_exists($this->filePath)) {
            Log::error('CSV file not found: ' . $this->filePath);
            return [];
        }
        
        $handle = fopen($this->filePath, 'r');
        if (!$handle) {
            Log::error('Cannot open CSV file: ' . $this->filePath);
            return [];
        }
        
        // Читаем заголовок
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return [];
        }
        
        // Пропускаем строки до начала нашего чанка
        $startRow = $this->chunkIndex * $this->chunkSize;
        for ($i = 0; $i < $startRow; $i++) {
            if (fgetcsv($handle) === false) {
                fclose($handle);
                return []; // Достигли конца файла
            }
        }
        
        // Читаем только строки нашего чанка
        $rows = [];
        for ($i = 0; $i < $this->chunkSize; $i++) {
            $values = fgetcsv($handle);
            if ($values === false) {
                break; // Конец файла
            }
            
            if (empty($values) || (count($values) === 1 && $values[0] === null)) {
                continue; // Пропускаем пустые строки
            }
            
            // Дополняем значения если нужно
            if (count($values) !== count($headers)) {
                // array_pad не обрезает строки с лишними колонками.
                $values = array_slice(
                    array_pad($values, count($headers), null),
                    0,
                    count($headers)
                );
            }
            
            $rows[] = array_combine($headers, $values);
        }
        
        fclose($handle);
        return $rows;
    }

    protected function mapCsvRowToProduct(array $row, array $mapping): array
    {
        $product = [];
        
        // Если маппинг пустой, пытаемся использовать прямые имена колонок
        if (empty($mapping)) {
            Log::warning('CSV Chunk - Empty field mapping, using direct column names');
            // Стандартные имена полей
            $defaultFields = ['name', 'sku', 'price', 'description', 'category', 'image', 'gallery', 'quantity', 'available'];
            foreach ($defaultFields as $field) {
                if (array_key_exists($field, $row)) {
                    $product[$field] = $row[$field];
                }
            }
        } else {
            foreach ($mapping as $dbField => $csvColumn) {
                if (array_key_exists($csvColumn, $row)) {
                    $value = $row[$csvColumn];
                    // Очищаем значение от пробелов
                    $product[$dbField] = is_string($value) ? trim($value) : $value;
                } else {
                    Log::warning('CSV Chunk - Column not found in row', [
                        'db_field' => $dbField,
                        'csv_column' => $csvColumn,
                        'available_columns' => array_keys($row)
                    ]);
                }
            }
        }
        
        return $product;
    }

    protected function processProduct($productData, $options)
    {
        try {
            // Подготавливаем данные товара
            $productData = $this->prepareProductData($productData, $options);
            
            // Создаем или обновляем товар
            return $this->createOrUpdateProduct($productData, $options);
        } catch (\Exception $e) {
            Log::error('Error processing product in chunked import: ' . $e->getMessage(), [
                'product_data' => $productData,
                'error' => $e->getTraceAsString(),
                'chunk_index' => $this->chunkIndex,
                'memory_usage' => memory_get_usage(true)
            ]);
            throw $e;
        }
    }

    protected function prepareProductData($productData, $options)
    {
        // Проверяем обязательные поля
        $missing = [];
        if (isset($productData['name'])) $productData['name'] = trim((string)$productData['name']);
        if (isset($productData['sku'])) $productData['sku'] = trim((string)$productData['sku']);
        
        foreach (['name', 'sku'] as $field) {
            if (!isset($productData[$field]) || trim((string)$productData[$field]) === '' || $productData[$field] === null) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new \Exception('Missing required fields: ' . implode(', ', array_unique($missing)));
        }

        return $productData;
    }

    protected function createOrUpdateProduct($productData, $options)
    {
        try {
            // Подготавливаем поля товара
            $fields = $this->prepareProductFields($productData, $options);
            
            $dryRun = (bool)($options['dry_run'] ?? false);
            
            // Проверяем, существует ли товар по SKU
            $existingProduct = \Marvel\Database\Models\Product::where('sku', $productData['sku'])->first();
            
            if ($existingProduct) {
                // АВТОМАТИЧЕСКАЯ ОБРАБОТКА ДУБЛИКАТА: Обновляем существующий товар
                if (!$dryRun) {
                    // Если товар из другого магазина - логируем предупреждение
                    if ($existingProduct->shop_id != $fields['shop_id']) {
                        Log::warning('Product exists in different shop, updating shop_id', [
                            'sku' => $existingProduct->sku,
                            'old_shop_id' => $existingProduct->shop_id,
                            'new_shop_id' => $fields['shop_id']
                        ]);
                    }
                    
                    $existingProduct->update($fields);
                    Log::info('Product updated in chunked import', [
                        'id' => $existingProduct->id,
                        'sku' => $existingProduct->sku,
                        'shop_id' => $fields['shop_id']
                    ]);
                    // Обрабатываем связи для обновленного товара
                    $this->processProductRelations($existingProduct, $productData, $options);
                }
                return 'updated';
            } else {
                // Создаем новый товар
                if ($dryRun) {
                    Log::info('Product would be created in dry run', [
                        'sku' => $productData['sku'],
                        'name' => $productData['name']
                    ]);
                } else {
                    try {
                        $product = \Marvel\Database\Models\Product::create($fields);
                        Log::info('Product created in chunked import', [
                            'id' => $product->id,
                            'sku' => $product->sku,
                            'shop_id' => $fields['shop_id']
                        ]);
                        
                        // Обрабатываем связи: категории, теги, атрибуты
                        $this->processProductRelations($product, $productData, $options);
                    } catch (\Illuminate\Database\QueryException $e) {
                        // АВТОМАТИЧЕСКАЯ ОБРАБОТКА: Если ошибка дубликата - пробуем обновить
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                            strpos($e->getMessage(), 'duplicate key') !== false) {
                            
                            Log::warning('Duplicate entry detected during create, trying update', [
                                'sku' => $productData['sku']
                            ]);
                            
                            $existingProduct = \Marvel\Database\Models\Product::where('sku', $productData['sku'])->first();
                            if ($existingProduct) {
                                $existingProduct->update($fields);
                                Log::info('Product updated after duplicate error', [
                                    'id' => $existingProduct->id,
                                    'sku' => $existingProduct->sku
                                ]);
                                // Обрабатываем связи для обновленного товара
                                $this->processProductRelations($existingProduct, $productData, $options);
                                return 'updated';
                            }
                        }
                        throw $e;
                    }
                }
                return 'imported';
            }
        } catch (\Exception $e) {
            Log::error('Error creating/updating product in chunked import: ' . $e->getMessage(), [
                'product_data' => $productData,
                'error' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function prepareProductFields($productData, $options)
    {
        // НЕ используем shop_id в defaultValues! Он ДОЛЖЕН приходить из options
        $defaultValues = [
            'status' => 'publish',
            'unit' => '1',
            'in_stock' => true,
            'is_taxable' => false,
            'is_digital' => false,
            'is_external' => false,
            'quantity' => 100,
            'language' => 'ru'
        ];

        // Логируем что приходит в options
        Log::info('ChunkedXmlImportJob - prepareProductFields options:', [
            'shop_id_in_options' => $options['shop_id'] ?? 'not set',
            'shop_id_type' => isset($options['shop_id']) ? gettype($options['shop_id']) : 'not set',
            'all_options' => $options
        ]);

        // ВАЖНО: НЕ переопределяем is_external из options, чтобы товар не стал внешним
        $optionsWithoutExternal = $options;
        unset($optionsWithoutExternal['is_external']);
        
        $fields = array_merge($defaultValues, $optionsWithoutExternal);
        
        // shop_id ОБЯЗАТЕЛЕН - если не пришел, выбрасываем ошибку
        if (!isset($fields['shop_id']) || empty($fields['shop_id'])) {
            Log::error('ChunkedXmlImportJob - shop_id is missing!', [
                'sku' => $productData['sku'] ?? 'unknown',
                'options' => $options,
                'fields' => $fields
            ]);
            throw new \Exception('shop_id is required for product import. Please select a shop in import settings.');
        }
        
        // ПРИНУДИТЕЛЬНО проверяем что shop_id это число
        $fields['shop_id'] = (int) $fields['shop_id'];
        if ($fields['shop_id'] <= 0) {
            Log::error('ChunkedXmlImportJob - Invalid shop_id!', [
                'shop_id' => $fields['shop_id'],
                'sku' => $productData['sku'] ?? 'unknown',
                'options' => $options
            ]);
            throw new \Exception('Invalid shop_id. Please select a valid shop.');
        }
        
        Log::info('ChunkedXmlImportJob - shop_id in fields:', [
            'shop_id' => $fields['shop_id'],
            'sku' => $productData['sku'] ?? 'unknown'
        ]);
        
        Log::info('ChunkedXmlImportJob - shop_id after merge:', [
            'shop_id' => $fields['shop_id'],
            'sku' => $productData['sku'] ?? 'unknown'
        ]);
        
        // Убираем product_type из options, так как используем type_id
        unset($fields['product_type']);
        
        // Основные поля
        $fields['name'] = $productData['name'];
        $fields['slug'] = \Illuminate\Support\Str::slug($productData['name']);
        $fields['sku'] = $productData['sku'];
        
        // Определяем type_id
        $fields['type_id'] = $this->resolveTypeId($options, $productData);
        
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
        
        // Изображения - ОПТИМИЗИРОВАННАЯ ОБРАБОТКА
        if (!empty($productData['image'])) {
            try {
                // КРИТИЧНО: Ограничиваем обработку изображений для производительности
                $download = (bool)($options['download_images'] ?? false); // По умолчанию НЕ загружаем
                
                if ($download) {
                    // Загружаем только если явно указано
                    $imageData = $this->processImages($productData['image'], $options);
                    if (is_array($imageData) && !empty($imageData)) {
                        $fields['image'] = $imageData[0];
                    } else {
                        $fields['image'] = null;
                    }
                } else {
                    // Просто сохраняем URL без загрузки
                    $imageUrl = is_array($productData['image']) ? $productData['image'][0] : $productData['image'];
                    $fields['image'] = [
                        'thumbnail' => $imageUrl,
                        'original' => $imageUrl,
                        'id' => null
                    ];
                    Log::info('Chunked import - Image URL saved without downloading:', [
                        'sku' => $productData['sku'] ?? 'unknown',
                        'url' => $imageUrl
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Chunked import - Image processing error: ' . $e->getMessage(), [
                    'sku' => $productData['sku'] ?? 'unknown',
                    'image_data' => $productData['image']
                ]);
                $fields['image'] = null;
            }
        }
        
        // Галерея изображений - ОПТИМИЗИРОВАННАЯ ОБРАБОТКА
        if (!empty($productData['gallery'])) {
            try {
                $download = (bool)($options['download_images'] ?? false);
                
                if (!$download) {
                    // Просто сохраняем URLs без загрузки
                    $gallery = $productData['gallery'];
                    $parts = [];
                    
                    if (is_string($gallery)) {
                        $decoded = json_decode($gallery, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $parts = $decoded;
                        } else {
                            $parts = preg_split('/[;,|\n]+/', $gallery);
                            $parts = array_values(array_filter(array_map('trim', $parts)));
                        }
                    } elseif (is_array($gallery)) {
                        $parts = $gallery;
                    }
                    
                    if (!empty($parts)) {
                        $stored = [];
                        foreach (array_slice($parts, 0, 10) as $url) { // Максимум 10
                            if (empty($url)) continue;
                            $stored[] = [
                                'id' => uniqid('gallery_', true),
                                'original' => $url,
                                'thumbnail' => $url,
                            ];
                        }
                        $fields['gallery'] = $stored;
                        Log::info('Chunked import - Gallery URLs saved without downloading:', [
                            'sku' => $productData['sku'] ?? 'unknown',
                            'count' => count($stored)
                        ]);
                    }
                } else {
                    // Загружаем изображения (старый медленный способ)
                    $gallery = $productData['gallery'];
                    $parts = [];
                    
                    if (is_string($gallery)) {
                        $decoded = json_decode($gallery, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $parts = $decoded;
                        } else {
                            $parts = preg_split('/[;,|\n]+/', $gallery);
                            $parts = array_values(array_filter(array_map('trim', $parts)));
                        }
                    } elseif (is_array($gallery)) {
                        $parts = $gallery;
                    }
                    
                    if (!empty($parts)) {
                        $stored = [];
                        foreach (array_slice($parts, 0, 10) as $url) {
                            if (empty($url)) continue;
                            [$origUrl, $thumbUrl] = $this->storeRemoteWithThumb((string)$url, $options);
                            $stored[] = [
                                'id' => uniqid('gallery_', true),
                                'original' => $origUrl ?? $url,
                                'thumbnail' => $thumbUrl ?? ($origUrl ?? $url),
                            ];
                        }
                        $fields['gallery'] = $stored;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Chunked import - Gallery processing error: ' . $e->getMessage(), [
                    'sku' => $productData['sku'] ?? 'unknown'
                ]);
                $fields['gallery'] = null;
            }
        }
        
        // Статус товара
        if (!empty($productData['status'])) {
            $status = strtolower(trim($productData['status']));
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
        } else {
            // Если статус не указан, определяем по наличию цены и категории
            $hasPrice = !empty($productData['price']);
            $hasCategory = !empty($productData['category']) || !empty($options['category_id']);
            $fields['status'] = ($hasPrice && $hasCategory) ? 'publish' : 'draft';
        }
        
        // Превью/внешняя ссылка из CSV: сохраняем как preview_url и НЕ переключаем товар в внешний
        if (!empty($productData['url'])) {
            $fields['preview_url'] = $productData['url'];
            // Никогда не выставляем is_external из импорта URL, чтобы не ломать кнопку "КУПИТЬ"
            // Товар остается обычным (не внешним), но сохраняет ссылку на внешний источник для справки
            Log::info('Chunked import - URL saved to preview_url:', [
                'sku' => $productData['sku'] ?? 'unknown',
                'url' => $productData['url']
            ]);
        }
        
        return $fields;
    }

    /**
     * Обработка изображений (оптимизированная версия)
     */
    protected function processImages($imageData, array $options = [])
    {
        // Очищаем память перед обработкой изображений
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $download = (bool)($options['download_images'] ?? true);
        
        if (empty($imageData)) {
            return null;
        }
        
        if (is_array($imageData)) {
            $images = [];
            $maxImages = 10; // Максимум 10 изображений на товар для экономии памяти
            foreach (array_slice($imageData, 0, $maxImages) as $img) {
                if (empty($img)) continue;
                
                $attachment = $this->createAttachmentFromUrl((string)$img, $download, $options);
                if ($attachment) {
                    $images[] = $attachment;
                }
                
                // Очищаем память после каждого изображения
                unset($attachment);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            return empty($images) ? null : $images;
        } else {
            $attachment = $this->createAttachmentFromUrl((string)$imageData, $download, $options);
            return $attachment ? [$attachment] : null;
        }
    }

    /**
     * Создает Attachment из URL изображения (оптимизированная версия)
     */
    protected function createAttachmentFromUrl(string $imageUrl, bool $download, array $options = [])
    {
        try {
            Log::info('Chunked import - Creating attachment from URL:', [
                'url' => $imageUrl,
                'download' => $download,
                'memory_usage' => memory_get_usage(true)
            ]);

            $attachment = new \Marvel\Database\Models\Attachment();
            $attachment->save();

            if ($download) {
                $tempFile = $this->downloadImageToTemp($imageUrl);
                if ($tempFile) {
                    $attachment->addMedia($tempFile)
                        ->toMediaCollection();
                    
                    // Очищаем временный файл сразу после использования
                    if (file_exists($tempFile)) {
                        @unlink($tempFile);
                    }
                    
                    foreach ($attachment->getMedia() as $media) {
                        if (strpos($media->mime_type, 'image/') !== false) {
                            $result = [
                                'thumbnail' => $media->getUrl('thumbnail'),
                                'original' => $media->getUrl(),
                                'id' => $attachment->id
                            ];
                            Log::info('Chunked import - Attachment created:', $result);
                            
                            // Очищаем память
                            unset($media);
                            if (function_exists('gc_collect_cycles')) {
                                gc_collect_cycles();
                            }
                            
                            return $result;
                        } else {
                            $result = [
                                'thumbnail' => '',
                                'original' => $media->getUrl(),
                                'id' => $attachment->id
                            ];
                            
                            unset($media);
                            if (function_exists('gc_collect_cycles')) {
                                gc_collect_cycles();
                            }
                            
                            return $result;
                        }
                    }
                } else {
                    return [
                        'thumbnail' => $imageUrl,
                        'original' => $imageUrl,
                        'id' => $attachment->id
                    ];
                }
            } else {
                return [
                    'thumbnail' => $imageUrl,
                    'original' => $imageUrl,
                    'id' => $attachment->id
                ];
            }
        } catch (\Exception $e) {
            Log::error('Chunked import - Failed to create attachment: ' . $e->getMessage(), [
                'url' => $imageUrl,
                'memory_usage' => memory_get_usage(true)
            ]);
            return null;
        }
    }

    /**
     * Скачивает изображение во временный файл (копия из XmlImportService)
     */
    protected function downloadImageToTemp(string $url)
    {
        try {
            $tempFile = storage_path('app/temp/') . uniqid('xml_import_chunk_');
            
            if (!is_dir(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0777, true);
            }
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($imageData === false || $httpCode !== 200) {
                Log::warning('Chunked import - Failed to download image', [
                    'url' => $url,
                    'http_code' => $httpCode
                ]);
                return null;
            }
            
            file_put_contents($tempFile, $imageData);
            
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (empty($extension)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tempFile);
                finfo_close($finfo);
                
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];
                $extension = $mimeToExt[$mimeType] ?? 'jpg';
            }
            
            $finalPath = $tempFile . '.' . $extension;
            rename($tempFile, $finalPath);
            
            return $finalPath;
        } catch (\Exception $e) {
            Log::error('Chunked import - Error downloading image: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Сохранение удаленного изображения с thumbnail (копия из XmlImportService)
     */
    protected function storeRemoteWithThumb(string $url, array $options): array
    {
        try {
            if ($url === '' || stripos($url, 'http') !== 0) return [null, null];
            
            $tempFile = $this->downloadImageToTemp($url);
            if (!$tempFile) {
                return [null, null];
            }

            $attachment = new \Marvel\Database\Models\Attachment();
            $attachment->save();

            $attachment->addMedia($tempFile)
                ->toMediaCollection();
            
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
            Log::warning('Chunked import - Image store failed: ' . $e->getMessage());
            return [null, null];
        }
    }

    protected function parsePrice($price)
    {
        // Убираем все нечисловые символы, кроме точки и запятой
        $cleanPrice = preg_replace('/[^0-9.,]/', '', $price);
        
        // Заменяем запятую на точку
        $cleanPrice = str_replace(',', '.', $cleanPrice);
        
        return (float)$cleanPrice;
    }

    protected function updateProgress(string $status, int $chunkIndex, int $totalChunks, string $error = null): void
    {
        $dir = storage_path('app/xml-import-progress');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        
        $path = $dir . '/' . $this->token . '.json';
        
        // Загружаем текущий прогресс
        $progressData = [];
        if (file_exists($path)) {
            $existing = json_decode(file_get_contents($path), true);
            if ($existing) {
                $progressData = $existing;
            }
        }
        
        // Обновляем данные
        $progressData['status'] = $status;
        $progressData['chunk_index'] = $chunkIndex;
        $progressData['total_chunks'] = $totalChunks;
        $progressData['chunks_completed'] = isset($progressData['chunks_completed']) ? $progressData['chunks_completed'] + 1 : 1;
        $progressData['progress_percent'] = round(($progressData['chunks_completed'] / $totalChunks) * 100, 2);
        $progressData['updated_at'] = now()->toISOString();
        
        if ($error) {
            $progressData['error'] = $error;
            $progressData['errors'] = isset($progressData['errors']) ? $progressData['errors'] + 1 : 1;
        }
        
        // Простая запись без блокировки
        file_put_contents($path, json_encode($progressData, JSON_PRETTY_PRINT));
    }

    protected function saveChunkResult(array $result): void
    {
        $dir = storage_path('app/xml-import-chunks');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        
        $path = $dir . '/' . $this->token . '_chunk_' . $this->chunkIndex . '.json';
        file_put_contents($path, json_encode($result));
    }

    protected function updateOverallProgress(array $chunkResult): void
    {
        $dir = storage_path('app/xml-import-stats');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        
        $path = $dir . '/' . $this->token . '.json';
        
        // Загружаем существующую статистику
        $stats = [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'errors_list' => [],
            'chunks_completed' => 0,
            'total_chunks' => $this->totalChunks,
            'status' => 'processing'
        ];
        
        if (file_exists($path)) {
            $existing = json_decode(file_get_contents($path), true);
            if ($existing) {
                $stats = array_merge($stats, $existing);
            }
        }

        // Обновляем статистику
        $stats['total'] += $chunkResult['total'];
        $stats['imported'] += $chunkResult['imported'];
        $stats['updated'] += $chunkResult['updated'];
        $stats['errors'] += $chunkResult['errors'];
        $stats['errors_list'] = array_merge($stats['errors_list'], $chunkResult['errors_list']);
        $stats['chunks_completed']++;
        $stats['updated_at'] = now()->toISOString();

        Log::info('Chunk progress updated', [
            'token' => $this->token,
            'chunk' => $this->chunkIndex,
            'chunks_completed' => $stats['chunks_completed'],
            'total_chunks' => $this->totalChunks,
            'imported' => $chunkResult['imported'],
            'updated' => $chunkResult['updated']
        ]);

        // Проверяем, завершен ли импорт
        if ($stats['chunks_completed'] >= $this->totalChunks) {
            $stats['status'] = 'completed';
            $stats['completed_at'] = now()->toISOString();
            
            Log::info('All chunks completed, deleting temp file', [
                'token' => $this->token,
                'total_imported' => $stats['imported'],
                'total_updated' => $stats['updated'],
                'total_errors' => $stats['errors']
            ]);
            
            // Удаляем временный файл после завершения всех чанков
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
                Log::info('Temporary import file deleted', [
                    'token' => $this->token,
                    'file_path' => $this->filePath
                ]);
            }
        }

        // Простая запись без блокировки
        file_put_contents($path, json_encode($stats, JSON_PRETTY_PRINT));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ChunkedXmlImportJob permanently failed', [
            'token' => $this->token,
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage()
        ]);

        $this->updateProgress('failed', $this->chunkIndex, $this->totalChunks, $exception->getMessage());
    }

    /**
     * Обрабатывает связи товара: категории, теги, атрибуты
     */
    protected function processProductRelations($product, array $productData, array $options = [])
    {
        try {
            if (!$product || !method_exists($product, 'categories')) {
                return;
            }

            // Категории: используем только первую категорию
            $categoryIds = [];
            if (!empty($options['category_id'])) {
                $categoryIds[] = (int)$options['category_id'];
            }
            if (!empty($productData['category'])) {
                $categoryData = is_array($productData['category']) ? $productData['category'] : [$productData['category']];
                foreach ($categoryData as $cand) {
                    if (is_numeric($cand)) {
                        $cat = \Marvel\Database\Models\Category::find((int)$cand);
                        if ($cat) {
                            $categoryIds[] = (int)$cat->id;
                            break; // Берем только первую найденную категорию
                        }
                        continue;
                    }
                    $slug = \Illuminate\Support\Str::slug((string)$cand);
                    $cat = \Marvel\Database\Models\Category::where('slug', $slug)->first();
                    if (!$cat) {
                        $cat = \Marvel\Database\Models\Category::where('name', (string)$cand)->first();
                    }
                    if ($cat) {
                        $categoryIds[] = (int)$cat->id;
                        break; // Берем только первую найденную категорию
                    }
                }
            }
            $categoryIds = array_values(array_unique(array_filter($categoryIds)));
            if (!empty($categoryIds)) {
                // Используем только первую категорию (теперь товар может иметь только одну категорию)
                $firstCategoryId = $categoryIds[0];
                $product->categories()->sync([$firstCategoryId]);
                Log::info('Chunked import - Category attached', [
                    'product_id' => $product->id,
                    'category_id' => $firstCategoryId
                ]);
            }

            // Теги
            if (!empty($productData['tags']) && method_exists($product, 'tags')) {
                $tagIds = [];
                $tags = is_array($productData['tags']) ? $productData['tags'] : explode(',', (string)$productData['tags']);
                foreach ($tags as $tagName) {
                    $tagName = trim((string)$tagName);
                    if (empty($tagName)) continue;
                    
                    if (is_numeric($tagName)) {
                        $tag = \Marvel\Database\Models\Tag::find((int)$tagName);
                        if ($tag) {
                            $tagIds[] = (int)$tag->id;
                        }
                    } else {
                        $slug = \Illuminate\Support\Str::slug($tagName);
                        $tag = \Marvel\Database\Models\Tag::firstOrCreate(
                            ['slug' => $slug],
                            ['name' => $tagName]
                        );
                        $tagIds[] = (int)$tag->id;
                    }
                }
                if (!empty($tagIds)) {
                    $product->tags()->syncWithoutDetaching($tagIds);
                }
            }

            // Атрибуты (старый формат из XML - для обратной совместимости)
            if (!empty($productData['attributes']) && is_array($productData['attributes']) && method_exists($product, 'attributes')) {
                $attachIds = [];
                foreach ($productData['attributes'] as $item) {
                    $name = trim((string)($item['name'] ?? ''));
                    $value = trim((string)($item['value'] ?? ''));
                    if ($name === '' || $value === '') continue;
                    $attr = \Marvel\Database\Models\Attribute::firstOrCreate(
                        ['slug' => \Illuminate\Support\Str::slug($name)],
                        ['name' => $name]
                    );
                    $attachIds[] = (int)$attr->id;
                }
                if (!empty($attachIds)) {
                    $product->attributes()->syncWithoutDetaching($attachIds);
                }
            }

            // Значения атрибутов (новый формат через attribute_values)
            if (!empty($productData['attribute_values']) && is_array($productData['attribute_values']) && method_exists($product, 'attributes')) {
                $attributeValuesData = [];
                foreach ($productData['attribute_values'] as $attributeId => $value) {
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
                        // Если передан slug или name, пытаемся найти атрибут
                        $attr = \Marvel\Database\Models\Attribute::where('slug', \Illuminate\Support\Str::slug((string)$attributeId))
                            ->orWhere('name', (string)$attributeId)
                            ->first();
                        if (!$attr) {
                            continue;
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
                    Log::info('Chunked import - Attribute values saved', [
                        'product_id' => $product->id,
                        'attributes_count' => count($attributeValuesData)
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Chunked import - Product relations processing error: ' . $e->getMessage(), [
                'product_id' => $product->id ?? 'unknown',
                'error' => $e->getTraceAsString()
            ]);
        }
    }

    private function resolveTypeId(array $options, array $productData): int
    {
        // 1) Из options напрямую
        if (isset($options['type_id']) && is_numeric($options['type_id'])) {
            return (int)$options['type_id'];
        }
        // 2) Из категории (options.category_id)
        if (isset($options['category_id']) && is_numeric($options['category_id'])) {
            $cat = \Marvel\Database\Models\Category::find((int)$options['category_id']);
            if ($cat && isset($cat->type_id)) return (int)$cat->type_id;
        }
        // 3) Из productData['type'] как id или slug
        if (!empty($productData['type'])) {
            $val = $productData['type'];
            if (is_numeric($val)) return (int)$val;
            $slug = \Illuminate\Support\Str::slug((string)$val);
            $id = \Marvel\Database\Models\Type::where('slug', $slug)->value('id');
            if ($id) return (int)$id;
        }
        // 4) По умолчанию используем тип со slug 'element', если есть
        $elementId = \Marvel\Database\Models\Type::where('slug', 'element')->value('id');
        if ($elementId) return (int)$elementId;
        // 5) Любой доступный тип
        $any = \Marvel\Database\Models\Type::orderBy('id')->value('id');
        if ($any) return (int)$any;
        // 6) Если совсем нет типов — ошибка
        throw new \Exception('No product types found to assign type_id');
    }
}

