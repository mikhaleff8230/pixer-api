<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Services\OrderLineItemService;
use App\Services\YooKassa\YooKassaService;
use App\Services\YooKassa\YooKassaConfig;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;

class CustomYooKassaOrderController extends Controller
{
    public function create(Request $request)
    {
        try {
            \Log::info('CustomYooKassaOrderController: входящие данные', $request->all());
            
            $data = $request->all();
            $shipping = $data['shipping_address'] ?? [];
            if (!is_array($shipping)) {
                $shipping = [];
            }
            $name = $data['name'] ?? $shipping['name'] ?? '';
            $email = $data['email'] ?? $shipping['email'] ?? '';
            $phone = $data['phone'] ?? $shipping['phone'] ?? '';
            $address = $data['address'] ?? $shipping['address'] ?? '';
            $comment = $data['note'] ?? $shipping['comment'] ?? '';
            $lineItemService = app(OrderLineItemService::class);
            $data['products'] = $lineItemService->resolve(is_array($data['products'] ?? null) ? $data['products'] : []);
            $amount = $lineItemService->total($data['products']);
            $language = $data['language'] ?? 'ru'; // Получаем язык из запроса или дефолт 'ru'

            // Чтобы выдача цифровых товаров могла сопоставить гостевой заказ с аккаунтом по email
            if ($email !== '' && empty($shipping['email'])) {
                $shipping['email'] = $email;
            }
            if ($phone !== '' && empty($shipping['phone'])) {
                $shipping['phone'] = $phone;
            }
            if ($name !== '' && empty($shipping['name'])) {
                $shipping['name'] = $name;
            }

            // Логируем входящие данные для отладки
            \Log::info('CustomYooKassaOrderController: детали входящих данных', [
                'shipping_address' => $shipping,
                'has_pvz_info' => isset($shipping['pvz_info']),
                'delivery_type' => $shipping['delivery_type'] ?? 'not_set'
            ]);

            // Создаем заказ (оставляем новые поля для админки)
            $order = new \Marvel\Database\Models\Order();
            $order->tracking_number = OrderRepository::generateTrackingNumberStatic();
            $order->customer_id = auth()->id();
            $order->amount = $amount;
            $order->language = $language; // Используем язык из запроса
            $order->payment_gateway = $data['payment_gateway'] ?? 'yookassa';
            $order->order_status = OrderStatus::PENDING; // 'order-pending'
            $order->payment_status = PaymentStatus::PENDING; // 'payment-pending'
            
            // Принудительно устанавливаем русский язык
            \Log::info('CustomYooKassaOrderController: устанавливаем language = ru', [
                'language' => 'ru'
            ]);
            
            // Сохраняем shipping_address с поддержкой ПВЗ
            $order->shipping_address = $shipping; // Используем полную структуру из запроса
            
            // Убеждаемся, что pvz_info сохраняется
            if (isset($shipping['pvz_info'])) {
                \Log::info('CustomYooKassaOrderController: pvz_info найден', [
                    'pvz_info' => $shipping['pvz_info']
                ]);
            } else {
                \Log::warning('CustomYooKassaOrderController: pvz_info НЕ найден в shipping_address');
            }
            
            $order->save();
            
            // Перезагружаем заказ из БД для проверки сохраненных значений
            $order->refresh();
            
            // Проверяем сохраненный язык и статусы
            \Log::info('CustomYooKassaOrderController: заказ сохранен', [
                'order_id' => $order->id,
                'tracking_number' => $order->tracking_number,
                'language' => $order->language,
                'payment_gateway' => $order->payment_gateway,
                'order_status' => $order->order_status,
                'order_status_raw' => $order->getRawOriginal('order_status'),
                'payment_status' => $order->payment_status,
                'payment_status_raw' => $order->getRawOriginal('payment_status'),
            ]);

            // Логируем данные ПВЗ для отладки
            \Log::info('CustomYooKassaOrderController: данные ПВЗ сохранены', [
                'order_id' => $order->id,
                'shipping_address' => $order->shipping_address,
                'delivery_type' => $shipping['delivery_type'] ?? 'not_set',
                'pvz_info' => $shipping['pvz_info'] ?? 'not_set'
            ]);

            // Привязываем товары ПЕРЕД созданием дочерних заказов
            if (!empty($data['products'])) {
                foreach ($data['products'] as $item) {
                    $order->products()->attach($item['product_id'], [
                        'order_quantity' => $item['order_quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                        'variation_option_id' => $item['variation_option_id'],
                        'variation_snapshot' => $item['variation_snapshot'] ? json_encode($item['variation_snapshot'], JSON_UNESCAPED_UNICODE) : null,
                    ]);
                }
                \Log::info('CustomYooKassaOrderController: товары привязаны к заказу', [
                    'order_id' => $order->id,
                    'products_count' => count($data['products'])
                ]);
            }
            
            // Создаем дочерние заказы для каждого магазина ПЕРЕД вызовом события
            // Это необходимо, чтобы продавцы видели свои заказы
            if (!empty($data['products']) && $order->id) {
                try {
                    $orderRepository = app(OrderRepository::class);
                    
                    // Подготавливаем данные для создания дочерних заказов
                    $requestForChildOrders = new \Illuminate\Http\Request();
                    $requestForChildOrders->merge([
                        'products' => $data['products'],
                        'order_status' => $order->order_status ?? OrderStatus::PENDING,
                        'payment_status' => $order->payment_status ?? PaymentStatus::PENDING,
                        'customer_id' => $order->customer_id,
                        'shipping_address' => $order->shipping_address,
                        'billing_address' => $order->shipping_address, // Используем shipping как billing
                        'customer_contact' => $phone,
                        'customer_name' => $name,
                        'delivery_time' => null,
                        'payment_gateway' => $order->payment_gateway,
                        'language' => $order->language,
                    ]);
                    
                    \Log::info('CustomYooKassaOrderController: начинаем создание дочерних заказов', [
                        'parent_order_id' => $order->id,
                        'parent_tracking_number' => $order->tracking_number,
                        'products_count' => count($data['products'])
                    ]);
                    
                    $orderRepository->createChildOrder($order->id, $requestForChildOrders);
                    
                    // Перезагружаем заказ с дочерними заказами для проверки
                    $order->refresh();
                    $order->load('children');
                    
                    \Log::info('CustomYooKassaOrderController: дочерние заказы созданы', [
                        'parent_order_id' => $order->id,
                        'parent_tracking_number' => $order->tracking_number,
                        'children_count' => $order->children ? $order->children->count() : 0,
                        'children' => $order->children ? $order->children->map(function($child) {
                            return [
                                'id' => $child->id,
                                'tracking_number' => $child->tracking_number,
                                'shop_id' => $child->shop_id
                            ];
                        })->toArray() : []
                    ]);
                } catch (\Exception $e) {
                    \Log::error('CustomYooKassaOrderController: ошибка создания дочерних заказов', [
                        'parent_order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Не прерываем выполнение, так как родительский заказ уже создан
                }
            }

            // Вызываем событие OrderCreated для автоматического создания отправлений ПОСЛЕ создания дочерних заказов
            event(new \Marvel\Events\OrderCreated($order, []));

            // Собираем receipt для ЮKassa (54-ФЗ) - обязателен для боевого режима
            $receipt = [
                'items' => []
            ];
            
            // Добавляем данные клиента, если есть
            $customer = [];
            if (!empty($email)) {
                $customer['email'] = $email;
            }
            if (!empty($phone)) {
                $customer['phone'] = $phone;
            }
            
            // Добавляем customer только если есть хотя бы email или phone
            if (!empty($customer)) {
                $receipt['customer'] = $customer;
            }
            
            // Добавляем товары из заказа
            if (!empty($data['products'])) {
                foreach ($data['products'] as $item) {
                    // Получаем название товара
                    $productName = 'Товар';
                    if (isset($item['product_id'])) {
                        $product = Product::find($item['product_id']);
                        if ($product) {
                            $productName = $product->name ?? 'Товар';
                        }
                    }
                    
                    $receipt['items'][] = [
                        'description' => $productName,
                        'quantity' => strval($item['order_quantity'] ?? 1),
                        'amount' => [
                            'value' => number_format($item['unit_price'] ?? 0, 2, '.', ''),
                            'currency' => 'RUB'
                        ],
                        'vat_code' => 1, // НДС 20%
                        'payment_mode' => 'full_payment',
                        'payment_subject' => 'commodity'
                    ];
                }
            }
            
            // Если товаров нет, но сумма есть - добавляем один товар на всю сумму
            if (empty($receipt['items']) && $amount > 0) {
                $receipt['items'][] = [
                    'description' => 'Оплата заказа',
                    'quantity' => '1',
                    'amount' => [
                        'value' => number_format($amount, 2, '.', ''),
                        'currency' => 'RUB'
                    ],
                    'vat_code' => 1,
                    'payment_mode' => 'full_payment',
                    'payment_subject' => 'commodity'
                ];
            }
            
            // Проверяем, что receipt валиден (должен быть хотя бы один товар)
            if (empty($receipt['items'])) {
                \Log::warning('CustomYooKassaOrderController: receipt пустой, но это может вызвать ошибку в боевом режиме');
            }

            // Создаем конфигурацию и сервис ЮKassa
            $shopId = config('services.yookassa.shop_id');
            $secretKey = config('services.yookassa.secret_key');
            $isTest = config('services.yookassa.is_test', false);
            
            // Проверяем наличие конфигурации
            if (empty($shopId) || empty($secretKey)) {
                \Log::error('CustomYooKassaOrderController: YooKassa не настроен в .env');
                throw new \RuntimeException('YooKassa не настроен. Проверьте YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY в .env файле');
            }
            
            $config = new YooKassaConfig($shopId, $secretKey, $isTest);
            $service = new YooKassaService($config);

            // Создаем платеж для виджета
            // returnUrl - это URL на фронтенде, куда вернется пользователь после оплаты
            $returnUrl = 'https://sancan.ru/payment/success';
            
            \Log::info('CustomYooKassaOrderController: создаем платеж для виджета', [
                'order_id' => $order->tracking_number,
                'amount' => $amount,
                'return_url' => $returnUrl
            ]);
            
            $payment = $service->createPaymentForWidget(
                $order->tracking_number,
                $amount,
                "Оплата заказа #{$order->tracking_number}",
                $returnUrl,
                $receipt
            );

            return response()->json([
                'confirmation_token' => $payment['confirmation_token'] ?? null,
                'payment_id' => $payment['id'] ?? null,
                'order_id' => $order->tracking_number,
                'success' => true,
                'debug' => [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'comment' => $comment,
                    'amount' => $amount,
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Корзина изменилась.', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            \Log::error('CustomYooKassaOrderController: ошибка', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

