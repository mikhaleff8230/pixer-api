<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('yandex_direct_settings', function (Blueprint $table) {
            $table->decimal('campaign_bid_ceiling', 8, 2)->default(40);
            $table->decimal('default_bid_level', 8, 2)->default(20);
            $table->json('allowed_bid_levels')->nullable();
            $table->string('strategy_sync_status')->nullable();
            $table->timestamp('strategy_synced_at')->nullable();
        });
        Schema::table('seller_yandex_ad_groups', function (Blueprint $table) {
            $table->decimal('bid_level', 8, 2)->default(20);
            $table->unsignedSmallInteger('last_applied_bid_modifier')->nullable();
            $table->unsignedBigInteger('bid_modifier_id')->nullable();
        });
        Schema::create('seller_yandex_intensity_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('old_level', 8, 2)->nullable();
            $table->decimal('new_level', 8, 2);
            $table->unsignedSmallInteger('calculated_modifier');
            $table->string('source')->default('seller');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('seller_yandex_intensity_audits');
        Schema::table('seller_yandex_ad_groups', fn (Blueprint $table) => $table->dropColumn(['bid_level','last_applied_bid_modifier','bid_modifier_id']));
        Schema::table('yandex_direct_settings', fn (Blueprint $table) => $table->dropColumn(['campaign_bid_ceiling','default_bid_level','allowed_bid_levels','strategy_sync_status','strategy_synced_at']));
    }
};
