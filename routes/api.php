<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\CustomYooKassaOrderController;
use App\Http\Controllers\PvzController;
use Marvel\Http\Controllers\OrderController;
use Marvel\Http\Controllers\PlaceController;
use Marvel\Http\Controllers\YmlFeedController;
use Marvel\Enums\Permission;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\YandexDirectController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum', 'throttle:20,1'])
    ->post('/admin/users/{user}/impersonate', [ImpersonationController::class, 'store']);

// AntiBot API routes (только для админов) - ВРЕМЕННО ЗАКОММЕНТИРОВАНО (контроллер отсутствует на сервере)
// Route::middleware('auth:api')->prefix('antibot')->group(function () {
//     Route::get('/statistics', [App\Http\Controllers\AntiBotController::class, 'getStatistics']);
//     Route::get('/realtime', [App\Http\Controllers\AntiBotController::class, 'getRealtimeStats']);
//     Route::get('/blocked-ips', [App\Http\Controllers\AntiBotController::class, 'getBlockedIps']);
//     Route::post('/block-ip', [App\Http\Controllers\AntiBotController::class, 'blockIp']);
//     Route::post('/unblock-ip/{ip}', [App\Http\Controllers\AntiBotController::class, 'unblockIp']);
//     Route::get('/ip/{ip}', [App\Http\Controllers\AntiBotController::class, 'getIpDetails']);
// });

// Auth routes - используем существующие Marvel контроллеры
// Регистрация и вход уже есть в Marvel Routes: /api/register, /api/token
// Дополнительные auth роуты можно добавить здесь при необходимости

// Chat routes - все роуты уже зарегистрированы в Marvel Routes.php
// Доступны через: /api/chat/conversations, /api/chat/messages, и т.д.

// Broadcasting auth endpoint for Laravel WebSockets
Route::middleware('auth:sanctum')->post('/broadcasting/auth', function (Request $request) {
    $channelName = $request->input('channel_name');
    $socketId = $request->input('socket_id');
    
    if (!$channelName || !$socketId) {
        return response()->json(['error' => 'Invalid request'], 400);
    }
    
    $user = $request->user();
    
    // For Laravel WebSockets, we need to return auth signature
    // Using HMAC SHA256 with app secret
    $appSecret = config('broadcasting.connections.pusher.secret', 'local');
    $appKey = config('broadcasting.connections.pusher.key', 'local');
    
    // Generate signature
    $signature = hash_hmac('sha256', $socketId . ':' . $channelName, $appSecret);
    
    return response()->json([
        'auth' => $appKey . ':' . $signature,
        'channel_data' => json_encode([
            'user_id' => (string) $user->id,
            'user_info' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]),
    ]);
});

// Маршрут для получения CSRF-токена
Route::get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()]);
});

Route::get('/homepage-banners', [App\Http\Controllers\HomepageBannerController::class, 'index']);
Route::middleware(['auth:api', 'permission:' . Permission::SUPER_ADMIN])->prefix('admin')->group(function () {
    Route::get('/yandex-direct', [YandexDirectController::class, 'show']);
    Route::put('/yandex-direct', [YandexDirectController::class, 'update']);
    Route::post('/yandex-direct/test', [YandexDirectController::class, 'test'])->middleware('throttle:10,1');
    Route::get('/yandex-direct/errors', [YandexDirectController::class, 'errors']);
    Route::post('/yandex-direct/groups/{group}/action', [YandexDirectController::class, 'groupAction']);
    Route::get('/homepage-banners', [App\Http\Controllers\HomepageBannerController::class, 'adminIndex']);
    Route::post('/homepage-banners', [App\Http\Controllers\HomepageBannerController::class, 'store']);
    Route::post('/homepage-banners/settings', [App\Http\Controllers\HomepageBannerController::class, 'updateSettings']);
    Route::post('/homepage-banners/{banner}', [App\Http\Controllers\HomepageBannerController::class, 'updateOne']);
    Route::delete('/homepage-banners/{banner}', [App\Http\Controllers\HomepageBannerController::class, 'destroy']);
});

// Маршрут для приема платежей ЮKassa (только для продакшена)
Route::post('/custom-yookassa-order', [CustomYooKassaOrderController::class, 'create']);
// Совместимый fallback endpoint для ручного подтверждения оплаты (используется фронтом при 403 на access)
Route::post('/payments/yookassa/confirm', [OrderController::class, 'confirmYooKassaPayment'])->middleware('auth:sanctum');

// Webhook для ЮKassa
Route::post('/webhooks/yookassa', [\Marvel\Http\Controllers\WebHookController::class, 'yookassa']);

// Webhook debug endpoint (для диагностики)
Route::post('/webhooks/debug', [App\Http\Controllers\WebhookDebugController::class, 'debug']);
Route::any('/webhooks/debug', [App\Http\Controllers\WebhookDebugController::class, 'debug']); // Любой метод

// Plans API routes (согласно заданию)
Route::get('/plans', [App\Http\Controllers\PlanController::class, 'index']);
Route::get('/plans/{id}', [App\Http\Controllers\PlanController::class, 'show']);

// Billing Info API routes (для продавцов)
Route::middleware('auth:api')->prefix('billing')->group(function () {
    Route::get('/current', [App\Http\Controllers\BillingInfoController::class, 'current']);
});

// Plan Subscription API routes (для продавцов) - DEPRECATED, используем ProSubscription
Route::middleware('auth:api')->prefix('plan')->group(function () {
    Route::get('/status', [App\Http\Controllers\PlanStatusController::class, 'status']);
    Route::get('/subscription', [App\Http\Controllers\PlanSubscriptionController::class, 'current']);
    Route::post('/subscribe', [App\Http\Controllers\PlanSubscriptionController::class, 'subscribe']);
});

// Pro Subscription API routes (новая система)
Route::middleware('auth:api')->prefix('pro-subscription')->group(function () {
    Route::get('/status', [App\Http\Controllers\ProSubscriptionController::class, 'status']);
    Route::post('/subscribe', [App\Http\Controllers\ProSubscriptionController::class, 'subscribe']);
});

// Public endpoint для проверки подписки (для фронта) - без авторизации
Route::get('/pro-subscription/check/{sellerId}', [App\Http\Controllers\ProSubscriptionController::class, 'checkPublic'])->middleware('throttle:60,1');

