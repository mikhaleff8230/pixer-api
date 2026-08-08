<?php
namespace App\Console\Commands;
use App\Models\SecondLifeOrder; use App\Services\Payments\DirectSbpOrderService; use Illuminate\Console\Command;
class CancelExpiredSecondLifeReservations extends Command { protected $signature='orders:cancel-expired-reservations'; protected $description='Cancel expired Second Hand reservations'; public function handle(DirectSbpOrderService $s):int{SecondLifeOrder::whereIn('order_status',['reserved','waiting_payment'])->where('reserved_until','<',now())->chunkById(100,fn($orders)=>$orders->each(fn($o)=>$s->cancelExpiredReservation($o)));return self::SUCCESS;} }
