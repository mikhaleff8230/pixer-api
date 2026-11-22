<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('variation_options', 'stock_status')) {
            Schema::table('variation_options', function (Blueprint $table) {
                $table->string('stock_status', 24)->default('known')->after('quantity');
            });
        }
        if (!Schema::hasColumn('order_product', 'variation_snapshot')) {
            Schema::table('order_product', function (Blueprint $table) {
                $table->json('variation_snapshot')->nullable()->after('variation_option_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_product', 'variation_snapshot')) {
            Schema::table('order_product', fn (Blueprint $table) => $table->dropColumn('variation_snapshot'));
        }
        if (Schema::hasColumn('variation_options', 'stock_status')) {
            Schema::table('variation_options', fn (Blueprint $table) => $table->dropColumn('stock_status'));
        }
    }
};
