<?php

namespace App\Providers;

use App\Services\DigitalProducts\Handlers\AccountProductHandler;
use App\Services\DigitalProducts\Handlers\FileProductHandler;
use App\Services\DigitalProducts\Handlers\KeyProductHandler;
use App\Services\DigitalProducts\Handlers\LinkProductHandler;
use App\Services\DigitalProducts\Handlers\PromptProductHandler;
use App\Services\DigitalProducts\Handlers\SubscriptionProductHandler;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        foreach ([
            FileProductHandler::class,
            PromptProductHandler::class,
            LinkProductHandler::class,
            AccountProductHandler::class,
            KeyProductHandler::class,
            SubscriptionProductHandler::class,
        ] as $handler) {
            $this->app->bind($handler, $handler);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);
        \Marvel\Database\Models\Product::observe(\App\Observers\OnboardingProductObserver::class);
    }
}
