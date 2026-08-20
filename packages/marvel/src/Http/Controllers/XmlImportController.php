<?php

namespace Marvel\Http\Controllers;

use Marvel\Services\XmlImportService;
use Marvel\Services\ChunkedImportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Marvel\Jobs\XmlImportJob;
use Marvel\Database\Models\Shop;

class XmlImportController extends CoreController
{
    protected function getXmlImportService()
    {
        return new XmlImportService();
    }

    protected function getChunkedImportService()
    {
        return new ChunkedImportService();
    }

    /**
     * Загрузить и импортировать XML файл
     */
    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'xml_file' => 'sometimes|file|max:51200', // до 50MB
            'xml_url' => 'sometimes|url',
            'options' => 'sometimes',
            'field_mapping' => 'sometimes', // может быть массивом или JSON-строкой
            'attribute_mapping' => 'sometimes', // маппинг характеристик param name => атрибут
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            \Illuminate\Support\Facades\Log::info('XML Import started', [
                'has_file' => $request->hasFile('xml_file'),
                'has_url' => $request->filled('xml_url'),
                'queue' => $request->boolean('queue'),
                'chunked' => $request->boolean('chunked'),
            ]);

            $content = null;
            $ext = null;
            if ($request->hasFile('xml_file')) {
                $file = $request->file('xml_file');
                $content = file_get_contents($file->getRealPath());
                $ext = strtolower($file->getClientOriginalExtension());
                \Illuminate\Support\Facades\Log::info('File uploaded', [
                    'ext' => $ext,
                    'size' => strlen($content),
                ]);
            } elseif ($request->filled('xml_url')) {
                $url = (string)$request->input('xml_url');
                $content = @file_get_contents($url);
                if ($content === false) {
                    throw new \Exception('Failed to download file from URL');
                }
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            } else {
                throw new \Exception('No file or URL provided');
            }

            // КРИТИЧЕСКОЕ ЛОГИРОВАНИЕ: что приходит в запросе
            \Illuminate\Support\Facades\Log::info('XmlImportController - Raw request data:', [
                'all_input' => $request->all(),
                'has_options' => $request->has('options'),
                'options_raw' => $request->input('options'),
                'files' => $request->files->all()
            ]);
            
            // Получаем настройки импорта
            $options = $request->input('options', []);
            $fieldMapping = $request->input('field_mapping', []);
            $attributeMapping = $request->input('attribute_mapping', []);

            // Если field_mapping передан как JSON строка, декодируем её
            if (is_string($fieldMapping)) {
                $fieldMapping = json_decode($fieldMapping, true) ?: [];
            }

            // Если attribute_mapping передан как JSON строка, декодируем её
            if (is_string($attributeMapping)) {
                $attributeMapping = json_decode($attributeMapping, true) ?: [];
            }

            // Если options пришли как JSON строка (multipart), декодируем
            if (is_string($options)) {
                $decoded = json_decode($options, true);
                $options = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($options)) {
                $options = [];
            }
            if (!is_array($attributeMapping)) {
                $attributeMapping = [];
            }
            
            // КРИТИЧЕСКОЕ ЛОГИРОВАНИЕ: что приходит в options
            \Illuminate\Support\Facades\Log::info('XmlImportController - Received options:', [
                'shop_id' => $options['shop_id'] ?? 'NOT SET',
                'shop_id_type' => isset($options['shop_id']) ? gettype($options['shop_id']) : 'not set',
                'category_id' => $options['category_id'] ?? 'NOT SET',
                'all_options' => $options
            ]);
            
            // ПРОВЕРЯЕМ что shop_id передан и это число
            if (!isset($options['shop_id']) || empty($options['shop_id']) || !is_numeric($options['shop_id'])) {
                \Illuminate\Support\Facades\Log::error('XmlImportController - Invalid shop_id!', [
                    'shop_id' => $options['shop_id'] ?? 'not set',
                    'shop_id_type' => isset($options['shop_id']) ? gettype($options['shop_id']) : 'not set',
                    'all_options' => $options
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'shop_id is required and must be a number. Please select a shop.',
                    'errors' => ['shop_id' => ['Shop ID is required']]
                ], 422);
            }

