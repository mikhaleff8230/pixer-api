<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Marvel\Database\Models\User;
use Carbon\Carbon;

class CheckPlanStatus extends Command
{
    protected $signature = 'billing:check-plan-status';
    protected $description = 'Проверить статус тарифов и деактивировать функции при неоплате';

    public function handle()
    {
        $this->info('Проверка статуса тарифов...');

        $now = Carbon::now();
        $currentPeriodStart = $now->copy()->startOfMonth();
        $currentPeriodEnd = $now->copy()->endOfMonth();

        // Получаем всех продавцов с тарифами (кроме Free)
        $sellers = User::whereHas('plan', function ($query) {
            $query->where('name', '!=', 'Free');
        })->with('plan')->get();

        $deactivatedCount = 0;

        foreach ($sellers as $seller) {
            // Проверяем, оплачен ли тариф за текущий период
            $isPaid = $seller->isPlanPaidForCurrentPeriod();

            if (!$isPaid) {
                $this->warn("Тариф продавца {$seller->id} ({$seller->name}) не оплачен за текущий период");

                // Исторические счета и тарифы больше не управляют публикацией товаров.
                // Размещение товаров в SANCAN бесплатное, поэтому status менять нельзя.

                $deactivatedCount++;
            }
        }

        $this->info("Проверка завершена. Найдено {$deactivatedCount} продавцов с неоплаченными тарифами.");
        return 0;
    }
}