// Seller Balance API routes (для продавцов)
Route::middleware('auth:api')->prefix('seller')->group(function () {
    Route::get('/balance', [App\Http\Controllers\SellerBalanceController::class, 'get']);
    Route::post('/balance/deposit', [App\Http\Controllers\SellerBalanceController::class, 'deposit']);
    Route::get('/balance/check-pending', [App\Http\Controllers\SellerBalanceController::class, 'checkPending']);
});

// Additional Purchases API routes (для продавцов)
Route::middleware('auth:api')->prefix('additional-purchases')->group(function () {
    Route::get('/', [App\Http\Controllers\AdditionalPurchaseController::class, 'index']);
    Route::post('/', [App\Http\Controllers\AdditionalPurchaseController::class, 'purchase']);
});

// Invoice API routes (для продавцов)
Route::middleware('auth:api')->prefix('invoices')->group(function () {
    Route::get('/', [App\Http\Controllers\InvoiceController::class, 'index']);
    Route::post('/{invoice}/pay', [App\Http\Controllers\InvoiceController::class, 'pay']);
});

// Payment History API routes (только для супер-админа)
Route::middleware('auth:api')->prefix('admin')->group(function () {
    Route::get('/payment-history', [App\Http\Controllers\PaymentHistoryController::class, 'index']);
    // Виртуальное пополнение баланса продавца (только для супер-админа)
    Route::post('/seller/balance/virtual-deposit', [App\Http\Controllers\SellerBalanceController::class, 'virtualDeposit']);
});

// Invoice webhook (для обработки уведомлений о платежах)
Route::post('/invoices/webhook', [App\Http\Controllers\InvoiceController::class, 'webhook']);

// Тестовый роут для проверки ЮKassa
Route::get('/test-yookassa', function() {
    return response()->json(['message' => 'YooKassa test route works!']);
});

// Маршруты плейсов перенесены в Marvel Routes

// Тестовые маршруты удалены - используем Marvel API

