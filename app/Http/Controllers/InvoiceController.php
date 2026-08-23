<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Services\YooKassa\YooKassaService;
use App\Services\YooKassa\YooKassaConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Marvel\Enums\Permission;

class InvoiceController extends Controller
{
    /**
     * Получить все счета продавца
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }
            
            // Определяем seller_id: для супер-админа можно указать seller_id в запросе
            $sellerId = $user->id;
            
            // Если супер-админ и указан seller_id в запросе, используем его
            if ($user->hasPermissionTo(Permission::SUPER_ADMIN) && $request->has('seller_id')) {
                $sellerId = $request->input('seller_id');
            }
            
            // Получаем счета продавца
            $invoices = Invoice::where('seller_id', $sellerId)
                ->orderBy('created_at', 'desc')
                ->with('seller:id,name,email')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
        } catch (\Exception $e) {
            Log::error('InvoiceController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении счетов'
            ], 500);
        }
    }

    /**
     * Создать платёж для счёта
     */
    public function pay(Request $request, Invoice $invoice)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            // Проверяем, что счёт принадлежит пользователю
            if ($invoice->seller_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доступ запрещён'
                ], 403);
            }

            // Проверяем статус счёта
            if ($invoice->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Счёт уже оплачен или просрочен'
                ], 400);
            }

            // Создаём конфигурацию и сервис ЮKassa
            $shopId = config('services.yookassa.shop_id');
            $secretKey = config('services.yookassa.secret_key');
            $isTest = config('services.yookassa.is_test', false);

            if (empty($shopId) || empty($secretKey)) {
                Log::error('InvoiceController@pay: YooKassa не настроен');
                return response()->json([
                    'success' => false,
                    'message' => 'Платёжная система не настроена'
                ], 500);
            }

            $config = new YooKassaConfig($shopId, $secretKey, $isTest);
            $service = new YooKassaService($config);

            // Используем данные текущего авторизованного пользователя для receipt
            // Загружаем профиль пользователя и seller из invoice
            $user->load('profile');
            $invoice->load('seller.profile');
            
            // Логируем для отладки
            Log::info('InvoiceController@pay: Формируем receipt', [
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'has_profile' => $user->profile ? true : false,
                'seller_email' => $invoice->seller ? $invoice->seller->email : null
            ]);

            // Формируем receipt для ЮKassa (54-ФЗ) - обязателен для боевого режима
            $receipt = [
                'items' => []
            ];

            // Добавляем данные клиента (приоритет: текущий пользователь, затем seller из invoice)
            $customer = [];
            
            // Email обязателен в боевом режиме, используем email пользователя или seller
            $email = !empty($user->email) ? $user->email : ($invoice->seller && !empty($invoice->seller->email) ? $invoice->seller->email : null);
            if ($email) {
                $customer['email'] = $email;
            }
            
            // Телефон - опционально
            if ($user->profile && !empty($user->profile->contact)) {
                $customer['phone'] = $user->profile->contact;
            } elseif ($invoice->seller && $invoice->seller->profile && !empty($invoice->seller->profile->contact)) {
                $customer['phone'] = $invoice->seller->profile->contact;
            }

            // В боевом режиме customer с email обязателен
            if (!empty($customer['email'])) {
                $receipt['customer'] = $customer;
            } else {
                // Если нет email, это критическая ошибка
                Log::error('InvoiceController@pay: Нет email для receipt', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id
                ]);
                throw new \RuntimeException('Не удалось получить email для формирования чека. Обратитесь в поддержку.');
            }

            // Добавляем товар (услуга) в чек - обязательное поле
            $receipt['items'][] = [
                'description' => "Оплата счёта #{$invoice->id} за период {$invoice->period_start->format('d.m.Y')} - {$invoice->period_end->format('d.m.Y')}",
                'quantity' => '1',
                'amount' => [
                    'value' => number_format((float) $invoice->total_amount, 2, '.', ''),
                    'currency' => 'RUB'
                ],
                'vat_code' => 1, // НДС 20%
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service' // Услуга, так как это оплата за размещение товаров
            ];

            // Логируем сформированный receipt для отладки
            Log::info('InvoiceController@pay: Сформирован receipt', [
                'invoice_id' => $invoice->id,
                'has_customer' => isset($receipt['customer']),
                'customer_email' => $receipt['customer']['email'] ?? null,
                'items_count' => count($receipt['items']),
                'total_amount' => $invoice->total_amount,
                'receipt_structure' => [
                    'has_customer' => isset($receipt['customer']),
                    'items_count' => count($receipt['items']),
                    'first_item' => $receipt['items'][0] ?? null
                ]
            ]);

            // Создаём платёж
            $returnUrl = config('app.frontend_url', 'https://sancan.ru') . '/dashboard/billing?payment=success';
            $description = "Оплата счёта #{$invoice->id} за период {$invoice->period_start->format('d.m.Y')} - {$invoice->period_end->format('d.m.Y')}";

            $payment = $service->createPayment(
                "invoice_{$invoice->id}",
                (float) $invoice->total_amount,
                $description,
                $returnUrl,
                $returnUrl . '?payment=failed',
                $receipt
            );

            // Сохраняем ID платежа в счёт
            $invoice->update(['payment_id' => $payment['id']]);

            return response()->json([
                'success' => true,
                'payment_url' => $payment['payment_url'],
                'payment_id' => $payment['id'],
                'invoice_id' => $invoice->id
            ]);
        } catch (\Exception $e) {
            Log::error('InvoiceController@pay: ' . $e->getMessage(), [
                'invoice_id' => $invoice->id,
                'error' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании платежа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook для обработки уведомлений о платежах
     */
    public function webhook(Request $request)
    {
        try {
            // YooKassa отправляет webhook в формате JSON в теле запроса
            $rawContent = $request->getContent();
            $data = json_decode($rawContent, true);
            
            // Если JSON не распарсился, пробуем получить из input (для обратной совместимости)
            if (!$data) {
                $data = $request->all();
            }
            
            Log::info('InvoiceController@webhook: Получено уведомление', [
                'raw_content' => $rawContent,
                'parsed_data' => $data,
                'content_type' => $request->header('Content-Type'),
                'method' => $request->method()
            ]);

            $event = $data['event'] ?? $request->input('event');
            $payment = $data['object'] ?? $request->input('object');

            if (!$event || !$payment) {
                Log::warning('InvoiceController@webhook: Неверный формат данных', [
                    'event' => $event,
                    'has_payment' => !empty($payment),
                    'data_keys' => array_keys($data ?? [])
                ]);
                return response()->json(['error' => 'Invalid data'], 400);
            }

            // Извлекаем payment_id из объекта
            $paymentId = null;
            if (is_array($payment)) {
                $paymentId = $payment['id'] ?? null;
            } elseif (is_object($payment)) {
                $paymentId = $payment->id ?? null;
            }

            if (!$paymentId) {
                Log::warning('InvoiceController@webhook: Payment ID отсутствует', [
                    'payment_object' => $payment
                ]);
                return response()->json(['error' => 'Payment ID missing'], 400);
            }

            // Извлекаем paid и status
            $paid = null;
            $status = null;
            if (is_array($payment)) {
                $paid = $payment['paid'] ?? null;
                $status = $payment['status'] ?? null;
            } elseif (is_object($payment)) {
                $paid = $payment->paid ?? null;
                $status = $payment->status ?? null;
            }
            
            Log::info('InvoiceController@webhook: Получен webhook', [
                'event' => $event,
                'payment_id' => $paymentId,
                'paid' => $paid,
                'status' => $status,
                'payment_type' => gettype($payment)
            ]);

            // ПЕРВЫМ ДЕЛОМ проверяем пополнение баланса
            Log::info('InvoiceController@webhook: Ищем пополнение баланса по payment_id', [
                'payment_id' => $paymentId
            ]);
            
            // Проверяем все записи для диагностики
            $allDeposits = \App\Models\BalanceDeposit::whereNotNull('payment_id')->get();
            Log::info('InvoiceController@webhook: Все записи с payment_id', [
                'count' => $allDeposits->count(),
                'deposits' => $allDeposits->map(function($d) {
                    return [
                        'id' => $d->id,
                        'payment_id' => $d->payment_id,
                        'status' => $d->status,
                        'amount' => $d->amount
                    ];
                })->toArray()
            ]);
            
            $balanceDeposit = \App\Models\BalanceDeposit::where('payment_id', $paymentId)->first();
            if ($balanceDeposit) {
                Log::info('InvoiceController@webhook: ✓ Найдено пополнение баланса', [
                    'deposit_id' => $balanceDeposit->id,
                    'payment_id' => $paymentId,
                    'seller_id' => $balanceDeposit->seller_id,
                    'amount' => $balanceDeposit->amount,
                    'status' => $balanceDeposit->status
                ]);
                
                // ВАЖНО: Проверяем статус через API YooKassa (как делает команда ProcessPendingBalanceDeposits)
                // Не полагаемся только на данные из webhook
                try {
                    $shopId = config('services.yookassa.shop_id');
                    $secretKey = config('services.yookassa.secret_key');
                    $isTest = config('services.yookassa.is_test', false);
                    
                    if (empty($shopId) || empty($secretKey)) {
                        Log::error('InvoiceController@webhook: YooKassa не настроен');
                        throw new \Exception('YooKassa не настроен');
                    }
                    
                    $config = new \App\Services\YooKassa\YooKassaConfig($shopId, $secretKey, $isTest);
                    $service = new \App\Services\YooKassa\YooKassaService($config);
                    
                    Log::info('InvoiceController@webhook: Проверяем статус платежа через API', [
                        'payment_id' => $paymentId
                    ]);
                    
                    $paymentInfo = $service->checkPayment($paymentId);
                    
                    Log::info('InvoiceController@webhook: Статус платежа из API', [
                        'status' => $paymentInfo['status'] ?? null,
                        'paid' => $paymentInfo['paid'] ?? null,
                        'amount' => $paymentInfo['amount'] ?? null
                    ]);
                    
                    // Проверяем статус ТОЧНО как в команде ProcessPendingBalanceDeposits
                    if (($paymentInfo['status'] ?? null) === 'succeeded' && ($paymentInfo['paid'] ?? false) === true) {
                        if ($balanceDeposit->status === 'pending') {
                            // Платёж успешен - пополняем баланс (ТОЧНО как в команде)
                            $balance = \App\Models\SellerBalance::getOrCreate($balanceDeposit->seller_id);
                            $oldBalance = $balance->balance;
                            $balance->deposit($balanceDeposit->amount, "Пополнение баланса через YooKassa webhook");
                            
                            $balanceDeposit->update([
                                'status' => 'succeeded',
                                'paid_at' => now()
                            ]);

                            Log::info('InvoiceController@webhook: ✓✓✓ Баланс пополнен успешно (через API проверку) ✓✓✓', [
                                'deposit_id' => $balanceDeposit->id,
                                'seller_id' => $balanceDeposit->seller_id,
                                'amount' => $balanceDeposit->amount,
                                'old_balance' => $oldBalance,
                                'new_balance' => $balance->balance,
                                'payment_id' => $paymentId,
                                'payment_status_from_api' => $paymentInfo['status'],
                                'payment_paid_from_api' => $paymentInfo['paid']
                            ]);
                        } else {
                            Log::info('InvoiceController@webhook: Пополнение баланса уже обработано', [
                                'deposit_id' => $balanceDeposit->id,
                                'status' => $balanceDeposit->status
                            ]);
                        }
                    } else {
                        Log::warning('InvoiceController@webhook: Платеж еще не завершен или не оплачен по данным API', [
                            'payment_id' => $paymentId,
                            'status_from_api' => $paymentInfo['status'] ?? null,
                            'paid_from_api' => $paymentInfo['paid'] ?? null,
                            'deposit_status' => $balanceDeposit->status
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('InvoiceController@webhook: Ошибка при проверке статуса платежа через API', [
                        'payment_id' => $paymentId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Продолжаем обработку, возможно это не пополнение баланса
                }
                
                return response()->json(['status' => 'ok']);
            } else {
                Log::warning('InvoiceController@webhook: ✗ Пополнение баланса НЕ найдено', [
                    'payment_id' => $paymentId,
                    'searched_in' => $allDeposits->count() . ' записей'
                ]);
            }

            // Находим счёт по payment_id
            $invoice = Invoice::where('payment_id', $paymentId)->first();

            if (!$invoice) {
                Log::warning('InvoiceController@webhook: Счёт не найден', ['payment_id' => $paymentId]);
                return response()->json(['error' => 'Invoice not found'], 404);
            }

            // ВАЖНО: Проверяем статус через API YooKassa (как для пополнения баланса)
            try {
                $shopId = config('services.yookassa.shop_id');
                $secretKey = config('services.yookassa.secret_key');
                $isTest = config('services.yookassa.is_test', false);
                
                if (empty($shopId) || empty($secretKey)) {
                    Log::error('InvoiceController@webhook: YooKassa не настроен');
                    throw new \Exception('YooKassa не настроен');
                }
                
                $config = new \App\Services\YooKassa\YooKassaConfig($shopId, $secretKey, $isTest);
                $service = new \App\Services\YooKassa\YooKassaService($config);
                
                Log::info('InvoiceController@webhook: Проверяем статус платежа через API для invoice', [
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoice->id
                ]);
                
                $paymentInfo = $service->checkPayment($paymentId);
                
                Log::info('InvoiceController@webhook: Статус платежа из API для invoice', [
                    'status' => $paymentInfo['status'] ?? null,
                    'paid' => $paymentInfo['paid'] ?? null,
                    'amount' => $paymentInfo['amount'] ?? null
                ]);
                
                // Проверяем статус ТОЧНО как для пополнения баланса
                if (($paymentInfo['status'] ?? null) === 'succeeded' && ($paymentInfo['paid'] ?? false) === true) {
                    if ($invoice->status === 'pending') {
                        // Платёж успешен
                        $invoice->update([
                            'status' => 'paid',
                            'paid_at' => now()
                        ]);

                        // Проверяем подписку PRO
                        $proSubscription = \App\Models\ProSubscription::where('invoice_id', $invoice->id)->first();
                        if ($proSubscription) {
                            $proSubscription->update(['status' => 'active']);
                            Log::info('InvoiceController@webhook: Подписка PRO активирована', [
                                'subscription_id' => $proSubscription->id,
                                'seller_id' => $proSubscription->seller_id
                            ]);
                        }
                        
                        // Старая логика для PlanSubscription (deprecated)
                        $subscription = \App\Models\PlanSubscription::where('invoice_id', $invoice->id)->first();
                        if ($subscription) {
                            $subscription->update(['status' => 'active']);
                            
                            // Обновляем тариф пользователя (всегда, не только если пустой)
                            $seller = $invoice->seller;
                            if ($seller) {
                                $seller->plan_id = $subscription->plan_id;
                                $seller->save();
                                
                                Log::info('InvoiceController@webhook: Тариф пользователя обновлен', [
                                    'seller_id' => $seller->id,
                                    'plan_id' => $subscription->plan_id,
                                    'subscription_id' => $subscription->id
                                ]);
                            }
                        }
                    } else {
                        Log::info('InvoiceController@webhook: Invoice уже обработан', [
                            'invoice_id' => $invoice->id,
                            'status' => $invoice->status
                        ]);
                    }
                } else {
                    Log::warning('InvoiceController@webhook: Платеж еще не завершен или не оплачен по данным API', [
                        'payment_id' => $paymentId,
                        'status_from_api' => $paymentInfo['status'] ?? null,
                        'paid_from_api' => $paymentInfo['paid'] ?? null,
                        'invoice_status' => $invoice->status
                    ]);
                    return response()->json(['status' => 'ok', 'message' => 'Payment not completed yet']);
                }
            } catch (\Exception $e) {
                Log::error('InvoiceController@webhook: Ошибка при проверке статуса платежа через API', [
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoice->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['error' => 'Error checking payment status'], 500);
            }
            
            // Обрабатываем событие (старая логика для обратной совместимости, если API проверка не сработала)
            if ($event === 'payment.succeeded' && ($payment['paid'] ?? false) === true && $invoice->status === 'pending') {

                // Пополнение баланса уже обработано выше

                // Если это покупка доп. товаров/плейсов
                $purchase = \App\Models\AdditionalPurchase::where('payment_id', $paymentId)->first();
                if ($purchase) {
                    $purchase->update(['status' => 'paid']);
                }

                // Оплата исторического счёта не меняет статусы товаров.
                Log::info('InvoiceController@webhook: Счёт оплачен', [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $paymentId,
                    'seller_id' => $invoice->seller_id
                ]);
            } elseif ($event === 'payment.canceled') {
                // Платёж отменён
                Log::info('InvoiceController@webhook: Платёж отменён', [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $paymentId
                ]);
            }

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('InvoiceController@webhook: Ошибка', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Internal error'], 500);
        }
    }
}


