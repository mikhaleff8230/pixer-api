<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Marvel\Enums\Permission;
use Marvel\Http\Controllers\AbusiveReportController;
use Marvel\Http\Controllers\AddressController;
use Marvel\Http\Controllers\AiController;
use Marvel\Http\Controllers\AnalyticsController;
use Marvel\Http\Controllers\AttachmentController;
use Marvel\Http\Controllers\AttributeController;
use Marvel\Http\Controllers\AttributeValueController;
use Marvel\Http\Controllers\AuthorController;
use Marvel\Http\Controllers\CategoryController;
use Marvel\Http\Controllers\CheckoutController;
use Marvel\Http\Controllers\ConversationController;
use Marvel\Http\Controllers\CouponController;
use Marvel\Http\Controllers\DeliveryTimeController;
use Marvel\Http\Controllers\DownloadController;
use Marvel\Http\Controllers\FeedbackController;
use Marvel\Http\Controllers\ManufacturerController;
use Marvel\Http\Controllers\MessageController;
use Marvel\Http\Controllers\OrderController;
use Marvel\Http\Controllers\PaymentIntentController;
use Marvel\Http\Controllers\PaymentMethodController;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Controllers\ProductWizardController;
use Marvel\Http\Controllers\QuestionController;
use Marvel\Http\Controllers\RefundController;
use Marvel\Http\Controllers\ResourceController;
use Marvel\Http\Controllers\ReviewController;
use Marvel\Http\Controllers\SettingsController;
use Marvel\Http\Controllers\ShippingController;
use Marvel\Http\Controllers\ShopController;
use Marvel\Http\Controllers\TagController;
use Marvel\Http\Controllers\HashtagController;
use Marvel\Http\Controllers\TaxController;
use Marvel\Http\Controllers\TypeController;
use Marvel\Http\Controllers\UserController;
use Marvel\Http\Controllers\WebHookController;
use Marvel\Http\Controllers\WishlistController;
use Marvel\Http\Controllers\WithdrawController;
use Marvel\Http\Controllers\LanguageController;
use Marvel\Http\Controllers\StoreNoticeController;
use Marvel\Http\Controllers\PlaceController;
use Marvel\Http\Controllers\PlaceWishlistController;
use Marvel\Http\Controllers\PlaceLikeController;
use Marvel\Http\Controllers\PlaceCommentController;
use Marvel\Http\Controllers\CommentController;
use Marvel\Http\Controllers\XmlImportController;
use Marvel\Http\Controllers\PlaceParserController;
use App\Http\Controllers\CustomYooKassaOrderController;
use App\Http\Controllers\Admin\AdminInvoiceController;
use App\Http\Controllers\Admin\AdminBillingProductController;
use App\Http\Controllers\Admin\AdminBillingSellerController;
use App\Http\Controllers\Admin\AdminBillingSettingsController;
use App\Http\Controllers\Admin\AdminBillingReportsController;
use App\Http\Controllers\Admin\AdminBillingPlanController;
use App\Http\Controllers\CourseController;
use Marvel\Http\Controllers\PvzController;
use Marvel\Http\Controllers\UserAddressController;
use Marvel\Http\Controllers\ShipmentController;
use Marvel\Http\Controllers\EmailController;
use Marvel\Http\Controllers\CategoryAttributeController;
use Marvel\Http\Controllers\ProductAttributeController;
use Marvel\Http\Controllers\SearchController;
use Marvel\Http\Controllers\TestVariableProductController;
use Marvel\Http\Controllers\ProductGroupController;
use Marvel\Http\Controllers\ProductSkuController;


/**
 * ******************************************
 * Available Public Routes
 * ******************************************
 */

// Elasticsearch Search Routes
Route::get('/search', [SearchController::class, 'search']);
Route::get('/search/autocomplete', [SearchController::class, 'autocomplete']);
Route::get('/search/suggestions', [SearchController::class, 'suggestions']);
Route::post('/search/track-click', [SearchController::class, 'trackClick']);

// CustomYooKassaOrderController маршруты перенесены в routes/api.php
// YooKassa webhook также обрабатывается в routes/api.php
Route::get('/email/verify/{id}/{hash}', [UserController::class, 'verifyEmail'])->name('verification.verify');

Route::post('/register', [UserController::class, 'register']);
Route::post('/token', [UserController::class, 'token']);
Route::post('/logout', [UserController::class, 'logout']);

// Тестовый роут для диагностики авторизации (БЕЗ middleware для проверки)
Route::get('/test-auth-debug', function (Request $request) {
    $token = $request->bearerToken();
    
    // Пробуем найти токен в БД
    $tokenModel = null;
    $userFromToken = null;
    if ($token) {
        try {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel) {
                $userFromToken = $tokenModel->tokenable;
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки
        }
    }
    
    return response()->json([
        'has_bearer_token' => !empty($token),
        'token_preview' => $token ? substr($token, 0, 30) . '...' : null,
        'token_found_in_db' => $tokenModel !== null,
        'user_from_token' => $userFromToken ? [
            'id' => $userFromToken->id,
            'name' => $userFromToken->name,
            'email' => $userFromToken->email,
        ] : null,
        'request_user' => $request->user() ? [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
        ] : null,
        'auth_sanctum_check' => auth('sanctum')->check(),
        'auth_sanctum_user' => auth('sanctum')->user() ? auth('sanctum')->user()->id : null,
        'auth_api_check' => auth('api')->check(),
        'auth_api_user' => auth('api')->user() ? auth('api')->user()->id : null,
        'auth_web_check' => auth('web')->check(),
    ]);
});

