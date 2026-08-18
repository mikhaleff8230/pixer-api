<?php

namespace Marvel\Database\Models;

use App\Jobs\SyncSellerYandexBoostJob;
use App\Enums\RoleType;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class User extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;
    use HasRoles;
    use HasApiTokens;


    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'is_active', 'shop_id', 'last_login_at'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    protected $appends = ['email_verified', 'role', 'user_permissions'];

    protected static function boot()
    {
        parent::boot();
        // Order by updated_at desc
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('updated_at', 'desc');
        });
        static::updated(function ($user) {
            if ($user->wasChanged('is_active')) SyncSellerYandexBoostJob::dispatchAfterResponse((int) $user->id);
        });
    }


    public function getEmailVerifiedAttribute(): bool
    {
        return $this->hasVerifiedEmail();
    }

    public function getRoleAttribute()
    {
        $roles = $this->getRoleNames();
        return $roles->count() > 0 ? $roles->first() : null;
    }

    public function getUserPermissionsAttribute()
    {
        return $this->getPermissionNames()->toArray();
    }


    /**
     * @return HasMany
     */
    public function address(): HasMany
    {
        return $this->hasMany(Address::class, 'customer_id');
    }

    /**
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id')->with(['products.variation_options', 'status']);
    }

    /**
     * @return HasOne
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'customer_id');
    }

    /**
     * @return HasOne
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'customer_id');
    }

    /**
     * @return HasMany
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'owner_id');
    }

    /**
     * @return HasMany
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Shop::class, 'customer_id');
    }

    /**
     * @return BelongsTo
     */
    public function managed_shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class, 'user_id', 'id');
    }

    /**
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function ordered_files(): HasMany
    {
        return $this->hasMany(OrderedFiles::class, 'customer_id');
    }

    /**
     * Follow shop
     *
     * @return BelongsToMany
     */
    public function follow_shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'user_shop');
    }


    /**
     * Follow shop
     *
     * @return HasMany
     */
    public function payment_gateways(): HasMany
    {
        return $this->HasMany(PaymentGateway::class, 'user_id');
    }

    /**
     * Связь с тарифным планом
     *
     * @return BelongsTo
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Plan::class, 'plan_id');
    }

    /**
     * Связь с балансом продавца
     *
     * @return HasOne
     */
    public function sellerBalance(): HasOne
    {
        return $this->hasOne(\App\Models\SellerBalance::class, 'seller_id');
    }

    /**
     * Связь с подписками на тарифы
     *
     * @return HasMany
     */
    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(\App\Models\PlanSubscription::class, 'seller_id');
    }

    /**
     * Связь с дополнительными покупками
     *
     * @return HasMany
     */
    public function additionalPurchases(): HasMany
    {
        return $this->hasMany(\App\Models\AdditionalPurchase::class, 'seller_id');
    }

    /**
     * Проверить, оплачен ли тариф за текущий период
     * 
     * Новая логика: Проверяем активную подписку на тариф
     * 
     * @return bool
     */
    public function isPlanPaidForCurrentPeriod(): bool
    {
        if (!$this->plan_id) {
            // Если тариф не выбран, считаем что не оплачен
            return false;
        }

        $plan = $this->plan;
        
        // Если тариф Free, всегда считается оплаченным
        if ($plan && $plan->name === 'Free') {
            return true;
        }

        // Проверяем активную подписку
        $subscription = \App\Models\PlanSubscription::getActive($this->id);
        
        return $subscription !== null && $subscription->plan_id === $this->plan_id;
    }

    /**
     * Получить активную подписку
     * 
     * @return \App\Models\PlanSubscription|null
     */
    public function getActiveSubscription(): ?\App\Models\PlanSubscription
    {
        return \App\Models\PlanSubscription::getActive($this->id);
    }

    /**
     * Проверить, активен ли тариф (оплачен за текущий период)
     * 
     * @return bool
     */
    public function isPlanActive(): bool
    {
        // Если тариф Free, всегда активен
        $plan = $this->plan;
        if ($plan && $plan->name === 'Free') {
            return true;
        }

        return $this->isPlanPaidForCurrentPeriod();
    }

    /**
     * Получить доступные функции тарифа (с учетом оплаты)
     * 
     * @return array
     */
    public function getAvailablePlanFeatures(): array
    {
        $plan = $this->plan;
        
        if (!$plan) {
            return [
                'link_ozon_wb' => false,
                'utm_tracking' => false,
                'chat_enabled' => false,
                'featured_collections' => false,
            ];
        }

        // Если тариф не оплачен, функции недоступны (кроме Free)
        if (!$this->isPlanActive()) {
            return [
                'link_ozon_wb' => false,
                'utm_tracking' => false,
                'chat_enabled' => false,
                'featured_collections' => false,
            ];
        }

        return [
            'link_ozon_wb' => (bool) $plan->link_ozon_wb,
            'utm_tracking' => (bool) $plan->utm_tracking,
            'chat_enabled' => (bool) $plan->chat_enabled,
            'featured_collections' => (bool) $plan->featured_collections,
        ];
    }
}
