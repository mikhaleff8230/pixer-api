<?php

namespace App\Models;

use App\Jobs\SyncSellerYandexBoostJob;
use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\User;

class SellerBalance extends Model
{
    protected $table = 'seller_balances';

    protected $fillable = [
        'seller_id',
        'balance',
        'total_deposited',
        'total_spent',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'total_deposited' => 'decimal:2',
        'total_spent' => 'decimal:2',
    ];

    /**
     * Связь с продавцом
     */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Пополнить баланс
     */
    public function deposit(float $amount, string $description = ''): bool
    {
        $this->balance += $amount;
        $this->total_deposited += $amount;
        $saved = $this->save();
        if ($saved) SyncSellerYandexBoostJob::dispatchAfterResponse((int) $this->seller_id, false);
        return $saved;
    }

    /**
     * Списать с баланса
     */
    public function withdraw(float $amount): bool
    {
        if ($this->balance < $amount) {
            return false; // Недостаточно средств
        }

        $this->balance -= $amount;
        $this->total_spent += $amount;
        return $this->save();
    }

    /**
     * Проверить, достаточно ли средств
     */
    public function hasEnough(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Получить или создать баланс для продавца
     */
    public static function getOrCreate(int $sellerId): self
    {
        return self::firstOrCreate(
            ['seller_id' => $sellerId],
            [
                'balance' => 0,
                'total_deposited' => 0,
                'total_spent' => 0,
            ]
        );
    }
}

