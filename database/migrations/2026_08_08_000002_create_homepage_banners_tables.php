<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_banners', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['hero', 'strip'])->index();
            $table->json('content');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('homepage_banner_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('autoplay')->default(true);
            $table->unsignedInteger('interval_ms')->default(5000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_banner_settings');
        Schema::dropIfExists('homepage_banners');
    }
};
