<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'seller_tax_status_id')) {
                $table->unsignedBigInteger('seller_tax_status_id')->nullable()->index()->after('id');
            }
            if (!Schema::hasColumn('users', 'seller_type')) {
                $table->string('seller_type')->default('private')->index()->after('seller_tax_status_id');
            }
            if (!Schema::hasColumn('users', 'seller_status')) {
                $table->string('seller_status')->default('inactive')->index()->after('seller_type');
            }
            if (!Schema::hasColumn('users', 'seller_balance')) {
                $table->decimal('seller_balance', 15, 2)->default(0)->after('seller_status');
            }
            if (!Schema::hasColumn('users', 'seller_debt')) {
                $table->decimal('seller_debt', 15, 2)->default(0)->after('seller_balance');
            }
            if (!Schema::hasColumn('users', 'seller_agreed_tax_responsibility')) {
                $table->boolean('seller_agreed_tax_responsibility')->default(false)->after('seller_debt');
            }
            if (!Schema::hasColumn('users', 'seller_agreed_at')) {
                $table->timestamp('seller_agreed_at')->nullable()->after('seller_agreed_tax_responsibility');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'product_origin_type_id')) {
                $table->unsignedBigInteger('product_origin_type_id')->nullable()->index()->after('id');
            }
            if (!Schema::hasColumn('products', 'product_condition_id')) {
                $table->unsignedBigInteger('product_condition_id')->nullable()->index()->after('product_origin_type_id');
            }
            if (!Schema::hasColumn('products', 'is_personal_item')) {
                $table->boolean('is_personal_item')->default(false)->index()->after('product_condition_id');
            }
            if (!Schema::hasColumn('products', 'is_commercial_item')) {
                $table->boolean('is_commercial_item')->default(false)->index()->after('is_personal_item');
            }
            if (!Schema::hasColumn('products', 'requires_tax_warning')) {
                $table->boolean('requires_tax_warning')->default(false)->after('is_commercial_item');
            }
            if (!Schema::hasColumn('products', 'seller_declared_origin')) {
                $table->text('seller_declared_origin')->nullable()->after('requires_tax_warning');
            }
            if (!Schema::hasColumn('products', 'reserved_at')) {
                $table->timestamp('reserved_at')->nullable()->after('seller_declared_origin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'product_origin_type_id',
                'product_condition_id',
                'is_personal_item',
                'is_commercial_item',
                'requires_tax_warning',
                'seller_declared_origin',
                'reserved_at',
            ] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'seller_tax_status_id',
                'seller_type',
                'seller_status',
                'seller_balance',
                'seller_debt',
                'seller_agreed_tax_responsibility',
                'seller_agreed_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};