            $user = $request->user();
            $shop = Shop::query()->find((int) $options['shop_id']);
            if (!$shop) {
                return response()->json(['success' => false, 'message' => 'Магазин не найден.'], 404);
            }
            if (!$user->hasPermissionTo('super_admin') && (int) $shop->owner_id !== (int) $user->id) {
                return response()->json(['success' => false, 'message' => 'Можно импортировать товары только в собственный магазин.'], 403);
            }

            // Базовая проверка расширения
            $allowed = ['xml','yml','yaml','csv','txt'];
            if (!in_array($ext, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['xml_file' => ['Unsupported file type: ' . $ext]]
                ], 422);
            }

            // Валидация options
            $optionErrors = $this->validateOptions($options);
            if (!empty($optionErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $optionErrors
                ], 422);
            }

            // Валидация маппинга (минимальная)
            $mappingErrors = $this->validateMapping($fieldMapping, $options);
            if (!empty($mappingErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $mappingErrors
                ], 422);
            }

            $productCount = $this->estimateProductCount($content, $ext);
            $forceChunked = $productCount > 50;

            if ($request->boolean('queue') || $forceChunked) {
                \Illuminate\Support\Facades\Log::info('Import queued');

                $useChunked = $request->boolean('chunked') || $forceChunked;
                
                \Illuminate\Support\Facades\Log::info('Calculated import params', [
                    'product_count' => $productCount,
                    'use_chunked' => $useChunked
                ]);
                
                if ($useChunked) {
                    \Illuminate\Support\Facades\Log::info('Starting chunked import');
                    $chunkSize = $request->input('chunk_size') ? (int)$request->input('chunk_size') : null;
                    $result = $this->getChunkedImportService()->startChunkedImport(
                        $content, 
                        $ext, 
                        $options, 
                        $fieldMapping, 
                        $chunkSize
                    );
                    
                    if ($result['success']) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Chunked import started',
                            'data' => [
                                'token' => $result['token'],
                                'total_products' => $result['total_products'],
                                'chunk_size' => $result['chunk_size'],
                                'total_chunks' => $result['total_chunks'],
                                'import_type' => 'chunked'
                            ]
                        ]);
                    } else {
                        return response()->json([
                            'success' => false,
                            'message' => $result['message']
                        ], 500);
                    }
                } else {
                    // Обычный импорт в очереди
                    $token = Str::uuid()->toString();
                    XmlImportJob::dispatch($content, $ext, $options, $fieldMapping, $token);
                    return response()->json([
                        'success' => true,
                        'message' => 'Import queued',
                        'data' => [
                            'token' => $token,
                            'import_type' => 'standard'
                        ]
                    ]);
                }
            }

            // КРИТИЧЕСКОЕ ЛОГИРОВАНИЕ: что передаем в сервис
            \Illuminate\Support\Facades\Log::info('XmlImportController - Passing to service:', [
                'options' => $options,
                'shop_id_in_options' => $options['shop_id'] ?? 'NOT SET',
                'fieldMapping' => $fieldMapping,
                'ext' => $ext
            ]);
            
            $xmlImportService = $this->getXmlImportService();

            // Передаём в сервис маппинг характеристик, если он есть (для XML/YML импорта)
            if (!empty($attributeMapping) && method_exists($xmlImportService, 'setAttributeMapping')) {
                $xmlImportService->setAttributeMapping($attributeMapping);
            }
            
            if (in_array($ext, ['csv'])) {
                $result = $xmlImportService->importFromCsv($content, $options, $fieldMapping);
            } else {
                if (!empty($fieldMapping)) {
                    $xmlImportService->setCustomFieldMapping($fieldMapping);
                }
                $result = $xmlImportService->importFromXml($content, $options);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Import completed successfully',
                'data' => $result,
                'logs' => method_exists($xmlImportService, 'getLogs') ? $xmlImportService->getLogs() : []
            ]);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Import failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить статистику импорта
     */
    public function getStats(): JsonResponse
    {
        $token = request()->query('token');
        if ($token) {
            $path = storage_path('app/xml-import-stats/' . basename($token) . '.json');
            if (file_exists($path)) {
                $json = file_get_contents($path);
                $data = json_decode($json, true) ?: [];
                // Совместимость: если сохранены только stats без оболочки
                if (isset($data['stats']) || isset($data['logs'])) {
                    return response()->json(['success' => true, 'data' => $data['stats'] ?? [], 'logs' => $data['logs'] ?? []]);
                }
                return response()->json(['success' => true, 'data' => $data, 'logs' => []]);
            }
            return response()->json(['success' => true, 'data' => null]);
        }
        $xmlImportService = $this->getXmlImportService();
        $stats = $xmlImportService->getImportStats();
        $logs = method_exists($xmlImportService, 'getLogs') ? $xmlImportService->getLogs() : [];
        return response()->json(['success' => true, 'data' => $stats, 'logs' => $logs]);
    }

    /**
     * Получить доступные поля для маппинга
     */
    public function getAvailableFields(): JsonResponse
    {
        $fields = [
            'product_fields' => [
                'name' => 'Название товара',
                'description' => 'Описание',
                'price' => 'Цена',
                'sale_price' => 'Цена со скидкой',
                'sku' => 'Артикул (SKU)',
                'quantity' => 'Количество',
                'category' => 'Категория',
                'image' => 'Изображение',
                'gallery' => 'Галерея изображений',
                'vendor' => 'Производитель',
                'model' => 'Модель',
                'weight' => 'Вес',
                'dimensions' => 'Размеры',
                'url' => 'Внешняя ссылка',
                'status' => 'Статус товара',
                'type' => 'Тип товара'
            ],
            'xml_formats' => [
                'yandex_market' => 'Yandex.Market',
                '1c' => '1C:Предприятие',
                'universal' => 'Универсальный формат',
                'csv' => 'CSV'
            ],
            'default_mappings' => [
                'yandex_market' => $this->getXmlImportService()->getDefaultFieldMapping('yandex_market'),
                '1c' => $this->getXmlImportService()->getDefaultFieldMapping('1c'),
                'universal' => $this->getXmlImportService()->getDefaultFieldMapping('universal'),
                'csv' => $this->getXmlImportService()->getDefaultFieldMapping('universal')
            ]
        ];
        
        return response()->json([
            'success' => true,
            'data' => $fields
        ]);
    }

    /**
     * Сохранить настройки маппинга полей
     */
    public function saveFieldMapping(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'field_mapping' => 'required|array',
            'mapping_name' => 'required|string|max:255',
            'attribute_mapping' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Персистим во временное файловое хранилище (storage/app/xml-mappings.json)
            $path = storage_path('app/xml-mappings.json');
            $list = [];
            if (file_exists($path)) {
                $json = file_get_contents($path);
                $list = json_decode($json, true) ?: [];
            }
            $list[] = [
                'id' => count($list) + 1,
                'user_id' => (int) $request->user()->id,
                'mapping_name' => $request->input('mapping_name'),
                'field_mapping' => $request->input('field_mapping'),
                'attribute_mapping' => $request->input('attribute_mapping', []),
                'created_at' => now()->toDateTimeString(),
            ];
            file_put_contents($path, json_encode($list));

            return response()->json([
                'success' => true,
                'message' => 'Field mapping saved successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save field mapping: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить сохраненные настройки маппинга
     */
    public function getSavedMappings(Request $request): JsonResponse
    {
        $path = storage_path('app/xml-mappings.json');
        $list = [];
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $list = json_decode($json, true) ?: [];
        }
        if (!$request->user()->hasPermissionTo('super_admin')) {
            $userId = (int) $request->user()->id;
            $list = array_values(array_filter($list, static fn ($item) => (int) ($item['user_id'] ?? 0) === $userId));
        }
        return response()->json([
            'success' => true,
            'data' => $list
        ]);
    }

    /**
     * Удалить настройки маппинга
     */
    public function deleteMapping(Request $request, $id): JsonResponse
    {
        try {
            $path = storage_path('app/xml-mappings.json');
            $list = [];
            if (file_exists($path)) {
                $json = file_get_contents($path);
                $list = json_decode($json, true) ?: [];
            }
            $idNum = (int) $id;
            $isSuperAdmin = $request->user()->hasPermissionTo('super_admin');
            $userId = (int) $request->user()->id;
            $list = array_values(array_filter($list, function ($item) use ($idNum, $isSuperAdmin, $userId) {
                if ((int) ($item['id'] ?? 0) !== $idNum) return true;
                return !$isSuperAdmin && (int) ($item['user_id'] ?? 0) !== $userId;
            }));
            file_put_contents($path, json_encode($list));

            return response()->json([
                'success' => true,
                'message' => 'Mapping deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete mapping: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Предварительный просмотр XML файла
     */
    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'xml_file' => 'sometimes|file|max:51200',
            'xml_url' => 'sometimes|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $content = null;
            $ext = null;
            if ($request->hasFile('xml_file')) {
                $file = $request->file('xml_file');
                $content = file_get_contents($file->getRealPath());
                $ext = strtolower($file->getClientOriginalExtension());
            } elseif ($request->filled('xml_url')) {
                $url = (string)$request->input('xml_url');
                $content = @file_get_contents($url);
                if ($content === false) {
                    throw new \Exception('Failed to download file from URL');
                }
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            } else {
                throw new \Exception('No file or URL provided');
            }

            $allowed = ['xml','yml','yaml','csv','txt'];
            if (!in_array($ext, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['xml_file' => ['Unsupported file type: ' . $ext]]
                ], 422);
            }

            if ($ext === 'csv' || $ext === 'txt') {
                $parsed = $this->getXmlImportService()->extractCsvPreview($content);
                return response()->json([
                    'success' => true,
                    'data' => [
                        'xml_type' => 'csv',
                        'preview' => array_slice($parsed['items'], 0, 5),
                        'total_products' => $parsed['total']
                    ]
                ]);
            }

            // Парсим XML/YML для предварительного просмотра
            $xml = new \SimpleXMLElement($content);

            $xmlType = $this->detectXmlType($xml);
            $preview = $this->extractPreviewData($xml, $xmlType);

            return response()->json([
                'success' => true,
                'data' => [
                    'xml_type' => $xmlType,
                    'preview' => $preview,
                    'total_products' => count($this->getAllProducts($xml, $xmlType))
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to preview XML: ' . $e->getMessage()
            ], 500);
        }
    }

    private function validateOptions(array $options): array
    {
        $errors = [];
        $ints = ['shop_id','type_id','category_id'];
        foreach ($ints as $key) {
            if (isset($options[$key]) && !is_numeric($options[$key])) {
                $errors[$key] = ["$key must be numeric"];
            }
        }
        $bools = ['update_existing','create_categories','dry_run'];
        foreach ($bools as $key) {
            if (isset($options[$key]) && !is_bool($options[$key])) {
                // приводим строковые 'true'/'false' в булево, иначе ошибка
                if (in_array($options[$key], ['true','false','1','0',1,0], true)) {
                    // допустимые строковые значения
                } else {
                    $errors[$key] = ["$key must be boolean"];
                }
            }
        }
        return $errors;
    }

    private function validateMapping($fieldMapping, array $options): array
    {
        if (empty($fieldMapping) || !is_array($fieldMapping)) return [];
        $errors = [];
        $required = ['name','sku'];
        foreach ($required as $field) {
            if (empty($fieldMapping[$field])) {
                $errors["field_mapping.$field"] = ["mapping for '$field' is required"];
            }
        }
        return $errors;
    }

    /**
     * Определить тип XML файла
     */
    private function detectXmlType($xml): string
    {
        if (isset($xml->shop) && isset($xml->shop->offers)) {
            return 'yandex_market';
        } elseif (isset($xml->КоммерческаяИнформация)) {
            return '1c';
        } elseif (isset($xml->products) || isset($xml->items)) {
            return 'universal';
        }
        
        return 'unknown';
    }

    /**
     * Извлечь данные для предварительного просмотра
     */
    private function extractPreviewData($xml, $xmlType): array
    {
        $products = $this->getAllProducts($xml, $xmlType);
        $preview = array_slice($products, 0, 5); // Первые 5 товаров
        
        return $preview;
    }

    /**
     * Получить все товары из XML
     */
    private function getAllProducts($xml, $xmlType): array
    {
        $products = [];
        
        switch ($xmlType) {
            case 'yandex_market':
                if (isset($xml->shop->offers->offer)) {
                    foreach ($xml->shop->offers->offer as $offer) {
                        $products[] = [
                            'id' => (string)$offer->id,
                            'name' => (string)$offer->name,
                            'price' => (string)$offer->price,
                            'category' => (string)$offer->categoryId,
                            'available' => (string)$offer->available
                        ];
                    }
                }
                break;
                
            case '1c':
                if (isset($xml->КоммерческаяИнформация->Товары->Товар)) {
                    foreach ($xml->КоммерческаяИнформация->Товары->Товар as $товар) {
                        $products[] = [
                            'id' => (string)$товар->Ид,
                            'name' => (string)$товар->Наименование,
                            'price' => (string)$товар->Цена,
                            'category' => (string)$товар->Группы,
                            'available' => (string)$товар->Количество
                        ];
                    }
                }
                break;
                
            case 'universal':
                $productNodes = $xml->products->product ?? $xml->items->item;
                if ($productNodes) {
                    foreach ($productNodes as $product) {
                        $products[] = [
                            'id' => (string)($product->id ?? $product->sku ?? ''),
                            'name' => (string)($product->name ?? ''),
                            'price' => (string)($product->price ?? ''),
                            'category' => (string)($product->category ?? ''),
                            'available' => (string)($product->available ?? '')
                        ];
                    }
                }
                break;
        }
        
        return $products;
    }

    /**
     * Получить прогресс chunked импорта
     */
    public function getImportProgress(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token is required'
            ], 400);
        }

        $result = $this->getChunkedImportService()->getImportProgress($token);
        return response()->json($result);
    }

    /**
     * Получить детальную статистику импорта
     */
    public function getImportStats(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token is required'
            ], 400);
        }

        $result = $this->getChunkedImportService()->getImportStats($token);
        return response()->json($result);
    }

    /**
     * Получить список активных импортов
     */
    public function getActiveImports(): JsonResponse
    {
        $imports = $this->getChunkedImportService()->getActiveImports();
        return response()->json([
            'success' => true,
            'data' => $imports
        ]);
    }

    /**
     * Очистить данные импорта
     */
    public function cleanupImport(Request $request): JsonResponse
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token is required'
            ], 400);
        }

        $success = $this->getChunkedImportService()->cleanupImport($token);
        return response()->json([
            'success' => $success,
            'message' => $success ? 'Import data cleaned up' : 'Failed to cleanup import data'
        ]);
    }

    /**
     * Оценить количество товаров в файле
     */
    private function estimateProductCount(string $content, string $ext): int
    {
        try {
            if (in_array($ext, ['csv', 'txt'])) {
                return $this->getChunkedImportService()->countProducts($content, $ext);
            } else {
                // Для XML используем простую оценку по размеру
                $sizeKB = strlen($content) / 1024;
                return (int)($sizeKB / 2); // Примерно 2KB на товар
            }
        } catch (\Exception $e) {
            return 100; // Fallback
        }
    }
}
