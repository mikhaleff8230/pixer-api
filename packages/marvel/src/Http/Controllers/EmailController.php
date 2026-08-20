<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Mail\ContactAdmin;
use Marvel\Mail\ForgetPassword;
use Marvel\Mail\AdminCommissionRateUpdate;
use Marvel\Mail\VendorCommissionRateUpdate;
use Marvel\Mail\SellerCreateShopReminder;
use Marvel\Notifications\NewOrderReceived;
use Marvel\Notifications\OrderPlacedSuccessfully;
use Marvel\Notifications\OrderCancelledNotification;
use Marvel\Notifications\OrderDeliveredNotification;
use Marvel\Notifications\OrderStatusChangedNotification;
use Marvel\Notifications\PaymentSuccessfulNotification;
use Marvel\Notifications\PaymentFailedNotification;
use Marvel\Notifications\ProductApprovedNotification;
use Marvel\Notifications\ProductRejectedNotification;
use Marvel\Notifications\NewReviewCreated;
use Marvel\Notifications\NotifyQuestionAnswered;
use Marvel\Notifications\RefundRequested;
use Marvel\Notifications\RefundUpdate;
use Marvel\Notifications\StoreNoticeNotification;
use Marvel\Services\EmailService;
use Marvel\Exceptions\MarvelException;
use Illuminate\Support\Facades\Log;

class EmailController extends CoreController
{
    private const MANUAL_TEMPLATES = [
        'seller_create_shop' => ['key' => 'seller_create_shop', 'title' => 'Призыв создать магазин', 'description' => 'Письмо продавцу, который ещё не создал магазин'],
    ];
    protected $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    public function manualTemplates(): JsonResponse
    {
        return response()->json(['data' => array_values(self::MANUAL_TEMPLATES)]);
    }

