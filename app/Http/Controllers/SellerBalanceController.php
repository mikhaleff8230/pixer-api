<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\SellerBalance;
use App\Models\BalanceDeposit;
use App\Models\SellerBalanceTransaction;
use App\Services\YooKassa\YooKassaService;
use App\Services\YooKassa\YooKassaConfig;
use Carbon\Carbon;
use Marvel\Enums\Permission;
use Marvel\Database\Models\User;

class SellerBalanceController extends Controller
{
    /**
     * Единый журнал пополнений и списаний баланса продавца.
     * GET /api/seller/balance/ledger
     */
    public function ledger(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $limit = min(max((int) $request->get('limit', 50), 1), 100);
        $transactions = SellerBalanceTransaction::query()
            ->where('seller_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SellerBalanceTransaction $transaction) => [
                'id' => 'transaction-' . $transaction->id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
                'balance_before' => (float) $transaction->balance_before,
                'balance_after' => (float) $transaction->balance_after,
                'description' => $transaction->description ?: 'Операция по балансу',
                'created_at' => $transaction->created_at,
            ]);

        $deposits = BalanceDeposit::query()
            ->where('seller_id', $user->id)
            ->where('status', 'succeeded')
            ->latest('paid_at')
            ->limit($limit)
            ->get()
            ->map(fn (BalanceDeposit $deposit) => [
                'id' => 'deposit-' . $deposit->id,
                'type' => 'balance_deposit',
                'amount' => (float) $deposit->amount,
                'balance_before' => null,
                'balance_after' => null,
                'description' => str_starts_with((string) $deposit->payment_id, 'virtual_')
                    ? 'Пополнение баланса администратором'
                    : 'Пополнение баланса',
                'created_at' => $deposit->paid_at ?: $deposit->updated_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => $transactions
                ->concat($deposits)
                ->sortByDesc('created_at')
                ->take($limit)
                ->values(),
        ]);
    }

    /**
     * Получить баланс продавца
     * GET /api/seller/balance
     */
    public function get()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            $balance = SellerBalance::getOrCreate($user->id);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => (float) $balance->balance,
                    'total_deposited' => (float) $balance->total_deposited,
                    'total_spent' => (float) $balance->total_spent,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('SellerBalanceController@get: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении баланса'
            ], 500);
        }
    }

    /**
     * Проверить статус последнего пополнения баланса и обработать, если оплачено
     * GET /api/seller/balance/check-pending
     */
    public function checkPending()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            // Находим последнее pending пополнение для этого пользователя
            $deposit = BalanceDeposit::where('seller_id', $user->id)
                ->where('status', 'pending')
                ->whereNotNull('payment_id')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$deposit) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_pending' => false,
                        'message' => 'Нет pending пополнений'
                    ]
                ]);
            }

            // Проверяем статус платежа в YooKassa
            $shopId = config('services.yookassa.shop_id');
            $secretKey = config('services.yookassa.secret_key');
            $isTest = config('services.yookassa.is_test', false);

            if (empty($shopId) || empty($secretKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Платёжная система не настроена'
                ], 500);
            }

            $config = new YooKassaConfig($shopId, $secretKey, $isTest);
            $service = new YooKassaService($config);

            try {
                $paymentInfo = $service->checkPayment($deposit->payment_id);

                if ($paymentInfo['status'] === 'succeeded' && ($paymentInfo['paid'] ?? false)) {
                    // Платёж успешен - пополняем баланс, если еще не пополнен
                    if ($deposit->status === 'pending') {
                        $balance = SellerBalance::getOrCreate($user->id);
                        $oldBalance = $balance->balance;
                        $balance->deposit($deposit->amount, "Пополнение баланса (проверка при возврате)");
                        
                        $deposit->update([
                            'status' => 'succeeded',
                            'paid_at' => now()
                        ]);

                        Log::info('SellerBalanceController@checkPending: Баланс пополнен', [
                            'deposit_id' => $deposit->id,
                            'seller_id' => $user->id,
                            'amount' => $deposit->amount,
                            'old_balance' => $oldBalance,
                            'new_balance' => $balance->balance,
                            'payment_id' => $deposit->payment_id
                        ]);

                        return response()->json([
                            'success' => true,
                            'data' => [
                                'has_pending' => false,
                                'processed' => true,
                                'amount' => (float) $deposit->amount,
                                'old_balance' => (float) $oldBalance,
                                'new_balance' => (float) $balance->balance,
                                'message' => 'Баланс успешно пополнен'
                            ]
                        ]);
                    } else {
                        // Уже обработан
                        return response()->json([
                            'success' => true,
                            'data' => [
                                'has_pending' => false,
                                'processed' => false,
                                'message' => 'Пополнение уже обработано'
                            ]
                        ]);
                    }
                } else {
                    // Платёж еще не завершен
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'has_pending' => true,
                            'status' => $paymentInfo['status'],
                            'message' => 'Платеж еще обрабатывается'
                        ]
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('SellerBalanceController@checkPending: Ошибка при проверке платежа', [
                    'deposit_id' => $deposit->id,
                    'payment_id' => $deposit->payment_id,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка при проверке статуса платежа: ' . $e->getMessage()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('SellerBalanceController@checkPending: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при проверке пополнения'
            ], 500);
        }
    }

    /**
     * Пополнить баланс
     * POST /api/seller/balance/deposit
     */
    public function deposit(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            $request->validate([
                'amount' => 'required|numeric|min:1',
                'payment_method' => 'required|in:balance,yookassa',
            ]);

            $amount = (float) $request->amount;

            if ($request->payment_method === 'yookassa') {
                // Создаем платеж в YooKassa
                $shopId = config('services.yookassa.shop_id');
                $secretKey = config('services.yookassa.secret_key');
                $isTest = config('services.yookassa.is_test', false);

                if (empty($shopId) || empty($secretKey)) {
                    Log::error('SellerBalanceController@deposit: YooKassa не настроен');
                    return response()->json([
                        'success' => false,
                        'message' => 'Платёжная система не настроена'
                    ], 500);
                }

                $config = new YooKassaConfig($shopId, $secretKey, $isTest);
                $service = new YooKassaService($config);

                $returnUrl = config('app.admin_url', 'https://seller.sancan.ru') . '/dashboard/billing?deposit=success';
                $description = "Пополнение баланса на сумму {$amount} ₽";

                // Формируем receipt для ЮKassa (54-ФЗ) - обязателен для боевого режима
                $receipt = null;
                if (!$isTest) {
                    $receipt = [
                        'items' => [
                            [
                                'description' => 'Пополнение баланса',
                                'quantity' => '1.00',
                                'amount' => [
                                    'value' => number_format($amount, 2, '.', ''),
                                    'currency' => 'RUB'
                                ],
                                'vat_code' => 1, // НДС не облагается
                                'payment_mode' => 'full_payment',
                                'payment_subject' => 'service' // Услуга
                            ]
                        ]
                    ];

                    // Добавляем данные клиента (email обязателен в боевом режиме)
                    if (!empty($user->email)) {
                        $receipt['customer'] = [
                            'email' => $user->email
                        ];
                    } else {
                        Log::error('SellerBalanceController@deposit: Нет email для receipt', [
                            'user_id' => $user->id
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Не удалось получить email для формирования чека. Обратитесь в поддержку.'
                        ], 500);
                    }
                }

                try {
                    $payment = $service->createPayment(
                        "balance_deposit_{$user->id}_" . time(),
                        $amount,
                        $description,
                        $returnUrl,
                        $returnUrl . '?deposit=failed',
                        $receipt
                    );

                    // Сохраняем транзакцию пополнения
                    $paymentId = $payment['id'] ?? null;
                    
                    if (!$paymentId) {
                        Log::error('SellerBalanceController@deposit: payment_id отсутствует в ответе YooKassa', [
                            'payment_response' => $payment
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Ошибка: не получен payment_id от платежной системы'
                        ], 500);
                    }
                    
                    $deposit = BalanceDeposit::create([
                        'seller_id' => $user->id,
                        'amount' => $amount,
                        'payment_id' => $paymentId,
                        'status' => 'pending',
                    ]);

                    Log::info('SellerBalanceController@deposit: ✓ Создана транзакция пополнения', [
                        'deposit_id' => $deposit->id,
                        'seller_id' => $user->id,
                        'amount' => $amount,
                        'payment_id' => $paymentId,
                        'payment_url' => $payment['payment_url'] ?? null
                    ]);
                    
                    // Проверяем, что запись сохранилась
                    $savedDeposit = BalanceDeposit::find($deposit->id);
                    if (!$savedDeposit || $savedDeposit->payment_id !== $paymentId) {
                        Log::error('SellerBalanceController@deposit: ОШИБКА! Запись не сохранилась правильно', [
                            'deposit_id' => $deposit->id,
                            'saved_payment_id' => $savedDeposit->payment_id ?? 'NULL',
                            'expected_payment_id' => $paymentId
                        ]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Перейдите на страницу оплаты',
                        'payment_url' => $payment['payment_url'],
                        'payment_id' => $payment['id'],
                        'amount' => $amount,
                    ]);
                } catch (\Exception $e) {
                    Log::error('SellerBalanceController@deposit: Ошибка при создании платежа', [
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка при создании платежа: ' . $e->getMessage()
                    ], 500);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Неподдерживаемый способ оплаты'
            ], 400);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('SellerBalanceController@deposit: Ошибка валидации', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации: ' . implode(', ', array_map(function ($errors) {
                    return implode(', ', $errors);
                }, $e->errors()))
            ], 400);
        } catch (\Exception $e) {
            Log::error('SellerBalanceController@deposit: ' . $e->getMessage(), [
                'user_id' => $user->id ?? null,
                'amount' => $request->amount ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при пополнении баланса: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Виртуальное пополнение баланса продавца (только для супер-админа)
     * POST /api/admin/seller/balance/virtual-deposit
     */
    public function virtualDeposit(Request $request)
    {
        try {
            $admin = Auth::user();
            
            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            // Проверяем, что пользователь - супер-админ
            if (!$admin->hasPermissionTo(Permission::SUPER_ADMIN)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доступ запрещен. Только супер-админ может выполнять виртуальное пополнение баланса.'
                ], 403);
            }

            $request->validate([
                'seller_id' => 'required|integer|exists:users,id',
                'amount' => 'required|numeric|min:0.01',
            ]);

            $sellerId = (int) $request->seller_id;
            $amount = (float) $request->amount;

            // Проверяем, что пользователь является продавцом (store_owner)
            $seller = User::findOrFail($sellerId);
            if (!$seller->hasPermissionTo(Permission::STORE_OWNER)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не является продавцом (store_owner)'
                ], 400);
            }

            // Получаем или создаем баланс продавца
            $balance = SellerBalance::getOrCreate($sellerId);
            $oldBalance = $balance->balance;

            // Пополняем баланс
            $balance->deposit($amount, "Виртуальное пополнение баланса супер-админом");

            // Создаем запись о пополнении в истории (имитируем успешное пополнение через YooKassa)
            // Генерируем уникальный payment_id для виртуального пополнения
            $paymentId = 'virtual_' . time() . '_' . $sellerId . '_' . uniqid();
            $deposit = BalanceDeposit::create([
                'seller_id' => $sellerId,
                'amount' => $amount,
                'payment_id' => $paymentId,
                'status' => 'succeeded', // Сразу succeeded, минуя pending
                'paid_at' => now(),
            ]);

            Log::info('SellerBalanceController@virtualDeposit: Виртуальное пополнение баланса выполнено', [
                'admin_id' => $admin->id,
                'seller_id' => $sellerId,
                'amount' => $amount,
                'old_balance' => $oldBalance,
                'new_balance' => $balance->balance,
                'deposit_id' => $deposit->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Баланс успешно пополнен',
                'data' => [
                    'seller_id' => $sellerId,
                    'amount' => $amount,
                    'old_balance' => (float) $oldBalance,
                    'new_balance' => (float) $balance->balance,
                    'deposit_id' => $deposit->id,
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('SellerBalanceController@virtualDeposit: Ошибка валидации', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации: ' . implode(', ', array_map(function ($errors) {
                    return implode(', ', $errors);
                }, $e->errors()))
            ], 400);
        } catch (\Exception $e) {
            Log::error('SellerBalanceController@virtualDeposit: ' . $e->getMessage(), [
                'admin_id' => Auth::id(),
                'seller_id' => $request->seller_id ?? null,
                'amount' => $request->amount ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при виртуальном пополнении баланса: ' . $e->getMessage()
            ], 500);
        }
    }
}