// Тестовый роут с auth:sanctum middleware
Route::get('/test-auth', function (Request $request) {
    $user = $request->user();
    return response()->json([
        'success' => true,
        'user' => $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ] : null,
    ]);
})->middleware('auth:sanctum');
Route::post('/forget-password', [UserController::class, 'forgetPassword']);
Route::post('/verify-forget-password-token', [UserController::class, 'verifyForgetPasswordToken']);
Route::post('/reset-password', [UserController::class, 'resetPassword']);
Route::post('/contact-us', [UserController::class, 'contactAdmin']);
Route::post('/social-login-token', [UserController::class, 'socialLogin']);
Route::post('/send-otp-code', [UserController::class, 'sendOtpCode']);
Route::post('/verify-otp-code', [UserController::class, 'verifyOtpCode']);
Route::post('/otp-login', [UserController::class, 'otpLogin']);
Route::post('/set-pin-code', [UserController::class, 'setPinCode'])->middleware('auth:sanctum');
Route::post('/verify-pin-code', [UserController::class, 'verifyPinCode']);
Route::get('top-shops', [ShopController::class, 'topShops']);
Route::get('top-authors', [AuthorController::class, 'topAuthor']);
Route::get('top-manufacturers', [ManufacturerController::class, 'topManufacturer']);
Route::get('popular-products', [ProductController::class, 'popularProducts']);
Route::get('best-selling-products', [ProductController::class, 'bestSellingProducts']);

// === ГЕОЛОКАЦИЯ ===
Route::get('products/geo-feed', [ProductController::class, 'geoFeed']); // Основной гео-фильтр (регион + соседи + радиус)

// Карта: отдельный путь, чтобы не пересекаться с пагинацией GET /products и с products/{id}
Route::get('products/map', [ProductController::class, 'productsMapByBbox']);

// Поддержка bbox через тот же index (опционально). Должен быть ДО apiResource('products')
Route::get('products', [ProductController::class, 'index']);
Route::get('products/related', [ProductController::class, 'relatedProducts']);
Route::get('products/dynamic', [ProductController::class, 'dynamicProducts']);
Route::get('products/search', [ProductController::class, 'searchProducts']);
Route::get('products/filters', [ProductController::class, 'getFilters']);
Route::get('products/test-category-children', [ProductController::class, 'testCategoryChildren']);
Route::get('check-availability', [ProductController::class, 'checkAvailability']);
Route::get("products/calculate-rental-price", [ProductController::class, 'calculateRentalPrice']);
Route::post('import-products', [ProductController::class, 'importProducts']);
Route::post('import-variation-options', [ProductController::class, 'importVariationOptions']);
Route::get('products/{id}/download', [DownloadController::class, 'downloadPurchasedProduct'])
    ->middleware('auth:sanctum')
    ->where('id', '[0-9]+');
// Доступ без обязательного Bearer: при отсутствии сессии/токена можно передать email + tracking_number
// (совпадение с email в заказе проверяется в DownloadController / DigitalAccessGrantService).
Route::get('products/{id}/access', [DownloadController::class, 'accessPurchasedProduct'])
    ->where('id', '[0-9]+');
Route::post('products/{id}/access', [DownloadController::class, 'accessPurchasedProduct'])
    ->where('id', '[0-9]+');

// Онлайн-курсы (подписка + drip + прогресс)
Route::get('courses', [CourseController::class, 'index']);
Route::get('courses/{id}/progress', [CourseController::class, 'courseProgress'])->where('id', '[0-9]+');
Route::get('courses/{id}', [CourseController::class, 'show'])->where('id', '[0-9]+');
Route::get('lessons/{id}', [CourseController::class, 'lesson'])->where('id', '[0-9]+');
Route::post('lessons/{id}/complete', [CourseController::class, 'completeLesson'])->where('id', '[0-9]+');

// Тестовые маршруты для отладки вариативных товаров
Route::get('test/variable-product/check', [TestVariableProductController::class, 'checkData']);
Route::post('test/variable-product', [TestVariableProductController::class, 'testCreateVariableProduct'])->middleware('auth:sanctum');
Route::get('export-products/{shop_id}', [ProductController::class, 'exportProducts']);
Route::get('export-variation-options/{shop_id}', [ProductController::class, 'exportVariableOptions']);
Route::post('generate-description', [ProductController::class, 'generateDescription']);
Route::post('import-attributes', [AttributeController::class, 'importAttributes']);
Route::get('export-attributes/{shop_id}', [AttributeController::class, 'exportAttributes']);
Route::get('download_url/token/{token}', [DownloadController::class, 'downloadFile'])->name('download_url.token');
Route::get('export-order/token/{token}', [OrderController::class, 'exportOrder'])->name('export_order.token');
Route::post('subscribe-to-newsletter', [UserController::class, 'subscribeToNewsletter'])->name('subscribeToNewsletter');
Route::get('download-invoice/token/{token}', [OrderController::class, 'downloadInvoice'])->name('download_invoice.token');
Route::post('webhooks/razorpay', [WebHookController::class, 'razorpay']);
Route::post('webhooks/intellectmoney', [WebHookController::class, 'intellectmoney']);
Route::post('webhooks/tinkoff', [WebHookController::class, 'tinkoff']);
Route::post('webhooks/stripe', [WebHookController::class, 'stripe']);
Route::post('webhooks/paypal', [WebHookController::class, 'paypal']);
Route::post('webhooks/mollie', [WebHookController::class, 'mollie']);
Route::post('webhooks/sslcommerz', [WebHookController::class, 'sslcommerz'])->name('sslc.sslcommerz');
Route::post('webhooks/paystack', [WebHookController::class, 'paystack']);
Route::post('webhooks/paymongo', [WebHookController::class, 'paymongo']);
Route::post('webhooks/xendit', [WebHookController::class, 'xendit']);
Route::post('webhooks/iyzico', [WebHookController::class, 'iyzico']);
Route::post('webhooks/bitpay', [WebHookController::class, 'bitpay']);
Route::post('webhooks/coinbase', [WebHookController::class, 'coinbase']);
Route::post('webhooks/bkash', [WebHookController::class, 'bkash']);
Route::post('webhooks/flutterwave', [WebHookController::class, 'flutterwave']);
// YooKassa webhook обрабатывается в routes/api.php