// GeoIP маршруты
Route::prefix('geoip')->group(function () {
    // Простой тест endpoint
    Route::get('/test', function () {
        try {
            return response()->json([
                'status' => 'ok',
                'message' => 'GeoIP routes are working',
                'server' => [
                    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
                    'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    });
    
    // Тест Яндекс Locator API
    Route::get('/test-yandex', function () {
        try {
            $testIp = request()->query('ip', '95.24.18.3'); // Тестовый IP из России
            
            $results = [
                'test_ip' => $testIp,
                'yandex_locator' => [
                    'configured' => false,
                    'working' => false,
                    'data' => null,
                    'error' => null
                ],
                'maxmind' => [
                    'configured' => false,
                    'working' => false,
                    'data' => null,
                    'error' => null
                ],
                'yandex_geocoder' => [
                    'configured' => false,
                    'working' => false,
                    'data' => null,
                    'error' => null
                ]
            ];
            
            // Тест 1: Яндекс Locator
            try {
                $yandexGeoService = app(\App\Services\YandexGeoService::class);
                $yandexApiKey = config('services.yandex_locator.api_key');
                
                $results['yandex_locator']['configured'] = !empty($yandexApiKey);
                
                if ($yandexApiKey) {
                    $yandexLocation = $yandexGeoService->getLocationByIp($testIp);
                    if ($yandexLocation) {
                        $results['yandex_locator']['working'] = true;
                        $results['yandex_locator']['data'] = [
                            'city' => $yandexLocation['city'] ?? null,
                            'country' => $yandexLocation['country'] ?? null,
                            'lat' => $yandexLocation['lat'] ?? null,
                            'lon' => $yandexLocation['lon'] ?? null,
                            'source' => $yandexLocation['source'] ?? null
                        ];
                    } else {
                        $results['yandex_locator']['error'] = 'Yandex Locator вернул null';
                    }
                } else {
                    $results['yandex_locator']['error'] = 'YANDEX_GEO_API_KEY не настроен в .env';
                }
            } catch (\Exception $e) {
                $results['yandex_locator']['error'] = $e->getMessage();
                Log::error('Yandex Locator test error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            
            // Тест 2: MaxMind
            try {
                $geoService = app(\App\Services\GeoLocationService::class);
                $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');
                
                $results['maxmind']['configured'] = file_exists($dbPath);
                
                if (file_exists($dbPath)) {
                    $maxmindLocation = $geoService->getLocationByIp($testIp);
                    if ($maxmindLocation && isset($maxmindLocation['city'])) {
                        $results['maxmind']['working'] = true;
                        $results['maxmind']['data'] = [
                            'city' => $maxmindLocation['city'] ?? null,
                            'country' => $maxmindLocation['country'] ?? null,
                            'lat' => $maxmindLocation['lat'] ?? null,
                            'lon' => $maxmindLocation['lon'] ?? null,
                            'source' => $maxmindLocation['source'] ?? null
                        ];
                    } else {
                        $results['maxmind']['error'] = 'MaxMind вернул пустые данные';
                    }
                } else {
                    $results['maxmind']['error'] = 'База данных MaxMind не найдена: ' . $dbPath;
                }
            } catch (\Exception $e) {
                $results['maxmind']['error'] = $e->getMessage();
                Log::error('MaxMind test error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            
            // Тест 3: Yandex Geocoder (для поиска городов)
            try {
                // Используем тот же ключ, что и для Locator
                $geocoderApiKey = config('services.yandex_geocoder.api_key');
                $results['yandex_geocoder']['configured'] = !empty($geocoderApiKey);
                
                if ($geocoderApiKey) {
                    // Тестируем поиск города "Москва"
                    $response = Http::timeout(5)->get('https://geocode-maps.yandex.ru/1.x/', [
                        'apikey' => $geocoderApiKey,
                        'geocode' => 'Москва',
                        'format' => 'json',
                        'results' => 1,
                        'kind' => 'locality'
                    ]);
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        if (isset($data['response']['GeoObjectCollection']['featureMember'])) {
                            $results['yandex_geocoder']['working'] = true;
                            $results['yandex_geocoder']['data'] = [
                                'test_query' => 'Москва',
                                'found' => count($data['response']['GeoObjectCollection']['featureMember']) > 0
                            ];
                        } else {
                            $results['yandex_geocoder']['error'] = 'Неверная структура ответа';
                        }
                    } else {
                        $results['yandex_geocoder']['error'] = 'HTTP ' . $response->status() . ': ' . $response->body();
                    }
                } else {
                    $results['yandex_geocoder']['error'] = 'YANDEX_GEO_API_KEY не настроен в .env (используется для обоих сервисов)';
                }
            } catch (\Exception $e) {
                $results['yandex_geocoder']['error'] = $e->getMessage();
                Log::error('Yandex Geocoder test error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            
            return response()->json([
                'status' => 'ok',
                'test_results' => $results,
                'summary' => [
                    'yandex_locator_ok' => $results['yandex_locator']['configured'] && $results['yandex_locator']['working'],
                    'maxmind_ok' => $results['maxmind']['configured'] && $results['maxmind']['working'],
                    'yandex_geocoder_ok' => $results['yandex_geocoder']['configured'] && $results['yandex_geocoder']['working']
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    });
    
    // Debug endpoint для диагностики определения IP
    Route::get('/debug', function () {
        try {
            $geoService = app(\App\Services\GeoLocationService::class);
            $detectedIp = $geoService->getClientIp();
            
            return response()->json([
                'detected_ip' => $detectedIp,
                'laravel_ip' => request()->ip(),
                'server' => [
                    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
                    'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
                    'HTTP_X_REAL_IP' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
                    'HTTP_CLIENT_IP' => $_SERVER['HTTP_CLIENT_IP'] ?? null,
                ],
                'is_localhost' => in_array($detectedIp, ['127.0.0.1', '::1', 'localhost']),
                'location' => $geoService->getLocationByIp()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error in debug endpoint',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    });
    
    // GET и POST для /location - POST поддерживает WiFi/Cell данные
    Route::match(['get', 'post'], '/location', function () {
        try {
            $request = request();
            $ip = $request->ip();
            
            // Для localhost используем тестовый IP из России
            if ($ip === '127.0.0.1' || $ip === '::1') {
                $ip = '95.24.18.3';
            }
            
            // ПРОБУЕМ ПОЛУЧИТЬ ПОЛЬЗОВАТЕЛЯ
            $user = null;
            try {
                $user = auth('sanctum')->user();
            } catch (\Exception $e) {}
            
            if (!$user) {
                try {
                    $user = $request->user();
                } catch (\Exception $e) {}
            }
            
            if (!$user) {
                try {
                    $user = auth('api')->user();
                } catch (\Exception $e) {}
            }
            
            if (!$user) {
                try {
                    $user = auth('web')->user();
                } catch (\Exception $e) {}
            }
            
            if (!$user && $request->bearerToken()) {
                try {
                    $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                    if ($token) {
                        $user = $token->tokenable;
                    }
                } catch (\Exception $e) {}
            }
            
            // ПРИОРИТЕТ 1: Если есть user_address (выбранный пользователем) - возвращаем его БЕЗ запроса к Dadata
            $userAddress = null;
            if ($user) {
                $userAddress = \Marvel\Database\Models\UserAddress::where('user_id', $user->id)
                    ->where('type', 'user_selected')
                    ->where('source', 'user_selected')
                    ->where('is_active', true)
                    ->orderBy('is_default', 'desc')
                    ->orderBy('updated_at', 'desc')
                    ->first();
                
                if ($userAddress) {
                    return response()->json([
                        'ip' => $ip,
                        'location' => [
                            'country' => $userAddress->country ?? 'Unknown',
                            'iso_code' => $userAddress->country === 'Россия' ? 'RU' : 'Unknown',
                            'city' => $userAddress->city ?? 'Unknown',
                            'state' => $userAddress->region ?? null,
                            'state_name' => $userAddress->region_with_type ?? $userAddress->region ?? null,
                            'postal_code' => $userAddress->postal_code ?? null,
                            'lat' => $userAddress->latitude ?? 0,
                            'lon' => $userAddress->longitude ?? 0,
                            'timezone' => 'Europe/Moscow',
                            'source' => 'user_selected'
                        ],
                        'from_saved' => true
                    ]);
                }
                
                // ПРИОРИТЕТ 2: Если есть auto_address (автоопределенный) - возвращаем его БЕЗ запроса к Dadata
                $autoAddress = \Marvel\Database\Models\UserAddress::where('user_id', $user->id)
                    ->where('type', 'auto_detected')
                    ->where('source', 'auto_detected')
                    ->where('is_active', true)
                    ->orderBy('updated_at', 'desc')
                    ->first();
                
                if ($autoAddress) {
                    return response()->json([
                        'ip' => $ip,
                        'location' => [
                            'country' => $autoAddress->country ?? 'Unknown',
                            'iso_code' => $autoAddress->country === 'Россия' ? 'RU' : 'Unknown',
                            'city' => $autoAddress->city ?? 'Unknown',
                            'state' => $autoAddress->region ?? null,
                            'state_name' => $autoAddress->region_with_type ?? $autoAddress->region ?? null,
                            'postal_code' => $autoAddress->postal_code ?? null,
                            'lat' => $autoAddress->latitude ?? 0,
                            'lon' => $autoAddress->longitude ?? 0,
                            'timezone' => 'Europe/Moscow',
                            'source' => 'auto_detected'
                        ],
                        'from_saved' => true
                    ]);
                }
            }
            
            // ПРИОРИТЕТ 3: Если нет ни user_address, ни auto_address - делаем запрос к Dadata
            $geoService = app(\App\Services\GeoLocationService::class);
            
            // Получаем WiFi и Cell данные из запроса (если есть)
            $wifi = $request->input('wifi', []);
            $cell = $request->input('cell', []);
            $coordinates = $request->input('coordinates', null); // lat, lon от HTML5 Geolocation
            
            // Используем GeoLocationService с гибридной системой
            // Передаем WiFi/Cell данные для максимальной точности
            $location = $geoService->getLocationByIp($ip, $wifi, $cell, $coordinates);
            
            // Проверяем, что получили валидные данные
            if (!$location || empty($location)) {
                Log::warning('GeoIP: Empty location data', ['ip' => $ip]);
                return response()->json([
                    'ip' => $ip,
                    'location' => [
                        'country' => 'Unknown',
                        'iso_code' => 'Unknown',
                        'city' => 'Unknown',
                        'state' => null,
                        'state_name' => null,
                        'postal_code' => null,
                        'lat' => 0,
                        'lon' => 0,
                        'timezone' => 'UTC',
                        'source' => 'default'
                    ],
                    'error' => 'Не удалось определить местоположение'
                ], 200); // Возвращаем 200, но с дефолтными данными
            }
            
            // СОХРАНЯЕМ автоопределенный адрес в БД (только если пользователь авторизован и нет user_address)
            if ($user && !$userAddress) {
                try {
                    \Marvel\Database\Models\UserAddress::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'type' => 'auto_detected',
                            'source' => 'auto_detected'
                        ],
                        [
                            'title' => 'Автоопределенный адрес',
                            'city' => $location['city'] ?? null,
                            'region' => $location['state_name'] ?? $location['state'] ?? null,
                            'region_with_type' => $location['state_name'] ?? null,
                            'country' => $location['country'] ?? 'Россия',
                            'address' => $location['city'] ?? 'Unknown',
                            'full_address' => ($location['city'] ?? '') . ($location['state_name'] ? ', ' . $location['state_name'] : ''),
                            'latitude' => $location['lat'] ?? null,
                            'longitude' => $location['lon'] ?? null,
                            'postal_code' => $location['postal_code'] ?? null,
                            'is_default' => false,
                            'is_active' => true
                        ]
                    );
                } catch (\Exception $e) {
                    // Игнорируем ошибки сохранения, но логируем
                    Log::warning('Failed to save auto-detected address', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            return response()->json([
                'ip' => $location['ip'] ?? $ip,
                'location' => [
                    'country' => $location['country'] ?? 'Unknown',
                    'iso_code' => $location['iso_code'] ?? 'Unknown',
                    'city' => $location['city'] ?? 'Unknown',
                    'state' => $location['state'] ?? null,
                    'state_name' => $location['state_name'] ?? null,
                    'postal_code' => $location['postal_code'] ?? null,
                    'lat' => $location['lat'] ?? 0,
                    'lon' => $location['lon'] ?? 0,
                    'timezone' => $location['timezone'] ?? 'UTC',
                    'source' => $location['source'] ?? 'unknown'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('GeoIP location error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Возвращаем дефолтные данные вместо ошибки 500
            return response()->json([
                'ip' => request()->ip(),
                'location' => [
                    'country' => 'Unknown',
                    'iso_code' => 'Unknown',
                    'city' => 'Unknown',
                    'state' => null,
                    'state_name' => null,
                    'postal_code' => null,
                    'lat' => 0,
                    'lon' => 0,
                    'timezone' => 'UTC',
                    'source' => 'error'
                ],
                'error' => 'Ошибка определения местоположения',
                'message' => $e->getMessage()
            ], 200); // Возвращаем 200, чтобы фронтенд мог обработать
        }
    });
    
    Route::get('/location/{ip}', function ($ip) {
        try {
            $geoService = app(\App\Services\GeoLocationService::class);
            $location = $geoService->getLocationByIp($ip);
            
            return response()->json([
                'ip' => $ip,
                'location' => $location
            ]);
        } catch (\Exception $e) {
            Log::error('GeoIP location endpoint error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $ip,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Возвращаем дефолтное местоположение вместо 500 ошибки
            try {
                $geoService = app(\App\Services\GeoLocationService::class);
                $defaultLocation = $geoService->getLocationByIp($ip);
                return response()->json([
                    'ip' => $ip,
                    'location' => $defaultLocation,
                    'error' => 'Failed to get location: ' . $e->getMessage()
                ], 200);
            } catch (\Exception $e2) {
                return response()->json([
                    'ip' => $ip,
                    'location' => [
                        'country' => 'Unknown',
                        'iso_code' => 'Unknown',
                        'city' => 'Unknown',
                        'lat' => 0,
                        'lon' => 0,
                        'source' => 'error'
                    ],
                    'error' => 'Failed to get location: ' . $e->getMessage()
                ], 200);
            }
        }
    });
    
    Route::get('/country', function () {
        try {
            $geoService = app(\App\Services\GeoLocationService::class);
            $location = $geoService->getLocationByIp();
            $country = $location['country'] ?? 'Unknown';
            $isRussian = $location['iso_code'] === 'RU';
            
            return response()->json([
                'country' => $country,
                'is_russian' => $isRussian,
                'ip' => $location['ip'] ?? request()->ip()
            ]);
            } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get country',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    });
    
    // Поиск городов через Yandex Geocoder (автодополнение)
    Route::get('/cities/search', function (Request $request) {
        try {
            $query = $request->query('q', '');
            
            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'cities' => [],
                    'total' => 0
                ]);
            }
            
            // Используем DaData для поиска городов (основной сервис)
            $geoService = app(\App\Services\GeoLocationService::class);
            $citiesResult = $geoService->searchCities($query);
            
            if (isset($citiesResult['error'])) {
                return response()->json([
                    'error' => $citiesResult['error'],
                    'cities' => []
                ], 500);
            }
            
            return response()->json([
                'cities' => $citiesResult['cities'] ?? [],
                'total' => $citiesResult['total'] ?? 0
            ]);
            
                } catch (\Exception $e) {
            Log::error('City search error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'error' => 'Ошибка поиска городов',
                'cities' => []
            ], 500);
        }
    });
    
    // Поиск полных адресов (город, улица, дом) через DaData
    Route::get('/addresses/search', function (Request $request) {
        try {
            $query = $request->query('q', '');
            $count = (int) $request->query('count', 10);
            
            Log::debug('API: Запрос поиска адресов', [
                'query' => $query,
                'count' => $count,
                'ip' => $request->ip()
            ]);
            
            if (empty($query) || strlen($query) < 2) {
                Log::debug('API: Запрос слишком короткий', ['query' => $query]);
                return response()->json([
                    'addresses' => [],
                    'total' => 0,
                    'message' => 'Запрос должен содержать минимум 2 символа'
                ]);
            }
            
            // Используем DaData для поиска полных адресов
                $geoService = app(\App\Services\GeoLocationService::class);
            $addressesResult = $geoService->searchAddresses($query, $count);
            
            Log::debug('API: Результат поиска', [
                'query' => $query,
                'found' => $addressesResult['total'] ?? 0,
                'has_error' => isset($addressesResult['error'])
            ]);
            
            if (isset($addressesResult['error'])) {
                Log::warning('API: Ошибка поиска адресов', [
                    'query' => $query,
                    'error' => $addressesResult['error']
                ]);
                return response()->json([
                    'error' => $addressesResult['error'],
                    'addresses' => [],
                    'total' => 0
                ], 500);
            }
            
            return response()->json([
                'addresses' => $addressesResult['addresses'] ?? [],
                'total' => $addressesResult['total'] ?? 0,
                'query' => $query
            ]);
            
        } catch (\Exception $e) {
            Log::error('Address search error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'query' => $query ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка поиска адресов: ' . $e->getMessage(),
                'addresses' => [],
                'total' => 0
            ], 500);
        }
    });
    
    // Поиск организации по ИНН через DaData
    Route::get('/party/find-by-inn', function (Request $request) {
        try {
            $inn = $request->query('inn', '');
            $type = $request->query('type'); // 'LEGAL' или 'INDIVIDUAL'
            
            if (empty($inn)) {
                return response()->json([
                    'success' => false,
                    'error' => 'ИНН не указан',
                    'data' => null
                ], 400);
            }

            // Валидация ИНН (10 или 12 цифр)
            if (!preg_match('/^\d{10}$|^\d{12}$/', $inn)) {
                return response()->json([
                    'success' => false,
                    'error' => 'ИНН должен содержать 10 или 12 цифр',
                    'data' => null
                ], 400);
            }

            Log::debug('API: Поиск организации по ИНН', [
                'inn' => $inn,
                'type' => $type,
                'ip' => $request->ip()
            ]);

            $geoService = app(\App\Services\GeoLocationService::class);
            $result = $geoService->findPartyByInn($inn, $type);

            Log::debug('API: Party find result', [
                'inn' => $inn,
                'type' => $type,
                'success' => $result['success'] ?? false,
                'has_data' => !empty($result['data']),
            ]);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                    'data' => null
                ], 404);
            }

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Party find by INN error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'inn' => $inn ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ошибка поиска организации: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    });

    // Сохранение адреса пользователя (город, улица, дом)
    Route::post('/address/save', function (Request $request) {
        try {
            // Уменьшаем логирование для производительности
            // Log::debug('API: Сохранение адреса', [
            //     'user_id' => $request->user()?->id,
            //     'is_authenticated' => $request->user() !== null,
            //     'request_data' => $request->all()
            // ]);
            
            $city = $request->input('city');
            $region = $request->input('region');
            $region_with_type = $request->input('region_with_type');
            $country = $request->input('country');
            $street = $request->input('street');
            $street_with_type = $request->input('street_with_type');
            $house = $request->input('house');
            $flat = $request->input('flat');
            $postal_code = $request->input('postal_code');
            $full_address = $request->input('full_address') ?: $request->input('address');
            $lat = $request->input('lat');
            $lon = $request->input('lon');
            $kladr_id = $request->input('kladr_id');
            $fias_id = $request->input('fias_id');
            
            if (!$city && !$full_address) {
                return response()->json([
                    'error' => 'Адрес не указан'
                ], 400);
            }
            
            // Формируем данные адреса
            $addressData = [
                'city' => $city,
                'region' => $region,
                'country' => $country,
                'street' => $street,
                'house' => $house,
                'flat' => $flat,
                'postal_code' => $postal_code,
                'full_address' => $full_address,
                'lat' => $lat,
                'lon' => $lon,
                'kladr_id' => $kladr_id,
                'fias_id' => $fias_id,
                'saved_at' => now()->toIso8601String()
            ];
            
            // Пробуем получить пользователя разными способами
            $user = null;
            
            // Способ 1: через Sanctum (API guard)
            try {
                $user = auth('sanctum')->user();
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
            
            // Способ 2: через request->user() (если используется)
            if (!$user && method_exists($request, 'user')) {
                try {
                    $user = $request->user();
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            // Способ 3: через API guard
            if (!$user) {
                try {
                    $user = auth('api')->user();
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            // Способ 4: через web guard (сессия)
            if (!$user) {
                try {
                    $user = auth('web')->user();
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            // Способ 5: через bearer token (если передан в заголовке)
            if (!$user && $request->bearerToken()) {
                try {
                    $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                    if ($token) {
                        $user = $token->tokenable;
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            // Детальное логирование для диагностики
            Log::info('API: Address save - Auth check', [
                'user_id' => $user?->id,
                'is_authenticated' => $user !== null,
                'auth_methods' => [
                    'sanctum' => auth('sanctum')->check(),
                    'api' => auth('api')->check(),
                    'web' => auth('web')->check(),
                    'bearer_token' => $request->bearerToken() !== null,
                    'bearer_token_value' => $request->bearerToken() ? substr($request->bearerToken(), 0, 20) . '...' : null
                ],
                'request_data' => [
                    'city' => $city,
                    'has_full_address' => !empty($full_address),
                    'has_street' => !empty($street),
                    'has_house' => !empty($house)
                ]
            ]);
            
            // Если пользователь не авторизован, сохраняем в сессию
            if (!$user) {
                // Сохраняем в сессию для неавторизованных пользователей
                session(['user_address' => $addressData]);
                
                Log::info('API: Address save - Saved to session (not authenticated)', [
                    'session_id' => session()->getId()
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Адрес сохранен в сессию (требуется авторизация для сохранения в БД)',
                    'address' => $addressData
                ]);
            }
            
            // Авторизованный пользователь - сохраняем в таблицу user_addresses
            // Используем тип 'user_selected' для адресов, выбранных пользователем вручную
            try {
                // Проверяем, есть ли уже user_selected адрес
                $existingAddress = \Marvel\Database\Models\UserAddress::where('user_id', $user->id)
                    ->where('type', 'user_selected')
                    ->where('source', 'user_selected')
                    ->first();
                
                Log::info('API: Address save - Before updateOrCreate', [
                    'user_id' => $user->id,
                    'existing_address_id' => $existingAddress?->id,
                    'will_update' => $existingAddress !== null,
                    'will_create' => $existingAddress === null
                ]);
                
                $userAddress = \Marvel\Database\Models\UserAddress::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'type' => 'user_selected',
                        'source' => 'user_selected'
                    ],
                    [
                        'title' => 'Мой адрес',
                        'city' => $city,
                        'region' => $region,
                        'region_with_type' => $request->input('region_with_type'),
                        'country' => $country ?? 'Россия',
                        'address' => $full_address ?: $city,
                        'full_address' => $full_address,
                        'street' => $street,
                        'street_with_type' => $request->input('street_with_type'),
                        'house' => $house,
                        'flat' => $flat,
                        'postal_code' => $postal_code,
                        'latitude' => $lat,
                        'longitude' => $lon,
                        'kladr_id' => $kladr_id,
                        'fias_id' => $fias_id,
                        'is_default' => true, // Адрес, выбранный пользователем, всегда по умолчанию
                        'is_active' => true
                    ]
                );
                
                // Детальное логирование результата сохранения
                Log::info('API: Address save - Database result', [
                    'user_id' => $user->id,
                    'address_id' => $userAddress->id,
                    'was_recently_created' => $userAddress->wasRecentlyCreated,
                    'type' => $userAddress->type,
                    'source' => $userAddress->source,
                    'city' => $userAddress->city,
                    'full_address' => $userAddress->full_address,
                    'created_at' => $userAddress->created_at?->toDateTimeString(),
                    'updated_at' => $userAddress->updated_at?->toDateTimeString()
                ]);
                
                // Проверяем, что адрес действительно сохранился
                $verifyAddress = \Marvel\Database\Models\UserAddress::find($userAddress->id);
                if (!$verifyAddress) {
                    Log::error('API: Address save - Verification failed', [
                        'user_id' => $user->id,
                        'address_id' => $userAddress->id,
                        'error' => 'Address not found after save'
                    ]);
                } else {
                    Log::info('API: Address save - Verification success', [
                        'user_id' => $user->id,
                        'address_id' => $verifyAddress->id,
                        'type' => $verifyAddress->type,
                        'source' => $verifyAddress->source
                    ]);
                }
                
                // Также сохраняем в кэш для быстрого доступа
                Cache::put("user_address_{$user->id}", $addressData, 86400 * 30); // 30 дней
                
                return response()->json([
                    'success' => true,
                    'message' => 'Адрес сохранен в базу данных',
                    'address' => $addressData,
                    'user_address_id' => $userAddress->id,
                    'saved_to_database' => true,
                    'was_recently_created' => $userAddress->wasRecentlyCreated,
                    'type' => $userAddress->type,
                    'source' => $userAddress->source
                ]);
            } catch (\Exception $e) {
                Log::error('API: Ошибка сохранения адреса в БД', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // В случае ошибки БД, сохраняем в кэш как fallback
                Cache::put("user_address_{$user->id}", $addressData, 86400 * 30);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Адрес сохранен в кэш (ошибка БД)',
                    'address' => $addressData,
                    'saved_to_database' => false,
                    'error' => $e->getMessage()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Address save error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'error' => 'Ошибка сохранения адреса',
                'message' => $e->getMessage()
            ], 500);
        }
    });
    
    // Сохранение города пользователя (для обратной совместимости)
    Route::post('/city/save', function (Request $request) {
        try {
            $city = $request->input('city');
            $region = $request->input('region');
            $country = $request->input('country');
            $lat = $request->input('lat');
            $lon = $request->input('lon');
            
            if (!$city) {
            return response()->json([
                    'error' => 'Город не указан'
                ], 400);
            }
            
            $user = $request->user();
            $cityData = [
                'city' => $city,
                'region' => $region,
                'country' => $country,
                'lat' => $lat,
                'lon' => $lon,
                'saved_at' => now()->toIso8601String()
            ];
            
            if ($user) {
                // Авторизованный пользователь - сохраняем в профиль
                $profile = $user->profile;
                if (!$profile) {
                    $profile = $user->profile()->create([]);
                }
                
                // Сохраняем в поле bio или создаем отдельное поле
                $profile->update([
                    'bio' => json_encode($cityData, JSON_UNESCAPED_UNICODE)
                ]);
                
                // Также сохраняем в кэш для быстрого доступа
                Cache::put("user_city_{$user->id}", $cityData, 86400 * 30); // 30 дней
                
                return response()->json([
                    'success' => true,
                    'message' => 'Город сохранен в профиль',
                    'city' => $cityData
                ]);
            } else {
                // Неавторизованный пользователь - сохраняем в сессию
                $sessionId = $request->session()->getId();
                Cache::put("guest_city_{$sessionId}", $cityData, 86400 * 7); // 7 дней
                
                return response()->json([
                    'success' => true,
                    'message' => 'Город сохранен в сессию',
                    'city' => $cityData
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Save city error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'error' => 'Ошибка сохранения города',
                'message' => $e->getMessage()
            ], 500);
        }
    });
    
    // Получение сохраненного адреса пользователя
    Route::get('/address/saved', function (Request $request) {
        try {
            // Уменьшаем логирование для производительности - только при необходимости
            // Log::debug('API: Получение сохраненного адреса', [
            //     'has_bearer_token' => $request->bearerToken() !== null,
            //     'ip' => $request->ip()
            // ]);
            
            // Пробуем получить пользователя разными способами (как в /address/save)
            $user = null;
            
            try {
                $user = auth('sanctum')->user();
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
            
            if (!$user) {
                try {
                    $user = $request->user();
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            if (!$user) {
                try {
                    $user = auth('api')->user();
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            if (!$user) {
                try {
                    $user = auth('web')->user();
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            if (!$user && $request->bearerToken()) {
                try {
                    $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                    if ($token) {
                        $user = $token->tokenable;
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
            
            if ($user) {
                // Авторизованный пользователь - получаем из таблицы user_addresses
                // ПРИОРИТЕТ 1: user_address (выбранный пользователем вручную) - ВЫСШИЙ ПРИОРИТЕТ
                $userAddress = \Marvel\Database\Models\UserAddress::where('user_id', $user->id)
                    ->where('type', 'user_selected')
                    ->where('source', 'user_selected')
                    ->where('is_active', true)
                    ->orderBy('is_default', 'desc')
                    ->orderBy('updated_at', 'desc')
                    ->first();
                
                if ($userAddress) {
                    $addressData = [
                        'city' => $userAddress->city,
                        'region' => $userAddress->region,
                        'region_with_type' => $userAddress->region_with_type,
                        'country' => $userAddress->country,
                        'street' => $userAddress->street,
                        'street_with_type' => $userAddress->street_with_type,
                        'house' => $userAddress->house,
                        'flat' => $userAddress->flat,
                        'postal_code' => $userAddress->postal_code,
                        'full_address' => $userAddress->full_address ?: $userAddress->address,
                        'address' => $userAddress->address,
                        'lat' => $userAddress->latitude,
                        'lon' => $userAddress->longitude,
                        'kladr_id' => $userAddress->kladr_id,
                        'fias_id' => $userAddress->fias_id,
                        'saved_at' => $userAddress->updated_at->toIso8601String(),
                        'source_type' => 'user_selected'
                    ];
                    
                    return response()->json([
                        'address' => $addressData
                    ]);
                }
                
                // ПРИОРИТЕТ 2: auto_address (автоопределенный через Dadata)
                $autoAddress = \Marvel\Database\Models\UserAddress::where('user_id', $user->id)
                    ->where('type', 'auto_detected')
                    ->where('source', 'auto_detected')
                    ->where('is_active', true)
                    ->orderBy('updated_at', 'desc')
                    ->first();
                
                if ($autoAddress) {
                    $addressData = [
                        'city' => $autoAddress->city,
                        'region' => $autoAddress->region,
                        'region_with_type' => $autoAddress->region_with_type,
                        'country' => $autoAddress->country,
                        'street' => $autoAddress->street,
                        'street_with_type' => $autoAddress->street_with_type,
                        'house' => $autoAddress->house,
                        'flat' => $autoAddress->flat,
                        'postal_code' => $autoAddress->postal_code,
                        'full_address' => $autoAddress->full_address ?: $autoAddress->address,
                        'address' => $autoAddress->address,
                        'lat' => $autoAddress->latitude,
                        'lon' => $autoAddress->longitude,
                        'kladr_id' => $autoAddress->kladr_id,
                        'fias_id' => $autoAddress->fias_id,
                        'saved_at' => $autoAddress->updated_at->toIso8601String(),
                        'source_type' => 'auto_detected'
                    ];
                    
                    return response()->json([
                        'address' => $addressData
                    ]);
                }
                
                // Fallback: пробуем получить из кэша (для обратной совместимости)
                $cachedAddress = Cache::get("user_address_{$user->id}");
                if ($cachedAddress) {
                    return response()->json([
                        'address' => $cachedAddress
                    ]);
                }
                
                // Fallback: пробуем получить из поля bio профиля (для обратной совместимости)
                $profile = $user->profile;
                if ($profile && $profile->bio) {
                    $addressData = json_decode($profile->bio, true);
                    if ($addressData && (isset($addressData['full_address']) || isset($addressData['city']))) {
                        return response()->json([
                            'address' => $addressData
                        ]);
                    }
                }
            } else {
                // Неавторизованный пользователь - получаем из сессии
                $sessionAddress = session('user_address');
                if ($sessionAddress) {
                    return response()->json([
                        'address' => $sessionAddress
                    ]);
                }
            }
            
            // Возвращаем 200 с null, а не 404 - это не ошибка, если адреса нет
            return response()->json([
                'address' => null
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Get saved address error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'error' => 'Ошибка получения сохраненного адреса',
                'message' => $e->getMessage()
            ], 500);
        }
    });
    
    // Получение сохраненного города (для обратной совместимости)
    Route::get('/city/saved', function (Request $request) {
        try {
            $user = $request->user();
            
            if ($user) {
                // Авторизованный пользователь - получаем из кэша или профиля
                $cityData = Cache::get("user_city_{$user->id}");
                
                if (!$cityData && $user->profile && $user->profile->bio) {
                    $bio = json_decode($user->profile->bio, true);
                    if (isset($bio['city'])) {
                        $cityData = $bio;
                    }
                }
                
                return response()->json([
                    'city' => $cityData
                ]);
            } else {
                // Неавторизованный пользователь - получаем из сессии
                $sessionId = $request->session()->getId();
                $cityData = Cache::get("guest_city_{$sessionId}");
                
                return response()->json([
                    'city' => $cityData
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Get saved city error', [
                'message' => $e->getMessage()
            ]);
            
            return response()->json([
                'city' => null
            ]);
        }
    });
});

// API для работы с местоположением пользователей
Route::prefix('user-location')->group(function () {
    // Получить местоположение пользователя
    Route::get('/{userId}', function ($userId) {
        try {
            // Здесь должна быть логика получения местоположения из базы данных
            // Пока возвращаем заглушку
            return response()->json([
                'message' => 'User location endpoint - to be implemented',
                'user_id' => $userId
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get user location',
                'message' => $e->getMessage()
            ], 500);
        }
    });
    
    // Сохранить местоположение пользователя
    Route::post('/', function (Request $request) {
        try {
            $data = $request->validate([
                'user_id' => 'required|integer',
                'city' => 'required|string|max:255',
                'region' => 'required|string|max:255',
                'country' => 'required|string|max:255',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'timezone' => 'required|string|max:255',
                'ip_address' => 'nullable|string|max:45',
                'is_auto_detected' => 'boolean'
            ]);
            
            // Здесь должна быть логика сохранения в базу данных
            // Пока возвращаем заглушку
            return response()->json([
                'message' => 'User location saved successfully',
                'data' => $data
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to save user location',
                'message' => $e->getMessage()
            ], 500);
        }
    });
    
    // Обновить местоположение пользователя
    Route::put('/{userId}', function (Request $request, $userId) {
        try {
            $data = $request->validate([
                'city' => 'sometimes|string|max:255',
                'region' => 'sometimes|string|max:255',
                'country' => 'sometimes|string|max:255',
                'latitude' => 'sometimes|numeric',
                'longitude' => 'sometimes|numeric',
                'timezone' => 'sometimes|string|max:255',
                'ip_address' => 'nullable|string|max:45',
                'is_auto_detected' => 'sometimes|boolean'
            ]);
            
            // Здесь должна быть логика обновления в базе данных
            // Пока возвращаем заглушку
            return response()->json([
                'message' => 'User location updated successfully',
                'user_id' => $userId,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update user location',
                'message' => $e->getMessage()
            ], 500);
        }
    });
});

// API для получения списка российских городов
Route::get('/russian-cities', function () {
    try {
        // Здесь должен быть список российских городов
        // Пока возвращаем заглушку
        $cities = [
            ['id' => '1', 'name' => 'Москва', 'region' => 'Московская область'],
            ['id' => '2', 'name' => 'Санкт-Петербург', 'region' => 'Ленинградская область'],
            ['id' => '3', 'name' => 'Новосибирск', 'region' => 'Новосибирская область'],
            ['id' => '4', 'name' => 'Екатеринбург', 'region' => 'Свердловская область'],
            ['id' => '5', 'name' => 'Казань', 'region' => 'Республика Татарстан'],
            ['id' => '6', 'name' => 'Нижний Новгород', 'region' => 'Нижегородская область'],
            ['id' => '7', 'name' => 'Челябинск', 'region' => 'Челябинская область'],
            ['id' => '8', 'name' => 'Самара', 'region' => 'Самарская область'],
            ['id' => '9', 'name' => 'Омск', 'region' => 'Омская область'],
            ['id' => '10', 'name' => 'Ростов-на-Дону', 'region' => 'Ростовская область']
        ];
        
        return response()->json($cities);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to get cities list',
            'message' => $e->getMessage()
        ], 500);
    }
});

// API для поиска по КЛАДР через DaData
Route::get('/kladr/search', function (Request $request) {
    $query = $request->get('q', '');
    
    if (empty($query)) {
        return response()->json([
            'addresses' => [],
            'total' => 0,
            'message' => 'Введите поисковый запрос'
        ]);
    }
    
    try {
        $geoService = app(\App\Services\GeoLocationService::class);
        $result = $geoService->getLocationByKladr($query);
        
        return response()->json($result);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to search KLADR',
            'message' => $e->getMessage(),
            'addresses' => [],
            'total' => 0
        ], 500);
    }
});

// API для получения адреса по координатам через Яндекс.Геокодер
Route::get('/geocoder/reverse', function (Request $request) {
    $lat = $request->get('lat');
    $lon = $request->get('lon');
    
    if (!$lat || !$lon) {
        return response()->json([
            'error' => 'Latitude and longitude are required',
            'message' => 'Параметры lat и lon обязательны'
        ], 400);
    }
    
    try {
        $geoService = app(\App\Services\GeoLocationService::class);
        $result = $geoService->getLocationByCoordinates((float)$lat, (float)$lon);
        
        if ($result) {
            return response()->json([
                'success' => true,
                'address' => $result
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Адрес не найден'
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to get address',
            'message' => $e->getMessage()
        ], 500);
    }
});

// YML Feed для Яндекс.Маркета
Route::get('/yml-feed', [YmlFeedController::class, 'index']);
Route::get('/yml-feed/{page?}', [YmlFeedController::class, 'index']);
Route::post('/promotion/visit', [App\Http\Controllers\YandexBoostController::class, 'track'])->middleware('throttle:120,1');


// Second Life Marketplace API routes
Route::get('/seller/tax-statuses', [App\Http\Controllers\SecondLife\DictionaryController::class, 'taxStatuses']);
Route::get('/product-origin-types', [App\Http\Controllers\SecondLife\DictionaryController::class, 'productOriginTypes']);
Route::get('/product-conditions', [App\Http\Controllers\SecondLife\DictionaryController::class, 'productConditions']);
Route::get('/second-life/products/{productId}/payment-options', [App\Http\Controllers\SecondLife\OrderController::class, 'paymentOptions']);
Route::get('/second-life/products/{productId}/payment-options', [App\Http\Controllers\SecondLife\OrderController::class, 'paymentOptions']);

Route::middleware('auth:api')->group(function () {
    Route::put('/products/{product}/boost', [App\Http\Controllers\YandexBoostController::class, 'toggle'])->middleware('throttle:30,1');
    Route::put('/seller/promotion/boost', [App\Http\Controllers\YandexBoostController::class, 'bulkToggle'])->middleware('throttle:10,1');
    Route::get('/seller/promotion', [App\Http\Controllers\YandexBoostController::class, 'dashboard']);
    Route::get('/seller/payment-profiles', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'index']);
    Route::post('/seller/payment-profiles', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'store']);
    Route::get('/seller/payment-profiles/{id}', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'show']);
    Route::put('/seller/payment-profiles/{id}', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'update']);
    Route::delete('/seller/payment-profiles/{id}', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'destroy']);
    Route::post('/seller/payment-profiles/{id}/set-default', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'setDefault']);
    Route::post('/seller/payment-profiles/{id}/activate', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'activate']);
    Route::post('/seller/payment-profiles/{id}/deactivate', [App\Http\Controllers\SecondLife\PaymentProfileController::class, 'deactivate']);

    Route::post('/products/{id}/publish', App\Http\Controllers\SecondLife\ProductPublishController::class);

    Route::post('/second-life/orders', [App\Http\Controllers\SecondLife\OrderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/second-life/orders/{publicId}', [App\Http\Controllers\SecondLife\OrderController::class, 'show']);
    Route::post('/second-life/orders/{publicId}/mark-paid', [App\Http\Controllers\SecondLife\OrderController::class, 'markPaid'])->middleware('throttle:5,1');
    Route::post('/second-life/orders/{publicId}/confirm-payment', [App\Http\Controllers\SecondLife\OrderController::class, 'confirmPayment']);
    Route::post('/second-life/orders/{publicId}/reject-payment', [App\Http\Controllers\SecondLife\OrderController::class, 'rejectPayment']);
    Route::post('/second-life/orders/{publicId}/cancel', [App\Http\Controllers\SecondLife\OrderController::class, 'cancel']);
    Route::post('/second-life/orders/{publicId}/open-dispute', [App\Http\Controllers\SecondLife\OrderController::class, 'openDispute']);
    Route::get('/second-life/orders/{publicId}/proof/{confirmation}', [App\Http\Controllers\SecondLife\OrderController::class, 'proof']);
    Route::get('/second-life/orders/{publicId}/qr', [App\Http\Controllers\SecondLife\OrderController::class, 'qr']);

    Route::get('/seller/balance/transactions', [App\Http\Controllers\SecondLife\SellerController::class, 'balanceTransactions']);
    Route::get('/seller/rating', [App\Http\Controllers\SecondLife\SellerController::class, 'rating']);
    Route::post('/seller/agreements', [App\Http\Controllers\SecondLife\SellerController::class, 'acceptAgreement']);
    Route::get('/admin/second-life/payment-profiles', [App\Http\Controllers\SecondLife\AdminController::class, 'profiles']);
    Route::get('/admin/second-life/orders', [App\Http\Controllers\SecondLife\AdminController::class, 'orders']);
    Route::get('/admin/second-life/orders/{id}', [App\Http\Controllers\SecondLife\AdminController::class, 'order']);
    Route::post('/admin/second-life/orders/{id}/action', [App\Http\Controllers\SecondLife\AdminController::class, 'action']);
    Route::get('/admin/second-life/payment-confirmations', [App\Http\Controllers\SecondLife\AdminController::class, 'confirmations']);
});

// Seller AI services API routes
Route::middleware('auth:api')->group(function () {
    Route::get('/ai/services', [App\Http\Controllers\Ai\AiServiceController::class, 'services']);
    Route::post('/ai/jobs', [App\Http\Controllers\Ai\AiServiceController::class, 'createJob']);
    Route::get('/ai/jobs/{id}', [App\Http\Controllers\Ai\AiServiceController::class, 'showJob']);
    Route::post('/ai/jobs/{id}/confirm', [App\Http\Controllers\Ai\AiServiceController::class, 'confirm']);
    Route::post('/ai/jobs/{id}/cancel', [App\Http\Controllers\Ai\AiServiceController::class, 'cancel']);
    Route::post('/ai/jobs/{id}/apply', [App\Http\Controllers\Ai\AiServiceController::class, 'apply']);
    Route::post('/ai/jobs/{id}/reject', [App\Http\Controllers\Ai\AiServiceController::class, 'reject']);
    Route::get('/seller/ai/jobs', [App\Http\Controllers\Ai\AiServiceController::class, 'sellerJobs']);
});
