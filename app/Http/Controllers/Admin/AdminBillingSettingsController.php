<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BillingSettings;
use Illuminate\Support\Facades\Log;

class AdminBillingSettingsController extends Controller
{
    /**
     * Получить настройки биллинга
     */
    public function billing()
    {
        try {
            $settings = BillingSettings::allSettings();

            return response()->json([
                'success' => true,
                'data' => [
                    'price_per_product' => (float) ($settings['price_per_product'] ?? 5.00),
                    'currency' => $settings['currency'] ?? 'RUB',
                    'auto_generation' => (bool) ($settings['auto_generation'] ?? true),
                    'generation_day' => (int) ($settings['generation_day'] ?? 1),
                    'days_before_overdue' => (int) ($settings['days_before_overdue'] ?? 30),
                    'overdue_action' => 'none',
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('AdminBillingSettingsController@billing: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении настроек'
            ], 500);
        }
    }

    /**
     * Обновить настройки биллинга
     */
    public function updateBilling(Request $request)
    {
        try {
            $validated = $request->validate([
                'price_per_product' => 'sometimes|numeric|min:0',
                'currency' => 'sometimes|string|max:3',
                'auto_generation' => 'sometimes|boolean',
                'generation_day' => 'sometimes|integer|min:1|max:31',
                'days_before_overdue' => 'sometimes|integer|min:1',
                'overdue_action' => 'sometimes|in:none',
            ]);

            foreach ($validated as $key => $value) {
                BillingSettings::set($key, $value);
            }

            return response()->json([
                'success' => true,
                'message' => 'Настройки обновлены успешно'
            ]);
        } catch (\Exception $e) {
            Log::error('AdminBillingSettingsController@updateBilling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении настроек'
            ], 500);
        }
    }
}




