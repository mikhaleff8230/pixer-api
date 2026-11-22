<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_import_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('created_by');
            $table->string('telegram_channel_id')->nullable();
            $table->string('username')->nullable();
            $table->string('title');
            $table->string('url')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('access_method')->default('bot');
            $table->string('status')->default('active')->index();
            $table->boolean('auto_import_enabled')->default(true);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('price_source')->default('wholesale');
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->decimal('fixed_markup', 12, 2)->default(0);
            $table->unsignedInteger('round_to')->default(1);
            $table->decimal('minimum_sale_price', 12, 2)->nullable();
            $table->string('initial_import_mode')->default('none');
            $table->unsignedInteger('initial_import_limit')->nullable();
            $table->timestamp('initial_from')->nullable();
            $table->timestamp('initial_to')->nullable();
            $table->bigInteger('last_processed_message_id')->nullable();
            $table->timestamp('last_publication_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('content_permission_confirmed_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'telegram_channel_id'], 'tg_channels_shop_channel_unique');
            $table->index(['shop_id', 'auto_import_enabled']);
        });

        Schema::create('telegram_import_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('telegram_import_channels')->cascadeOnDelete();
            $table->bigInteger('telegram_message_id');
            $table->string('grouped_id')->nullable();
            $table->string('source_url')->nullable();
            $table->longText('original_text')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('normalized_data')->nullable();
            $table->json('previous_versions')->nullable();
            $table->json('manual_fields')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('markup_percent_override', 8, 2)->nullable();
            $table->string('status')->default('new')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->timestamp('original_date')->nullable()->index();
            $table->timestamp('edited_date')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'telegram_message_id'], 'tg_posts_channel_message_unique');
            $table->index(['channel_id', 'grouped_id']);
            $table->index(['channel_id', 'status', 'created_at']);
            $table->index('category_id');
        });

        Schema::create('telegram_import_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('telegram_import_posts')->cascadeOnDelete();
            $table->string('telegram_file_identifier');
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->json('storage_data')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('content_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->unique(['post_id', 'telegram_file_identifier'], 'tg_media_post_file_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_import_media');
        Schema::dropIfExists('telegram_import_posts');
        Schema::dropIfExists('telegram_import_channels');
    }
};
