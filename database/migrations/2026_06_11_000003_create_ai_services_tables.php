<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_services')) {
            Schema::create('ai_services', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('service_type')->index();
                $table->string('provider')->default('openai')->index();
                $table->string('model')->nullable();
                $table->decimal('cost', 15, 2)->default(0);
                $table->string('currency')->default('credits');
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('url');
                $table->string('thumbnail_url')->nullable();
                $table->boolean('is_ai_generated')->default(false)->index();
                $table->unsignedBigInteger('original_image_id')->nullable()->index();
                $table->unsignedBigInteger('ai_service_job_id')->nullable()->index();
                $table->string('image_role')->default('gallery')->index();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ai_service_jobs')) {
            Schema::create('ai_service_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('product_image_id')->nullable()->index();
                $table->unsignedBigInteger('ai_service_id')->index();
                $table->string('status')->default('draft')->index();
                $table->string('provider')->nullable()->index();
                $table->string('model')->nullable();
                $table->json('input_payload')->nullable();
                $table->json('output_payload')->nullable();
                $table->text('input_image_url')->nullable();
                $table->text('output_image_url')->nullable();
                $table->decimal('cost', 15, 2)->default(0);
                $table->string('currency')->default('credits');
                $table->unsignedBigInteger('balance_transaction_id')->nullable()->index();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('seller_balance_transactions') && !Schema::hasColumn('seller_balance_transactions', 'ai_service_job_id')) {
            Schema::table('seller_balance_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('ai_service_job_id')->nullable()->index()->after('order_id');
            });
        }

        $this->seedAiServices();
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_balance_transactions') && Schema::hasColumn('seller_balance_transactions', 'ai_service_job_id')) {
            Schema::table('seller_balance_transactions', function (Blueprint $table) {
                $table->dropColumn('ai_service_job_id');
            });
        }

        Schema::dropIfExists('ai_service_jobs');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('ai_services');
    }

    private function seedAiServices(): void
    {
        $now = now();

        foreach ([
            ['code' => 'photo_enhance', 'name' => 'Улучшить фото', 'description' => 'Улучшает свет, резкость и чистоту фото без изменения товара.', 'service_type' => 'photo', 'provider' => 'openai', 'model' => 'gpt-image-mini', 'cost' => 15, 'currency' => 'credits', 'sort_order' => 10],
            ['code' => 'photo_white_background', 'name' => 'Белый фон', 'description' => 'Отделяет товар от фона и ставит белый или светлый фон.', 'service_type' => 'photo', 'provider' => 'openai', 'model' => 'gpt-image-mini', 'cost' => 20, 'currency' => 'credits', 'sort_order' => 20],
            ['code' => 'photo_marketplace_crop', 'name' => 'Обрезка под маркетплейс', 'description' => 'Центрирует вещь и готовит вертикальный формат для карточки.', 'service_type' => 'photo', 'provider' => 'internal', 'model' => 'image_processor', 'cost' => 3, 'currency' => 'credits', 'sort_order' => 30],
            ['code' => 'generate_description', 'name' => 'Описание товара', 'description' => 'Генерирует заголовок, описание, категорию, теги и рекомендации.', 'service_type' => 'text', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'cost' => 2, 'currency' => 'credits', 'sort_order' => 40],
            ['code' => 'photo_analyze', 'name' => 'Анализ фото', 'description' => 'Определяет тип вещи, цвет, дефекты, бирку и качество фото.', 'service_type' => 'analysis', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'cost' => 3, 'currency' => 'credits', 'sort_order' => 50],
            ['code' => 'estimate_price', 'name' => 'Оценка цены', 'description' => 'Предлагает цену для быстрой продажи, обычную и максимальную цену.', 'service_type' => 'pricing', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'cost' => 3, 'currency' => 'credits', 'sort_order' => 60],
        ] as $service) {
            DB::table('ai_services')->updateOrInsert(
                ['code' => $service['code']],
                array_merge($service, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now])
            );
        }
    }
};