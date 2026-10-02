<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Place;
use Marvel\Database\Models\PlaceImage;
use Marvel\Database\Models\PlaceVideo;
use Marvel\Database\Models\Hashtag;
use Marvel\Database\Models\Product;
use Marvel\Http\Resources\PlaceResource;
use Marvel\Http\Resources\PlaceFeedResource;
use Marvel\Http\Controllers\CoreController;
use Marvel\Http\Queries\PlaceFeedQuery;
use Marvel\Http\Queries\PlaceSimilarQuery;
// TODO: Завтра добавить систему slug'ов для хештегов
// Временная заглушка для функции translitToLatin
/*
if (!function_exists('translitToLatin')) {
    function translitToLatin($string)
    {
        $converter = [
            'а' => 'a',   'б' => 'b',   'в' => 'v',   'г' => 'g',   'д' => 'd',
            'е' => 'e',   'ё' => 'yo',  'ж' => 'zh',  'з' => 'z',   'и' => 'i',
            'й' => 'y',   'к' => 'k',   'л' => 'l',   'м' => 'm',   'н' => 'n',
            'о' => 'o',   'п' => 'p',   'р' => 'r',   'с' => 's',   'т' => 't',
            'у' => 'u',   'ф' => 'f',   'х' => 'h',   'ц' => 'ts',  'ч' => 'ch',
            'ш' => 'sh',  'щ' => 'sch', 'ъ' => '',    'ы' => 'y',   'ь' => '',
            'э' => 'e',   'ю' => 'yu',  'я' => 'ya',
            'А' => 'A',   'Б' => 'B',   'В' => 'V',   'Г' => 'G',   'Д' => 'D',
            'Е' => 'E',   'Ё' => 'Yo',  'Ж' => 'Zh',  'З' => 'Z',   'И' => 'I',
            'Й' => 'Y',   'К' => 'K',   'Л' => 'L',   'М' => 'M',   'Н' => 'N',
            'О' => 'O',   'П' => 'P',   'Р' => 'R',   'С' => 'S',   'Т' => 'T',
            'У' => 'U',   'Ф' => 'F',   'Х' => 'H',   'Ц' => 'Ts',  'Ч' => 'Ch',
            'Ш' => 'Sh',  'Щ' => 'Sch', 'Ъ' => '',    'Ы' => 'Y',   'Ь' => '',
            'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
        ];
        return strtr($string, $converter);
    }
}
*/

class PlaceController extends CoreController
{
    // === МЕТОДЫ ДЛЯ CANONICAL URL ===
    protected function generateCanonicalUrl($place, Request $request): string
    {
        $baseUrl = rtrim(config('app.url', 'https://sancan.ru'), '/');
        
        // Получаем id
        if (is_array($place)) {
            $id = $place['id'] ?? '';
        } else {
            $id = $place->id ?? '';
        }
        
        if (!$id) {
            \Log::warning('PlaceController::generateCanonicalUrl - missing id', [
                'id' => $id,
            ]);
            return $baseUrl . '/places';
        }
        
        // Канонический URL для плейсов: /places/{id}
        $canonical = "{$baseUrl}/places/{$id}";
        
        \Log::info('PlaceController::generateCanonicalUrl result', [
            'canonical' => $canonical,
            'place_id' => $id,
        ]);
        
        return $canonical;
    }