    public function sendManualTemplate(Request $request, int $userId): JsonResponse
    {
        $request->validate(['template' => 'required|string|in:' . implode(',', array_keys(self::MANUAL_TEMPLATES))]);
        $user = User::withCount('shops')->findOrFail($userId);

        if (!$user->email || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'У пользователя не указана корректная электронная почта.'], 422);
        }

        if ($request->template === 'seller_create_shop') {
            if (!$user->permissions()->where('name', 'store_owner')->exists()) {
                return response()->json(['message' => 'Это письмо доступно только для продавцов.'], 422);
            }
            if ($user->shops_count > 0) {
                return response()->json(['message' => 'У продавца уже есть магазин — письмо не отправлено.'], 422);
            }
            try {
                Mail::to($user->email)->send(new SellerCreateShopReminder($user));
            } catch (\Throwable $exception) {
                Log::error('Manual marketing email failed', [
                    'template' => $request->template,
                    'recipient_user_id' => $user->id,
                    'recipient_email' => $user->email,
                    'error' => $exception->getMessage(),
                ]);
                return response()->json([
                    'message' => 'Почтовый сервер сейчас недоступен. Письмо не отправлено.',
                ], 502);
            }
        }

        Log::info('Manual marketing email sent', ['template' => $request->template, 'recipient_user_id' => $user->id, 'recipient_email' => $user->email, 'admin_user_id' => optional($request->user())->id]);
        return response()->json(['success' => true, 'message' => 'Письмо отправлено на ' . $user->email]);
    }

    /**
     * Send contact form email to admin
     */
    public function sendContactEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'subject' => 'required|string|max:255',
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'description' => 'required|string|max:1000',
            ]);

            $details = $request->only('subject', 'name', 'email', 'description');
            Mail::to(config('shop.admin_email'))->send(new ContactAdmin($details));

            return response()->json([
                'message' => 'Email sent successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send email: ' . $e->getMessage());
        }
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                    'success' => false
                ], 404);
            }

            // Generate reset token
            $token = \Str::random(64);
            \DB::table('password_resets')->updateOrInsert(
                ['email' => $user->email],
                ['token' => \Hash::make($token), 'created_at' => now()]
            );

            // Send email
            Mail::to($user->email)->send(new ForgetPassword($user, $token));

            return response()->json([
                'message' => 'Password reset email sent successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send password reset email: ' . $e->getMessage());
        }
    }

    /**
     * Send order notification to customer
     */
    public function sendOrderNotificationToCustomer(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'order_id' => 'required|exists:orders,id',
                'notification_type' => 'required|in:created,processed,delivered,cancelled,status_changed',
            ]);

            $order = Order::with(['customer', 'shop'])->findOrFail($request->order_id);
            $notificationType = $request->notification_type;

            if (!$order->customer) {
                return response()->json([
                    'message' => 'Order has no customer',
                    'success' => false
                ], 400);
            }

            switch ($notificationType) {
                case 'created':
                    $order->customer->notify(new OrderPlacedSuccessfully($order));
                    break;
                case 'processed':
                    $order->customer->notify(new \Marvel\Notifications\NewOrderProcessed($order));
                    break;
                case 'delivered':
                    $order->customer->notify(new OrderDeliveredNotification($order));
                    break;
                case 'cancelled':
                    $order->customer->notify(new OrderCancelledNotification($order));
                    break;
                case 'status_changed':
                    $order->customer->notify(new OrderStatusChangedNotification($order));
                    break;
            }

            return response()->json([
                'message' => 'Order notification sent to customer successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send order notification: ' . $e->getMessage());
        }
    }

    /**
     * Send order notification to store owner
     */
    public function sendOrderNotificationToStoreOwner(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'order_id' => 'required|exists:orders,id',
                'notification_type' => 'required|in:created,processed,delivered,cancelled,status_changed',
            ]);

            $order = Order::with(['shop.owner'])->findOrFail($request->order_id);
            $notificationType = $request->notification_type;

            if (!$order->shop || !$order->shop->owner) {
                return response()->json([
                    'message' => 'Order has no shop owner',
                    'success' => false
                ], 400);
            }

            switch ($notificationType) {
                case 'created':
                    $order->shop->owner->notify(new NewOrderReceived($order, 'storeOwner'));
                    break;
                case 'processed':
                    $order->shop->owner->notify(new \Marvel\Notifications\NewOrderProcessed($order));
                    break;
                case 'delivered':
                    $order->shop->owner->notify(new OrderDeliveredNotification($order));
                    break;
                case 'cancelled':
                    $order->shop->owner->notify(new OrderCancelledNotification($order));
                    break;
                case 'status_changed':
                    $order->shop->owner->notify(new OrderStatusChangedNotification($order));
                    break;
            }

            return response()->json([
                'message' => 'Order notification sent to store owner successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send order notification: ' . $e->getMessage());
        }
    }

    /**
     * Send order notification to admin
     */
    public function sendOrderNotificationToAdmin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'order_id' => 'required|exists:orders,id',
                'notification_type' => 'required|in:created,processed,delivered,cancelled,status_changed',
            ]);

            $order = Order::findOrFail($request->order_id);
            $notificationType = $request->notification_type;

            // Get all admin users
            $admins = User::whereHas('permissions', function($query) {
                $query->where('name', 'super_admin');
            })->get();

            if ($admins->isEmpty()) {
                return response()->json([
                    'message' => 'No admin users found',
                    'success' => false
                ], 400);
            }

            foreach ($admins as $admin) {
                switch ($notificationType) {
                    case 'created':
                        $admin->notify(new NewOrderReceived($order, 'admin'));
                        break;
                    case 'processed':
                        $admin->notify(new \Marvel\Notifications\NewOrderProcessed($order));
                        break;
                    case 'delivered':
                        $admin->notify(new OrderDeliveredNotification($order));
                        break;
                    case 'cancelled':
                        $admin->notify(new OrderCancelledNotification($order));
                        break;
                    case 'status_changed':
                        $admin->notify(new OrderStatusChangedNotification($order));
                        break;
                }
            }

            return response()->json([
                'message' => 'Order notification sent to admins successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send order notification: ' . $e->getMessage());
        }
    }

    /**
     * Send product notification
     */
    public function sendProductNotification(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'notification_type' => 'required|in:approved,rejected',
                'recipient_type' => 'required|in:vendor,admin',
            ]);

            $product = Product::with(['shop.owner'])->findOrFail($request->product_id);
            $notificationType = $request->notification_type;
            $recipientType = $request->recipient_type;

            if ($recipientType === 'vendor' && $product->shop && $product->shop->owner) {
                if ($notificationType === 'approved') {
                    $product->shop->owner->notify(new ProductApprovedNotification($product));
                } else {
                    $product->shop->owner->notify(new ProductRejectedNotification($product));
                }
            } elseif ($recipientType === 'admin') {
                $admins = User::whereHas('permissions', function($query) {
                    $query->where('name', 'super_admin');
                })->get();

                foreach ($admins as $admin) {
                    if ($notificationType === 'approved') {
                        $admin->notify(new ProductApprovedNotification($product));
                    } else {
                        $admin->notify(new ProductRejectedNotification($product));
                    }
                }
            }

            return response()->json([
                'message' => 'Product notification sent successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send product notification: ' . $e->getMessage());
        }
    }

    /**
     * Send payment notification
     */
    public function sendPaymentNotification(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'order_id' => 'required|exists:orders,id',
                'notification_type' => 'required|in:successful,failed',
                'recipient_type' => 'required|in:customer,vendor,admin',
            ]);

            $order = Order::with(['customer', 'shop.owner'])->findOrFail($request->order_id);
            $notificationType = $request->notification_type;
            $recipientType = $request->recipient_type;

            if ($recipientType === 'customer' && $order->customer) {
                if ($notificationType === 'successful') {
                    $order->customer->notify(new PaymentSuccessfulNotification($order));
                } else {
                    $order->customer->notify(new PaymentFailedNotification($order));
                }
            } elseif ($recipientType === 'vendor' && $order->shop && $order->shop->owner) {
                if ($notificationType === 'successful') {
                    $order->shop->owner->notify(new PaymentSuccessfulNotification($order));
                } else {
                    $order->shop->owner->notify(new PaymentFailedNotification($order));
                }
            } elseif ($recipientType === 'admin') {
                $admins = User::whereHas('permissions', function($query) {
                    $query->where('name', 'super_admin');
                })->get();

                foreach ($admins as $admin) {
                    if ($notificationType === 'successful') {
                        $admin->notify(new PaymentSuccessfulNotification($order));
                    } else {
                        $admin->notify(new PaymentFailedNotification($order));
                    }
                }
            }

            return response()->json([
                'message' => 'Payment notification sent successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send payment notification: ' . $e->getMessage());
        }
    }

    /**
     * Send bulk email to users
     */
    public function sendBulkEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'exists:users,id',
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
                'template' => 'nullable|string',
            ]);

            $users = User::whereIn('id', $request->user_ids)->get();
            $subject = $request->subject;
            $message = $request->message;
            $template = $request->template ?? 'emails.custom';

            foreach ($users as $user) {
                Mail::to($user->email)->send(new \Marvel\Mail\CustomEmail($user, $subject, $message, $template));
            }

            return response()->json([
                'message' => 'Bulk email sent successfully to ' . $users->count() . ' users',
                'success' => true,
                'sent_count' => $users->count()
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send bulk email: ' . $e->getMessage());
        }
    }

    /**
     * Send commission rate update notification
     */
    public function sendCommissionRateUpdate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'shop_id' => 'required|exists:shops,id',
                'old_rate' => 'required|numeric|min:0|max:100',
                'new_rate' => 'required|numeric|min:0|max:100',
                'recipient_type' => 'required|in:vendor,admin',
            ]);

            $shop = Shop::with('owner')->findOrFail($request->shop_id);
            $oldRate = $request->old_rate;
            $newRate = $request->new_rate;
            $recipientType = $request->recipient_type;

            if ($recipientType === 'vendor' && $shop->owner) {
                Mail::to($shop->owner->email)->send(new VendorCommissionRateUpdate($shop, $oldRate, $newRate));
            } elseif ($recipientType === 'admin') {
                $admins = User::whereHas('permissions', function($query) {
                    $query->where('name', 'super_admin');
                })->get();

                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new AdminCommissionRateUpdate($shop, $oldRate, $newRate));
                }
            }

            return response()->json([
                'message' => 'Commission rate update notification sent successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            throw new MarvelException('Failed to send commission rate update: ' . $e->getMessage());
        }
    }

    /**
     * Test email configuration
     */
    public function testEmailConfiguration(): JsonResponse
    {
        try {
            $testEmail = config('mail.from.address');
            $adminEmail = config('shop.admin_email');

            if (!$testEmail || !$adminEmail) {
                return response()->json([
                    'message' => 'Email configuration is incomplete',
                    'success' => false,
                    'config' => [
                        'from_address' => $testEmail,
                        'admin_email' => $adminEmail,
                    ]
                ], 400);
            }

            // Send test email
            Mail::to($adminEmail)->send(new \Marvel\Mail\TestEmail());

            return response()->json([
                'message' => 'Test email sent successfully',
                'success' => true,
                'config' => [
                    'from_address' => $testEmail,
                    'admin_email' => $adminEmail,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send test email: ' . $e->getMessage(),
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}


