<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE homepage_banners MODIFY kind ENUM('hero', 'strip', 'mobile') NOT NULL");
    }

    public function down(): void
    {
        DB::table('homepage_banners')->where('kind', 'mobile')->delete();
        DB::statement("ALTER TABLE homepage_banners MODIFY kind ENUM('hero', 'strip') NOT NULL");
    }
};