    protected function generateHreflangTags($place, Request $request): array
    {
        $baseUrl = rtrim(config('app.url', 'https://sancan.ru'), '/');
        
        // Получаем id
        if (is_array($place)) {
            $id = $place['id'] ?? '';
        } else {
            $id = $place->id ?? '';
        }
        
        if (!$id) {
            return [];
        }
        
        // Плейсы не имеют языковых версий, поэтому все языки указывают на один URL без префикса
        $canonicalUrl = "{$baseUrl}/places/{$id}";
        
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

    public function index(Request $request)
    {
        $query = Place::with(['images', 'videos', 'hashtags', 'user', 'community', 'likes', 'products', 'wishlists'])->latest();

        // Лимит
        $limit = $request->get('limit', 20);

        // Фильтрация по тегу (по name или slug)
        if ($request->has('tag')) {
            $tag = $request->get('tag');
            $query->whereHas('hashtags', function ($q) use ($tag) {
                $q->where('name', $tag)->orWhere('slug', $tag);
            });
        }
        
        // Фильтрация по slug хэштега
        if ($request->has('hashtag_slug')) {
            $hashtagSlug = $request->get('hashtag_slug');
            $query->whereHas('hashtags', function ($q) use ($hashtagSlug) {
                $q->where('slug', $hashtagSlug);
            });
        }

        // Фильтрация по пользователю
        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Фильтрация по лайкнутым плейсам (liked_by)
        if ($request->has('liked_by')) {
            $userId = $request->get('liked_by');
            $query->whereHas('likes', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }

        // Фильтрация по избранным плейсам (favorited_by)
        if ($request->has('favorited_by')) {
            $userId = $request->get('favorited_by');
            $query->whereHas('wishlists', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }

        $places = $query->paginate($limit);

        // Отладка
        Log::info('PlaceController::index - запрос плейсов', [
            'limit' => $limit,
            'total_places' => $places->total(),
            'current_page' => $places->currentPage(),
            'per_page' => $places->perPage(),
            'has_data' => $places->count() > 0,
        ]);

        return PlaceResource::collection($places);
    }

    /**
     * Получить фид плейсов для бесконечной прокрутки (cursor-based pagination)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function feed(Request $request)
    {
        try {
            Log::info('PlaceController::feed - начало выполнения', [
                'request_all' => $request->all(),
                'method' => $request->method(),
                'url' => $request->url(),
            ]);

            $limit = $request->get('limit', 20);
            $cursor = $request->get('cursor');

            Log::info('PlaceController::feed - параметры', [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);

            // Валидация параметров
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'cursor' => 'nullable|string',
                'hashtag_slug' => 'nullable|string|max:100',
            ]);

            // Предварительная обработка hashtag_slug
            $hashtagSlug = null;
            if ($request->has('hashtag_slug')) {
                $hashtagSlugRaw = $request->get('hashtag_slug');

                // Очищаем slug от возможных артефактов курсора (например :1 в конце)
                $hashtagSlug = trim($hashtagSlugRaw);
                // Удаляем все после двоеточия, если оно есть
                if (strpos($hashtagSlug, ':') !== false) {
                    $hashtagSlug = explode(':', $hashtagSlug)[0];
                }

                Log::info('PlaceController::feed - обработка hashtag_slug', [
                    'raw' => $hashtagSlugRaw,
                    'cleaned' => $hashtagSlug,
                ]);
            }

            // Создаем запрос через PlaceFeedQuery
            $queryParams = [
                'limit' => $limit,
                'cursor' => $cursor,
            ];

            // Фильтрация по slug хэштега (используем уже очищенный hashtagSlug)
            if ($hashtagSlug) {
                $queryParams['hashtag_slug'] = $hashtagSlug;
            }

            $query = PlaceFeedQuery::query($queryParams);

            Log::info('PlaceController::feed - запрос создан, выполняем get()');

            $places = $query->get();

            // Определяем следующий курсор
            $nextCursor = null;
            if ($places->count() >= $limit) {
                $lastPlace = $places->last();
                $nextCursor = PlaceFeedQuery::createCursor($lastPlace);
            }

            // Определяем есть ли еще данные
            $hasMore = $nextCursor !== null;

            Log::info('PlaceController::feed - выполнен запрос', [
                'limit' => $limit,
                'cursor' => $cursor,
                'places_count' => $places->count(),
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ]);

            try {
                $resourceData = PlaceFeedResource::collection($places);

                Log::info('PlaceController::feed - ресурсы созданы', [
                    'places_count' => $places->count(),
                    'resource_count' => $resourceData->count(),
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $resourceData,
                    'meta' => [
                        'next_cursor' => $nextCursor,
                        'has_more' => $hasMore,
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('PlaceController::feed - ошибка создания ресурсов', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'places_count' => $places->count(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка обработки данных',
                    'data' => [],
                    'meta' => [
                        'next_cursor' => null,
                        'has_more' => false,
                    ],
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('PlaceController::feed - критическая ошибка', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_params' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении фида плейсов',
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ], 500);
        }
    }

    /**
     * Получить избранные плейсы пользователя (page-based pagination)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function favorites(Request $request)
    {
        try {
            Log::info('PlaceController::favorites - начало выполнения', [
                'request_all' => $request->all(),
                'method' => $request->method(),
                'url' => $request->url(),
            ]);

            $limit = $request->get('limit', 20);
            $page = $request->get('page', 1);
            $favoritedBy = $request->get('favorited_by');

            Log::info('PlaceController::favorites - параметры', [
                'limit' => $limit,
                'page' => $page,
                'favorited_by' => $favoritedBy,
            ]);

            // Валидация
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'favorited_by' => 'required|integer|min:1',
            ]);

            // Получаем избранные плейсы пользователя
            $query = Place::query()
                ->select([
                    'id', 'title', 'slug', 'user_id', 'created_at',
                ])
                ->whereHas('wishlists', function ($q) use ($favoritedBy) {
                    $q->where('user_id', $favoritedBy);
                })
                ->orderBy('created_at', 'desc')
                ->with([
                    'images' => function ($q) {
                        $q->select(['id', 'place_id', 'url'])
                          ->orderBy('id', 'asc');
                    },
                    'user' => function ($q) {
                        $q->select(['id', 'name'])
                          ->with(['profile' => function ($profileQuery) {
                              $profileQuery->select(['id', 'customer_id', 'avatar']);
                          }]);
                    },
                    'hashtags' => function ($q) {
                        $q->select(['hashtags.id', 'hashtags.name', 'hashtags.slug']);
                    },
                ])
                ->withCount(['likes', 'comments']);

            $places = $query->paginate($limit, ['*'], 'page', $page);

            Log::info('PlaceController::favorites - запрос выполнен', [
                'favorited_by' => $favoritedBy,
                'total_places' => $places->total(),
                'current_page' => $places->currentPage(),
                'per_page' => $places->perPage(),
            ]);

            try {
                $resourceData = PlaceFeedResource::collection($places->items());

                return response()->json([
                    'success' => true,
                    'data' => $resourceData,
                    'meta' => [
                        'current_page' => $places->currentPage(),
                        'per_page' => $places->perPage(),
                        'total' => $places->total(),
                        'last_page' => $places->lastPage(),
                        'has_more' => $places->hasMorePages(),
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('PlaceController::favorites - ошибка создания ресурсов', [
                    'error' => $e->getMessage(),
                    'favorited_by' => $favoritedBy,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка обработки данных избранных плейсов',
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'per_page' => $limit,
                        'total' => 0,
                        'last_page' => 1,
                        'has_more' => false,
                    ],
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('PlaceController::favorites - критическая ошибка', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_params' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении избранных плейсов',
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ], 500);
        }
    }

    /**
     * Показать Place с поддержкой SEO-URL формата {slug}-{id}
     * 
     * @param Request $request
     * @param string $slugId Формат: "{slug}-{id}" или просто "{id}" (для обратной совместимости)
     */
    public function show(Request $request, $slugId)
    {
        // Пытаемся распарсить slug-id
        $parsed = Place::parseSlugId($slugId);
        
        if ($parsed) {
            // Новый формат: {slug}-{id}
            $slug = $parsed['slug'];
            $id = $parsed['id'];
            
            $result = Place::findBySlugOrHistory($slug, $id);
            
            if (!$result) {
                abort(404, 'Place not found');
            }
            
            $place = $result['place'];
            $needsRedirect = $result['redirect'];
            
            // Если нужен редирект на актуальный URL
            if ($needsRedirect) {
                return redirect($place->url, 301);
            }
            
            // Загружаем связи
            $place->load(['images', 'videos', 'hashtags', 'user.profile', 'likes', 'products', 'wishlists']);
            
            // Генерируем canonical и meta данные
            $canonicalUrl = $this->generateCanonicalUrl($place, $request);
            $hreflangTags = $this->generateHreflangTags($place, $request);
            
            // Получаем данные плейса через ресурс
            $placeData = (new PlaceResource($place))->toArray($request);
            
            // Получаем первое изображение для OG
            $ogImage = null;
            if (!empty($placeData['images']) && is_array($placeData['images']) && count($placeData['images']) > 0) {
                $firstImage = $placeData['images'][0];
                $ogImage = $firstImage['url'] ?? $firstImage['thumbnail'] ?? null;
            }
            
            // Возвращаем структурированный ответ с meta
            return response()->json([
                'success' => true,
                'data' => $placeData,
                'meta' => [
                    'canonical' => $canonicalUrl,
                    'hreflang' => $hreflangTags,
                    'title' => $placeData['title'] ?? '',
                    'description' => isset($placeData['description']) 
                        ? mb_substr(strip_tags($placeData['description']), 0, 160) 
                        : '',
                    'og_image' => $ogImage,
                    'og_type' => 'article',
                ]
            ]);
        } else {
            // Старый формат: просто ID (для обратной совместимости)
            // Редиректим на новый формат
            $place = Place::with(['images', 'videos', 'hashtags', 'user.profile', 'community', 'likes', 'products', 'wishlists'])->findOrFail($slugId);
            return redirect($place->url, 301);
        }
    }

    public function store(Request $request)
    {
        // Логирование входящих данных для отладки
        $allFiles = $request->allFiles();
        Log::info('PlaceController::store - входящие данные', [
            'has_images' => $request->hasFile('images'),
            'images_count' => $request->hasFile('images') ? (is_array($request->file('images')) ? count($request->file('images')) : 1) : 0,
            'all_files' => array_keys($allFiles),
            'images_files' => $request->file('images') ? (is_array($request->file('images')) ? count($request->file('images')) : 'single file') : 'no files',
            'images_type' => $request->file('images') ? gettype($request->file('images')) : 'null',
            'all_files_images' => isset($allFiles['images']) ? (is_array($allFiles['images']) ? count($allFiles['images']) : 'single') : 'not set',
        ]);

        try {
            // Валидация - Laravel должен автоматически обработать images[] как массив
            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'images' => 'nullable|array|max:20',
                'images.*' => 'file|image|max:5120', // до 5 МБ на файл
                'video' => 'nullable|file|mimetypes:video/mp4,video/webm|max:40960', // до 40 МБ
                'videos' => 'nullable|array|max:20',
                'videos.*' => 'file|mimetypes:video/mp4,video/webm|max:40960',
                'hashtags' => 'nullable|array',
                'hashtags.*' => 'string|max:50',
                'product_ids' => 'nullable|array',
                'product_ids.*' => 'exists:products,id',
                'community_id' => 'nullable|exists:communities,id',
                'location' => 'nullable|string|max:255',
                'alt_text' => 'nullable|string|max:1000',
                'allow_comments' => 'nullable|boolean',
            ], [
                'images.max' => 'Максимальное количество медиафайлов - 20',
                'images.*.max' => 'Размер каждого изображения не должен превышать 5 МБ',
                'images.*.image' => 'Файл должен быть изображением',
                'video.max' => 'Объем файла должен быть не больше 40 мб',
                'video.mimetypes' => 'Видео должно быть в формате MP4 или WebM',
            ]);

            $mediaCount = count($request->file('images', []))
                + count($request->file('videos', []))
                + ($request->hasFile('video') ? 1 : 0);
            if ($mediaCount > 20) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'media' => ['Максимальное количество фото и видео - 20'],
                ]);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('PlaceController::store - ошибка валидации', [
                'errors' => $e->errors(),
                'message' => $e->getMessage(),
                'input' => $request->all(),
                'files' => $request->allFiles(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('PlaceController::store - неизвестная ошибка при валидации', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->all(),
                'files' => $request->allFiles(),
            ]);
            throw $e;
        }

        try {
            if (!empty($data['community_id'])) {
                $community = \Marvel\Database\Models\Community::where('status', 'active')->findOrFail($data['community_id']);
                $profile = $request->user()->profile;
                $isActiveMember = $profile && $community->members()
                    ->where('user_profiles.id', $profile->id)
                    ->wherePivot('status', 'active')
                    ->exists();
                if ($community->posting_policy === 'members_only' && !$isActiveMember) {
                    abort(403, 'Join the community before publishing');
                }
            }

            $place = Place::create([
                'user_id' => $request->user()->id,
                'community_id' => $data['community_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'alt_text' => $data['alt_text'] ?? null,
                'allow_comments' => $data['allow_comments'] ?? true,
            ]);

            // Сохраняем изображения (в S3)
            // Обрабатываем массив файлов images[] или images[0], images[1], etc.
            $imageFiles = $request->file('images');
            
            // Детальное логирование для диагностики
            $allFilesDebug = $request->allFiles();
            Log::info('PlaceController::store - обработка изображений', [
                'has_file_images' => $request->hasFile('images'),
                'file_images_type' => $imageFiles ? gettype($imageFiles) : 'null',
                'file_images_is_array' => $imageFiles ? is_array($imageFiles) : false,
                'file_images_count' => $imageFiles ? (is_array($imageFiles) ? count($imageFiles) : 1) : 0,
                'all_files_keys' => array_keys($allFilesDebug),
                'all_files_images_count' => isset($allFilesDebug['images']) ? (is_array($allFilesDebug['images']) ? count($allFilesDebug['images']) : 1) : 0,
                'all_files_images_keys' => isset($allFilesDebug['images']) && is_array($allFilesDebug['images']) ? array_keys($allFilesDebug['images']) : 'not array',
                'all_files_structure' => array_map(function($key, $value) {
                    return [
                        'key' => $key,
                        'type' => gettype($value),
                        'is_array' => is_array($value),
                        'count' => is_array($value) ? count($value) : 1,
                    ];
                }, array_keys($allFilesDebug), $allFilesDebug),
            ]);
            
            // Всегда проверяем allFiles() для надежности, даже если file('images') вернул что-то
            $allFiles = $request->allFiles();
            
            // Проверяем формат images[] (стандартный) - это приоритетный способ
            if (isset($allFiles['images'])) {
                if (is_array($allFiles['images'])) {
                    // Если это массив файлов, используем его
                    $imageFilesFromAllFiles = $allFiles['images'];
                    Log::info('PlaceController::store - найдены файлы в allFiles[images] (массив)', [
                        'count' => count($imageFilesFromAllFiles),
                    ]);
                    // Используем файлы из allFiles, если их больше или если file('images') не вернул массив
                    if (!$imageFiles || !is_array($imageFiles) || count($imageFilesFromAllFiles) > count($imageFiles)) {
                        $imageFiles = $imageFilesFromAllFiles;
                        Log::info('PlaceController::store - используем файлы из allFiles[images]', [
                            'count' => count($imageFiles),
                        ]);
                    }
                } else {
                    // Если это один файл, преобразуем в массив
                    if (!$imageFiles || !is_array($imageFiles)) {
                        $imageFiles = [$allFiles['images']];
                        Log::info('PlaceController::store - один файл из allFiles[images] преобразован в массив');
                    }
                }
            }
            
            // Если images не является массивом или пустой, пытаемся собрать из всех файлов
            if (!$imageFiles || !is_array($imageFiles) || empty($imageFiles)) {
                Log::info('PlaceController::store - файлы images не найдены через file() или allFiles[images], проверяем все файлы', [
                    'all_files_keys' => array_keys($allFiles),
                ]);
                
                // Проверяем формат images[0], images[1], etc. (старый формат)
                $imageFilesFromKeys = [];
                foreach ($allFiles as $key => $file) {
                    if (preg_match('/^images\[(\d+)\]$/', $key, $matches)) {
                        $imageFilesFromKeys[(int)$matches[1]] = $file;
                    }
                }
                if (!empty($imageFilesFromKeys)) {
                    ksort($imageFilesFromKeys);
                    $imageFiles = array_values($imageFilesFromKeys);
                    Log::info('PlaceController::store - найдены файлы в формате images[0], images[1]', [
                        'count' => count($imageFiles),
                    ]);
                }
            } elseif (!is_array($imageFiles)) {
                // Если это один файл, преобразуем в массив
                $imageFiles = [$imageFiles];
                Log::info('PlaceController::store - один файл преобразован в массив');
            }
            
            // Финальная проверка - убеждаемся, что у нас массив
            if (!is_array($imageFiles)) {
                $imageFiles = $imageFiles ? [$imageFiles] : [];
            }
            
            // Детальное логирование перед обработкой
            if (!empty($imageFiles)) {
                Log::info('PlaceController::store - начинаем обработку изображений', [
                    'total_files' => count($imageFiles),
                    'files_info' => array_map(function($file, $index) {
                        return [
                            'index' => $index,
                            'is_null' => $file === null,
                            'is_valid' => $file ? $file->isValid() : false,
                            'name' => $file ? $file->getClientOriginalName() : 'null',
                            'size' => $file ? $file->getSize() : 0,
                            'error' => $file ? $file->getError() : 'file is null',
                        ];
                    }, $imageFiles, array_keys($imageFiles)),
                ]);
            }
            
            if (!empty($imageFiles) && is_array($imageFiles)) {
                $savedCount = 0;
                $errorCount = 0;
                
                foreach ($imageFiles as $index => $file) {
                    if ($file && $file->isValid()) {
                        try {
                            $key = 'places/images/' . uniqid('', true) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                            Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
                                'visibility' => 'public',
                                'CacheControl' => 'public, max-age=31536000, immutable',
                                'ContentType' => $file->getMimeType() ?: 'image/jpeg',
                            ]);
                            PlaceImage::create(['place_id' => $place->id, 'url' => $key]);
                            $savedCount++;
                            Log::info('PlaceController::store - изображение сохранено', [
                                'place_id' => $place->id,
                                'index' => $index,
                                'file_name' => $file->getClientOriginalName(),
                                's3_key' => $key,
                                'file_size' => $file->getSize(),
                                'saved_count' => $savedCount,
                            ]);
                        } catch (\Exception $e) {
                            $errorCount++;
                            Log::error('PlaceController::store - ошибка сохранения изображения', [
                                'place_id' => $place->id,
                                'index' => $index,
                                'file_name' => $file->getClientOriginalName(),
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                                'error_count' => $errorCount,
                            ]);
                            // Продолжаем обработку других файлов, но логируем ошибку
                        }
                    } else {
                        $errorCount++;
                        Log::warning('PlaceController::store - невалидный файл изображения', [
                            'place_id' => $place->id,
                            'index' => $index,
                            'is_file' => $file !== null,
                            'is_valid' => $file ? $file->isValid() : false,
                            'error' => $file ? $file->getError() : 'file is null',
                            'error_count' => $errorCount,
                        ]);
                    }
                }
                
                Log::info('PlaceController::store - обработка изображений завершена', [
                    'place_id' => $place->id,
                    'total_files' => count($imageFiles),
                    'saved_count' => $savedCount,
                    'error_count' => $errorCount,
                ]);
            } else {
                Log::info('PlaceController::store - изображения не переданы или пустой массив', [
                    'place_id' => $place->id,
                    'imageFiles_empty' => empty($imageFiles),
                    'imageFiles_is_array' => is_array($imageFiles),
                ]);
            }

            // Сохраняем видео (в S3)
            $videoFiles = $request->file('videos', []);
            if ($request->hasFile('video')) {
                $videoFiles[] = $request->file('video');
            }

            foreach ($videoFiles as $file) {
                Log::info('PlaceController::store - сохраняем видео', [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
                $key = 'places/videos/' . uniqid('', true) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=86400',
                    'ContentType' => $file->getMimeType() ?: 'video/mp4',
                ]);
                $videoRecord = PlaceVideo::create(['place_id' => $place->id, 'url' => $key]);
                Log::info('PlaceController::store - видео сохранено', [
                    'video_id' => $videoRecord->id,
                    'video_url' => $videoRecord->url,
                    's3_key' => $key,
                ]);
                
                // Генерируем превью, постер и thumbnail
                try {
                    $tempPath = storage_path('app/temp/' . uniqid('place_video_') . '_' . basename($key));
                    $dir = dirname($tempPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($tempPath, Storage::disk('s3')->get($key));
                    \Marvel\Helpers\PlaceVideoOptimizer::optimizeVideo($videoRecord, $tempPath);
                    Log::info('PlaceController::store - превью видео сгенерировано успешно', [
                        'video_id' => $videoRecord->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('PlaceController::store - ошибка генерации превью видео', [
                        'video_id' => $videoRecord->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Не прерываем создание плейса, если генерация превью не удалась
                }
            }

            if (empty($videoFiles)) {
                Log::info('PlaceController::store - видео файл не найден');
            }

            // Обрабатываем хештеги
            if ($request->has('hashtags') && is_array($request->input('hashtags'))) {
                $hashtagIds = [];
                foreach ($request->input('hashtags') as $tag) {
                    if (is_string($tag) && !empty(trim($tag))) {
                        $tagName = trim($tag);
                        // Убираем # если есть
                        $tagName = ltrim($tagName, '#');
                        if (!empty($tagName)) {
                            $hashtag = Hashtag::firstOrCreate(
                                ['name' => $tagName],
                                ['slug' => \Illuminate\Support\Str::slug($tagName)]
                            );
                            $hashtagIds[] = $hashtag->id;
                        }
                    }
                }
                if (!empty($hashtagIds)) {
                    $place->hashtags()->sync($hashtagIds);
                }
            }

            // Привязка товаров
            if (!empty($data['product_ids'])) {
                $place->products()->sync($data['product_ids']);
            }

            return new PlaceResource($place->fresh(['images', 'videos', 'hashtags', 'user.profile', 'community', 'likes', 'products']));
        } catch (\Exception $e) {
            Log::error('PlaceController::store - критическая ошибка при создании плейса', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->all(),
                'files' => $request->allFiles(),
            ]);
            throw $e;
        }
    }

    public function update(Request $request, $id)
    {
        $place = Place::findOrFail($id);
        $this->authorize('update', $place);

        Log::info('PlaceController::update - входящие данные', [
            'place_id' => $id,
            'user_id' => $request->user()->id ?? 'не авторизован',
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'has_images' => $request->hasFile('images'),
            'images_count' => $request->hasFile('images') ? count($request->file('images')) : 0,
            'has_existing_images' => $request->has('existing_images'),
            'existing_images_count' => $request->has('existing_images') ? count($request->input('existing_images', [])) : 0,
            'has_video' => $request->hasFile('video'),
            'has_existing_video' => $request->has('existing_video'),
            // 'hashtags' => $request->input('hashtags'),
            'product_ids' => $request->input('product_ids'),
            'all_input' => $request->all(),
            'files' => $request->allFiles(),
            'images_files' => $request->file('images'),
            'existing_images_input' => $request->input('existing_images'),
        ]);

        // Детальное логирование всех данных запроса
        Log::info('PlaceController::update - детали запроса', [
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
            'url' => $request->url(),
            'all_headers' => $request->headers->all(),
            'all_post_data' => $request->post(),
            'all_files' => $request->allFiles(),
            'images_array' => $request->file('images'),
            'images_array_type' => gettype($request->file('images')),
            'images_array_count' => is_array($request->file('images')) ? count($request->file('images')) : 'not array',
        ]);

        // Упрощенная валидация для отладки
        try {
            $data = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'images' => 'nullable|array',
                'images.*' => 'file|image|max:5120', // новые файлы (если есть)
                'existing_images' => 'nullable|array',
                'existing_images.*' => 'string', // существующие URL (если есть)
                'video' => 'nullable|file|mimetypes:video/mp4,video/webm|max:40960', // до 40 МБ
                'existing_video' => 'nullable|string',
                'hashtags' => 'nullable|array',
                'hashtags.*' => 'string|max:50',
                'product_ids' => 'nullable|array',
                'product_ids.*' => 'exists:products,id',
                'community_id' => 'nullable|exists:communities,id',
                'location' => 'nullable|string|max:255',
                'alt_text' => 'nullable|string|max:1000',
                'allow_comments' => 'nullable|boolean',
            ], [
                'video.max' => 'Объем файла должен быть не больше 40 мб',
                'video.mimetypes' => 'Видео должно быть в формате MP4 или WebM',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('PlaceController::update - ошибка валидации', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
                'files' => $request->allFiles(),
            ]);
            throw $e;
        }

        // Дополнительное логирование для отладки
        Log::info('PlaceController::update - после валидации', [
            'validated_data' => $data,
            'has_images' => $request->hasFile('images'),
            'images_count' => $request->hasFile('images') ? count($request->file('images')) : 0,
            'has_existing_images' => $request->has('existing_images'),
            'existing_images_count' => $request->has('existing_images') ? count($request->input('existing_images', [])) : 0,
        ]);

        // Детальное логирование файлов
        if ($request->hasFile('images')) {
            Log::info('PlaceController::update - детали файлов images', [
                'files_count' => count($request->file('images')),
                'files_details' => array_map(function($file, $index) {
                    return [
                        'index' => $index,
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime' => $file->getMimeType(),
                        'is_valid' => $file->isValid(),
                        'error' => $file->getError(),
                        'type' => get_class($file)
                    ];
                }, $request->file('images'), array_keys($request->file('images')))
            ]);
        } else {
            Log::info('PlaceController::update - файлов images нет');
        }

        // Обновляем основные данные
        $place->update([
            'title' => $data['title'] ?? $place->title,
            'description' => $data['description'] ?? $place->description,
            'community_id' => array_key_exists('community_id', $data) ? $data['community_id'] : $place->community_id,
            'location' => array_key_exists('location', $data) ? $data['location'] : $place->location,
            'alt_text' => array_key_exists('alt_text', $data) ? $data['alt_text'] : $place->alt_text,
            'allow_comments' => array_key_exists('allow_comments', $data) ? $data['allow_comments'] : $place->allow_comments,
        ]);

        // Обрабатываем изображения
        $imageUrls = [];
        
        // Добавляем существующие изображения (фильтруем пустые)
        if ($request->has('existing_images')) {
            $existingImages = array_filter($request->input('existing_images', []), function($url) {
                return !empty($url) && is_string($url);
            });
            $imageUrls = array_merge($imageUrls, $existingImages);
            Log::info('PlaceController::update - обработка существующих изображений', [
                'existing_images_count' => count($existingImages),
                'existing_images' => $existingImages
            ]);
        } else {
            Log::info('PlaceController::update - существующих изображений нет');
        }
        
        // Добавляем новые изображения (фильтруем null) — загружаем в S3
        if ($request->hasFile('images')) {
            $imageFiles = $request->file('images');
            
            // Если images не является массивом, пытаемся собрать из всех файлов (для обратной совместимости)
            if (!$imageFiles || !is_array($imageFiles) || empty($imageFiles)) {
                $allFiles = $request->allFiles();
                
                // Проверяем формат images[] (стандартный)
                if (isset($allFiles['images']) && is_array($allFiles['images'])) {
                    $imageFiles = $allFiles['images'];
                } else {
                    // Проверяем формат images[0], images[1], etc. (старый формат)
                    $imageFiles = [];
                    foreach ($allFiles as $key => $file) {
                        if (preg_match('/^images\[(\d+)\]$/', $key, $matches)) {
                            $imageFiles[(int)$matches[1]] = $file;
                        }
                    }
                    if (!empty($imageFiles)) {
                        ksort($imageFiles);
                        $imageFiles = array_values($imageFiles);
                    }
                }
            }
            
            // Нормализуем в массив, если это не массив
            if (!is_array($imageFiles)) {
                $imageFiles = [$imageFiles];
            }
            
            Log::info('PlaceController::update - обработка новых изображений', [
                'files_count' => count($imageFiles),
                'files' => array_map(function($file) {
                    return [
                        'name' => $file ? $file->getClientOriginalName() : 'null',
                        'size' => $file ? $file->getSize() : 0,
                        'mime' => $file ? $file->getMimeType() : 'null',
                        'is_valid' => $file ? $file->isValid() : false,
                        'error' => $file ? $file->getError() : 'file is null',
                    ];
                }, $imageFiles)
            ]);
            
            // Фильтруем null значения и невалидные файлы
            $validFiles = [];
            foreach ($imageFiles as $index => $file) {
                if ($file !== null && $file->isValid()) {
                    $validFiles[] = $file;
                } else {
                    Log::warning('PlaceController::update - пропущен невалидный файл', [
                        'index' => $index,
                        'is_null' => $file === null,
                        'is_valid' => $file ? $file->isValid() : false,
                        'error' => $file ? $file->getError() : 'file is null',
                    ]);
                }
            }
            
            // Обрабатываем только валидные файлы
            foreach ($validFiles as $file) {
                try {
                    $key = 'places/images/' . uniqid('', true) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                    Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
                        'visibility' => 'public',
                        'CacheControl' => 'public, max-age=31536000, immutable',
                        'ContentType' => $file->getMimeType() ?: 'image/jpeg',
                    ]);
                    $imageUrls[] = $key;
                    Log::info('PlaceController::update - сохранено новое изображение', [
                        'original_name' => $file->getClientOriginalName(),
                        's3_key' => $key,
                    ]);
                } catch (\Exception $e) {
                    Log::error('PlaceController::update - ошибка сохранения изображения', [
                        'original_name' => $file->getClientOriginalName(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Если это критическая ошибка (например, проблема с S3), выбрасываем исключение
                    if (strpos($e->getMessage(), 'S3') !== false || strpos($e->getMessage(), 'storage') !== false) {
                        throw $e;
                    }
                }
            }
            
            if (empty($validFiles) && $request->hasFile('images')) {
                Log::warning('PlaceController::update - нет валидных изображений для сохранения');
            }
        } else {
            Log::info('PlaceController::update - новых изображений нет');
        }
        
        // Обновляем изображения в базе
        if (!empty($imageUrls)) {
            $place->images()->delete();
            foreach ($imageUrls as $url) {
                PlaceImage::create(['place_id' => $place->id, 'url' => $url]);
            }
        }

        // Обрабатываем видео — загружаем в S3
        if ($request->hasFile('video')) {
            // Новое видео
            $place->videos()->delete();
            $file = $request->file('video');
            $key = 'places/videos/' . uniqid('', true) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
            Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), [
                'visibility' => 'public',
                'CacheControl' => 'public, max-age=86400',
                'ContentType' => $file->getMimeType() ?: 'video/mp4',
            ]);
            $videoRecord = PlaceVideo::create(['place_id' => $place->id, 'url' => $key]);
            
            // Генерируем превью, постер и thumbnail
            try {
                $tempPath = storage_path('app/temp/' . uniqid('place_video_') . '_' . basename($key));
                $dir = dirname($tempPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($tempPath, Storage::disk('s3')->get($key));
                \Marvel\Helpers\PlaceVideoOptimizer::optimizeVideo($videoRecord, $tempPath);
            } catch (\Exception $e) {
                Log::error('PlaceController::update - ошибка генерации превью видео', [
                    'video_id' => $videoRecord->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($request->has('existing_video')) {
            // Сохраняем существующее видео
            $place->videos()->delete();
            PlaceVideo::create(['place_id' => $place->id, 'url' => $request->input('existing_video')]);
        }

        // Обрабатываем хештеги
        if ($request->has('hashtags') && is_array($request->input('hashtags'))) {
            $hashtagIds = [];
            foreach ($request->input('hashtags') as $tag) {
                if (is_string($tag) && !empty(trim($tag))) {
                    $tagName = trim($tag);
                    // Убираем # если есть
                    $tagName = ltrim($tagName, '#');
                    if (!empty($tagName)) {
                        $hashtag = Hashtag::firstOrCreate(
                            ['name' => $tagName],
                            ['slug' => \Illuminate\Support\Str::slug($tagName)]
                        );
                        $hashtagIds[] = $hashtag->id;
                    }
                }
            }
            if (!empty($hashtagIds)) {
                $place->hashtags()->sync($hashtagIds);
            }
        }

        // Обновляем привязку товаров
        if (isset($data['product_ids'])) {
            $place->products()->sync($data['product_ids']);
        }

        Log::info('PlaceController::update - плейс обновлен успешно', [
            'place_id' => $place->id,
            'title' => $place->title,
            'images_count' => count($imageUrls),
        ]);

        return new PlaceResource($place->fresh(['images', 'videos', 'hashtags', 'user.profile', 'community', 'likes', 'products']));
    }

    public function destroy($id)
    {
        $place = Place::findOrFail($id);
        $this->authorize('delete', $place);
        $place->delete();
        return response()->json(['success' => true]);
    }

    // Новый метод для поиска товаров
    public function searchProducts(Request $request)
    {
        $query = $request->get('q', '');
        $limit = $request->get('limit', 10);
        $shopId = $request->get('shop_id');
        
        $productsQuery = Product::where('name', 'like', "%{$query}%")
            ->orWhere('slug', 'like', "%{$query}%");
            
        // Фильтрация по магазину, если shop_id передан
        if ($shopId) {
            $productsQuery->where('shop_id', $shopId);
        }
        
        $products = $productsQuery
            ->select('id', 'name', 'slug', 'image')
            ->limit($limit)
            ->get();
            
        return response()->json(['data' => $products]);
    }

    public function similar(Request $request, $id)
    {
        try {
            $limit = $request->get('limit', 24); // Увеличиваем лимит по умолчанию до 24
            $cursor = $request->get('cursor');

            // Валидация параметров
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'cursor' => 'nullable|string',
            ]);

            // Проверяем, существует ли плейс
            $placeExists = \DB::table('places')->where('id', $id)->exists();
            if (!$placeExists) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => [
                        'next_cursor' => null,
                        'has_more' => false,
                    ],
                ]);
            }

            // Создаем запрос через PlaceSimilarQuery
            $queryParams = [
                'limit' => $limit,
                'cursor' => $cursor,
            ];

            $query = PlaceSimilarQuery::query($id, $queryParams);
            $places = $query->get();

            // Определяем следующий курсор и есть ли еще данные
            // Если получили ровно $limit записей, значит возможно есть еще данные
            $hasMore = $places->count() >= $limit;
            $nextCursor = null;
            if ($hasMore && $places->count() > 0) {
                $lastPlace = $places->last();
                $nextCursor = PlaceSimilarQuery::createCursor($lastPlace);
            }

            $resourceData = PlaceFeedResource::collection($places);

            return response()->json([
                'success' => true,
                'data' => $resourceData,
                'meta' => [
                    'next_cursor' => $nextCursor,
                    'has_more' => $hasMore,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('PlaceController::similar - ошибка', [
                'error' => $e->getMessage(),
                'place_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения похожих плейсов',
                'data' => [],
                'meta' => [
                    'next_cursor' => null,
                    'has_more' => false,
                ],
            ], 500);
        }
    }

    /**
     * Получить плейсы по хэштегу (name)
     */
    public function placesByHashtag(Request $request, $tag)
    {
        $limit = $request->get('limit', 20);
        $query = Place::with(['images', 'videos', 'hashtags', 'user', 'community', 'likes', 'products', 'wishlists'])->latest();
        $query->whereHas('hashtags', function ($q) use ($tag) {
            $q->where('name', $tag);
        });
        $places = $query->paginate($limit);
        return PlaceResource::collection($places);
    }

    /**
     * Получить избранные плейсы текущего пользователя
     */
    public function myPlaceWishlists(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $user = $request->user();
        $wishlist = \Marvel\Database\Models\PlaceWishlist::where('user_id', $user->id)->pluck('place_id');
        $places = Place::whereIn('id', $wishlist)
            ->with(['images', 'videos', 'hashtags', 'user', 'community', 'likes', 'products', 'wishlists'])
            ->latest()
            ->paginate($limit);
        return PlaceResource::collection($places);
    }
}
