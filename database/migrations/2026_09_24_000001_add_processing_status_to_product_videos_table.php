<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_videos', function (Blueprint $table) {
            $table->string('processing_status', 20)->default('ready')->after('mime_type');
            $table->text('processing_error')->nullable()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_videos', function (Blueprint $table) {
            $table->dropColumn(['processing_status', 'processing_error']);
        });
    }
};