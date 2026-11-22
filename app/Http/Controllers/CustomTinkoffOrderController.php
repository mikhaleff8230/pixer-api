<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Services\OrderLineItemService;
use App\Services\Tinkoff\TinkoffService;
use App\Services\Tinkoff\TinkoffConfig;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;

class CustomTinkoffOrderController extends Controller
{
    public function create(Request $request)
    {
        try {
            \Log::info('CustomTinkoffOrderController: входящие данные', $request->all());
            
            $data = $request->all();
            $shipping = $data['shipping_address'] ?? [];
            $name = $data['name'] ?? $shipping['name'] ?? '';
            $email = $data['email'] ?? $shipping['email'] ?? '';
            $phone = $data['phone'] ?? $shipping['phone'] ?? '';
            $address = $data['address'] ?? $shipping['address'] ?? '';
            $comment = $data['note'] ?? $shipping['comment'] ?? '';
            $lineItemService = app(OrderLineItemService::class);
            $data['products'] = $lineItemService->resolve(is_array($data['products'] ?? null) ? $data['products'] : []);
            $amount = $lineItemService->total($data['products']);

            // Логируем входящие данные для отладки
            \Log::info('CustomTinkoffOrderController: детали входящих данных', [
                'shipping_address' => $shipping,
                'has_pvz_info' => isset($shipping['pvz_info']),
                'delivery_type' => $shipping['delivery_type'] ?? 'not_set'
            ]);

            // Создаем заказ (оставляем новые поля для админки)
            $order = new \Marvel\Database\Models\Order();
            $order->tracking_number = OrderRepository::generateTrackingNumberStatic();
            $order->customer_id = auth()->id();
            $order->amount = $amount;
            $order->language = 'ru'; // Фиксируем русский язык
            $order->payment_gateway = $data['payment_gateway'] ?? 'tinkoff';
            $order->order_status = OrderStatus::PENDING; // 'order-pending'
            $order->payment_status = PaymentStatus::PENDING; // 'payment-pending'
            
            // Сохраняем shipping_address с поддержкой ПВЗ
            $order->shipping_address = $shipping; // Используем полную структуру из запроса
            
            // Убеждаемся, что pvz_info сохраняется
            if (isset($shipping['pvz_info'])) {
                \Log::info('CustomTinkoffOrderController: pvz_info найден', [
                    'pvz_info' => $shipping['pvz_info']
                ]);
            } else {
                \Log::warning('CustomTinkoffOrderController: pvz_info НЕ найден в shipping_address');
            }
            
            $order->save();

            // Логируем данные ПВЗ для отладки
            \Log::info('CustomTinkoffOrderController: заказ сохранен', [
                'order_id' => $order->id,
                'tracking_number' => $order->tracking_number,
                'language' => $order->language,
                'payment_gateway' => $order->payment_gateway,
                'order_status' => $order->order_status,
                'payment_status' => $order->payment_status
            ]);

            \Log::info('CustomTinkoffOrderController: данные ПВЗ сохранены', [
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
                \Log::info('CustomTinkoffOrderController: товары привязаны к заказу', [
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
                    
                    \Log::info('CustomTinkoffOrderController: начинаем создание дочерних заказов', [
                        'parent_order_id' => $order->id,
                        'parent_tracking_number' => $order->tracking_number,
                        'products_count' => count($data['products'])
                    ]);
                    
                    $orderRepository->createChildOrder($order->id, $requestForChildOrders);
                    
                    // Перезагружаем заказ с дочерними заказами для проверки
                    $order->refresh();
                    $order->load('children');
                    
                    \Log::info('CustomTinkoffOrderController: дочерние заказы созданы', [
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
                    \Log::error('CustomTinkoffOrderController: ошибка создания дочерних заказов', [
                        'parent_order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Не прерываем выполнение, так как родительский заказ уже создан
                }
            }

            // Вызываем событие OrderCreated для автоматического создания отправлений ПОСЛЕ создания дочерних заказов
            event(new \Marvel\Events\OrderCreated($order, []));

            // Собираем receipt для Тинькофф
            $receipt = [
                'Email' => $email,
                'Taxation' => 'osn',
                'Items' => [
                    [
                        'Name' => $name ?: 'Товар',
                        'Price' => (int)($amount * 100),
                        'Quantity' => 1,
                        'Amount' => (int)($amount * 100),
                        'Tax' => 'vat20',
                        'PaymentMethod' => 'full_payment',
                        'PaymentObject' => 'commodity'
                    ]
                ]
            ];
            
            if ($phone) {
                $receipt['Phone'] = $phone;
            }

            // Создаем конфигурацию и сервис Тинькофф
            $config = new TinkoffConfig(
                config('tinkoff.terminal'),
                config('tinkoff.password'),
                config('tinkoff.test', false),
                config('tinkoff.api_url', 'https://securepay.tinkoff.ru/v2')
            );
            $service = new TinkoffService($config);

            // Создаем платеж
            $payment = $service->createPayment(
                $order->tracking_number,
                $amount,
                "Оплата заказа #{$order->tracking_number}",
                config('app.url') . '/payment/success',
                config('app.url') . '/payment/fail',
                $receipt
            );

            return response()->json([
                'payment_url' => $payment['PaymentURL'] ?? null,
                'payment_id' => $payment['PaymentId'] ?? null,
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
            \Log::error('CustomTinkoffOrderController: ошибка', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
