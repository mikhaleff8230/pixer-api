<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('last_login_at');
            $table->timestamp('privacy_consent_accepted_at')->nullable()->after('terms_accepted_at');
            $table->timestamp('marketing_email_consent_at')->nullable()->after('privacy_consent_accepted_at');
            $table->timestamp('marketing_push_consent_at')->nullable()->after('marketing_email_consent_at');
            $table->string('consent_version', 32)->nullable()->after('marketing_push_consent_at');
            $table->string('consent_ip', 45)->nullable()->after('consent_version');
            $table->text('consent_user_agent')->nullable()->after('consent_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'terms_accepted_at', 'privacy_consent_accepted_at',
                'marketing_email_consent_at', 'marketing_push_consent_at',
                'consent_version', 'consent_ip', 'consent_user_agent',
            ]);
        });
    }
};
