<?php

namespace App\Services;

use App\Models\AiServiceJob;
use App\Models\SellerBalance;
use App\Models\SellerBalanceTransaction;
use Illuminate\Support\Facades\DB;

class SellerBalanceService
{
    public function topUp($seller, float $amount, ?string $description = null): SellerBalanceTransaction
    {
        return $this->changeBalance($seller, abs($amount), 'topup', $description ?? 'Пополнение баланса');
    }

    public function chargeFee($seller, float $amount, ?int $orderId = null, ?string $description = null): SellerBalanceTransaction
    {
        return DB::transaction(function () use ($seller, $amount, $orderId, $description) {
            if (!$this->canCharge($seller, $amount)) {
                throw new \RuntimeException('Недостаточно средств на внутреннем балансе продавца.');
            }

            return $this->changeBalance($seller, -abs($amount), 'fee_charge', $description ?? 'Списание комиссии платформы', $orderId);
        });
    }

    public function refund($seller, float $amount, ?int $orderId = null, ?string $description = null): SellerBalanceTransaction
    {
        return $this->changeBalance($seller, abs($amount), 'refund', $description ?? 'Возврат на баланс', $orderId);
    }

    public function chargeAiService($seller, AiServiceJob $job): SellerBalanceTransaction
    {
        return DB::transaction(function () use ($seller, $job) {
            if (!empty($job->balance_transaction_id)) {
                throw new \RuntimeException('AI-услуга уже была списана с баланса.');
            }

            if (!$this->canCharge($seller, (float)$job->cost)) {
                throw new \RuntimeException('Недостаточно средств для запуска AI-услуги.');
            }

            return $this->changeBalance(
                $seller,
                -abs((float)$job->cost),
                'ai_service_charge',
                'AI-услуга: ' . ($job->service?->name ?? 'AI service'),
                null,
                $job->id
            );
        });
    }

    public function refundAiService($seller, AiServiceJob $job, ?string $reason = null): SellerBalanceTransaction
    {
        return $this->changeBalance(
            $seller,
            abs((float)$job->cost),
            'ai_service_refund',
            $reason ?: 'Возврат за AI-услугу',
            null,
            $job->id
        );
    }

    public function adjustment($seller, float $amount, ?string $description = null): SellerBalanceTransaction
    {
        return $this->changeBalance($seller, $amount, 'adjustment', $description ?? 'Корректировка баланса');
    }

    public function canCharge($seller, float $amount): bool
    {
        $balance = SellerBalance::getOrCreate($seller->id);
        return (float)$balance->balance >= abs($amount);
    }

    public function getCurrentBalance($seller): float
    {
        return (float)SellerBalance::getOrCreate($seller->id)->balance;
    }

    private function changeBalance($seller, float $delta, string $type, string $description, ?int $orderId = null, ?int $aiServiceJobId = null): SellerBalanceTransaction
    {
        return DB::transaction(function () use ($seller, $delta, $type, $description, $orderId, $aiServiceJobId) {
            $balance = SellerBalance::getOrCreate($seller->id);
            $balance->refresh();

            $before = (float)$balance->balance;

            if ($delta >= 0) {
                $balance->deposit($delta, $description);
            } else {
                if (!$balance->withdraw(abs($delta))) {
                    throw new \RuntimeException('Недостаточно средств на внутреннем балансе продавца.');
                }
            }

            $balance->refresh();
            $after = (float)$balance->balance;

            if ($seller) {
                $seller->forceFill(['seller_balance' => $after]);
                if ($after <= 0 && ($seller->seller_status ?? null) !== 'blocked') {
                    $seller->forceFill(['seller_status' => 'limited']);
                }
                $seller->save();
            }

            return SellerBalanceTransaction::create([
                'seller_id' => $seller->id,
                'order_id' => $orderId,
                'ai_service_job_id' => $aiServiceJobId,
                'type' => $type,
                'amount' => $delta,
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => $description,
            ]);
        });
    }
}