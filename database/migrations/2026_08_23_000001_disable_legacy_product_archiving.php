<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_settings')
            ->where('key', 'overdue_action')
            ->update(['value' => 'none', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('billing_settings')
            ->where('key', 'overdue_action')
            ->update(['value' => 'hide_products', 'updated_at' => now()]);
    }
};
