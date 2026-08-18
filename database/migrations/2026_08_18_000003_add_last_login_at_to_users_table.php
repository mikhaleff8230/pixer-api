<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('remember_token')->index();
        });

        DB::table('personal_access_tokens')
            ->select('tokenable_id', DB::raw('MAX(created_at) as last_login_at'))
            ->where('tokenable_type', 'Marvel\\Database\\Models\\User')
            ->groupBy('tokenable_id')
            ->orderBy('tokenable_id')
            ->chunk(500, function ($tokens) {
                foreach ($tokens as $token) {
                    DB::table('users')
                        ->where('id', $token->tokenable_id)
                        ->update(['last_login_at' => $token->last_login_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn('last_login_at');
        });
    }
};
