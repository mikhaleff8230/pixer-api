<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_tax_statuses')) {
            Schema::create('seller_tax_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('requires_receipt')->default(false);
                $table->boolean('can_resell_goods')->default(false);
                $table->boolean('is_business')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_origin_types')) {
            Schema::create('product_origin_types', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_conditions')) {
            Schema::create('product_conditions', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('seller_agreements')) {
            Schema::create('seller_agreements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->string('agreement_type')->index();
                $table->timestamp('accepted_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payment_profiles')) {
            Schema::create('payment_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('type')->index();
                $table->string('receiver_name');
                $table->string('phone')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('inn')->nullable();
                $table->string('company_name')->nullable();
                $table->text('sbp_qr_url')->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('second_life_orders')) {
            Schema::create('second_life_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('buyer_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('payment_profile_id')->nullable()->index();
                $table->decimal('price', 15, 2)->default(0);
                $table->decimal('platform_fee', 15, 2)->default(0);
                $table->string('payment_method')->default('direct_sbp')->index();
                $table->string('payment_status')->default('waiting_payment')->index();
                $table->string('order_status')->default('created')->index();
                $table->string('receiver_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('company_name')->nullable();
                $table->string('inn')->nullable();
                $table->text('sbp_qr_url')->nullable();
                $table->text('buyer_payment_comment')->nullable();
                $table->text('buyer_payment_screenshot')->nullable();
                $table->timestamp('buyer_marked_paid_at')->nullable();
                $table->timestamp('seller_confirmed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('seller_balance_transactions')) {
            Schema::create('seller_balance_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('type')->index();
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_before', 15, 2)->default(0);
                $table->decimal('balance_after', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('platform_fee_rules')) {
            Schema::create('platform_fee_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_tax_status_id')->nullable()->index();
                $table->unsignedBigInteger('product_origin_type_id')->nullable()->index();
                $table->string('fee_type')->default('fixed');
                $table->decimal('fee_value', 15, 2)->default(0);
                $table->decimal('min_fee', 15, 2)->nullable();
                $table->decimal('max_fee', 15, 2)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('seller_ratings')) {
            Schema::create('seller_ratings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->unique();
                $table->unsignedInteger('completed_orders')->default(0);
                $table->unsignedInteger('cancelled_orders')->default(0);
                $table->unsignedInteger('disputes_count')->default(0);
                $table->decimal('rating_score', 5, 2)->default(0);
                $table->decimal('verification_score', 5, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('seller_verifications')) {
            Schema::create('seller_verifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->string('verification_type')->index();
                $table->string('status')->default('pending')->index();
                $table->text('document_url')->nullable();
                $table->text('face_scan_url')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        $this->seedDictionaries();
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_verifications');
        Schema::dropIfExists('seller_ratings');
        Schema::dropIfExists('platform_fee_rules');
        Schema::dropIfExists('seller_balance_transactions');
        Schema::dropIfExists('second_life_orders');
        Schema::dropIfExists('payment_profiles');
        Schema::dropIfExists('seller_agreements');
        Schema::dropIfExists('product_conditions');
        Schema::dropIfExists('product_origin_types');
        Schema::dropIfExists('seller_tax_statuses');
    }

    private function seedDictionaries(): void
    {
        $now = now();

        foreach ([
            ['code' => 'private_person', 'name' => 'Частное лицо', 'description' => 'Продает личные вещи.', 'requires_receipt' => false, 'can_resell_goods' => false, 'is_business' => false],
            ['code' => 'self_employed', 'name' => 'Самозанятый', 'description' => 'Оказывает услуги или продает товары собственного производства.', 'requires_receipt' => true, 'can_resell_goods' => false, 'is_business' => true],
            ['code' => 'individual_entrepreneur', 'name' => 'ИП', 'description' => 'Может заниматься торговлей и перепродажей.', 'requires_receipt' => true, 'can_resell_goods' => true, 'is_business' => true],
            ['code' => 'company', 'name' => 'Компания', 'description' => 'Юридическое лицо.', 'requires_receipt' => true, 'can_resell_goods' => true, 'is_business' => true],
            ['code' => 'unknown', 'name' => 'Не выбран', 'description' => 'Статус деятельности еще не выбран.', 'requires_receipt' => false, 'can_resell_goods' => false, 'is_business' => false],
        ] as $status) {
            DB::table('seller_tax_statuses')->updateOrInsert(
                ['code' => $status['code']],
                array_merge($status, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        foreach ([
            ['code' => 'personal_used', 'name' => 'Личная вещь б/у', 'description' => 'Личная вещь продавца, бывшая в употреблении.'],
            ['code' => 'personal_new', 'name' => 'Личная вещь новая', 'description' => 'Новая личная вещь продавца.'],
            ['code' => 'handmade', 'name' => 'Собственное производство', 'description' => 'Товар или услуга собственного производства.'],
            ['code' => 'resale', 'name' => 'Перепродажа', 'description' => 'Товар приобретен для дальнейшей продажи.'],
            ['code' => 'shop_stock', 'name' => 'Товар магазина', 'description' => 'Товар из складского остатка магазина.'],
        ] as $origin) {
            DB::table('product_origin_types')->updateOrInsert(
                ['code' => $origin['code']],
                array_merge($origin, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        foreach ([
            ['code' => 'new_with_tags', 'name' => 'Новый с бирками', 'description' => 'Новый товар с бирками или заводской упаковкой.', 'sort_order' => 10],
            ['code' => 'new_without_tags', 'name' => 'Новый без бирок', 'description' => 'Новый товар без бирок.', 'sort_order' => 20],
            ['code' => 'excellent', 'name' => 'Отличное', 'description' => 'Минимальные следы использования.', 'sort_order' => 30],
            ['code' => 'good', 'name' => 'Хорошее', 'description' => 'Есть обычные следы использования.', 'sort_order' => 40],
            ['code' => 'fair', 'name' => 'Удовлетворительное', 'description' => 'Есть заметные следы использования.', 'sort_order' => 50],
            ['code' => 'needs_repair', 'name' => 'Требует ремонта', 'description' => 'Нужен ремонт или восстановление.', 'sort_order' => 60],
        ] as $condition) {
            DB::table('product_conditions')->updateOrInsert(
                ['code' => $condition['code']],
                array_merge($condition, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }
};
