<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index();
            $table->json('avatar')->nullable();
            $table->json('cover')->nullable();
            $table->foreignId('owner_profile_id')->nullable()->constrained('user_profiles')->nullOnDelete();
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('created_by_admin')->default(false);
            $table->string('visibility')->default('public');
            $table->string('posting_policy')->default('members_only');
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('places_count')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('community_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->string('status')->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'profile_id']);
        });

        Schema::table('places', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->after('user_id')->constrained('communities')->nullOnDelete();
            $table->string('location')->nullable()->after('description');
            $table->text('alt_text')->nullable()->after('location');
            $table->boolean('allow_comments')->default(true)->after('alt_text');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropConstrainedForeignId('community_id');
            $table->dropColumn(['location', 'alt_text', 'allow_comments']);
        });

        Schema::dropIfExists('community_user');
        Schema::dropIfExists('communities');
    }
};
