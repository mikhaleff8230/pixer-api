<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
        \Marvel\Database\Models\Comment::class => \Marvel\Policies\CommentPolicy::class,
        \App\Models\PaymentProfile::class => \App\Policies\PaymentProfilePolicy::class,
        \App\Models\SecondLifeOrder::class => \App\Policies\OrderPolicy::class,
        \App\Models\PaymentConfirmation::class => \App\Policies\PaymentConfirmationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        //
    }
}
