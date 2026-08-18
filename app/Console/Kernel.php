<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \Marvel\Console\CleanupImportsCommand::class,
        \Marvel\Console\Commands\TestVariableProductCommand::class,
        \Marvel\Console\Commands\AddCodeToProductSlugs::class,
        Commands\UpdateGeoIPDatabase::class,
        Commands\GenerateMonthlyInvoices::class,
        Commands\CheckOverdueInvoices::class,
        Commands\RecalculateOldInvoices::class,
        Commands\CheckBillingSettings::class,
        Commands\CheckPlanStatus::class,
        Commands\AutoRenewSubscriptions::class,
        Commands\MarkAllOrdersCompleted::class,
        Commands\ProcessPendingBalanceDeposits::class,
        Commands\CheckBalanceDeposits::class,
        Commands\CheckSellerBalance::class,
        Commands\GenerateSitemap::class,
        Commands\CancelExpiredSecondLifeReservations::class,
        Commands\SyncYandexDirectStats::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        
        // Обновляем номера CDEK только когда включена физическая доставка
        if (config('services.marketplace.physical_shipping_enabled', false)) {
            $schedule->command('cdek:update-numbers')->everyThirtyMinutes();
        }
        
        // Автоматическая очистка старых незавершенных импортов каждый час
        $schedule->command('imports:cleanup --age=2')->hourly();
        
        // Обновление базы данных GeoIP каждую неделю (понедельник в 2:00) через GitHub
        $schedule->command('geoip:update-database')->weeklyOn(1, '02:00');
        
        // Генерация месячных счетов 1 числа каждого месяца в 3:00
        $schedule->command('billing:generate-monthly')->monthlyOn(1, '03:00');
        
        // Проверка просроченных счетов ежедневно в 4:00
        $schedule->command('billing:check-overdue')->dailyAt('04:00');
        
        // Проверка статуса тарифов ежедневно в 5:00
        $schedule->command('billing:check-plan-status')->dailyAt('05:00');
        
        // Автопродление подписок 1-го числа каждого месяца в 06:00
        $schedule->command('billing:auto-renew')->monthlyOn(1, '06:00');
        
        // Генерация sitemap ежедневно в 3:00
        $schedule->command('sitemap:generate')->dailyAt('03:00');
        $schedule->command('orders:cancel-expired-reservations')->everyMinute()->withoutOverlapping();
        $settings = \App\Models\YandexDirectSetting::query()->first();
        if ($settings?->enabled) {
            $schedule->command('yandex-direct:sync-stats')->everyFiveMinutes()->withoutOverlapping(20)->onOneServer();
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
