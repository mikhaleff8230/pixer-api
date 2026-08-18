<?php

namespace Marvel\Services;

use Marvel\Jobs\ChunkedXmlImportJob;
use Marvel\Services\XmlImportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChunkedImportService
{
    protected const DEFAULT_CHUNK_SIZE = 10;
    protected const MAX_CHUNK_SIZE = 10;
    protected const MIN_CHUNK_SIZE = 5;

    /**
     * Запустить chunked импорт
     */
    public function startChunkedImport(
        string $content, 
        string $ext, 
        array $options = [], 
        array $fieldMapping = [],
        ?int $chunkSize = null
    ): array {
        try {
            // АВТОМАТИЧЕСКАЯ ПОДГОТОВКА: Очищаем старые незавершенные импорты
            $this->autoCleanup();
            
            // Определяем размер чанка
            $chunkSize = $this->calculateOptimalChunkSize($content, $ext, $chunkSize);
            
            // Подсчитываем общее количество товаров
            $totalProducts = $this->countProducts($content, $ext);
            $totalChunks = ceil($totalProducts / $chunkSize);
            
            // Генерируем токен для отслеживания
            $token = Str::uuid()->toString();
            
            Log::info('Starting chunked import', [
                'token' => $token,
                'total_products' => $totalProducts,
                'chunk_size' => $chunkSize,
                'total_chunks' => $totalChunks,
                'ext' => $ext,
                'content_size' => strlen($content)
            ]);

            // ИСПРАВЛЕНИЕ: Сохраняем файл на диск вместо передачи контента в каждую джобу
            $filePath = $this->saveContentToTempFile($content, $ext, $token);
            
            Log::info('Content saved to temp file', [
                'token' => $token,
                'file_path' => $filePath,
                'file_size' => filesize($filePath)
            ]);

            // Инициализируем статистику
            $this->initializeProgress($token, $totalChunks, $totalProducts);

            // Запускаем чанки в очередь
            for ($i = 0; $i < $totalChunks; $i++) {
                ChunkedXmlImportJob::dispatch(
                    $filePath,  // Передаем путь к файлу вместо контента
                    $ext,
                    $options,
                    $fieldMapping,
                    $token,
                    $i,
                    $chunkSize,
                    $totalChunks
                )->onQueue('default')->delay(now()->addMinutes($i));
                
                Log::info("Dispatched chunk {$i}/{$totalChunks} for token {$token}");
            }

            return [
                'success' => true,
                'token' => $token,
                'total_products' => $totalProducts,
                'chunk_size' => $chunkSize,
                'total_chunks' => $totalChunks,
                'message' => 'Chunked import started successfully'
            ];

        } catch (\Exception $e) {
            Log::error('Failed to start chunked import: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to start chunked import: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получить прогресс импорта
     */
    public function getImportProgress(string $token): array
    {
        $progressPath = storage_path('app/xml-import-progress/' . $token . '.json');
        $statsPath = storage_path('app/xml-import-stats/' . $token . '.json');

        if (!file_exists($progressPath) && !file_exists($statsPath)) {
            return [
                'success' => false,
                'message' => 'Import not found'
            ];
        }

        $progress = [];
        $stats = [];

        if (file_exists($progressPath)) {
            $progress = json_decode(file_get_contents($progressPath), true) ?: [];
        }

        if (file_exists($statsPath)) {
            $stats = json_decode(file_get_contents($statsPath), true) ?: [];
        }

        return [
            'success' => true,
            'progress' => $progress,
            'stats' => $stats
        ];
    }

    /**
     * Получить детальную статистику импорта
     */
    public function getImportStats(string $token): array
    {
        $statsPath = storage_path('app/xml-import-stats/' . $token . '.json');
        
        if (!file_exists($statsPath)) {
            return [
                'success' => false,
                'message' => 'Import stats not found'
            ];
        }

        $stats = json_decode(file_get_contents($statsPath), true) ?: [];
        
        // Добавляем информацию о чанках
        $chunksDir = storage_path('app/xml-import-chunks');
        if (is_dir($chunksDir)) {
            $chunkFiles = glob($chunksDir . '/' . $token . '_chunk_*.json');
            $stats['chunk_files_count'] = count($chunkFiles);
        }

        return [
            'success' => true,
            'stats' => $stats
        ];
    }

    /**
     * Очистить данные импорта
     */
    public function cleanupImport(string $token): bool
    {
        try {
            $paths = [
                storage_path('app/xml-import-progress/' . $token . '.json'),
                storage_path('app/xml-import-progress/' . $token . '.json.lock'),
                storage_path('app/xml-import-stats/' . $token . '.json'),
                storage_path('app/xml-import-stats/' . $token . '.json.lock'),
            ];

            $chunksDir = storage_path('app/xml-import-chunks');
            if (is_dir($chunksDir)) {
                $chunkFiles = glob($chunksDir . '/' . $token . '_chunk_*.json');
                $paths = array_merge($paths, $chunkFiles);
            }
            
            // Удаляем временный файл
            $tempFiles = glob(storage_path('app/xml-import-temp/' . $token . '.*'));
            if ($tempFiles) {
                $paths = array_merge($paths, $tempFiles);
            }

            foreach ($paths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup import: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Сохранить контент во временный файл
     */
    protected function saveContentToTempFile(string $content, string $ext, string $token): string
    {
        $dir = storage_path('app/xml-import-temp');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $filePath = $dir . '/' . $token . '.' . $ext;
        file_put_contents($filePath, $content);
        
        return $filePath;
    }

    /**
     * Рассчитать оптимальный размер чанка
     */
    public function calculateOptimalChunkSize(string $content, string $ext, ?int $requestedChunkSize = null): int
    {
        // Если размер запрошен явно, используем его (с ограничениями)
        if ($requestedChunkSize !== null) {
            return max(self::MIN_CHUNK_SIZE, min($requestedChunkSize, self::MAX_CHUNK_SIZE));
        }

        // Подсчитываем количество товаров
        $productCount = $this->countProducts($content, $ext);
        
        // Рассчитываем размер чанка на основе количества товаров (оптимизировано для памяти)
        return self::DEFAULT_CHUNK_SIZE;
    }

    /**
     * Подсчитать количество товаров в файле
     */
    public function countProducts(string $content, string $ext): int
    {
        try {
            if (in_array($ext, ['csv', 'txt'])) {
                return $this->countCsvProducts($content);
            } else {
                return $this->countXmlProducts($content);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to count products: ' . $e->getMessage());
            return 1000; // Fallback значение
        }
    }

    /**
     * Подсчитать товары в CSV
     */
    protected function countCsvProducts(string $content): int
    {
        return count(CsvImportReader::parse($content));
    }

    /**
     * Подсчитать товары в XML
     */
    protected function countXmlProducts(string $content): int
    {
        try {
            $xml = new \SimpleXMLElement($content);
            
            // Yandex.Market
            if (isset($xml->shop->offers->offer)) {
                return count($xml->shop->offers->offer);
            }
            
            // 1C
            if (isset($xml->КоммерческаяИнформация->Товары->Товар)) {
                return count($xml->КоммерческаяИнформация->Товары->Товар);
            }
            
            // Универсальный формат
            if (isset($xml->products->product)) {
                return count($xml->products->product);
            }
            
            if (isset($xml->items->item)) {
                return count($xml->items->item);
            }
            
            return 0;
        } catch (\Exception $e) {
            Log::warning('Failed to parse XML for counting: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Инициализировать прогресс импорта
     */
    protected function initializeProgress(string $token, int $totalChunks, int $totalProducts): void
    {
        $progressData = [
            'status' => 'queued',
            'total_chunks' => $totalChunks,
            'total_products' => $totalProducts,
            'chunks_completed' => 0,
            'progress_percent' => 0,
            'started_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $dir = storage_path('app/xml-import-progress');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        
        $path = $dir . '/' . $token . '.json';
        file_put_contents($path, json_encode($progressData));

        // Инициализируем статистику
        $statsData = [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'errors_list' => [],
            'chunks_completed' => 0,
            'total_chunks' => $totalChunks,
            'status' => 'queued',
            'started_at' => now()->toISOString(),
        ];

        $statsDir = storage_path('app/xml-import-stats');
        if (!is_dir($statsDir)) @mkdir($statsDir, 0777, true);
        
        $statsPath = $statsDir . '/' . $token . '.json';
        file_put_contents($statsPath, json_encode($statsData));
    }

    /**
     * Получить список активных импортов
     */
    public function getActiveImports(): array
    {
        $progressDir = storage_path('app/xml-import-progress');
        if (!is_dir($progressDir)) {
            return [];
        }

        $imports = [];
        $files = glob($progressDir . '/*.json');
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && in_array($data['status'], ['queued', 'processing'])) {
                $imports[] = [
                    'token' => basename($file, '.json'),
                    'status' => $data['status'],
                    'progress_percent' => $data['progress_percent'] ?? 0,
                    'total_products' => $data['total_products'] ?? 0,
                    'started_at' => $data['started_at'] ?? null,
                ];
            }
        }

        return $imports;
    }
    
    /**
     * Автоматическая очистка старых незавершенных импортов
     */
    protected function autoCleanup(): void
    {
        try {
            Log::info('Auto-cleanup: Starting automatic cleanup of old imports');
            
            // 1. Создаем необходимые директории если их нет
            $dirs = [
                storage_path('app/xml-import-temp'),
                storage_path('app/xml-import-progress'),
                storage_path('app/xml-import-stats'),
                storage_path('app/xml-import-chunks'),
            ];
            
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                    Log::info('Auto-cleanup: Created directory', ['dir' => $dir]);
                }
            }
            
            // 2. Находим и очищаем старые незавершенные импорты (старше 1 часа)
            $progressDir = storage_path('app/xml-import-progress');
            if (is_dir($progressDir)) {
                $files = glob($progressDir . '/*.json');
                $cleaned = 0;
                
                foreach ($files as $file) {
                    $data = json_decode(file_get_contents($file), true);
                    if ($data) {
                        $startedAt = isset($data['started_at']) ? strtotime($data['started_at']) : 0;
                        $age = time() - $startedAt;
                        
                        // Если импорт старше 1 часа и не завершен - очищаем
                        if ($age > 3600 && in_array($data['status'] ?? '', ['queued', 'processing', 'error'])) {
                            $token = basename($file, '.json');
                            $this->cleanupImport($token);
                            $cleaned++;
                            Log::info('Auto-cleanup: Cleaned old import', [
                                'token' => $token,
                                'age_minutes' => round($age / 60),
                                'status' => $data['status'] ?? 'unknown'
                            ]);
                        }
                    }
                }
                
                if ($cleaned > 0) {
                    Log::info('Auto-cleanup: Cleaned old imports', ['count' => $cleaned]);
                }
            }
            
            // 3. Очищаем старые временные файлы (старше 2 часов)
            $tempDir = storage_path('app/xml-import-temp');
            if (is_dir($tempDir)) {
                $tempFiles = glob($tempDir . '/*');
                $cleaned = 0;
                
                foreach ($tempFiles as $file) {
                    if (is_file($file)) {
                        $age = time() - filemtime($file);
                        if ($age > 7200) { // 2 часа
                            @unlink($file);
                            $cleaned++;
                        }
                    }
                }
                
                if ($cleaned > 0) {
                    Log::info('Auto-cleanup: Removed old temp files', ['count' => $cleaned]);
                }
            }
            
            Log::info('Auto-cleanup: Completed successfully');
            
        } catch (\Exception $e) {
            Log::warning('Auto-cleanup: Failed but continuing', [
                'error' => $e->getMessage()
            ]);
            // Не прерываем импорт если cleanup не удался
        }
    }
}



