<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('yandex_direct_settings')) {
            Schema::create('yandex_direct_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('oauth_token')->nullable();
            $table->string('client_login')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('feed_id')->nullable();
            $table->decimal('markup_percent', 5, 2)->default(30);
            $table->decimal('balance_reserve', 15, 2)->default(100);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(15);
            $table->timestamp('last_connection_check_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('seller_yandex_ad_groups')) {
            Schema::create('seller_yandex_ad_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot')->default(1);
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('ad_group_id')->nullable()->unique();
            $table->unsignedBigInteger('shopping_ad_id')->nullable()->unique();
            $table->unsignedBigInteger('feed_id');
            $table->string('status')->default('pending')->index();
            $table->string('pause_reason')->nullable()->index();
            $table->string('boost_filter_hash', 64)->nullable();
            $table->decimal('last_yandex_cost', 15, 2)->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'campaign_id', 'slot']);
            });
        }

        if (!Schema::hasTable('seller_ad_billing_entries')) {
            Schema::create('seller_ad_billing_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_yandex_ad_group_id')->constrained('seller_yandex_ad_groups')->cascadeOnDelete();
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            $table->decimal('yandex_cost_delta', 15, 2);
            $table->decimal('markup_percent', 5, 2);
            $table->decimal('seller_charge', 15, 2);
            $table->unsignedBigInteger('balance_transaction_id')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['seller_yandex_ad_group_id', 'period_from', 'period_to'], 'seller_ad_billing_period_unique');
            });
        }

        if (!Schema::hasTable('seller_ad_stats_daily')) {
            Schema::create('seller_ad_stats_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_yandex_ad_group_id')->constrained('seller_yandex_ad_groups')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('yandex_cost', 15, 2)->default(0);
            $table->decimal('seller_cost', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['seller_yandex_ad_group_id', 'date']);
            });
        }

        if (!Schema::hasTable('product_promotion_stats_daily')) {
            Schema::create('product_promotion_stats_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('yandex_clicks')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'date']);
            });
        }

        if (!Schema::hasTable('product_promotion_visits')) {
            Schema::create('product_promotion_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('source')->default('yandex');
            $table->string('type')->default('paid_click');
            $table->string('yclid', 255)->nullable()->unique();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasTable('yandex_direct_error_logs')) {
            Schema::create('yandex_direct_error_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('operation')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message');
            $table->json('context')->nullable();
            $table->timestamps();
            });
        }

        if (!Schema::hasColumn('products', 'boost_enabled')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('boost_enabled')->default(false)->index();
                $table->string('boost_status')->default('off')->index();
                $table->timestamp('boost_started_at')->nullable();
                $table->timestamp('boost_stopped_at')->nullable();
                $table->text('boost_last_error')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['boost_enabled', 'boost_status', 'boost_started_at', 'boost_stopped_at', 'boost_last_error']);
        });
        Schema::dropIfExists('yandex_direct_error_logs');
        Schema::dropIfExists('product_promotion_visits');
        Schema::dropIfExists('product_promotion_stats_daily');
        Schema::dropIfExists('seller_ad_stats_daily');
        Schema::dropIfExists('seller_ad_billing_entries');
        Schema::dropIfExists('seller_yandex_ad_groups');
        Schema::dropIfExists('yandex_direct_settings');
    }
};
