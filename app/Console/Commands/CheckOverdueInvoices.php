<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Models\BillingSettings;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'billing:check-overdue';
    protected $description = 'Check and mark overdue invoices, hide products';

    public function handle()
    {
        $this->info('Checking overdue invoices...');

        $daysBeforeOverdue = (int) BillingSettings::get('days_before_overdue', 30);
        // Находим счета со статусом pending, старше указанного количества дней
        $overdueInvoices = Invoice::where('status', 'pending')
            ->where('created_at', '<=', now()->subDays($daysBeforeOverdue))
            ->get();

        $markedOverdue = 0;

        foreach ($overdueInvoices as $invoice) {
            // Меняем статус на overdue
            $invoice->update(['status' => 'overdue']);
            $markedOverdue++;

            $this->info("Marked invoice {$invoice->id} as overdue for seller {$invoice->seller_id}");

            // Просроченный исторический счёт не влияет на товары: размещение бесплатно.
        }

        $this->info("Overdue check completed. Marked {$markedOverdue} invoices as overdue. Product statuses were not changed.");
        return 0;
    }
}