Route::get('callback/flutterwave', [WebHookController::class, 'callback'])->name('callback.flutterwave');

Route::get('near-by-shop/{lat}/{lng}', [ShopController::class, 'nearByShop']);

Route::get('store-notices', [StoreNoticeController::class, 'index'])->name('store-notices.index');

// ПВЗ API для СДЭК и Яндекс.Доставка
Route::get('pvz', [PvzController::class, 'getPvz']);
Route::post('pvz/calculate', [PvzController::class, 'calculateDelivery']);
Route::post('pvz/orders', [PvzController::class, 'createOrder']);
Route::get('pvz/orders/{uuid}', [PvzController::class, 'getOrderInfo']);
Route::get('pvz/tariffs', [PvzController::class, 'getAvailableTariffs']);

// Shipments routes
Route::get('shipments', [ShipmentController::class, 'index']);
Route::get('shipments/{id}', [ShipmentController::class, 'show']);
Route::get('shipments/order/{orderId}', [ShipmentController::class, 'getByOrder']);
Route::get('shipments/statistics', [ShipmentController::class, 'getStatistics']);
Route::post('shipments/webhook', [ShipmentController::class, 'webhook']);

// GeoIP routes перенесены в основной routes/api.php

// ВАЖНО: специфичные маршруты должны идти ДО resource, иначе они перехватываются
// Публичный маршрут для получения атрибутов товара (для фронтенда) - ДО apiResource
// ВАЖНО: Этот маршрут должен быть доступен по /api/products/{id}/attributes для совместимости с фронтендом
Route::prefix('api')->group(function () {
    Route::get('products/{productId}/attributes', [ProductAttributeController::class, 'getProductAttributes']);
    
    // Новый формат URL для всех товаров: /api/element/{slug}-{id}
    // Этот роут обрабатывает и простые товары (Product), и группы (ProductGroup)
    // ВАЖНО: Этот маршрут должен быть доступен по /api/element/... для совместимости с Next.js rewrites
    Route::get('element/{slugId}', function($slugId, \Illuminate\Http\Request $request) {
    $language = $request->language ?? DEFAULT_LANGUAGE;
    
    // Логируем для отладки
    \Log::info('Route /element/{slugId}', [
        'slugId' => $slugId,
        'language' => $language,
    ]);
    
    // ВАЖНО: Сначала пробуем найти товар напрямую через ProductController::show()
    // Это правильно обрабатывает новые товары с slug_numeric_code
    // ProductController::show() имеет полную логику поиска по slug + slug_numeric_code
    try {
        $productController = app(\Marvel\Http\Controllers\ProductController::class);
        $response = $productController->show($request, $slugId);
        
        // Проверяем, что ответ успешный (не 404)
        if ($response) {
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                \Log::info('Product found via ProductController::show', [
                    'slugId' => $slugId,
                    'statusCode' => $statusCode
                ]);
                return $response;
            }
        }
    } catch (\Marvel\Exceptions\MarvelNotFoundException $e) {
        // Товар не найден - это нормально, пробуем другие способы
        \Log::info('Product not found via ProductController::show, trying alternative search', [
            'slugId' => $slugId
        ]);
    } catch (\Exception $e) {
        \Log::warning('ProductController::show failed, trying alternative search', [
            'slugId' => $slugId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    
    // Если ProductController не нашел, пробуем альтернативные способы
    // Пытаемся определить, это Product или ProductGroup
    $parsed = \Marvel\Database\Models\Product::parseSlugId($slugId);
    $id = $parsed['id'];
    $slug = $parsed['slug'];
    
    \Log::info('Parsed slugId', [
        'slug' => $slug,
        'id' => $id,
    ]);
    
    if ($id) {
        // Если есть ID, проверяем что существует
        // Сначала проверяем Product (С УЧЕТОМ LANGUAGE!)
        $product = \Marvel\Database\Models\Product::where('id', $id)
            ->where('language', $language)
            ->first();
        
        if ($product) {
            \Log::info('Product found by ID', ['id' => $id, 'slug' => $product->slug]);
            return app(\Marvel\Http\Controllers\ProductController::class)->show($request, $slugId);
        }
        
        // Если не Product, проверяем ProductGroup (С УЧЕТОМ LANGUAGE!)
        $group = \Marvel\Database\Models\ProductGroup::where('id', $id)
            ->where('language', $language)
            ->first();
        
        if ($group) {
            \Log::info('ProductGroup found by ID', ['id' => $id, 'slug' => $group->slug]);
            return app(\Marvel\Http\Controllers\ProductGroupController::class)->show($slugId);
        }
        
        \Log::warning('Product/Group not found by ID', ['id' => $id, 'language' => $language]);
    }
    
    // Если ID не передан или не найден по ID, ищем по slug
    // ВАЖНО: Для новых товаров нужно искать по slug + slug_numeric_code
    // 1. Ищем в обычных товарах (Product) - с учетом slug_numeric_code
    $product = null;
    
    // Проверяем, заканчивается ли slugId на 12-значный код
    if (preg_match('/^(.+)-(\d{12})$/', $slugId, $matches)) {
        $baseSlug = $matches[1];
        $code = $matches[2];
        // Ищем по базовому slug + slug_numeric_code
        $product = \Marvel\Database\Models\Product::where('slug', $baseSlug)
            ->where('slug_numeric_code', $code)
            ->where('language', $language)
            ->first();
    }
    
    // Если не найден по коду, ищем по базовому slug (для старых товаров)
    if (!$product) {
        $product = \Marvel\Database\Models\Product::where('slug', $slug)
            ->where('language', $language)
            ->first();
    }
    
    if ($product) {
        \Log::info('Product found by slug', ['slug' => $slug, 'slugId' => $slugId]);
        return app(\Marvel\Http\Controllers\ProductController::class)->show($request, $slugId);
    }
    
    // 2. Ищем в SKU (ProductSku) - это приоритет для вариаций
    $sku = \Marvel\Database\Models\ProductSku::where('slug', $slug)
        ->where('language', $language)
        ->first();
    if ($sku) {
        \Log::info('ProductSku found by slug', ['slug' => $slug]);
        // Используем универсальный метод для получения SKU
        return app(\Marvel\Http\Controllers\ProductSkuController::class)->getBySlug($slug, $language);
    }
    
    // 3. Ищем в группах товаров (ProductGroup)
    $group = \Marvel\Database\Models\ProductGroup::where('slug', $slug)->where('language', $language)->first();
    if ($group) {
        \Log::info('ProductGroup found by slug', ['slug' => $slug]);
        return app(\Marvel\Http\Controllers\ProductGroupController::class)->show($slugId);
    }
    
    \Log::error('404 - Nothing found', ['slugId' => $slugId, 'slug' => $slug, 'id' => $id, 'language' => $language]);
    abort(404);
    })->where('slugId', '.*');
    
    // Вариации (SKU) - URL формат: /api/element/{groupSlug}/{skuSlug}-{skuId}
    Route::get('element/{groupSlug}/{skuSlugId}', [ProductSkuController::class, 'show'])->where(['groupSlug' => '.*', 'skuSlugId' => '.*']);
});

// GET /products только через явный маршрут выше (строка ~163): там же ветка bbox для карты.
// Не добавлять index в apiResource — иначе дублируется GET /products и может отрабатывать «чужой» index.
Route::apiResource('products', ProductController::class, [
    'only' => ['show'],
]);

// Product Groups - списки для админки
Route::get('product-groups', [ProductGroupController::class, 'index']);
Route::get('product-groups/{groupId}/skus', [ProductSkuController::class, 'index'])->where('groupId', '[0-9]+');

// Product SKUs - общий список (для фильтрации по group_id)
Route::get('product-skus', [ProductSkuController::class, 'index']);
// Product SKUs - получение по slug для админки
Route::get('product-skus/{slug}', function($slug) {
    $sku = \Marvel\Database\Models\ProductSku::where('slug', $slug)
        ->with(['group', 'propertyValues', 'propertyValues.attribute', 'properties'])
        ->first();
    if ($sku) {
        return response()->json($sku);
    }
    abort(404);
});

// ProductGroup - получение по slug для админки (редактирование)
Route::get('product-groups/{slug}', [ProductGroupController::class, 'show']);
Route::get('skus/{slug}', function($slug) {
    $sku = \Marvel\Database\Models\ProductSku::where('slug', $slug)->with('group')->first();
    if ($sku) {
        return redirect("/api/element/{$sku->group->slug}/{$sku->slug}-{$sku->id}", 301);
    }
    abort(404);
});

// SKU - получение по ID для админки (редактирование)
Route::get('skus/{id}/get', function($id) {
    $sku = \Marvel\Database\Models\ProductSku::with(['group', 'propertyValues', 'propertyValues.attribute'])->find($id);
    if ($sku) {
        return response()->json($sku);
    }
    abort(404);
})->where('id', '[0-9]+');
Route::apiResource('types', TypeController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('attachments', AttachmentController::class, [
    'only' => ['index', 'show'],
]);
// ВАЖНО: специфичный маршрут должен идти ДО resource, иначе "menu" перехватывается как {category}
Route::get('categories/debug', [CategoryController::class, 'debugCategories']);
Route::get('categories/menu', [CategoryController::class, 'getMenuCategories']);
// Публичный маршрут для получения атрибутов категории (для фронтенда)
Route::get('categories/{categoryId}/attributes', [CategoryAttributeController::class, 'getCategoryAttributes']);
Route::apiResource('categories', CategoryController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('delivery-times', DeliveryTimeController::class, [
    'only' => ['index', 'show']
]);
Route::apiResource('languages', LanguageController::class, [
    'only' => ['index', 'show']
]);
Route::apiResource('tags', TagController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('hashtags', HashtagController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('resources', ResourceController::class, [
    'only' => ['index', 'show']
]);
Route::apiResource('coupons', CouponController::class, [
    'only' => ['index', 'show'],
]);
Route::post('coupons/verify', [CouponController::class, 'verify']);
Route::apiResource('attributes', AttributeController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('shops', ShopController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('settings', SettingsController::class, [
    'only' => ['index'],
]);
Route::apiResource('reviews', ReviewController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('questions', QuestionController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('feedbacks', FeedbackController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('authors', AuthorController::class, [
    'only' => ['index', 'show'],
]);
Route::apiResource('manufacturers', ManufacturerController::class, [
    'only' => ['index', 'show'],
]);

// Places - публичные роуты с SEO-URL
Route::get('places/feed', [PlaceController::class, 'feed']); // Cursor-based infinite scroll feed
Route::get('places/favorites', [PlaceController::class, 'favorites']); // Избранные плейсы пользователя
Route::get('places/{id}/similar', [PlaceController::class, 'similar'])->where('id', '[0-9]+'); // Похожие места
Route::get('places', [PlaceController::class, 'index']);
Route::get('places/search/products', [PlaceController::class, 'searchProducts']); // ВАЖНО: до {slugId}
Route::get('places/{slugId}', [PlaceController::class, 'show'])
    ->where('slugId', '[a-z0-9\-]+'); // Поддержка формата {slug}-{id}

// Place like routes (public, no auth required)
Route::post('place-likes/toggle/{placeId}', [PlaceLikeController::class, 'toggle']);
Route::get('place-likes/check/{placeId}', [PlaceLikeController::class, 'check']);
Route::get('place-likes/likers/{placeId}', [PlaceLikeController::class, 'likers']);

// Place comments routes (public read, auth required for write) - DEPRECATED, используйте /api/comments
Route::get('places/{placeId}/comments', [PlaceCommentController::class, 'index']);

// Universal comments routes (new architecture) - публичный доступ для чтения
Route::get('comments', [CommentController::class, 'index']); // Публичный доступ - только approved комментарии

Route::post('orders/checkout/verify', [CheckoutController::class, 'verify']);
Route::post('orders/create', [OrderController::class, 'createDigitalOrder'])->middleware('auth:sanctum');
Route::post('payments/success', [OrderController::class, 'markDigitalPaymentSuccess'])->middleware('auth:sanctum');
Route::post('payments/yookassa/confirm', [OrderController::class, 'confirmYooKassaPayment'])->middleware('auth:sanctum');
Route::apiResource('orders', OrderController::class, [
    'only' => ['show', 'store'],
]);

Route::post('/email/verification-notification', [UserController::class, 'sendVerificationEmail'])
    ->middleware(['auth:api', 'throttle:6,1'])
    ->name('verification.send');

Route::post('orders/payment', [OrderController::class, 'submitPayment']);
Route::post('generate-descriptions', [AiController::class, 'generateDescription']);
Route::get('/payment-intent', [PaymentIntentController::class, 'getPaymentIntent']);

/**
 * ******************************************
 * Authorized Route for Customers only
 * ******************************************
 */

Route::post('free-downloads/digital-file', [DownloadController::class, 'generateFreeDigitalDownloadableUrl']);

Route::group(['middleware' => ['can:' . Permission::CUSTOMER, 'auth:sanctum', 'email.verified']], function () {

    Route::post('/update-email', [UserController::class, 'updateUserEmail']);
    Route::get('me', [UserController::class, 'me']);

    Route::apiResource('orders', OrderController::class, [
        'only' => ['index'],
    ]);
    Route::apiResource('reviews', ReviewController::class, [
        'only' => ['store', 'update']
    ]);
    Route::apiResource('questions', QuestionController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('feedbacks', FeedbackController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('abusive_reports', AbusiveReportController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('conversations', ConversationController::class, [
        'only' => ['index', 'store'],
    ]);
    Route::get('conversations/{conversation_id}', [ConversationController::class, 'show']);
    Route::get('messages/conversations/{conversation_id}', [MessageController::class, 'index']);
    Route::post('messages/conversations/{conversation_id}', [MessageController::class, 'store']);
    Route::post('messages/seen/{conversation_id}', [MessageController::class, 'seen']);
    
    // Chat API routes (compatible with frontend)
    Route::get('chat/conversations', [ConversationController::class, 'index']);
    Route::get('chat/conversations/{id}', [ConversationController::class, 'show']);
    Route::post('chat/messages', [MessageController::class, 'storeMessage']);
    Route::post('chat/attachments', [MessageController::class, 'uploadAttachment']);
    Route::post('chat/conversations/{id}/read', [MessageController::class, 'markAsRead']);
    Route::get('my-questions', [QuestionController::class, 'myQuestions']);

    // User addresses routes
    Route::get('user/addresses', [UserAddressController::class, 'index']);
    Route::post('user/addresses', [UserAddressController::class, 'store']);
    Route::get('user/addresses/{id}', [UserAddressController::class, 'show']);
    Route::put('user/addresses/{id}', [UserAddressController::class, 'update']);
    Route::delete('user/addresses/{id}', [UserAddressController::class, 'destroy']);
    Route::post('user/addresses/{id}/set-default', [UserAddressController::class, 'setDefault']);
    Route::post('user/addresses/add-pvz', [UserAddressController::class, 'addPvzFromMap']);
    Route::get('my-reports', [AbusiveReportController::class, 'myReports']);
    Route::post('wishlists/toggle', [WishlistController::class, 'toggle']);
    Route::apiResource('wishlists', WishlistController::class, [
        'only' => ['index', 'store', 'destroy'],
    ]);
    Route::get('wishlists/in_wishlist/{product_id}', [WishlistController::class, 'in_wishlist']);
    Route::get('my-wishlists', [ProductController::class, 'myWishlists']);
    Route::get('orders/tracking-number/{tracking_number}', 'Marvel\Http\Controllers\OrderController@findByTrackingNumber');
    Route::post('orders/{tracking_number}/cancel', [OrderController::class, 'cancelOrder']);
    Route::apiResource('attachments', AttachmentController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    
    Route::apiResource('places', PlaceController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    
    // Place wishlist routes
    Route::post('place-wishlists/toggle', [PlaceWishlistController::class, 'toggle']);
    Route::apiResource('place-wishlists', PlaceWishlistController::class, [
        'only' => ['index', 'store', 'destroy'],
    ]);
    Route::get('place-wishlists/in_wishlist/{place_id}', [PlaceWishlistController::class, 'in_wishlist']);
    Route::get('my-place-wishlists', [PlaceController::class, 'myPlaceWishlists']);

    // Place like routes (authenticated only)
    Route::get('my-place-likes', [PlaceLikeController::class, 'myLikes']);

            // Place comments routes (authenticated only for write operations) - DEPRECATED
            Route::post('places/{placeId}/comments', [PlaceCommentController::class, 'store']);
            Route::put('places/{placeId}/comments/{commentId}', [PlaceCommentController::class, 'update']);
            Route::delete('places/{placeId}/comments/{commentId}', [PlaceCommentController::class, 'destroy']);

            // Universal comments routes (new architecture) - authenticated only
            Route::post('comments', [CommentController::class, 'store']);
            Route::put('comments/{comment}', [CommentController::class, 'update']);
            Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

    Route::put('users/{id}', [UserController::class, 'update']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
    Route::post('/update-contact', [UserController::class, 'updateContact']);
    Route::apiResource('address', AddressController::class, [
        'only' => ['destroy'],
    ]);
    Route::apiResource(
        'refunds',
        RefundController::class,
        [
            'only' => ['index', 'store', 'show'],
        ]
    );
    Route::get('downloads', [DownloadController::class, 'fetchDownloadableFiles']);
    Route::post('downloads/digital-file', [DownloadController::class, 'generateDownloadableUrl']);
    Route::get('/followed-shops-popular-products', [ShopController::class, 'followedShopsPopularProducts']);
    Route::get('/followed-shops', [ShopController::class, 'userFollowedShops']);
    Route::get('/follow-shop', [ShopController::class, 'userFollowedShop']);
    Route::post('/follow-shop', [ShopController::class, 'handleFollowShop']);
    Route::apiResource('cards', PaymentMethodController::class, [
        'only' => ['index', 'store', 'update', 'destroy'],
    ]);
    Route::post('/set-default-card', [PaymentMethodController::class, 'setDefaultCard']);
    Route::post('/save-payment-method', [PaymentMethodController::class, 'savePaymentMethod']);

    // User shipments routes
    Route::get('user/shipments', [ShipmentController::class, 'index']);
    Route::get('user/shipments/{id}', [ShipmentController::class, 'show']);
});

/**
 * ******************************************
 * Authorized Route for Staff & Store Owner
 * ******************************************
 */

Route::group(
    ['middleware' => ['permission:' . Permission::STAFF . '|' . Permission::STORE_OWNER, 'auth:api', 'email.verified']],
    function () {
        // ВАЖНО: Специфичные роуты должны идти ДО apiResource, иначе они перехватываются
        // Product Wizard routes (для работы с вариациями в визарде) - ДО apiResource('products')
        Route::post('/products/wizard/variants', [ProductWizardController::class, 'saveVariants']);
        Route::get('/products/wizard/variants', [ProductWizardController::class, 'getVariants']);
        Route::delete('/products/wizard/variants/{id}', [ProductWizardController::class, 'deleteVariant'])->where('id', '[0-9]+');
        Route::post('/products/wizard/ungroup', [ProductWizardController::class, 'ungroupProducts']);
        Route::post('/products/wizard/create-group', [ProductWizardController::class, 'createGroup']);
        
        // Product-Attribute management routes (GET уже публичный выше) - ДО apiResource('products')
        Route::post('/products/attributes/set', [ProductAttributeController::class, 'setProductAttributeValue']);
        Route::put('/products/attributes/update', [ProductAttributeController::class, 'updateProductAttributes']);
        Route::delete('/products/attributes/remove', [ProductAttributeController::class, 'removeProductAttribute']);
        Route::post('/products/filter-by-attributes', [ProductAttributeController::class, 'filterProductsByAttributes']);
        
        // Теперь регистрируем apiResource для products (после специфичных роутов)
        Route::apiResource('products', ProductController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);

        // Product Groups & SKUs CRUD (для владельцев магазинов и персонала)
        Route::apiResource('product-groups', ProductGroupController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::post('product-groups/{groupId}/skus', [ProductSkuController::class, 'store'])->where('groupId', '[0-9]+');
        Route::post('product-groups/{groupId}/generate-skus', [ProductSkuController::class, 'generateSkus'])->where('groupId', '[0-9]+');
        Route::put('skus/{id}', [ProductSkuController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('skus/{id}', [ProductSkuController::class, 'destroy'])->where('id', '[0-9]+');
        Route::apiResource('resources', ResourceController::class, [
            'only' => ['store']
        ]);
        Route::apiResource('attributes', AttributeController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::apiResource('attribute-values', AttributeValueController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);

        // Category-Attribute management routes (без GET /categories/{categoryId}/attributes - он уже публичный на строке 157)
        Route::post('/categories/attributes/attach', [CategoryAttributeController::class, 'attachAttributeToCategory']);
        Route::put('/categories/attributes/update', [CategoryAttributeController::class, 'updateCategoryAttribute']);
        Route::delete('/categories/attributes/detach', [CategoryAttributeController::class, 'detachAttributeFromCategory']);
        Route::get('/attributes/{attributeId}/categories', [CategoryAttributeController::class, 'getAttributeCategories']);

        // Allow category reorder for staff/store owner as well
        Route::patch('categories/reorder', [CategoryController::class, 'reorder']);
        Route::post('categories/reorder', [CategoryController::class, 'reorder']);
        Route::apiResource('orders', OrderController::class, [
            'only' => ['update', 'destroy'],
        ]);

        // Staff shipments routes
        Route::post('shipments', [ShipmentController::class, 'store']);
        Route::put('shipments/{id}', [ShipmentController::class, 'update']);
        Route::post('shipments/{id}/cancel', [ShipmentController::class, 'cancel']);
        Route::post('shipments/{id}/update-status', [ShipmentController::class, 'updateStatus']);

        // Route::get('shop-notification/{id}', [ShopNotificationController::class, 'show']);
        // Route::put('shop-notification/{id}', [ShopNotificationController::class, 'update']);
        // Route::get('popular-products', [AnalyticsController::class, 'popularProducts']);
        // Route::get('shops/refunds', 'Marvel\Http\Controllers\ShopController@refunds');
        Route::apiResource('questions', QuestionController::class, [
            'only' => ['update'],
        ]);
        Route::apiResource('authors', AuthorController::class, [
            'only' => ['store'],
        ]);
        Route::apiResource('manufacturers', ManufacturerController::class, [
            'only' => ['store'],
        ]);
        Route::get('store-notices/getStoreNoticeType', [StoreNoticeController::class, 'getStoreNoticeType']);
        Route::get('store-notices/getUsersToNotify', [StoreNoticeController::class, 'getUsersToNotify']);
        Route::post('store-notices/read/', [StoreNoticeController::class, 'readNotice']);
        Route::post('store-notices/read-all', [StoreNoticeController::class, 'readAllNotice']);
        Route::apiResource('store-notices', StoreNoticeController::class, [
            'only' => ['show', 'store', 'update', 'destroy']
        ]);

        Route::get('export-order-url/{shop_id?}', 'Marvel\Http\Controllers\OrderController@exportOrderUrl');
        Route::post('download-invoice-url', 'Marvel\Http\Controllers\OrderController@downloadInvoiceUrl');
    }
);


/**
 * *****************************************
 * Authorized Route for Store owner Only
 * *****************************************
 */

Route::group(
    ['middleware' => ['permission:' . Permission::STORE_OWNER, 'auth:api', 'email.verified']],
    function () {
        Route::apiResource('shops', ShopController::class, [
            'only' => ['store', 'update', 'destroy'],
        ]);
        Route::apiResource('withdraws', WithdrawController::class, [
            'only' => ['store', 'index', 'show'],
        ]);
        Route::post('staffs', [ShopController::class, 'addStaff']);
        Route::delete('staffs/{id}', [ShopController::class, 'deleteStaff']);
        Route::get('staffs', [UserController::class, 'staffs']);
        Route::get('my-shops', [ShopController::class, 'myShops']);
        Route::get('/admin/list', [UserController::class, 'admins']);
    }
);

// XML/CSV import is available to super admins and store owners. Ownership of
// the selected shop is additionally verified by XmlImportController.
Route::group(['middleware' => ['permission:' . Permission::SUPER_ADMIN . '|' . Permission::STORE_OWNER, 'auth:api', 'email.verified']], function () {
    Route::prefix('xml-import')->group(function () {
        Route::get('/fields', [XmlImportController::class, 'getAvailableFields']);
        Route::get('/stats', [XmlImportController::class, 'getStats']);
        Route::post('/preview', [XmlImportController::class, 'preview']);
        Route::post('/import', [XmlImportController::class, 'import']);
        Route::get('/progress', [XmlImportController::class, 'getImportProgress']);
        Route::get('/import-stats', [XmlImportController::class, 'getImportStats']);
        Route::get('/active-imports', [XmlImportController::class, 'getActiveImports']);
        Route::post('/cleanup', [XmlImportController::class, 'cleanupImport']);
        Route::get('/mappings', [XmlImportController::class, 'getSavedMappings']);
        Route::post('/mappings', [XmlImportController::class, 'saveFieldMapping']);
        Route::delete('/mappings/{id}', [XmlImportController::class, 'deleteMapping']);
    });
});

/**
 * *****************************************
 * Authorized Route for Super Admin only
 * *****************************************
 */

Route::group(['middleware' => ['permission:' . Permission::SUPER_ADMIN, 'auth:api']], function () {
    // Route::get('messages/get-conversations/{shop_id}', [ConversationController::class, 'getConversationByShopId']);
    Route::get('analytics', [AnalyticsController::class, 'analytics']);
    Route::apiResource('types', TypeController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::apiResource('withdraws', WithdrawController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::apiResource('categories', CategoryController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::post('categories/bulk-update-parent', [CategoryController::class, 'bulkUpdateParent']);
    Route::post('categories/bulk-update-status', [CategoryController::class, 'bulkUpdateStatus']);
    Route::patch('categories/reorder', [CategoryController::class, 'reorder']);
    // Compatibility alias (frontend may use POST)
    Route::post('categories/reorder', [CategoryController::class, 'reorder']);
    Route::apiResource('delivery-times', DeliveryTimeController::class, [
        'only' => ['store', 'update', 'destroy']
    ]);
    Route::apiResource('languages', LanguageController::class, [
        'only' => ['store', 'update', 'destroy']
    ]);
    Route::apiResource('tags', TagController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    Route::apiResource('resources', ResourceController::class, [
        'only' => ['update', 'destroy']
    ]);
    Route::apiResource('coupons', CouponController::class, [
        'only' => ['store', 'update', 'destroy'],
    ]);
    // Route::apiResource('order-status', OrderStatusController::class, [
    //     'only' => ['store', 'update', 'destroy'],
    // ]);
    Route::apiResource('reviews', ReviewController::class, [
        'only' => ['destroy']
    ]);
    Route::apiResource('questions', QuestionController::class, [
        'only' => ['destroy'],
    ]);
    Route::apiResource('feedbacks', QuestionController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::apiResource('abusive_reports', AbusiveReportController::class, [
        'only' => ['index', 'show', 'update', 'destroy'],
    ]);
    Route::post('abusive_reports/accept', [AbusiveReportController::class, 'accept']);
    Route::post('abusive_reports/reject', [AbusiveReportController::class, 'reject']);
    Route::apiResource('settings', SettingsController::class, [
        'only' => ['store'],
    ]);
    Route::apiResource('users', UserController::class);
    Route::apiResource('authors', AuthorController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::apiResource('manufacturers', ManufacturerController::class, [
        'only' => ['update', 'destroy'],
    ]);
    Route::post('users/block-user', [UserController::class, 'banUser']);
    Route::post('users/unblock-user', [UserController::class, 'activeUser']);
    Route::apiResource('taxes', TaxController::class);
    Route::apiResource('shippings', ShippingController::class);
    Route::post('approve-shop', [ShopController::class, 'approveShop']);
    Route::post('disapprove-shop', [ShopController::class, 'disApproveShop']);
    Route::post('approve-withdraw', [WithdrawController::class, 'approveWithdraw']);
    Route::post('add-points', [UserController::class, 'addPoints']);
    Route::post('users/make-admin', [UserController::class, 'makeOrRevokeAdmin']);
    Route::apiResource(
        'refunds',
        RefundController::class,
        [
            'only' => ['destroy', 'update'],
        ]
    );
    
    // Billing Admin Routes (SUPER ADMIN ONLY)
    Route::prefix('admin/billing')->group(function () {
        // Plans (Tariff Plans)
        Route::get('/plans', [AdminBillingPlanController::class, 'index']);
        Route::get('/plans/active', [AdminBillingPlanController::class, 'active']);
        Route::get('/plans/{id}', [AdminBillingPlanController::class, 'show']);
        Route::post('/plans', [AdminBillingPlanController::class, 'store']);
        Route::put('/plans/{id}', [AdminBillingPlanController::class, 'update']);
        Route::delete('/plans/{id}', [AdminBillingPlanController::class, 'destroy']);
        
        // Invoices
        Route::get('/invoices', [AdminInvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [AdminInvoiceController::class, 'show']);
        Route::post('/invoices', [AdminInvoiceController::class, 'store']);
        Route::put('/invoices/{invoice}', [AdminInvoiceController::class, 'update']);
        Route::post('/invoices/{invoice}/mark-paid', [AdminInvoiceController::class, 'markPaid']);
        
        // Products
        Route::get('/products', [AdminBillingProductController::class, 'index']);
        Route::put('/products/{product}/status', [AdminBillingProductController::class, 'updateStatus']);
        
        // Sellers
        Route::get('/sellers', [AdminBillingSellerController::class, 'index']);
        Route::get('/sellers/{seller}', [AdminBillingSellerController::class, 'show']);
        Route::post('/sellers/{seller}/toggle-status', [AdminBillingSellerController::class, 'toggleStatus']);
        
        // Shops with billing data
        Route::get('/shops', [\App\Http\Controllers\Admin\AdminBillingShopController::class, 'index']);
        
        // Settings
        Route::get('/settings', [AdminBillingSettingsController::class, 'billing']);
        Route::post('/settings', [AdminBillingSettingsController::class, 'updateBilling']);
        
        // Reports
        Route::get('/reports', [AdminBillingReportsController::class, 'index']);
    });

    // Route for serving product-parser images
    Route::get('product-parser/foto/{filename}', function ($filename) {
        $path = '/var/www/sancan.ru/product-parser/foto/' . $filename;

        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    })->where('filename', '.*');

    // Place Parser routes (ADMIN ONLY - убрано требование super_admin)
    Route::group(['middleware' => ['permission:store_owner|staff']], function () {
        Route::prefix('place-parser')->group(function () {
            Route::post('/parse', [PlaceParserController::class, 'parse']);
            Route::post('/create', [PlaceParserController::class, 'createFromParsed']);
            Route::post('/create-bulk', [PlaceParserController::class, 'createBulk']);
            Route::get('/search-users', [PlaceParserController::class, 'searchUsers']);
            Route::post('/list-images', [PlaceParserController::class, 'listImages']);
        });
    });
    
    // Email notification routes
    Route::prefix('email')->group(function () {
        // Public routes
        Route::post('/contact', [EmailController::class, 'sendContactEmail']);
        Route::post('/password-reset', [EmailController::class, 'sendPasswordResetEmail']);
        
        // Admin only routes
        Route::middleware(['auth:api', 'role:super_admin'])->group(function () {
            Route::post('/test-configuration', [EmailController::class, 'testEmailConfiguration']);
            Route::post('/bulk', [EmailController::class, 'sendBulkEmail']);
            Route::post('/commission-rate-update', [EmailController::class, 'sendCommissionRateUpdate']);
            
            // Order notifications
            Route::post('/order/customer', [EmailController::class, 'sendOrderNotificationToCustomer']);
            Route::post('/order/store-owner', [EmailController::class, 'sendOrderNotificationToStoreOwner']);
            Route::post('/order/admin', [EmailController::class, 'sendOrderNotificationToAdmin']);
            
            // Product notifications
            Route::post('/product', [EmailController::class, 'sendProductNotification']);
            
            // Payment notifications
            Route::post('/payment', [EmailController::class, 'sendPaymentNotification']);
        });
    });
});
