<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('status', 24)->default('in_progress');
            $table->string('step', 24)->default('shop');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('shop_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->foreignId('first_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->uuid('product_request_key')->unique();
            $table->json('product_draft')->nullable();
            $table->unsignedInteger('draft_version')->default(0);
            $table->json('attribution')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_onboarding_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 80);
            $table->json('payload')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('claimed_at')->nullable();
            $table->unique(['user_id', 'event']);
        });
        Schema::table('products', function (Blueprint $table) {
            // Null identifies legacy products; no change to their visibility.
            $table->string('moderation_status', 24)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('moderation_status'));
        Schema::dropIfExists('seller_onboarding_events');
        Schema::dropIfExists('seller_onboardings');
    }
};
