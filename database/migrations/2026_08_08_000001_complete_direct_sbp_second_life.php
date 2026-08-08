<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_profiles', 'bank_code')) $table->string('bank_code')->nullable()->after('bank_name');
            if (!Schema::hasColumn('payment_profiles', 'uploaded_qr_path')) $table->text('uploaded_qr_path')->nullable()->after('bank_code');
            if (!Schema::hasColumn('payment_profiles', 'payment_link')) $table->text('payment_link')->nullable()->after('uploaded_qr_path');
            if (!Schema::hasColumn('payment_profiles', 'verified_at')) $table->timestamp('verified_at')->nullable()->after('is_active');
            if (!Schema::hasColumn('payment_profiles', 'deleted_at')) $table->softDeletes();
        });

        Schema::table('second_life_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('second_life_orders', 'public_id')) $table->string('public_id')->nullable()->unique()->after('id');
            if (!Schema::hasColumn('second_life_orders', 'currency')) $table->string('currency', 3)->default('RUB')->after('platform_fee');
            if (!Schema::hasColumn('second_life_orders', 'reserved_until')) $table->timestamp('reserved_until')->nullable()->index();
            if (!Schema::hasColumn('second_life_orders', 'seller_confirmed_paid_at')) $table->timestamp('seller_confirmed_paid_at')->nullable();
            if (!Schema::hasColumn('second_life_orders', 'payment_rejected_at')) $table->timestamp('payment_rejected_at')->nullable();
            if (!Schema::hasColumn('second_life_orders', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable();
            if (!Schema::hasColumn('second_life_orders', 'dispute_reason')) $table->text('dispute_reason')->nullable();
            if (!Schema::hasColumn('second_life_orders', 'admin_comment')) $table->text('admin_comment')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'reserved_by_order_id')) $table->unsignedBigInteger('reserved_by_order_id')->nullable()->index();
            if (!Schema::hasColumn('products', 'reserved_until')) $table->timestamp('reserved_until')->nullable()->index();
        });

        if (!Schema::hasTable('order_payment_details')) {
            Schema::create('order_payment_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->string('receiver_name');
                $table->string('phone');
                $table->string('bank_name');
                $table->string('bank_code')->nullable();
                $table->text('uploaded_qr_path')->nullable();
                $table->text('payment_link')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payment_confirmations')) {
            Schema::create('payment_confirmations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('buyer_id')->index();
                $table->decimal('amount', 15, 2);
                $table->text('comment')->nullable();
                $table->text('screenshot_path')->nullable();
                $table->string('status')->default('submitted')->index();
                $table->timestamp('submitted_at');
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_events')) {
            Schema::create('order_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('event_type')->index();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('payment_confirmations');
        Schema::dropIfExists('order_payment_details');
    }
};
