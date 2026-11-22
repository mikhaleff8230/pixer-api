<?php

namespace Marvel\Http\Controllers;

use Marvel\Services\ElasticsearchService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SearchController extends CoreController
{
    protected ElasticsearchService $elasticsearch;

    public function __construct(ElasticsearchService $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * Main search endpoint
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:1',
            'type' => 'required|in:products,places,categories,shops',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'sort' => 'string|in:relevance,price_asc,price_desc,newest,popular,rating',
            'filters' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('q');
        $type = $request->input('type', 'products');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $sort = $request->input('sort', 'relevance');
        $filters = $request->input('filters', []);

        try {
            // Build Elasticsearch query
            $esQuery = $this->buildSearchQuery($query, $type, $sort, $filters);
            
            // Get index name
            $indexName = config("elasticsearch.indices.{$type}.name");
            
            // Calculate offset
            $from = ($page - 1) * $perPage;
            
            // Execute search
            $results = $this->elasticsearch->search($indexName, $esQuery, $from, $perPage);
            
            // Log search query for analytics
            if (config('elasticsearch.analytics.enabled')) {
                $this->logSearchQuery($query, $type, $results['hits']['total']['value'] ?? 0);
            }
            
            // Format response
            return response()->json([
                'success' => true,
                'data' => $this->formatSearchResults($results, $type),
                'pagination' => [
                    'total' => $results['hits']['total']['value'] ?? 0,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil(($results['hits']['total']['value'] ?? 0) / $perPage),
                ],
                'query' => $query,
                'type' => $type,
            ]);
        } catch (\Exception $e) {
            Log::error('Search error', [
                'query' => $query,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get search suggestions
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2',
            'type' => 'string|in:products,places',
            'limit' => 'integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('q');
        $type = $request->input('type', 'products');
        $limit = $request->input('limit', 5);

        try {
            $indexName = config("elasticsearch.indices.{$type}.name");
            
            // Build suggestions query
            $esQuery = $this->buildSuggestionsQuery($query, $type);
            
            // Execute search
            $results = $this->elasticsearch->search($indexName, $esQuery, 0, $limit);
            
            // Format suggestions with grouping
            $suggestions = [
                'products' => [],
                'tags' => [],
                'categories' => [],
            ];
            
            foreach ($results['hits']['hits'] as $hit) {
                $source = $hit['_source'];
                $score = $hit['_score'];
                $documentId = $source['id'] ?? $hit['_id'];
                
                if ($type === 'products') {
                    // Добавляем товары
                    $suggestions['products'][] = [
                        'text' => $source['name'],
                        'id' => $documentId,
                        'score' => $score,
                    ];
                    
                    // Извлекаем теги из товара
                    if (isset($source['tags']) && is_array($source['tags'])) {
                        foreach ($source['tags'] as $tag) {
                            if (stripos($tag['name'], $query) !== false) {
                                $suggestions['tags'][] = [
                                    'text' => $tag['name'],
                                    'id' => $tag['id'],
                                    'score' => $score * 0.8, // Немного меньше приоритет
                                ];
                            }
                        }
                    }
                    
                    // Извлекаем категории из товара
                    if (isset($source['categories']) && is_array($source['categories'])) {
                        foreach ($source['categories'] as $category) {
                            if (stripos($category['name'], $query) !== false) {
                                $suggestions['categories'][] = [
                                    'text' => $category['name'],
                                    'id' => $category['id'],
                                    'score' => $score * 0.6, // Еще меньше приоритет
                                ];
                            }
                        }
                    }
                } else {
                    // Для других типов
                    $suggestions['products'][] = [
                        'text' => $source['title'],
                        'id' => $documentId,
                        'score' => $score,
                    ];
                }
            }
            
            // Убираем дубликаты и сортируем по score
            foreach ($suggestions as $key => $items) {
                $suggestions[$key] = collect($items)
                    ->unique('text')
                    ->sortByDesc('score')
                    ->take(5)
                    ->pluck('text')
                    ->values()
                    ->toArray();
            }

            return response()->json([
                'success' => true,
                'suggestions' => $suggestions,
            ]);
        } catch (\Exception $e) {
            Log::error('Suggestions error', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Suggestions failed',
                'suggestions' => [],
            ], 500);
        }
    }

    /**
     * Autocomplete endpoint
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2',
            'type' => 'string|in:products,places',
            'limit' => 'integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('q');
        $type = $request->input('type', 'products');
        $limit = $request->input('limit', 10);

        try {
            $indexName = config("elasticsearch.indices.{$type}.name");
            
            // Build autocomplete query
            $esQuery = $this->buildAutocompleteQuery($query, $type);
            
            // Execute search
            $results = $this->elasticsearch->search($indexName, $esQuery, 0, $limit);
            
            // Format suggestions - возвращаем массив строк для горизонтальных подсказок
            // и массив объектов для детальных подсказок
            $suggestions = [];
            $textSuggestions = [];
            
            foreach ($results['hits']['hits'] as $hit) {
                $source = $hit['_source'];
                $documentId = $source['id'] ?? $hit['_id'];
                $text = $type === 'products' ? ($source['name'] ?? '') : ($source['title'] ?? '');
                
                // Добавляем объект с полной информацией
                $suggestions[] = [
                    'id' => $documentId,
                    'text' => $text,
                    'type' => $type,
                ];
                
                // Добавляем текст для горизонтальных подсказок (уникальные значения)
                if (!empty($text) && !in_array($text, $textSuggestions)) {
                    $textSuggestions[] = $text;
                }
            }

            // Возвращаем и объекты (для детальных подсказок) и строки (для горизонтальных)
            return response()->json([
                'success' => true,
                'suggestions' => $textSuggestions, // Массив строк для горизонтальных подсказок
                'items' => $suggestions, // Массив объектов для детальных подсказок
            ]);
        } catch (\Exception $e) {
            Log::error('Autocomplete error', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Autocomplete failed',
            ], 500);
        }
    }


    /**
     * Track search click for analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function trackClick(Request $request): JsonResponse
    {
        if (!config('elasticsearch.analytics.track_clicks')) {
            return response()->json(['success' => true]);
        }

        $validator = Validator::make($request->all(), [
            'query' => 'required|string',
            'result_id' => 'required|integer',
            'result_type' => 'required|string',
            'position' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Log click to database
        try {
            \DB::table('search_logs')->insert([
                'query' => $request->input('query'),
                'result_id' => $request->input('result_id'),
                'result_type' => $request->input('result_type'),
                'position' => $request->input('position', 0),
                'user_id' => auth()->id(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to track click', ['error' => $e->getMessage()]);
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Build Elasticsearch search query
     */
    protected function buildSearchQuery(string $query, string $type, string $sort, array $filters): array
    {
        $boosting = config("elasticsearch.search.boosting.{$type}", []);
        
        // Multi-match query with boosting
        $must = [
            [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $this->getSearchFields($type, $boosting),
                    'type' => 'best_fields',
                    'fuzziness' => 'AUTO',
                ],
            ],
        ];

        // Apply filters
        $filter = $this->buildFilters($type, $filters);

        // Build query
        $esQuery = [
            'query' => [
                'bool' => [
                    'must' => $must,
                    'filter' => $filter,
                ],
            ],
        ];

        // Add sorting
        if ($sort !== 'relevance') {
            $esQuery['sort'] = $this->buildSort($sort);
        }

        // Add aggregations for faceted search
        if ($type === 'products') {
            $esQuery['aggs'] = $this->buildAggregations();
        }

        return $esQuery;
    }

    /**
     * Build autocomplete query
     */
    protected function buildAutocompleteQuery(string $query, string $type): array
    {
        if ($type === 'products') {
            // Умный autocomplete для товаров: используем match_phrase_prefix для лучшего autocomplete
            return [
                'query' => [
                    'bool' => [
                        'should' => [
                            // Поиск по названию товара с autocomplete полем (высокий приоритет)
                            [
                                'match_phrase_prefix' => [
                                    'name.autocomplete' => [
                                        'query' => $query,
                                        'max_expansions' => 10,
                                        'boost' => 3.0,
                                    ],
                                ],
                            ],
                            // Fallback на обычное поле name
                            [
                                'match_phrase_prefix' => [
                                    'name' => [
                                        'query' => $query,
                                        'max_expansions' => 10,
                                        'boost' => 2.5,
                                    ],
                                ],
                            ],
                            // Поиск по тегам (высокий приоритет для autocomplete)
                            [
                                'nested' => [
                                    'path' => 'tags',
                                    'query' => [
                                        'wildcard' => [
                                            'tags.name' => [
                                                'value' => mb_strtolower($query) . '*',
                                                'case_insensitive' => true,
                                                'boost' => 2.0,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            // Поиск по категориям
                            [
                                'nested' => [
                                    'path' => 'categories',
                                    'query' => [
                                        'match_phrase_prefix' => [
                                            'categories.name' => [
                                                'query' => $query,
                                                'max_expansions' => 5,
                                                'boost' => 1.5,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
                'sort' => [
                    '_score' => ['order' => 'desc'],
                ],
            ];
        } else {
            // Для других типов используем простой поиск
            $field = 'title';
            return [
                'query' => [
                    'match_phrase_prefix' => [
                        $field => [
                            'query' => $query,
                            'max_expansions' => 10,
                        ],
                    ],
                ],
            ];
        }
    }

    /**
     * Get search fields with boosting
     */
    protected function getSearchFields(string $type, array $boosting): array
    {
        $fields = [];
        
        foreach ($boosting as $field => $boost) {
            $fields[] = "{$field}^{$boost}";
        }

        return $fields ?: ['*'];
    }

    /**
     * Build filters
     */
    protected function buildFilters(string $type, array $filters): array
    {
        $filter = [];

        // Default filters from config
        $defaultFilters = config("elasticsearch.search.default_filters.{$type}", []);
        foreach ($defaultFilters as $field => $value) {
            $filter[] = ['term' => [$field => $value]];
        }

        // User-provided filters
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $filter[] = ['terms' => [$field => $value]];
            } else {
                $filter[] = ['term' => [$field => $value]];
            }
        }

        // Price range filter
        if (isset($filters['price_min']) || isset($filters['price_max'])) {
            $range = [];
            if (isset($filters['price_min'])) {
                $range['gte'] = (float) $filters['price_min'];
            }
            if (isset($filters['price_max'])) {
                $range['lte'] = (float) $filters['price_max'];
            }
            $filter[] = ['range' => ['price' => $range]];
        }

        return $filter;
    }

    /**
     * Build sort
     */
    protected function buildSort(string $sort): array
    {
        return match($sort) {
            'price_asc' => [['price' => 'asc']],
            'price_desc' => [['price' => 'desc']],
            'newest' => [['created_at' => 'desc']],
            'popular' => [['total_reviews' => 'desc']],
            'rating' => [['ratings' => 'desc']],
            default => [],
        };
    }

    /**
     * Build aggregations for faceted search
     */
    protected function buildAggregations(): array
    {
        return [
            'categories' => [
                'nested' => ['path' => 'categories'],
                'aggs' => [
                    'category_ids' => [
                        'terms' => ['field' => 'categories.id', 'size' => 50],
                    ],
                ],
            ],
            'price_ranges' => [
                'range' => [
                    'field' => 'price',
                    'ranges' => [
                        ['to' => 1000],
                        ['from' => 1000, 'to' => 5000],
                        ['from' => 5000, 'to' => 10000],
                        ['from' => 10000, 'to' => 50000],
                        ['from' => 50000],
                    ],
                ],
            ],
            'in_stock' => [
                'terms' => ['field' => 'in_stock'],
            ],
        ];
    }

    /**
     * Format search results
     */
    protected function formatSearchResults(array $results, string $type): array
    {
        $data = [
            'items' => [],
            'aggregations' => [],
        ];

        // Format hits
        foreach ($results['hits']['hits'] as $hit) {
            $item = $hit['_source'];
            $item['id'] = $item['id'] ?? $hit['_id'];
            $item['score'] = $hit['_score'];
            $data['items'][] = $item;
        }

        // Format aggregations
        if (isset($results['aggregations'])) {
            $data['aggregations'] = $this->formatAggregations($results['aggregations']);
        }

        return $data;
    }

    /**
     * Format aggregations
     */
    protected function formatAggregations(array $aggregations): array
    {
        $formatted = [];

        foreach ($aggregations as $name => $agg) {
            if (isset($agg['buckets'])) {
                $formatted[$name] = $agg['buckets'];
            } elseif (isset($agg['category_ids']['buckets'])) {
                $formatted[$name] = $agg['category_ids']['buckets'];
            }
        }

        return $formatted;
    }

    /**
     * Log search query for analytics
     */
    protected function logSearchQuery(string $query, string $type, int $resultsCount): void
    {
        try {
            \DB::table('search_logs')->insert([
                'query' => $query,
                'type' => $type,
                'results_count' => $resultsCount,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log search query', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build suggestions query for Elasticsearch
     */
    protected function buildSuggestionsQuery(string $query, string $type): array
    {
        if ($type === 'products') {
            // Умный поиск по товарам: название + теги + категории
            return [
                'query' => [
                    'bool' => [
                        'should' => [
                            // Поиск по названию товара (высокий приоритет)
                            [
                                'match_phrase_prefix' => [
                                    'name' => [
                                        'query' => $query,
                                        'max_expansions' => 10,
                                        'boost' => 3.0,
                                    ],
                                ],
                            ],
                            // Поиск по тегам
                            [
                                'nested' => [
                                    'path' => 'tags',
                                    'query' => [
                                        'wildcard' => [
                                            'tags.name' => [
                                                'value' => mb_strtolower($query) . '*',
                                                'case_insensitive' => true,
                                                'boost' => 2.0,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            // Поиск по категориям
                            [
                                'nested' => [
                                    'path' => 'categories',
                                    'query' => [
                                        'match_phrase_prefix' => [
                                            'categories.name' => [
                                                'query' => $query,
                                                'max_expansions' => 5,
                                                'boost' => 1.5,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            // Поиск по описанию (низкий приоритет)
                            [
                                'match_phrase_prefix' => [
                                    'description' => [
                                        'query' => $query,
                                        'max_expansions' => 5,
                                        'boost' => 0.5,
                                    ],
                                ],
                            ],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
                'sort' => [
                    '_score' => ['order' => 'desc'],
                ],
            ];
        } else {
            // Для других типов используем простой поиск
            $field = 'title';
            return [
                'query' => [
                    'match_phrase_prefix' => [
                        $field => [
                            'query' => $query,
                            'max_expansions' => 10,
                        ],
                    ],
                ],
                'sort' => [
                    '_score' => ['order' => 'desc'],
                ],
            ];
        }
    }
}



